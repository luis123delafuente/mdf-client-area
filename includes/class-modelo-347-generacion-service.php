<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Simulacion guardada, confirmacion con huella y generacion por tandas de las
 * cartas del Modelo 347 (#307).
 *
 * Que se guarda y cuanto:
 * - Simulacion (transient mdf_ca_347_sim_{user}_{id}, 15 min): las filas YA
 *   LEIDAS y saneadas del Excel (nombre, identificador, domicilio, importes),
 *   la sociedad y el ejercicio. El Excel nunca se guarda. Se borra al
 *   confirmar (consumo atomico), al cancelar o al empezar otra simulacion;
 *   si nadie confirma, caduca y la tarea diaria de WordPress borra los
 *   transients caducados.
 * - Trabajo de generacion (transient mdf_ca_347_job_{user}_{id}, 1 h): solo
 *   las filas que generan carta y el avance. Se borra al terminar.
 *
 * Generar es idempotente: cada carta se crea por
 * Documento_Service::guardar_modelo_347_generado(), que no crea una segunda
 * para la misma farmacia, sociedad y ejercicio (comprobacion previa + UNIQUE).
 * Si una tanda se repite o dos pestanas compiten, la segunda cuenta "ya
 * existia". Reprocesar el mismo Excel solo genera lo que falte; corregir una
 * carta = retirarla y volver a subir el Excel.
 */
class Modelo_347_Generacion_Service {

	public const ERROR_CAMBIADO = 'mdf_ca_347_simulacion_cambiada';

	private const PREFIJO_SIM = 'mdf_ca_347_sim_';
	private const PREFIJO_JOB = 'mdf_ca_347_job_';
	private const TTL_SIM     = 15 * MINUTE_IN_SECONDS;
	private const TTL_JOB     = HOUR_IN_SECONDS;
	public const POR_TANDA    = 10;

	// ------------------------------------------------------------------
	// Simulacion
	// ------------------------------------------------------------------

	/**
	 * Guarda una simulacion nueva del usuario (borrando la anterior que
	 * tuviera) y devuelve su id opaco.
	 *
	 * @param array<int, array<string, mixed>> $filas Del lector.
	 */
	public static function guardar_simulacion( int $usuario_id, string $emisor_cif, int $ejercicio, array $filas ): string {
		$anterior = get_user_meta( $usuario_id, 'mdf_ca_347_sim_actual', true );

		if ( is_string( $anterior ) && 1 === preg_match( '/^[a-f0-9]{32}$/D', $anterior ) ) {
			delete_transient( self::PREFIJO_SIM . $usuario_id . '_' . $anterior );
		}

		$id = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::PREFIJO_SIM . $usuario_id . '_' . $id,
			array(
				'emisor_cif' => $emisor_cif,
				'ejercicio'  => $ejercicio,
				'filas'      => $filas,
			),
			self::TTL_SIM
		);
		update_user_meta( $usuario_id, 'mdf_ca_347_sim_actual', $id );

		return $id;
	}

	/**
	 * @return array{emisor_cif: string, ejercicio: int, filas: array<int, array<string, mixed>>}|null
	 */
	public static function leer_simulacion( int $usuario_id, string $id ): ?array {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			return null;
		}

		$sim = get_transient( self::PREFIJO_SIM . $usuario_id . '_' . $id );

		return is_array( $sim ) && isset( $sim['emisor_cif'], $sim['ejercicio'], $sim['filas'] ) ? $sim : null;
	}

	public static function borrar_simulacion( int $usuario_id, string $id ): void {
		if ( 1 === preg_match( '/^[a-f0-9]{32}$/D', $id ) ) {
			delete_transient( self::PREFIJO_SIM . $usuario_id . '_' . $id );
		}
	}

	/**
	 * Lee y borra: de dos confirmaciones simultaneas solo una consigue la
	 * simulacion (patron del importador de farmacias).
	 *
	 * @return array{emisor_cif: string, ejercicio: int, filas: array<int, array<string, mixed>>}|null
	 */
	private static function consumir_simulacion( int $usuario_id, string $id ): ?array {
		$sim = self::leer_simulacion( $usuario_id, $id );

		if ( ! $sim || ! delete_transient( self::PREFIJO_SIM . $usuario_id . '_' . $id ) ) {
			return null;
		}

		return $sim;
	}

	// ------------------------------------------------------------------
	// Confirmacion y generacion
	// ------------------------------------------------------------------

	/**
	 * Confirma la simulacion: la consume, vuelve a clasificar contra la BD y
	 * solo si la huella coincide crea el trabajo de generacion. Si no
	 * coincide, guarda la simulacion recalculada (para ensenarla) y devuelve
	 * ERROR_CAMBIADO con el id nuevo en los datos del error.
	 *
	 * $sociedad_confirmada: la casilla "Confirmo que este Excel es de ..."
	 * (el Excel no dice de que sociedad es). Sin ella no se consume nada.
	 * Ademas, si el cruce con las facturas bloquea la simulacion
	 * (Modelo_347_Simulacion_Service, "sociedad"), no se genera.
	 *
	 * $sin_marca (#321) y $descargable (v0.36.0): la configuracion de todas
	 * las cartas de esta generacion; se guarda en el trabajo y no se vuelve a
	 * leer de la peticion.
	 *
	 * @return array{job_id: string, total: int}|\WP_Error
	 */
	public function confirmar( int $usuario_id, string $sim_id, string $huella, bool $sociedad_confirmada, bool $sin_marca = Documento_Service::POR_DEFECTO_SIN_MARCA, bool $descargable = Documento_Service::POR_DEFECTO_DESCARGABLE ) {
		if ( ! $sociedad_confirmada ) {
			return new \WP_Error( 'mdf_ca_347_sociedad_sin_confirmar', 'Marca la casilla que confirma de qué sociedad es el Excel. No se ha generado nada.', array( 'sim_id' => $sim_id ) );
		}

		$sim = self::consumir_simulacion( $usuario_id, $sim_id );

		if ( ! $sim ) {
			return new \WP_Error( 'mdf_ca_347_simulacion_caducada', 'La simulación ha caducado o ya se confirmó. Vuelve a subir el Excel.' );
		}

		$actual = ( new Modelo_347_Simulacion_Service() )->simular( $sim['filas'], $sim['emisor_cif'], (int) $sim['ejercicio'] );

		if ( ! hash_equals( $actual['huella'], $huella ) ) {
			$nuevo = self::guardar_simulacion( $usuario_id, $sim['emisor_cif'], (int) $sim['ejercicio'], $sim['filas'] );

			return new \WP_Error(
				self::ERROR_CAMBIADO,
				'Los datos han cambiado desde la simulación (alguna farmacia, cuenta o carta). No se ha generado nada: revisa la simulación actualizada.',
				array( 'sim_id' => $nuevo )
			);
		}

		if ( $actual['sociedad']['bloquea'] ) {
			$nuevo = self::guardar_simulacion( $usuario_id, $sim['emisor_cif'], (int) $sim['ejercicio'], $sim['filas'] );

			return new \WP_Error( 'mdf_ca_347_sociedad_no_coincide', self::mensaje_sociedad_no_coincide( $sim['emisor_cif'], $actual['sociedad'] ), array( 'sim_id' => $nuevo ) );
		}

		$generables = array_values(
			array_filter( $actual['filas'], static fn( array $f ): bool => in_array( $f['categoria'], Modelo_347_Simulacion_Service::GENERABLES, true ) )
		);

		if ( ! $generables ) {
			return new \WP_Error( 'mdf_ca_347_nada_que_generar', 'No hay ninguna carta que generar.' );
		}

		$job_id = bin2hex( random_bytes( 16 ) );

		set_transient(
			self::PREFIJO_JOB . $usuario_id . '_' . $job_id,
			array(
				'emisor_cif' => $sim['emisor_cif'],
				'ejercicio'  => (int) $sim['ejercicio'],
				'filas'      => array_map( array( self::class, 'datos_carta' ), $generables ),
				'cursor'     => 0,
				'creadas'    => 0,
				'omitidas'   => 0,
				'errores'    => array(),
				'sin_marca'   => $sin_marca,
				'descargable' => $descargable,
			),
			self::TTL_JOB
		);

		return array(
			'job_id' => $job_id,
			'total'  => count( $generables ),
		);
	}

	/**
	 * Texto del bloqueo por el cruce con las facturas, el mismo en la
	 * simulacion y al confirmar.
	 *
	 * @param array{con_facturas: int, dudosas: int} $sociedad
	 */
	public static function mensaje_sociedad_no_coincide( string $emisor_cif, array $sociedad ): string {
		return sprintf(
			'Este Excel no parece de %1$s: de las %2$d farmacias del fichero que tienen facturas en el portal, %3$d solo tienen facturas de otra sociedad. ¿Has elegido la sociedad correcta? No se puede generar: descarta la simulación y vuelve a subir el Excel con la sociedad que corresponda.',
			Factura_Emisores::nombre_corto( $emisor_cif ) ?? $emisor_cif,
			$sociedad['con_facturas'],
			$sociedad['dudosas']
		);
	}

	/**
	 * Siguiente tanda del trabajo.
	 *
	 * @return array{total: int, hechas: int, creadas: int, omitidas: int, errores: array<int, string>, terminado: bool}|\WP_Error
	 */
	public function procesar_tanda( int $usuario_id, string $job_id ) {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/D', $job_id ) ) {
			return new \WP_Error( 'mdf_ca_347_trabajo', 'Trabajo de generación no válido.' );
		}

		$clave = self::PREFIJO_JOB . $usuario_id . '_' . $job_id;
		$job   = get_transient( $clave );

		if ( ! is_array( $job ) || ! isset( $job['filas'], $job['cursor'] ) ) {
			return new \WP_Error( 'mdf_ca_347_trabajo_caducado', 'El trabajo de generación ha caducado o ya terminó. Si faltan cartas, vuelve a subir el Excel: solo se generan las que no existan.' );
		}

		$servicio = ( new Documento_Service() )
			->sin_marca_de_agua( ! empty( $job['sin_marca'] ) )
			->descargable( (bool) ( $job['descargable'] ?? Documento_Service::POR_DEFECTO_DESCARGABLE ) );
		$tanda    = array_slice( $job['filas'], $job['cursor'], self::POR_TANDA );

		foreach ( $tanda as $fila ) {
			$pdf = Modelo_347_Carta::pdf( $job['emisor_cif'], $job['ejercicio'], array( $fila ) );

			if ( is_wp_error( $pdf ) ) {
				$job['errores'][] = sprintf( 'Fila %d: %s', $fila['linea'], $pdf->get_error_message() );
				continue;
			}

			$documento = $servicio->guardar_modelo_347_generado( (int) $fila['farmacia_id'], $job['emisor_cif'], $job['ejercicio'], $pdf );

			if ( ! is_wp_error( $documento ) ) {
				++$job['creadas'];
			} elseif ( 'mdf_ca_documento_modelo_347_ya_existe' === $documento->get_error_code() ) {
				++$job['omitidas'];
			} else {
				$job['errores'][] = sprintf( 'Fila %d: %s', $fila['linea'], $documento->get_error_message() );
			}
		}

		$job['cursor'] += count( $tanda );
		$terminado      = $job['cursor'] >= count( $job['filas'] );

		if ( $terminado ) {
			delete_transient( $clave );
		} else {
			set_transient( $clave, $job, self::TTL_JOB );
		}

		return array(
			'total'     => count( $job['filas'] ),
			'hechas'    => $job['cursor'],
			'creadas'   => $job['creadas'],
			'omitidas'  => $job['omitidas'],
			'errores'   => $job['errores'],
			'terminado' => $terminado,
		);
	}

	/**
	 * Solo lo que la carta necesita (nada de avisos ni categorias).
	 *
	 * @param array<string, mixed> $fila
	 * @return array<string, mixed>
	 */
	public static function datos_carta( array $fila ): array {
		return array(
			'linea'       => (int) $fila['linea'],
			'farmacia_id' => (int) ( $fila['farmacia_id'] ?? 0 ),
			'nombre'      => (string) $fila['nombre'],
			'nif'         => (string) $fila['nif'],
			'direccion'   => (string) $fila['direccion'],
			'cp'          => (string) $fila['cp'],
			'poblacion'   => (string) $fila['poblacion'],
			't1'          => (int) $fila['t1'],
			't2'          => (int) $fila['t2'],
			't3'          => (int) $fila['t3'],
			't4'          => (int) $fila['t4'],
			'dev'         => (int) $fila['dev'],
		);
	}
}
