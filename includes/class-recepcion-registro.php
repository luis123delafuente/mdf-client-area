<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Control de la recepcion automatica de documentos (#303): interruptor, tope
 * diario y registro de auditoria. Solo numeros: ni CIF, ni nombres, ni
 * importes, ni identificadores de farmacia.
 *
 * - Interruptor: option mdf_ca_recepcion_activa, apagada por defecto. La
 *   constante MDF_CA_RECEPCION_DESACTIVADA (wp-config.php) fuerza el apagado
 *   aunque la option este encendida, como en los avisos.
 * - Tope diario: option mdf_ca_recepcion_tope_diario, documentos ACEPTADOS
 *   por dia natural (wp_timezone()). Con envios en paralelo puede pasarse en
 *   tantos documentos como peticiones simultaneas haya; el robot envia de
 *   uno en uno.
 * - Registro: una fila por dia y resultado (aceptado, duplicado, rechazado,
 *   excepcion) con el total. Solo se registran peticiones ya autenticadas.
 *   publicado_bloque (#316) suma los documentos publicados con "Publicar
 *   todos los pendientes" ese dia: es el ultimo paso del mismo circuito y
 *   tampoco lleva datos fiscales ni identificadores. despublicado_lote
 *   (#317) suma los documentos despublicados al deshacer un lote.
 */
class Recepcion_Registro {

	public const OPTION_ACTIVA = 'mdf_ca_recepcion_activa';
	public const OPTION_TOPE   = 'mdf_ca_recepcion_tope_diario';

	/**
	 * Con ella activa, la recepcion crea los documentos pendientes de
	 * publicar (#304). ACTIVADA por defecto: si la option no existe se trata
	 * como activada.
	 */
	public const OPTION_REQUIERE_APROBACION = 'mdf_ca_recepcion_requiere_aprobacion';

	/** Por defecto, holgado para la ingesta del historico por lotes. */
	public const TOPE_POR_DEFECTO = 1000;
	public const TOPE_MAXIMO      = 100000;

	public const ACEPTADO  = 'aceptado';
	public const DUPLICADO = 'duplicado';
	public const RECHAZADO = 'rechazado';
	public const EXCEPCION = 'excepcion';

	public const PUBLICADO_BLOQUE  = 'publicado_bloque';
	public const DESPUBLICADO_LOTE = 'despublicado_lote';

	public static function forzada_apagada_por_constante(): bool {
		return defined( 'MDF_CA_RECEPCION_DESACTIVADA' ) && MDF_CA_RECEPCION_DESACTIVADA;
	}

	public static function esta_activa(): bool {
		return ! self::forzada_apagada_por_constante() && '1' === get_option( self::OPTION_ACTIVA, '0' );
	}

	public static function set_activa( bool $activa ): void {
		update_option( self::OPTION_ACTIVA, $activa ? '1' : '0', false );
	}

	public static function requiere_aprobacion(): bool {
		return '0' !== (string) get_option( self::OPTION_REQUIERE_APROBACION, '1' );
	}

	public static function set_requiere_aprobacion( bool $requiere ): void {
		update_option( self::OPTION_REQUIERE_APROBACION, $requiere ? '1' : '0', false );
	}

	public static function get_tope(): int {
		$tope = (int) get_option( self::OPTION_TOPE, self::TOPE_POR_DEFECTO );

		return $tope >= 1 && $tope <= self::TOPE_MAXIMO ? $tope : self::TOPE_POR_DEFECTO;
	}

	public static function set_tope( int $tope ): bool {
		if ( $tope < 1 || $tope > self::TOPE_MAXIMO ) {
			return false;
		}

		update_option( self::OPTION_TOPE, $tope, false );

		return true;
	}

	public static function tope_alcanzado(): bool {
		return self::total_hoy( self::ACEPTADO ) >= self::get_tope();
	}

	public static function total_hoy( string $resultado ): int {
		global $wpdb;

		$table = DB_Schema::get_recepcion_registro_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT total FROM {$table} WHERE fecha = %s AND resultado = %s", wp_date( 'Y-m-d' ), $resultado )
		);
	}

	/** Suma $cantidad (por defecto 1) al recuento de hoy de $resultado, de forma atomica. */
	public static function registrar( string $resultado, int $cantidad = 1 ): void {
		global $wpdb;

		if ( $cantidad < 1 || ! in_array( $resultado, array( self::ACEPTADO, self::DUPLICADO, self::RECHAZADO, self::EXCEPCION, self::PUBLICADO_BLOQUE, self::DESPUBLICADO_LOTE ), true ) ) {
			return;
		}

		$table = DB_Schema::get_recepcion_registro_table_name();

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (fecha, resultado, total) VALUES (%s, %s, %d) ON DUPLICATE KEY UPDATE total = total + %d",
				wp_date( 'Y-m-d' ),
				$resultado,
				$cantidad,
				$cantidad
			)
		);
	}

	/**
	 * Recuentos de los ultimos $dias dias, mas recientes primero.
	 *
	 * @return array<string, array<string, int>> fecha => resultado => total
	 */
	public static function recuentos_recientes( int $dias = 14 ): array {
		global $wpdb;

		$table = DB_Schema::get_recepcion_registro_table_name();
		$desde = wp_date( 'Y-m-d', time() - ( max( 1, $dias ) - 1 ) * DAY_IN_SECONDS );
		$filas = $wpdb->get_results(
			$wpdb->prepare( "SELECT fecha, resultado, total FROM {$table} WHERE fecha >= %s ORDER BY fecha DESC", $desde ),
			ARRAY_A
		);

		$recuentos = array();

		foreach ( $filas ?: array() as $fila ) {
			$recuentos[ $fila['fecha'] ][ $fila['resultado'] ] = (int) $fila['total'];
		}

		return $recuentos;
	}
}
