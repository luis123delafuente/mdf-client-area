<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Upsert de farmacias por CIF/NIF (Fase 4, importador CSV). No sabe nada de
 * CSV ni de pantallas: recibe filas ya leidas (Farmacia_Csv_Parser las
 * produce, el futuro alta manual le pasara una sola) y decide que pasa con
 * cada una. Dos operaciones:
 *
 * - simular(): no escribe nada. Por fila: crear, actualizar, sin_cambios o
 *   rechazada (con motivo).
 * - aplicar(): vuelve a simular contra la BD de ese momento -- nunca se fia
 *   de una simulacion anterior -- y escribe solo lo que salga de ahi, todo
 *   dentro de una transaccion: o se aplica entero o no se aplica nada.
 *
 * Reglas:
 * - Clave: CIF/NIF normalizado (Identificador_Fiscal_Validator).
 * - El plan se referencia por slug y se compara con sanitize_title(), el
 *   mismo algoritmo con el que Plan_Service genera el slug: "Premium",
 *   " premium " y "PREMIUM" resuelven al mismo plan.
 * - Un CIF repetido dentro del mismo lote rechaza TODAS sus filas: no hay
 *   forma de saber cual es la buena.
 * - Una farmacia existente solo cambia nombre y plan. wp_user_id, estado
 *   de invitacion y documentos no se tocan; nunca se crean usuarios ni se
 *   envian invitaciones (eso sigue siendo Invitacion_Service).
 * - Idempotente: reaplicar el mismo lote deja todas las filas en
 *   sin_cambios y no escribe nada.
 */
class Farmacia_Import_Service {

	public const ACCION_CREAR       = 'crear';
	public const ACCION_ACTUALIZAR  = 'actualizar';
	public const ACCION_SIN_CAMBIOS = 'sin_cambios';
	public const ACCION_RECHAZADA   = 'rechazada';

	/** Longitud de la columna nombre (VARCHAR(255), ver DB_Schema). */
	private const NOMBRE_MAX_CARACTERES = 255;

	private Farmacia_Repository $repository;
	private Plan_Repository $plan_repository;
	private Farmacia_Service $farmacia_service;

	public function __construct( ?Farmacia_Repository $repository = null, ?Plan_Repository $plan_repository = null, ?Farmacia_Service $farmacia_service = null ) {
		$this->repository       = $repository ?? new Farmacia_Repository();
		$this->plan_repository  = $plan_repository ?? new Plan_Repository();
		$this->farmacia_service = $farmacia_service ?? new Farmacia_Service( $this->repository, $this->plan_repository );
	}

	/**
	 * @param array<int, array{linea: int, cif: string, nombre: string, plan: string}> $filas
	 * @return array<int, array{
	 *     linea: int, cif: string, nombre: string, plan: string,
	 *     accion: string, motivos: string[], farmacia_id: ?int, plan_id: ?int,
	 *     nombre_anterior: ?string, plan_anterior: ?string, plan_nombre: ?string
	 * }>
	 */
	public function simular( array $filas ): array {
		$planes_por_slug = array();
		$planes_por_id   = array();

		foreach ( $this->plan_repository->find_all() as $plan ) {
			$planes_por_slug[ $plan->get_slug() ] = $plan;
			$planes_por_id[ $plan->get_id() ]     = $plan;
		}

		$slugs_validos = array_keys( $planes_por_slug );
		sort( $slugs_validos );

		// Primera pasada: normalizar y localizar duplicados dentro del lote.
		$lineas_por_cif = array();

		foreach ( $filas as $i => $fila ) {
			$cif                  = Identificador_Fiscal_Validator::normalizar( $fila['cif'] );
			$filas[ $i ]['cif']   = $cif;
			$filas[ $i ]['nombre'] = trim( $fila['nombre'] );

			if ( '' !== $cif ) {
				$lineas_por_cif[ $cif ][] = $fila['linea'];
			}
		}

		$existentes = $this->repository->find_by_cifs(
			array_filter( array_keys( $lineas_por_cif ), array( Identificador_Fiscal_Validator::class, 'is_valid' ) )
		);

		$resultados = array();

		foreach ( $filas as $fila ) {
			$cif     = $fila['cif'];
			$nombre  = $fila['nombre'];
			$motivos = array();

			if ( '' === $cif ) {
				$motivos[] = 'CIF/NIF vacio.';
			} elseif ( ! Identificador_Fiscal_Validator::is_valid( $cif ) ) {
				$motivos[] = sprintf( 'CIF/NIF "%s" no valido (formato o digito de control).', $cif );
			}

			if ( '' === $nombre ) {
				$motivos[] = 'Nombre vacio.';
			} elseif ( mb_strlen( $nombre ) > self::NOMBRE_MAX_CARACTERES ) {
				$motivos[] = sprintf( 'Nombre demasiado largo (maximo %d caracteres).', self::NOMBRE_MAX_CARACTERES );
			}

			$plan_slug = sanitize_title( $fila['plan'] );
			$plan      = $planes_por_slug[ $plan_slug ] ?? null;

			if ( '' === trim( $fila['plan'] ) ) {
				$motivos[] = 'Plan vacio.';
			} elseif ( ! $plan ) {
				$motivos[] = sprintf(
					'El plan "%s" no existe. Planes validos: %s.',
					$fila['plan'],
					$slugs_validos ? implode( ', ', $slugs_validos ) : '(no hay ningun plan creado)'
				);
			}

			if ( '' !== $cif && count( $lineas_por_cif[ $cif ] ) > 1 ) {
				$motivos[] = sprintf( 'CIF/NIF duplicado en el fichero (lineas %s).', implode( ', ', $lineas_por_cif[ $cif ] ) );
			}

			$existente = $existentes[ $cif ] ?? null;

			if ( $motivos ) {
				$accion = self::ACCION_RECHAZADA;
			} elseif ( ! $existente ) {
				$accion = self::ACCION_CREAR;
			} elseif ( $existente->get_nombre() === $nombre && $existente->get_plan_id() === $plan->get_id() ) {
				$accion = self::ACCION_SIN_CAMBIOS;
			} else {
				$accion = self::ACCION_ACTUALIZAR;
			}

			$plan_anterior = null;

			if ( $existente && $existente->get_plan_id() ) {
				$plan_anterior = isset( $planes_por_id[ $existente->get_plan_id() ] )
					? $planes_por_id[ $existente->get_plan_id() ]->get_nombre()
					: null;
			}

			$resultados[] = array(
				'linea'           => $fila['linea'],
				'cif'             => $cif,
				'nombre'          => $nombre,
				'plan'            => $fila['plan'],
				'accion'          => $accion,
				'motivos'         => $motivos,
				'farmacia_id'     => $existente ? $existente->get_id() : null,
				'plan_id'         => $plan ? $plan->get_id() : null,
				'plan_nombre'     => $plan ? $plan->get_nombre() : null,
				'nombre_anterior' => $existente ? $existente->get_nombre() : null,
				'plan_anterior'   => $plan_anterior,
			);
		}

		return $resultados;
	}

