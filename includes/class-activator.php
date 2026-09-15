<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate() {
		self::create_tables();
		update_option( 'mdf_ca_db_version', MDF_CA_DB_VERSION );
		Roles::register();

		// La rewrite rule de Documento_Endpoint se registra en cada carga
		// via 'init', pero el flush (caro, no se debe hacer en cada
		// peticion) solo debe pasar al activar/desactivar.
		Documento_Endpoint::registrar_rewrite();
		flush_rewrite_rules();
	}

	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( DB_Schema::get_create_table_statements() as $sql ) {
			dbDelta( $sql );
		}
	}
}
