<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registro de recepcion de documentos (#303): recuentos por dia, resultado y
 * origen, sin CIF, nombres, importes ni identificadores de farmacia.
 *
 * - Una fila por dia, resultado (aceptado, duplicado, rechazado, excepcion)
 *   y origen, con el total. publicado_bloque (#316) suma los documentos
 *   publicados en bloque ese dia y despublicado_lote (#317) los despublicados
 *   al deshacer un lote: son el ultimo paso del mismo circuito.
 * - Origen: "backoffice" ("Subir facturas", la bandeja y las publicaciones y
 *   despublicaciones en bloque). "rest" solo aparece en las filas antiguas,
 *   del robot de recepcion, retirado en #336 junto con su interruptor, su
 *   tope diario y su aprobacion previa: ya no se escribe.
 * - Se ve en «Subir facturas», en «Actividad de los ultimos 14 dias».
 */
class Recepcion_Registro {

	public const ACEPTADO  = 'aceptado';
	public const DUPLICADO = 'duplicado';
	public const RECHAZADO = 'rechazado';
	public const EXCEPCION = 'excepcion';

	public const PUBLICADO_BLOQUE  = 'publicado_bloque';
	public const DESPUBLICADO_LOTE = 'despublicado_lote';

	/** Solo filas historicas: lo que recibio el robot por la ruta REST (retirada en #336). */
	public const ORIGEN_REST       = 'rest';
	public const ORIGEN_BACKOFFICE = 'backoffice';

	/** Suma $cantidad (por defecto 1) al recuento de hoy de $resultado y $origen, de forma atomica. */
	public static function registrar( string $resultado, int $cantidad = 1, string $origen = self::ORIGEN_BACKOFFICE ): void {
		global $wpdb;

		if ( $cantidad < 1
			|| ! in_array( $resultado, array( self::ACEPTADO, self::DUPLICADO, self::RECHAZADO, self::EXCEPCION, self::PUBLICADO_BLOQUE, self::DESPUBLICADO_LOTE ), true )
			|| self::ORIGEN_BACKOFFICE !== $origen ) {
			return;
		}

		$table = DB_Schema::get_recepcion_registro_table_name();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (fecha, resultado, origen, total) VALUES (%s, %s, %s, %d) ON DUPLICATE KEY UPDATE total = total + %d",
				wp_date( 'Y-m-d' ),
				$resultado,
				$origen,
				$cantidad,
				$cantidad
			)
		);
	}

	/**
	 * Recuentos de los ultimos $dias dias, mas recientes primero, por origen.
	 *
	 * @return array<string, array<string, array<string, int>>> fecha => resultado => origen => total
	 */
	public static function recuentos_recientes( int $dias = 14 ): array {
		global $wpdb;

		$table = DB_Schema::get_recepcion_registro_table_name();
		$desde = wp_date( 'Y-m-d', time() - ( max( 1, $dias ) - 1 ) * DAY_IN_SECONDS );
		$filas = $wpdb->get_results(
			$wpdb->prepare( "SELECT fecha, resultado, origen, total FROM {$table} WHERE fecha >= %s ORDER BY fecha DESC", $desde ),
			ARRAY_A
		);

		$recuentos = array();

		foreach ( $filas ?: array() as $fila ) {
			$recuentos[ $fila['fecha'] ][ $fila['resultado'] ][ $fila['origen'] ] = (int) $fila['total'];
		}

		return $recuentos;
	}
}
