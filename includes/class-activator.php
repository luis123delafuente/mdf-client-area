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
	}

	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( DB_Schema::get_create_table_statements() as $sql ) {
			dbDelta( $sql );
		}
	}
}
