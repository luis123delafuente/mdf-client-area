<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define el esquema de las tablas propias del plugin.
 *
 * No se definen restricciones FOREIGN KEY reales: dbDelta() no las gestiona
 * de forma fiable (no genera los ALTER TABLE necesarios ni las revierte bien
 * en actualizaciones posteriores). La integridad farmacia <-> documento se
 * garantiza a nivel de aplicacion en las tareas de logica de negocio, no en
 * el esquema de base de datos.
 */
class DB_Schema {

	public static function get_farmacias_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_farmacias';
	}

	public static function get_documentos_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_documentos';
	}

	/**
	 * Sentencias CREATE TABLE listas para pasar a dbDelta().
	 *
	 * @return string[]
	 */
	public static function get_create_table_statements() {
		global $wpdb;

		$charset_collate  = $wpdb->get_charset_collate();
		$farmacias_table  = self::get_farmacias_table_name();
		$documentos_table = self::get_documentos_table_name();

		// plan es VARCHAR nullable, no un campo con FK a una tabla de planes:
		// en Fase 2 todavia no existe la entidad real (ver CLAUDE.md, Planes
		// es entidad de datos a partir de Fase 3). Nullable para no requerir
		// backfill de las farmacias ya existentes; sin plan asignado el
		// catalogo por plan simplemente no muestra nada (Permissions).
		$farmacias_sql = "CREATE TABLE {$farmacias_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cif VARCHAR(9) NOT NULL,
			nombre VARCHAR(255) NOT NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			plan VARCHAR(50) NULL,
			fecha_alta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY cif (cif),
			KEY wp_user_id (wp_user_id)
		) {$charset_collate};";

		$documentos_sql = "CREATE TABLE {$documentos_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			farmacia_id BIGINT UNSIGNED NOT NULL,
			nombre VARCHAR(255) NOT NULL,
			ruta_fichero VARCHAR(500) NOT NULL,
			tipo_mime VARCHAR(100) NULL,
			tamano_bytes BIGINT UNSIGNED NULL,
			fecha_subida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY farmacia_id (farmacia_id)
		) {$charset_collate};";

		return array( $farmacias_sql, $documentos_sql );
	}
}
