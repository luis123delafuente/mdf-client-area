<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Clasifica las filas leidas del Excel del Modelo 347 contra la BD actual
 * (#307, generador). No escribe nada: es la simulacion que ve
 * Administracion antes de generar, y la misma funcion que se repite al
 * confirmar para comparar la huella (patron del importador de farmacias).
 *
 * Categorias, en orden de prioridad (la primera que aplica):
 * - invalido:         identificador fiscal no valido (Identificador_Fiscal_Validator).
 * - importe_invalido: algun importe ilegible o DEV vacio.
 * - no_cuadra:        T1+T2+T3+T4 != DEV (+-1 centimo). Bloquea la fila.
 * - repetido:         el identificador aparece mas de una vez en el fichero.
 *                     Bloquea TODAS las apariciones (solo puede haber un 347
 *                     vigente por farmacia, sociedad y ejercicio, y no se
 *                     elige cual vale).
 * - no_portal:        ninguna farmacia con ese CIF/NIF.
 * - ya_vigente:       la farmacia ya tiene un 347 vigente de esta sociedad y
 *                     ejercicio: se omite, nunca se sustituye.
 * - sin_cuenta:       farmacia sin cuenta activa o sin email. SE GENERA (queda
 *                     en su area para cuando active la cuenta) pero no se le
 *                     envia correo.
 * - lista:            farmacia con cuenta activa y email: se genera y se envia.
 *
 * Avisos que no bloquean: trimestre negativo, trimestre vacio tomado como 0,
 * RECC distinto de 0/False, y "solo tiene facturas de la otra sociedad".
 *
 * Cruce con las facturas (sociedad): el Excel de SAGE no dice de que
 * sociedad es (Mediformplus y Formacion exportan las mismas columnas), asi
 * que se contrasta con las facturas del portal. Una fila es dudosa si su
 * farmacia tiene facturas de otra sociedad y ninguna de la elegida. Si las
 * dudosas son mas de la mitad de las filas cuya farmacia tiene alguna
 * factura con emisor, la simulacion queda bloqueada: casi seguro se ha
 * elegido la sociedad equivocada, y no se genera nada. Las filas sin
 * facturas no dicen nada y no cuentan. Ademas, generar exige marcar una
 * casilla que confirma la sociedad (Modelo_347_Generacion_Service::confirmar()).
 *
 * Modo: hoy solo "clientes" (cruce con el portal). El modo "proveedores"
 * (sin cruce, email de la columna Email, solo correo y sin guardar) se
 * enchufara aqui como otro clasificador sin tocar el lector ni la carta.
 */
class Modelo_347_Simulacion_Service {

	public const MODO_CLIENTES = 'clientes';

	public const INVALIDO         = 'invalido';
	public const IMPORTE_INVALIDO = 'importe_invalido';
	public const NO_CUADRA        = 'no_cuadra';
	public const REPETIDO         = 'repetido';
	public const NO_PORTAL        = 'no_portal';
	public const YA_VIGENTE       = 'ya_vigente';
	public const SIN_CUENTA       = 'sin_cuenta';
	public const LISTA            = 'lista';

	/** Orden y etiqueta de las categorias en la pantalla. */
	public const ETIQUETAS = array(
		self::LISTA            => 'Lista (se genera y se envía)',
		self::SIN_CUENTA       => 'Farmacia sin cuenta activa o sin email (se genera, no se envía)',
		self::YA_VIGENTE       => 'Ya tiene un 347 vigente (se omite)',
		self::NO_PORTAL        => 'No está en el portal',
		self::INVALIDO         => 'Identificador inválido',
		self::IMPORTE_INVALIDO => 'Importes ilegibles',
		self::NO_CUADRA        => 'La suma de trimestres no cuadra con DEV',
		self::REPETIDO         => 'Identificador repetido en el fichero',
	);

	/** Categorias que generan carta. */
	public const GENERABLES = array( self::LISTA, self::SIN_CUENTA );

	/**
	 * Categorias cuya carta se puede PREVISUALIZAR (al vuelo, sin escribir
	 * nada): todas las filas con identificador valido, importes legibles y
	 * suma correcta, se generen o no. Por eso quedan fuera invalido,
	 * importe_invalido y no_cuadra. En este orden de prioridad para la vista
	 * previa de varias cartas.
	 */
	public const PREVISUALIZABLES = array( self::LISTA, self::SIN_CUENTA, self::NO_PORTAL, self::YA_VIGENTE, self::REPETIDO );

	/** @param array<string, mixed> $fila Fila ya clasificada por simular(). */
	public static function es_previsualizable( array $fila ): bool {
		return in_array( $fila['categoria'] ?? '', self::PREVISUALIZABLES, true );
	}

	/** Diferencia admitida entre la suma de trimestres y DEV, en centimos. */
	private const TOLERANCIA_CENTIMOS = 1;

	private Farmacia_Repository $farmacias;
	private Documento_Repository $documentos;

	public function __construct( ?Farmacia_Repository $farmacias = null, ?Documento_Repository $documentos = null ) {
		$this->farmacias  = $farmacias ?? new Farmacia_Repository();
		$this->documentos = $documentos ?? new Documento_Repository();
	}

	/**
	 * @param array<int, array<string, mixed>> $filas De Modelo_347_Excel_Lector::leer().
	 * @return array{
	 *     filas: array<int, array<string, mixed>>,
	 *     recuentos: array<string, int>,
	 *     total_dev_fichero: int, total_dev_lista: int, total_dev_sin_cuenta: int,
	 *     filas_sin_dev: int,
	 *     sociedad: array{con_facturas: int, dudosas: int, bloquea: bool},
	 *     huella: string
	 * }
	 */
	public function simular( array $filas, string $emisor_cif, int $ejercicio ): array {
		// Identificadores normalizados y repetidos (entre los validos).
		$apariciones = array();

		foreach ( $filas as $i => $fila ) {
			$nif                   = Identificador_Fiscal_Validator::normalizar( (string) $fila['nif'] );
			$filas[ $i ]['nif']    = $nif;
			$filas[ $i ]['valido'] = '' !== $nif && Identificador_Fiscal_Validator::is_valid( $nif );

			if ( $filas[ $i ]['valido'] ) {
				$apariciones[ $nif ] = ( $apariciones[ $nif ] ?? 0 ) + 1;
			}
		}

		// Una consulta para todas las farmacias.
		$por_cif = array();

		foreach ( $this->farmacias->find_by_cifs( array_keys( $apariciones ) ) as $farmacia ) {
			$por_cif[ $farmacia->get_cif() ] = $farmacia;
		}

		$emisores_facturas = $this->documentos->emisores_de_facturas( array_map( static fn( Farmacia $f ): int => $f->get_id(), $por_cif ) );
		$sociedad          = array(
			'con_facturas' => 0,
			'dudosas'      => 0,
			'bloquea'      => false,
		);

		$resultado = array();
		$recuentos = array_fill_keys( array_keys( self::ETIQUETAS ), 0 );
		$totales   = array(
			'fichero'    => 0,
			'lista'      => 0,
			'sin_cuenta' => 0,
			'sin_dev'    => 0,
		);

		foreach ( $filas as $fila ) {
			$fila['avisos']      = self::avisos( $fila );
			$fila['farmacia_id'] = null;
			$fila['categoria']   = $this->categoria( $fila, $apariciones, $por_cif, $emisor_cif, $ejercicio );

			// Cruce con las facturas: solo filas validas cuya farmacia esta en el portal.
			$emisores_fila           = $fila['valido'] && isset( $por_cif[ $fila['nif'] ] ) ? ( $emisores_facturas[ $por_cif[ $fila['nif'] ]->get_id() ] ?? array() ) : array();
			$fila['sociedad_dudosa'] = $emisores_fila && ! in_array( $emisor_cif, $emisores_fila, true );

			if ( $emisores_fila ) {
				++$sociedad['con_facturas'];
			}

			if ( $fila['sociedad_dudosa'] ) {
				++$sociedad['dudosas'];
				$fila['avisos'][] = 'Solo tiene facturas de ' . implode( ' y ', array_map( static fn( string $cif ): string => (string) ( Factura_Emisores::nombre_corto( $cif ) ?? $cif ), $emisores_fila ) ) . ' en el portal';
			}

			if ( isset( $por_cif[ $fila['nif'] ] ) && in_array( $fila['categoria'], array( self::YA_VIGENTE, self::SIN_CUENTA, self::LISTA ), true ) ) {
				$fila['farmacia_id']     = $por_cif[ $fila['nif'] ]->get_id();
				$fila['farmacia_nombre'] = $por_cif[ $fila['nif'] ]->get_nombre();
			}

			if ( null === $fila['dev'] ) {
				++$totales['sin_dev'];
			} else {
				$totales['fichero'] += (int) $fila['dev'];
			}

			if ( self::LISTA === $fila['categoria'] ) {
				$totales['lista'] += (int) $fila['dev'];
			} elseif ( self::SIN_CUENTA === $fila['categoria'] ) {
				$totales['sin_cuenta'] += (int) $fila['dev'];
			}

			++$recuentos[ $fila['categoria'] ];
			$resultado[] = $fila;
		}

		$sociedad['bloquea'] = $sociedad['dudosas'] * 2 > $sociedad['con_facturas'];

		return array(
			'filas'                => $resultado,
			'recuentos'            => $recuentos,
			'total_dev_fichero'    => $totales['fichero'],
			'total_dev_lista'      => $totales['lista'],
			'total_dev_sin_cuenta' => $totales['sin_cuenta'],
			'filas_sin_dev'        => $totales['sin_dev'],
			'sociedad'             => $sociedad,
			'huella'               => self::huella( $resultado, $emisor_cif, $ejercicio ),
		);
	}

	/**
	 * @param array<string, mixed>    $fila
	 * @param array<string, int>      $apariciones
	 * @param array<string, Farmacia> $por_cif
	 */
	private function categoria( array $fila, array $apariciones, array $por_cif, string $emisor_cif, int $ejercicio ): string {
		if ( ! $fila['valido'] ) {
			return self::INVALIDO;
		}

		foreach ( array( 't1', 't2', 't3', 't4', 'dev' ) as $campo ) {
			if ( null === $fila[ $campo ] ) {
				return self::IMPORTE_INVALIDO;
			}
		}

		if ( abs( $fila['t1'] + $fila['t2'] + $fila['t3'] + $fila['t4'] - $fila['dev'] ) > self::TOLERANCIA_CENTIMOS ) {
			return self::NO_CUADRA;
		}

		if ( ( $apariciones[ $fila['nif'] ] ?? 0 ) > 1 ) {
			return self::REPETIDO;
		}

		$farmacia = $por_cif[ $fila['nif'] ] ?? null;

		if ( ! $farmacia ) {
			return self::NO_PORTAL;
		}

		if ( $this->documentos->find_modelo_347_vigente( $farmacia->get_id(), $emisor_cif, $ejercicio ) ) {
			return self::YA_VIGENTE;
		}

		return null === self::email_de_cuenta_activa( $farmacia ) ? self::SIN_CUENTA : self::LISTA;
	}

	/** Email de la cuenta del portal si esta activa y tiene un email valido; si no, null. Lo usan la simulacion y el envio. */
	public static function email_de_cuenta_activa( Farmacia $farmacia ): ?string {
		$cuenta = Invitacion_Service::estado_cuenta( $farmacia );

		return 'activa' === $cuenta['estado'] && is_string( $cuenta['email'] ) && is_email( $cuenta['email'] ) ? $cuenta['email'] : null;
	}

	/**
	 * @param array<string, mixed> $fila
	 * @return string[]
	 */
	private static function avisos( array $fila ): array {
		$avisos = array();

		foreach ( array( 't1' => 'T1', 't2' => 'T2', 't3' => 'T3', 't4' => 'T4' ) as $campo => $etiqueta ) {
			if ( null !== $fila[ $campo ] && $fila[ $campo ] < 0 ) {
				$avisos[] = $etiqueta . ' negativo';
			}
		}

		if ( $fila['t_vacios'] ) {
			$avisos[] = 'Trimestre vacío tomado como 0 (' . strtoupper( implode( ', ', $fila['t_vacios'] ) ) . ')';
		}

		if ( $fila['recc_activo'] || ( null !== $fila['recc_importe'] && 0 !== $fila['recc_importe'] ) ) {
			$avisos[] = 'RECC distinto de 0';
		}

		return $avisos;
	}

	/**
	 * Huella de lo simulado: sociedad, ejercicio y, por fila, linea,
	 * categoria, farmacia, identificador, nombre, domicilio, importes y si es
	 * dudosa en el cruce con las facturas. Si
	 * algo de esto cambia entre la simulacion y la confirmacion (otra farmacia
	 * activa su cuenta, alguien genera un 347...), la confirmacion no vale.
	 *
	 * @param array<int, array<string, mixed>> $filas
	 */
	private static function huella( array $filas, string $emisor_cif, int $ejercicio ): string {
		$partes = array( $emisor_cif, $ejercicio );

		foreach ( $filas as $f ) {
			$partes[] = array( $f['linea'], $f['categoria'], $f['farmacia_id'], $f['nif'], $f['nombre'], $f['direccion'], $f['cp'], $f['poblacion'], $f['t1'], $f['t2'], $f['t3'], $f['t4'], $f['dev'], $f['sociedad_dudosa'] );
		}

		return hash( 'sha256', (string) wp_json_encode( $partes ) );
	}
}
