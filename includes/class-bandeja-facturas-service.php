<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bandeja de facturas no reconocidas (#335): lo que "Subir facturas" lee
 * entero pero no puede asignar a una farmacia se guarda aqui en vez de
 * descartarse, para asignarlo a mano, descartarlo o volver a comprobarlo.
 *
 * - Entran: sin CIF, CIF invalido, CIF sin farmacia, numero distinto del
 *   nombre del fichero y lo que no parece una factura de SAGE (sin texto o
 *   formato inesperado). No entran: las repetidas (ya son documento o ya
 *   estan en la bandeja) ni lo que el lector no ha podido leer entero
 *   (topes, cifrado, no se abre: Factura_Lectura_Service::se_pudo_leer()).
 * - Fichero en la carpeta privada (bandeja-<aleatorio>.pdf), con la misma
 *   proteccion que los documentos; solo se ve por la vista previa del
 *   administrador. En la tabla, el motivo y lo que se haya leido (emisor,
 *   serie, numero, fecha, CIF leido, si es abono), nunca importes.
 * - Asignar a mano y "Volver a comprobar todas" pasan por
 *   Factura_Recepcion_Service (mismas reglas de unicidad y nombre) y la
 *   factura queda pendiente de publicar; despues sale de la bandeja.
 * - Descartar y caducar borran fichero y fila. Caduca lo que lleva mas de
 *   dias() en la bandeja (WP-Cron diario).
 * - Cada paso deja un evento en wp_mdf_ca_bandeja_eventos (sin datos
 *   fiscales). Nada se registra con valores de la factura.
 */
class Bandeja_Facturas_Service {

	/** Estados del cruce que entran en la bandeja. */
	public const MOTIVOS_QUE_ENTRAN = array(
		Factura_Cruce_Service::SIN_TEXTO,
		Factura_Cruce_Service::FORMATO_INESPERADO,
		Factura_Cruce_Service::NUMERO_DISCREPANTE,
		Factura_Cruce_Service::SIN_CIF,
		Factura_Cruce_Service::CIF_INVALIDO,
		Factura_Cruce_Service::CIF_SIN_FARMACIA,
	);

	public const OPTION_DIAS      = 'mdf_ca_bandeja_dias';
	public const DIAS_POR_DEFECTO = 90;
	public const DIAS_MINIMO      = 7;
	public const DIAS_MAXIMO      = 3650;

	/** La pantalla avisa de lo que caduca en estos dias. */
	public const DIAS_AVISO = 14;

	public const HOOK_CRON = 'mdf_ca_bandeja_caducar';

	/** Resultados de entrar(). */
	public const EN_BANDEJA    = 'bandeja';
	public const YA_EN_BANDEJA = 'ya_en_bandeja';
	public const YA_DOCUMENTO  = 'ya_documento';
	public const ERROR         = 'error';

	/** Tiempo maximo de "Volver a comprobar todas" en una peticion; si queda algo, se vuelve a pulsar. */
	private const SEGUNDOS_RECOMPROBAR = 20;

	/** Caducidad: cuantas por tanda (una ejecucion del cron puede hacer varias). */
	private const TANDA_CADUCAR = 200;

	private Bandeja_Facturas_Repository $repo;

	public function __construct( ?Bandeja_Facturas_Repository $repo = null ) {
		$this->repo = $repo ?? new Bandeja_Facturas_Repository();
	}

	public static function register_hooks(): void {
		add_action( self::HOOK_CRON, array( __CLASS__, 'ejecutar_caducidad' ) );
		add_action( 'init', array( __CLASS__, 'programar' ) );
	}

	/** Evento diario; wp_next_scheduled() lee la option "cron", ya en memoria. */
	public static function programar(): void {
		if ( ! wp_next_scheduled( self::HOOK_CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_CRON );
		}
	}

	public static function desprogramar(): void {
		wp_clear_scheduled_hook( self::HOOK_CRON );
	}

	public static function ejecutar_caducidad(): void {
		( new self() )->caducar();
	}

	public static function dias(): int {
		$dias = (int) get_option( self::OPTION_DIAS, self::DIAS_POR_DEFECTO );

		return $dias >= self::DIAS_MINIMO && $dias <= self::DIAS_MAXIMO ? $dias : self::DIAS_POR_DEFECTO;
	}

	public static function set_dias( int $dias ): bool {
		if ( $dias < self::DIAS_MINIMO || $dias > self::DIAS_MAXIMO ) {
			return false;
		}

		update_option( self::OPTION_DIAS, $dias, false );

		return true;
	}

	/** Fecha (UTC) en que caduca una fila de la bandeja. */
	public static function caduca_en( array $fila ): int {
		return (int) strtotime( $fila['subido_en'] . ' UTC' ) + self::dias() * DAY_IN_SECONDS;
	}

	public function contar(): int {
		return $this->repo->contar();
	}

	/** Las que caducan en los proximos DIAS_AVISO dias. */
	public function contar_caducan_pronto(): int {
		return $this->repo->contar_anteriores_a( gmdate( 'Y-m-d H:i:s', time() - ( self::dias() - self::DIAS_AVISO ) * DAY_IN_SECONDS ) );
	}

	/**
	 * Una factura de "Subir facturas" que no se ha podido asignar. El fichero
	 * es la subida HTTP ($archivo['tmp_name']) y se mueve a la carpeta privada.
	 *
	 * @param array{tmp_name: string, size: int} $archivo
	 * @param array<string, mixed>               $lectura Resultado de Factura_Lectura_Service.
	 * @return string EN_BANDEJA, YA_EN_BANDEJA, YA_DOCUMENTO o ERROR.
	 */
	public function entrar( array $archivo, string $nombre_original, array $lectura, string $motivo, int $usuario_id ): string {
		if ( ! in_array( $motivo, self::MOTIVOS_QUE_ENTRAN, true ) ) {
			return self::ERROR;
		}

		$hash = hash_file( 'sha256', $archivo['tmp_name'] );

		if ( false === $hash ) {
			return self::ERROR;
		}

		$datos = self::datos_leidos( $lectura );

		if ( self::ya_es_documento( $hash, $datos ) ) {
			return self::YA_DOCUMENTO;
		}

		if ( $this->repo->find_existente( $hash, $datos['emisor_cif'], $datos['serie'], $datos['numero'] ) ) {
			return self::YA_EN_BANDEJA;
		}

		$carpeta = Documento_Endpoint::get_carpeta_documentos();

		if ( ! is_dir( $carpeta ) || ! is_writable( $carpeta ) ) {
			return self::ERROR;
		}

		$nombre_disco = 'bandeja-' . bin2hex( random_bytes( 16 ) ) . '.pdf';
		$destino      = $carpeta . DIRECTORY_SEPARATOR . $nombre_disco;

		if ( ! move_uploaded_file( $archivo['tmp_name'], $destino ) ) {
			return self::ERROR;
		}

		$id = $this->repo->insert(
			array_merge(
				$datos,
				array(
					'ruta_fichero'    => $nombre_disco,
					'nombre_original' => substr( $nombre_original, 0, 255 ),
					'tamano_bytes'    => (int) $archivo['size'],
					'hash_sha256'     => $hash,
					'motivo'          => $motivo,
					'usuario_id'      => $usuario_id,
				)
			)
		);

		if ( null === $id ) {
			wp_delete_file( $destino );

			// Otra subida de la misma factura gano la carrera.
			return $this->repo->find_existente( $hash, $datos['emisor_cif'], $datos['serie'], $datos['numero'] ) ? self::YA_EN_BANDEJA : self::ERROR;
		}

		$this->repo->registrar_evento( $id, Bandeja_Facturas_Repository::EVENTO_ENTRADA, $usuario_id );

		return self::EN_BANDEJA;
	}

	/**
	 * Asignar a mano una factura de la bandeja a una farmacia. Lo leido del
	 * PDF manda; lo que falte (emisor, serie, numero, fecha, abono) sale de
	 * $formulario. Si el CIF leido no es el de la farmacia, hace falta
	 * $confirmar_cif.
	 *
	 * @param array{emisor?: string, serie?: string, numero?: string, fecha?: string, es_abono?: bool, sin_marca?: bool} $formulario
	 *        sin_marca: el documento nace sin marca de agua (#321).
	 * @return array{documento: Documento, ya_existia: bool}|\WP_Error
	 */
	public function asignar( int $id, int $farmacia_id, array $formulario, bool $confirmar_cif, int $usuario_id ) {
		$fila     = $this->repo->find_by_id( $id );
		$farmacia = $farmacia_id > 0 ? ( new Farmacia_Repository() )->find_by_id( $farmacia_id ) : null;

		if ( ! $fila ) {
			return new \WP_Error( 'mdf_ca_bandeja_no_existe', 'La factura ya no está en la bandeja (puede que otra persona la haya asignado o descartado).' );
		}

		if ( ! $farmacia ) {
			return new \WP_Error( 'mdf_ca_bandeja_sin_farmacia', 'Elige la farmacia de la lista.' );
		}

		if ( self::cif_distinto( $fila, $farmacia ) && ! $confirmar_cif ) {
			return new \WP_Error( 'mdf_ca_bandeja_cif_distinto', 'El CIF leído en la factura no es el de la farmacia elegida. Revísalo y, si es correcto, marca la casilla de confirmación.' );
		}

		$datos = self::datos_para_asignar( $fila, $formulario );

		if ( ! Factura_Recepcion_Service::datos_validos( $datos['emisor'], $datos['serie'], $datos['numero'], $datos['fecha'] ) ) {
			return new \WP_Error( 'mdf_ca_bandeja_datos', 'Faltan datos de la factura o no son válidos: sociedad, serie (de esa sociedad), número (solo cifras) y fecha de la factura.' );
		}

		$ruta = $this->ruta_fichero( $fila );

		if ( null === $ruta ) {
			return new \WP_Error( 'mdf_ca_bandeja_sin_fichero', 'El fichero de esta factura ya no está en el servidor. Descártala y vuelve a subirla.' );
		}

		$recepcion = ( new Factura_Recepcion_Service() )->asignar_manual( self::archivo_en_disco( $ruta ), $farmacia->get_id(), $datos, ! empty( $formulario['sin_marca'] ) );

		if ( Factura_Recepcion_Service::ACEPTADO === $recepcion['resultado'] ) {
			$this->sacar( $fila, $ruta, Bandeja_Facturas_Repository::EVENTO_ASIGNADA, $usuario_id, $recepcion['documento']->get_id() );

			return array(
				'documento'  => $recepcion['documento'],
				'ya_existia' => false,
			);
		}

		if ( Factura_Recepcion_Service::DUPLICADO === $recepcion['resultado'] ) {
			$this->sacar( $fila, $ruta, Bandeja_Facturas_Repository::EVENTO_YA_EXISTIA, $usuario_id, $recepcion['documento']->get_id() );

			return array(
				'documento'  => $recepcion['documento'],
				'ya_existia' => true,
			);
		}

		if ( Factura_Recepcion_Service::OTRA_FARMACIA === $recepcion['estado'] ) {
			return new \WP_Error( 'mdf_ca_bandeja_otra_farmacia', 'Esta factura (el mismo PDF, o la misma sociedad, serie y número) ya está subida para otra farmacia. No se ha asignado.' );
		}

		return new \WP_Error( 'mdf_ca_bandeja_error', 'No se ha podido asignar la factura. No se ha cambiado nada.' );
	}

	/** Descartar: borra fichero y fila, con su evento. */
	public function descartar( int $id, int $usuario_id ): bool {
		$fila = $this->repo->find_by_id( $id );

		if ( ! $fila ) {
			return false;
		}

		return $this->sacar( $fila, $this->ruta_fichero( $fila ), Bandeja_Facturas_Repository::EVENTO_DESCARTADA, $usuario_id, null );
	}

	/**
	 * Repite la lectura y el cruce de toda la bandeja (de la mas antigua a la
	 * mas reciente). Las que ahora encuentran farmacia pasan a Pendientes de
	 * publicar por Factura_Recepcion_Service; las que ya son documento salen;
	 * el resto se queda. Se para a los SEGUNDOS_RECOMPROBAR.
	 *
	 * @return array{asignadas: int, ya_existian: int, siguen: int, quedan: int}
	 */
	public function recomprobar_todas( int $usuario_id ): array {
		$fin       = microtime( true ) + self::SEGUNDOS_RECOMPROBAR;
		$lector    = new Factura_Lectura_Service();
		$cruce     = new Factura_Cruce_Service();
		$recepcion = new Factura_Recepcion_Service();
		$ids       = $this->repo->ids();
		$cuenta    = array(
			'asignadas'   => 0,
			'ya_existian' => 0,
			'siguen'      => 0,
			'quedan'      => 0,
		);

		foreach ( $ids as $k => $id ) {
			if ( microtime( true ) > $fin ) {
				$cuenta['quedan'] = count( $ids ) - $k;
				break;
			}

			$fila = $this->repo->find_by_id( $id );
			$ruta = $fila ? $this->ruta_fichero( $fila ) : null;

			if ( null === $ruta ) {
				continue;
			}

			$lectura = $lector->analizar( $ruta, $fila['nombre_original'] );

			// Solo se intenta guardar si ahora el cruce encuentra farmacia: asi
			// lo que se queda no vuelve a contar en el registro de recepcion.
			if ( ! $lector->se_pudo_leer() || Factura_Cruce_Service::ASIGNADA !== $cruce->cruzar( $lectura )['estado'] ) {
				++$cuenta['siguen'];
				continue;
			}

			$r = $recepcion->recibir( self::archivo_en_disco( $ruta ), $lectura, Publicacion_Evento_Repository::ORIGEN_BANDEJA );

			if ( Factura_Recepcion_Service::ACEPTADO === $r['resultado'] ) {
				$this->sacar( $fila, $ruta, Bandeja_Facturas_Repository::EVENTO_ASIGNADA_AUTO, $usuario_id, $r['documento']->get_id() );
				++$cuenta['asignadas'];
			} elseif ( Factura_Recepcion_Service::DUPLICADO === $r['resultado'] ) {
				$this->sacar( $fila, $ruta, Bandeja_Facturas_Repository::EVENTO_YA_EXISTIA, $usuario_id, $r['documento']->get_id() );
				++$cuenta['ya_existian'];
			} else {
				++$cuenta['siguen'];
			}
		}

		return $cuenta;
	}

	/** Borra lo que lleva mas de dias() en la bandeja. @return int Borradas. */
	public function caducar(): int {
		$limite = gmdate( 'Y-m-d H:i:s', time() - self::dias() * DAY_IN_SECONDS );
		$total  = 0;

		do {
			$ids = $this->repo->ids_anteriores_a( $limite, self::TANDA_CADUCAR );

			foreach ( $ids as $id ) {
				$fila = $this->repo->find_by_id( $id );

				if ( $fila && $this->sacar( $fila, $this->ruta_fichero( $fila ), Bandeja_Facturas_Repository::EVENTO_CADUCADA, null, null ) ) {
					++$total;
				}
			}
		} while ( count( $ids ) === self::TANDA_CADUCAR );

		return $total;
	}

	/** Lo leido del PDF, saneado para la tabla (lo que no tenga la forma esperada, null). */
	private static function datos_leidos( array $lectura ): array {
		$emisor = isset( $lectura['emisor'] ) && is_string( $lectura['emisor'] ) && Factura_Emisores::es_emisor( $lectura['emisor'] ) ? $lectura['emisor'] : null;
		$serie  = isset( $lectura['serie'] ) && is_string( $lectura['serie'] ) && 1 === preg_match( '/^[A-Z]{1,3}$/D', $lectura['serie'] ) ? $lectura['serie'] : null;
		$numero = isset( $lectura['numero'] ) && is_string( $lectura['numero'] ) && 1 === preg_match( '/^\d{1,20}$/D', $lectura['numero'] ) ? $lectura['numero'] : null;
		$fecha  = isset( $lectura['fecha'] ) && is_string( $lectura['fecha'] ) && 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/D', $lectura['fecha'] ) ? $lectura['fecha'] : null;
		$cif    = isset( $lectura['cif'] ) && is_string( $lectura['cif'] ) && '' !== $lectura['cif'] ? substr( Identificador_Fiscal_Validator::normalizar( $lectura['cif'] ), 0, 20 ) : null;

		return array(
			'emisor_cif'      => $emisor,
			'serie'           => $serie,
			'numero'          => $numero,
			'fecha_documento' => $fecha,
			'cif_leido'       => $cif,
			'es_abono'        => isset( $lectura['importe_total_centimos'] ) && is_int( $lectura['importe_total_centimos'] ) ? $lectura['importe_total_centimos'] < 0 : null,
		);
	}

	/** Lo guardado manda; el formulario solo rellena lo que falta. */
	private static function datos_para_asignar( array $fila, array $formulario ): array {
		return array(
			'emisor'   => (string) ( $fila['emisor_cif'] ?? $formulario['emisor'] ?? '' ),
			'serie'    => strtoupper( trim( (string) ( $fila['serie'] ?? $formulario['serie'] ?? '' ) ) ),
			'numero'   => trim( (string) ( $fila['numero'] ?? $formulario['numero'] ?? '' ) ),
			'fecha'    => (string) ( $fila['fecha_documento'] ?? $formulario['fecha'] ?? '' ),
			'es_abono' => null !== $fila['es_abono'] ? (bool) (int) $fila['es_abono'] : ! empty( $formulario['es_abono'] ),
		);
	}

	/** Hay un CIF leido y no es el de la farmacia. */
	public static function cif_distinto( array $fila, Farmacia $farmacia ): bool {
		return null !== $fila['cif_leido'] && '' !== $fila['cif_leido']
			&& Identificador_Fiscal_Validator::normalizar( (string) $fila['cif_leido'] ) !== Identificador_Fiscal_Validator::normalizar( $farmacia->get_cif() );
	}

	/** El mismo PDF, o la misma factura si se leyo entera, ya es un documento. */
	private static function ya_es_documento( string $hash, array $datos ): bool {
		$repositorio = new Documento_Repository();

		if ( $repositorio->find_by_hash( $hash ) ) {
			return true;
		}

		return null !== $datos['emisor_cif'] && null !== $datos['serie'] && null !== $datos['numero']
			&& null !== $repositorio->find_by_factura( $datos['emisor_cif'], $datos['serie'], $datos['numero'] );
	}

	/** Ruta real del fichero dentro de la carpeta privada, o null si no esta. */
	public function ruta_fichero( array $fila ): ?string {
		$carpeta = realpath( Documento_Endpoint::get_carpeta_documentos() );
		$ruta    = $carpeta ? realpath( $carpeta . DIRECTORY_SEPARATOR . basename( (string) $fila['ruta_fichero'] ) ) : false;

		return $ruta && str_starts_with( $ruta, $carpeta . DIRECTORY_SEPARATOR ) && is_file( $ruta ) ? $ruta : null;
	}

	private static function archivo_en_disco( string $ruta ): array {
		return array(
			'tmp_name'    => $ruta,
			'size'        => (int) filesize( $ruta ),
			'error'       => UPLOAD_ERR_OK,
			'desde_disco' => true,
		);
	}

	/**
	 * Saca una factura de la bandeja: borra la fila (si sigue ahi), anota el
	 * evento y borra el fichero. Si otra peticion ya la saco, no hace nada.
	 */
	private function sacar( array $fila, ?string $ruta, string $evento, ?int $usuario_id, ?int $documento_id ): bool {
		if ( 1 !== $this->repo->borrar( (int) $fila['id'] ) ) {
			return false;
		}

		$this->repo->registrar_evento( (int) $fila['id'], $evento, $usuario_id, $documento_id );

		if ( null !== $ruta ) {
			wp_delete_file( $ruta );
		}

		return true;
	}
}
