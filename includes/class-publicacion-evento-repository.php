<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Libro de eventos de publicacion de documentos (#318): quien publico o
 * despublico cada documento, cuando y por que via. De SOLO ANADIR: esta
 * clase expone insertar y leer, y no hay ningun UPDATE, DELETE ni TRUNCATE
 * sobre la tabla en todo el plugin (se comprueba con grep). Corregir un
 * error se hace con un evento nuevo, nunca reescribiendo uno.
 *
 * Los eventos se escriben DENTRO de la transaccion que cambia publicado
 * (Documento_Repository::cambiar_estado()) y en la de la creacion del
 * documento (Documento_Service): si la transaccion se revierte, el evento
 * tambien; si el evento falla, el estado no cambia. Esta clase no abre
 * transacciones.
 *
 * Datos personales: solo usuario_id (una persona de MDF o el robot), fecha,
 * accion, origen y lote. Nada de IP, user agent ni datos fiscales.
 */
class Publicacion_Evento_Repository {

	public const ACCION_PUBLICADO    = 'publicado';
	public const ACCION_DESPUBLICADO = 'despublicado';

	public const ORIGEN_INDIVIDUAL        = 'individual';
	public const ORIGEN_POR_FARMACIA      = 'por_farmacia';
	public const ORIGEN_BLOQUE            = 'bloque';
	public const ORIGEN_DESHACER_LOTE     = 'deshacer_lote';
	public const ORIGEN_RECEPCION         = 'recepcion';
	public const ORIGEN_SUBIDA_BACKOFFICE = 'subida_backoffice';

	/** Filas por INSERT (6 columnas cada una). */
	private const TANDA = 500;

	/**
	 * Inserta un evento por cada fila, por tandas. Todos con la misma accion,
	 * origen, usuario y fecha (UTC); el lote puede ser distinto en cada fila.
	 *
	 * @param array<int, int|null> $filas documento_id => lote_id (null si no hay).
	 * @return int|false Eventos insertados, o false si fallo alguna tanda
	 *                   (quien llama revierte la transaccion).
	 */
	public function insertar_para( array $filas, string $accion, string $origen, ?int $usuario_id ) {
		global $wpdb;

		if ( ! in_array( $accion, array( self::ACCION_PUBLICADO, self::ACCION_DESPUBLICADO ), true ) || ! $this->origen_valido( $origen ) ) {
			return false;
		}

		if ( ! $filas ) {
			return 0;
		}

		$table       = DB_Schema::get_publicacion_eventos_table_name();
		$fecha       = gmdate( 'Y-m-d H:i:s' );
		$usuario_sql = null !== $usuario_id && $usuario_id > 0 ? (string) (int) $usuario_id : 'NULL';
		$total       = 0;

		foreach ( array_chunk( $filas, self::TANDA, true ) as $tanda ) {
			$valores = array();

			foreach ( $tanda as $documento_id => $lote_id ) {
				// Los enteros se castean; los textos van por prepare().
				$lote_sql  = null === $lote_id ? 'NULL' : (string) (int) $lote_id;
				$valores[] = $wpdb->prepare( "(%d, %s, %s, {$usuario_sql}, %s, {$lote_sql})", (int) $documento_id, $accion, $origen, $fecha ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- enteros casteados arriba.
			}

			$ok = $wpdb->query( "INSERT INTO {$table} (documento_id, accion, origen, usuario_id, fecha, lote_id) VALUES " . implode( ', ', $valores ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- cada tupla ya viene de $wpdb->prepare().

			if ( false === $ok ) {
				return false;
			}

			$total += (int) $ok;
		}

		return $total;
	}

	/**
	 * Eventos de un documento, del mas antiguo al mas reciente.
	 *
	 * @return object[]
	 */
	public function find_by_documento( int $documento_id, int $limite = 200 ): array {
		global $wpdb;

		$table = DB_Schema::get_publicacion_eventos_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE documento_id = %d ORDER BY id ASC LIMIT %d", $documento_id, max( 1, $limite ) )
		);

		return $rows ?: array();
	}

	public function contar_por_documento( int $documento_id ): int {
		global $wpdb;

		$table = DB_Schema::get_publicacion_eventos_table_name();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE documento_id = %d", $documento_id ) );
	}

	private function origen_valido( string $origen ): bool {
		return in_array(
			$origen,
			array(
				self::ORIGEN_INDIVIDUAL,
				self::ORIGEN_POR_FARMACIA,
				self::ORIGEN_BLOQUE,
				self::ORIGEN_DESHACER_LOTE,
				self::ORIGEN_RECEPCION,
				self::ORIGEN_SUBIDA_BACKOFFICE,
			),
			true
		);
	}
}
