<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico acceso a la tabla de lotes de publicacion en bloque (#317). Sin
 * transacciones propias: las abre quien coordina el lote con los documentos
 * (Publicacion_Bloque_Service y Despublicacion_Lote_Service), para que lote
 * y documentos cambien juntos o no cambien. Sin datos fiscales.
 */
class Publicacion_Lote_Repository {

	public const ESTADO_APLICADO = 'aplicado';
	public const ESTADO_DESHECHO = 'deshecho';

	/**
	 * @param array{tipo: ?string, desde: ?string, hasta: ?string} $filtros Ya saneados.
	 * @return int|false Id del lote, o false si fallo.
	 */
	public function crear( int $usuario_id, array $filtros ) {
		global $wpdb;

		$ok = $wpdb->insert(
			DB_Schema::get_publicacion_lotes_table_name(),
			array(
				'creado_por' => $usuario_id,
				'creado_en'  => gmdate( 'Y-m-d H:i:s' ),
				'documentos' => 0,
				'filtros'    => (string) wp_json_encode( $filtros ),
				'estado'     => self::ESTADO_APLICADO,
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		return false === $ok ? false : (int) $wpdb->insert_id;
	}

	public function actualizar_documentos( int $lote_id, int $documentos ): bool {
		global $wpdb;

		return false !== $wpdb->update(
			DB_Schema::get_publicacion_lotes_table_name(),
			array( 'documentos' => $documentos ),
			array( 'id' => $lote_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Pasa el lote a deshecho solo si seguia aplicado.
	 *
	 * @return int|false 1 si lo marco, 0 si ya no estaba aplicado (otro deshacer
	 *                   gano la carrera), false si fallo la consulta.
	 */
	public function marcar_deshecho( int $lote_id, int $usuario_id, int $despublicados ) {
		global $wpdb;

		$table = DB_Schema::get_publicacion_lotes_table_name();

		return $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET estado = %s, deshecho_por = %d, deshecho_en = %s, despublicados = %d WHERE id = %d AND estado = %s",
				self::ESTADO_DESHECHO,
				$usuario_id,
				gmdate( 'Y-m-d H:i:s' ),
				$despublicados,
				$lote_id,
				self::ESTADO_APLICADO
			)
		);
	}

	/** @return object|null Fila del lote. */
	public function find_by_id( int $lote_id ): ?object {
		global $wpdb;

		$table = DB_Schema::get_publicacion_lotes_table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $lote_id ) );

		return $row ?: null;
	}

	/** @return object[] Los lotes mas recientes primero. */
	public function find_recientes( int $limite = 20 ): array {
		global $wpdb;

		$table = DB_Schema::get_publicacion_lotes_table_name();
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", max( 1, $limite ) ) );

		return $rows ?: array();
	}
}
