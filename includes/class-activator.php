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
		Area_Privada_Pages::crear_paginas();

		// La rewrite rule de Documento_Endpoint se registra en cada carga
		// via 'init', pero el flush (caro, no se debe hacer en cada
		// peticion) solo debe pasar al activar/desactivar.
		Documento_Endpoint::registrar_rewrite();
		flush_rewrite_rules();
	}

	/**
	 * dbDelta() en activate() no llega a las instalaciones donde el plugin
	 * ya estaba activo antes de un cambio de esquema (p. ej. la columna
	 * plan de #242): activate() no se vuelve a disparar solo por desplegar
	 * codigo nuevo. Enganchado en plugins_loaded, compara la version de BD
	 * guardada contra la constante y, si difiere, repite dbDelta() -- que
	 * es idempotente y solo aplica el ALTER TABLE que falte. El chequeo en
	 * si es una simple lectura de option, barato en cada carga.
	 *
	 * Las cuatro paginas del area de clientes (#244) se crean por el mismo
	 * motivo fuera de activate(): un despliegue de codigo nuevo sobre una
	 * instalacion ya activa no vuelve a disparar el hook de activacion.
	 * Area_Privada_Pages::crear_paginas() es idempotente por si misma (no
	 * pisa paginas ya creadas), asi que aqui basta con una option propia
	 * que evite repetir esa comprobacion en cada peticion una vez hecha.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'mdf_ca_db_version' ) !== MDF_CA_DB_VERSION ) {
			self::create_tables();
			update_option( 'mdf_ca_db_version', MDF_CA_DB_VERSION );
		}

		if ( ! get_option( 'mdf_ca_area_privada_paginas_creadas' ) ) {
			Area_Privada_Pages::crear_paginas();
			update_option( 'mdf_ca_area_privada_paginas_creadas', '1' );
		}
	}

	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( DB_Schema::get_create_table_statements() as $sql ) {
			dbDelta( $sql );
		}
	}
}