	/**
	 * Huella de una simulacion: cambia si cambia cualquier decision. La
	 * pantalla la manda al confirmar para comprobar que lo que se va a
	 * aplicar es exactamente lo que el administrador vio; si el formulario
	 * la manipula, lo unico que consigue es que no se aplique nada.
	 *
	 * @param array<int, array<string, mixed>> $resultados Salida de simular().
	 */
	public static function huella( array $resultados ): string {
		return hash( 'sha256', (string) wp_json_encode( $resultados ) );
	}

	/**
	 * @param array<int, array{linea: int, cif: string, nombre: string, plan: string}> $filas
	 * @param string|null $huella_esperada Si se indica y la simulacion actual no coincide, no se escribe nada.
	 * @return array{creadas: int, actualizadas: int, sin_cambios: int, rechazadas: int}|\WP_Error
	 */
	public function aplicar( array $filas, ?string $huella_esperada = null ) {
		global $wpdb;

		if ( ! $this->repository->es_transaccional() ) {
			return new \WP_Error(
				'mdf_ca_import_sin_transacciones',
				'La tabla de farmacias no admite transacciones (no es InnoDB). No se ha aplicado nada.'
			);
		}

		$resultados = $this->simular( $filas );

		if ( null !== $huella_esperada && ! hash_equals( self::huella( $resultados ), $huella_esperada ) ) {
			return new \WP_Error(
				'mdf_ca_import_simulacion_cambiada',
				'Los datos han cambiado desde la simulacion. Revisa la simulacion actualizada y vuelve a confirmar. No se ha aplicado nada.'
			);
		}

		$resumen = array(
			'creadas'      => 0,
			'actualizadas' => 0,
			'sin_cambios'  => 0,
			'rechazadas'   => 0,
		);

		$wpdb->query( 'START TRANSACTION' );

		foreach ( $resultados as $resultado ) {
			$error = null;

			switch ( $resultado['accion'] ) {
				case self::ACCION_CREAR:
					$farmacia = $this->farmacia_service->crear( $resultado['cif'], $resultado['nombre'], null, $resultado['plan_id'] );

					if ( is_wp_error( $farmacia ) ) {
						$error = $farmacia->get_error_message();
					} else {
						++$resumen['creadas'];
					}
					break;

				case self::ACCION_ACTUALIZAR:
					if ( $this->repository->update_nombre_y_plan( $resultado['farmacia_id'], $resultado['nombre'], $resultado['plan_id'] ) ) {
						++$resumen['actualizadas'];
					} else {
						$error = sprintf( 'Error de base de datos: %s', $wpdb->last_error );
					}
					break;

				case self::ACCION_SIN_CAMBIOS:
					++$resumen['sin_cambios'];
					break;

				default:
					++$resumen['rechazadas'];
			}

			if ( null !== $error ) {
				$wpdb->query( 'ROLLBACK' );

				return new \WP_Error(
					'mdf_ca_import_error_bd',
					sprintf( 'Linea %d: %s No se ha aplicado ninguna fila.', $resultado['linea'], $error )
				);
			}
		}

		$wpdb->query( 'COMMIT' );

		return $resumen;
	}
}
