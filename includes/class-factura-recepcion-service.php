<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Guardado de una factura recibida (#334, extraido de la ruta REST #303):
 * el unico camino por el que una factura de SAGE entra como documento, lo
 * use el robot (Recepcion_Documentos_Endpoint) o una persona de MDF desde
 * "Subir facturas" (Admin_Subir_Facturas). Mismo cruce, misma unicidad,
 * misma fecha de documento, mismo nombre visible y mismo registro:
 *
 * 1. Factura_Cruce_Service::cruzar() revalida todo el resultado del parser
 *    (el de Python que manda el robot o el de Factura_Lectura_Service) y
 *    decide la farmacia. Si no es "asignada", excepcion y no se escribe nada.
 * 2. Duplicados por SHA-256 del PDF y por emisor + serie + numero: en la
 *    misma farmacia, "duplicado" (idempotente: subir otra vez la misma
 *    carpeta no duplica nada); en otra, excepcion documento_en_otra_farmacia.
 * 3. Documento_Service::subir_factura_recibida(): tipo factura, PDF real,
 *    nombre "Factura|Abono <sociedad> <serie> <numero>", fecha de la factura
 *    ya revalidada. Si dos peticiones guardan la misma factura a la vez, el
 *    UNIQUE hace fallar el INSERT de la segunda y se vuelve al paso 2.
 *
 * Cada resultado suma uno en Recepcion_Registro con su origen (solo
 * recuentos, nunca datos fiscales). Nada de esto registra valores de la
 * factura: el error de BD de un INSERT que pierde la carrera se silencia en
 * Documento_Service.
 */
class Factura_Recepcion_Service {

	public const ACEPTADO  = 'aceptado';
	public const DUPLICADO = 'duplicado';
	public const EXCEPCION = 'excepcion';
	/** El fichero no se ha podido guardar (no es un PDF valido, disco, BD...). */
	public const RECHAZADO = 'rechazado';

	public const OTRA_FARMACIA = 'documento_en_otra_farmacia';

	/** Errores de Documento_Service que son del servidor, no del fichero (la REST responde 500, como antes de #334). */
	private const ERRORES_INTERNOS = array( 'mdf_ca_error_bd', 'mdf_ca_documento_carpeta_no_disponible', 'mdf_ca_documento_error_escritura' );

	private Factura_Cruce_Service $cruce;
	private Documento_Repository $repositorio;
	private Documento_Service $documentos;

	public function __construct( ?Factura_Cruce_Service $cruce = null, ?Documento_Repository $repositorio = null, ?Documento_Service $documentos = null ) {
		$this->cruce       = $cruce ?? new Factura_Cruce_Service();
		$this->repositorio = $repositorio ?? new Documento_Repository();
		$this->documentos  = $documentos ?? new Documento_Service();
	}

	/**
	 * @param array{tmp_name: string, size: int, error: int, desde_disco?: bool} $archivo
	 *        Subida HTTP del PDF o, con desde_disco, un PDF que ya esta en la
	 *        carpeta privada (bandeja, #335). Su nombre no se usa.
	 * @param array<string, mixed>                            $resultado Resultado del parser (sin validar).
	 * @param bool        $notificar   false: sin aviso por email a la farmacia.
	 * @param bool        $publicar    false: nace pendiente de publicar.
	 * @param string      $origen      Recepcion_Registro::ORIGEN_REST o ORIGEN_BACKOFFICE.
	 * @param string|null $origen_alta Evento de alta (ver Documento_Service::subir_factura_recibida()).
	 * @param string|null $accion_alta Accion de ese evento si nace pendiente (por defecto "recibido").
	 * @return array{resultado: string, estado: ?string, farmacia_id: ?int, documento: ?Documento, interno: bool}
	 *         estado: el del cruce (o documento_en_otra_farmacia) en una excepcion.
	 *         interno: en un rechazo, si el fallo es del servidor.
	 */
	public function recibir( array $archivo, array $resultado, bool $notificar, bool $publicar, string $origen, ?string $origen_alta = null, ?string $accion_alta = null ): array {
		$cruce = $this->cruce->cruzar( $resultado );

		if ( Factura_Cruce_Service::ASIGNADA !== $cruce['estado'] ) {
			return $this->excepcion( $cruce['estado'], $origen );
		}

		return $this->guardar(
			$archivo,
			(int) $cruce['farmacia_id'],
			array(
				'emisor_cif' => $cruce['emisor'],
				'serie'      => $cruce['serie'],
				'numero'     => $cruce['numero'],
			),
			$cruce['fecha'], // revalidada por Factura_Cruce_Service, nunca la declarada
			$cruce['importe_total_centimos'] < 0,
			$notificar,
			$publicar,
			$origen,
			$origen_alta,
			$accion_alta
		);
	}

	/**
	 * Factura de la bandeja (#335) asignada a mano a una farmacia: sin cruce
	 * por CIF (lo decide la persona), pero con las mismas reglas que una
	 * asignada por el cruce: emisor y serie de Factura_Emisores, serie de 1 a
	 * 3 letras, numero de 1 a 20 cifras, fecha de documento valida, misma
	 * unicidad por hash y por emisor + serie + numero, mismo nombre visible y
	 * mismo registro. Nace pendiente y sin aviso; el evento de alta es
	 * "asignado_manual" con origen "bandeja".
	 *
	 * @param array{tmp_name: string, size: int, error: int, desde_disco?: bool} $archivo
	 * @param array{emisor: string, serie: string, numero: string, fecha: string, es_abono: bool} $datos Sin validar.
	 * @return array{resultado: string, estado: ?string, farmacia_id: ?int, documento: ?Documento, interno: bool}
	 *         Con datos que no valen: excepcion formato_inesperado.
	 */
	public function asignar_manual( array $archivo, int $farmacia_id, array $datos, string $origen ): array {
		$emisor = Identificador_Fiscal_Validator::normalizar( (string) ( $datos['emisor'] ?? '' ) );
		$serie  = (string) ( $datos['serie'] ?? '' );
		$numero = (string) ( $datos['numero'] ?? '' );
		$fecha  = (string) ( $datos['fecha'] ?? '' );

		if ( ! self::datos_validos( $emisor, $serie, $numero, $fecha ) || $farmacia_id <= 0 ) {
			return $this->excepcion( Factura_Cruce_Service::FORMATO_INESPERADO, $origen );
		}

		return $this->guardar(
			$archivo,
			$farmacia_id,
			array(
				'emisor_cif' => $emisor,
				'serie'      => $serie,
				'numero'     => $numero,
			),
			$fecha,
			! empty( $datos['es_abono'] ),
			false,
			false,
			$origen,
			Publicacion_Evento_Repository::ORIGEN_BANDEJA,
			Publicacion_Evento_Repository::ACCION_ASIGNADO_MANUAL
		);
	}

	/** Las mismas comprobaciones que Factura_Cruce_Service hace con una factura "ok". */
	public static function datos_validos( string $emisor, string $serie, string $numero, string $fecha ): bool {
		$fecha_ok = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fecha );

		return 1 === preg_match( '/^[A-Z]{1,3}$/D', $serie )
			&& 1 === preg_match( '/^\d{1,20}$/D', $numero )
			&& $fecha_ok && $fecha_ok->format( 'Y-m-d' ) === $fecha && Documento_Service::fecha_documento_valida( $fecha )
			&& Factura_Emisores::admite( $emisor, $serie );
	}

	/**
	 * Duplicados, alta y carrera: el camino comun de recibir() y
	 * asignar_manual().
	 *
	 * @param array{emisor_cif: string, serie: string, numero: string} $factura Ya validada.
	 */
	private function guardar( array $archivo, int $farmacia_id, array $factura, string $fecha, bool $es_abono, bool $notificar, bool $publicar, string $origen, ?string $origen_alta, ?string $accion_alta ): array {
		$hash = hash_file( 'sha256', $archivo['tmp_name'] );

		if ( false === $hash ) {
			return $this->rechazo( false, $origen );
		}

		$existentes = $this->buscar_existentes( $hash, $factura );

		if ( $existentes ) {
			return $this->duplicado( $existentes, $farmacia_id, $origen );
		}

		$documento = $this->documentos->subir_factura_recibida(
			$farmacia_id,
			sprintf( '%s %s %s %s', $es_abono ? 'Abono' : 'Factura', Factura_Emisores::nombre_corto( $factura['emisor_cif'] ), $factura['serie'], $factura['numero'] ),
			array(
				// El nombre original no se usa para nada; el tipo lo decide el contenido.
				'name'        => 'factura.pdf',
				'type'        => 'application/pdf',
				'tmp_name'    => $archivo['tmp_name'],
				'error'       => (int) $archivo['error'],
				'size'        => (int) $archivo['size'],
				'desde_disco' => ! empty( $archivo['desde_disco'] ),
			),
			$hash,
			$factura,
			$notificar,
			$publicar,
			$fecha,
			$origen_alta,
			$accion_alta
		);

		if ( is_wp_error( $documento ) ) {
			// Dos envios de la misma factura a la vez: el UNIQUE del hash o el
			// de la factura hace fallar el segundo INSERT. Si ahora existe, es
			// un duplicado (o la misma factura en otra farmacia).
			$existentes = $this->buscar_existentes( $hash, $factura );

			if ( $existentes ) {
				return $this->duplicado( $existentes, $farmacia_id, $origen );
			}

			return $this->rechazo( in_array( $documento->get_error_code(), self::ERRORES_INTERNOS, true ), $origen );
		}

		Recepcion_Registro::registrar( Recepcion_Registro::ACEPTADO, 1, $origen );

		return self::salida( self::ACEPTADO, null, $farmacia_id, $documento );
	}

	/**
	 * Documentos ya guardados con el mismo PDF (hash) o la misma factura
	 * (emisor, serie y numero), sin repetir: normalmente uno o ninguno.
	 *
	 * @param array{emisor_cif: string, serie: string, numero: string} $factura
	 * @return Documento[]
	 */
	private function buscar_existentes( string $hash, array $factura ): array {
		$existentes = array();

		foreach ( array(
			$this->repositorio->find_by_hash( $hash ),
			$this->repositorio->find_by_factura( $factura['emisor_cif'], $factura['serie'], $factura['numero'] ),
		) as $documento ) {
			if ( $documento ) {
				$existentes[ $documento->get_id() ] = $documento;
			}
		}

		return array_values( $existentes );
	}

	/** @param Documento[] $existentes No vacio. */
	private function duplicado( array $existentes, int $farmacia_id, string $origen ): array {
		// El mismo PDF o la misma factura para otra farmacia no es un
		// duplicado: es un dato incoherente, y no se escribe nada.
		foreach ( $existentes as $existente ) {
			if ( $existente->get_farmacia_id() !== $farmacia_id ) {
				return $this->excepcion( self::OTRA_FARMACIA, $origen );
			}
		}

		Recepcion_Registro::registrar( Recepcion_Registro::DUPLICADO, 1, $origen );

		return self::salida( self::DUPLICADO, null, $farmacia_id, $existentes[0] );
	}

	private function excepcion( string $estado, string $origen ): array {
		Recepcion_Registro::registrar( Recepcion_Registro::EXCEPCION, 1, $origen );

		return self::salida( self::EXCEPCION, $estado, null, null );
	}

	private function rechazo( bool $interno, string $origen ): array {
		Recepcion_Registro::registrar( Recepcion_Registro::RECHAZADO, 1, $origen );

		return self::salida( self::RECHAZADO, null, null, null, $interno );
	}

	private static function salida( string $resultado, ?string $estado, ?int $farmacia_id, ?Documento $documento, bool $interno = false ): array {
		return array(
			'resultado'   => $resultado,
			'estado'      => $estado,
			'farmacia_id' => $farmacia_id,
			'documento'   => $documento,
			'interno'     => $interno,
		);
	}
}
