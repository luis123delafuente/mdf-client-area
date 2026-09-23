<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate() {
		self::create_tables();
		update_option( 'mdf_ca_db_version', MDF_CA_DB_VERSION );
		self::migrar_planes_desde_varchar();
		Roles::register();
		Area_Privada_Pages::crear_paginas();

		// Las rewrite rules de Documento_Endpoint y Documento_Visor se
		// registran en cada carga via 'init', pero el flush (caro, no se
		// debe hacer en cada peticion) solo debe pasar al activar/desactivar.
		Documento_Endpoint::registrar_rewrite();
		Documento_Visor::registrar_rewrite();
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

		if ( ! get_option( 'mdf_ca_planes_migrados' ) ) {
			self::migrar_planes_desde_varchar();
			update_option( 'mdf_ca_planes_migrados', '1' );
		}

		if ( ! get_option( 'mdf_ca_area_privada_paginas_creadas' ) ) {
			Area_Privada_Pages::crear_paginas();
			update_option( 'mdf_ca_area_privada_paginas_creadas', '1' );
		}

		// La rewrite rule de Documento_Visor (#248) se registra en cada
		// carga via 'init' igual que la de Documento_Endpoint, pero
		// WordPress cachea las reglas activas en la option "rewrite_rules"
		// hasta el proximo flush -- sin este flush una sola vez, una
		// instalacion ya activada (produccion) devolveria 404 en
		// /mdf-ca-visor/*/ hasta que alguien reguardara los enlaces
		// permanentes a mano en wp-admin. Gate por option, mismo patron que
		// el resto de maybe_upgrade(): barato de comprobar en cada peticion,
		// solo flushea de verdad la primera vez tras el despliegue.
		if ( ! get_option( 'mdf_ca_visor_rewrite_flushed' ) ) {
			flush_rewrite_rules();
			update_option( 'mdf_ca_visor_rewrite_flushed', '1' );
		}
	}

	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( DB_Schema::get_create_table_statements() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Migracion de la Fase 3 #245: sustituye la columna "plan" (VARCHAR) de
	 * farmacias por "plan_id", referencia a la nueva tabla wp_mdf_ca_planes.
	 *
	 * Se apoya en create_tables() (llamado antes que esto tanto en
	 * activate() como en maybe_upgrade()) para que la tabla de planes y la
	 * columna plan_id ya existan al llegar aqui -- dbDelta() las crea/anade
	 * pero nunca elimina la columna antigua, asi que ese ultimo paso se hace
	 * aqui a mano, una vez migrados los datos que contenga.
	 *
	 * Idempotente y seguro de repetir: si la columna "plan" ya no existe
	 * (instalacion nueva que nunca la tuvo, o una ya migrada), no hace nada
	 * y devuelve enseguida. Si existe, por cada valor distinto no vacio crea
	 * el plan correspondiente en wp_mdf_ca_planes si todavia no existe (por
	 * slug), vuelca plan_id en cada farmacia a partir de ese valor, y solo
	 * entonces elimina la columna. Las farmacias con "plan" NULL o vacio no
	 * generan ningun plan ni tocan plan_id: quedan sin plan asignado, mismo
	 * estado final que ya tenian (ver Permissions, que ya trata "sin plan"
	 * como "no ve nada de catalogo").
	 */
	private static function migrar_planes_desde_varchar(): void {
		global $wpdb;

		$farmacias_table = DB_Schema::get_farmacias_table_name();
		$planes_table    = DB_Schema::get_planes_table_name();

		$columna_antigua_existe = $wpdb->get_results( "SHOW COLUMNS FROM {$farmacias_table} LIKE 'plan'" );

		if ( ! $columna_antigua_existe ) {
			return;
		}

		$valores_plan = $wpdb->get_col( "SELECT DISTINCT plan FROM {$farmacias_table} WHERE plan IS NOT NULL AND plan != ''" );

		// Resolucion del plan_id por valor de texto en PHP, no con un JOIN
		// en SQL: slug sale de sanitize_title(), que hace mucho mas que un
		// LOWER(TRIM()) (normaliza acentos, colapsa espacios y caracteres
		// especiales a guiones...), y ese algoritmo no tiene un equivalente
		// directo en SQL. Al ser pocos valores distintos por instalacion
		// (un plan por farmacia, y las farmacias piloto son unas pocas),
		// una consulta UPDATE por valor no es un problema de rendimiento.
		foreach ( $valores_plan as $valor ) {
			$slug = sanitize_title( $valor );

			if ( '' === $slug ) {
				continue;
			}

			$plan_id = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$planes_table} WHERE slug = %s", $slug )
			);

			if ( ! $plan_id ) {
				$wpdb->insert(
					$planes_table,
					array(
						'nombre' => $valor,
						'slug'   => $slug,
					),
					array( '%s', '%s' )
				);

				$plan_id = $wpdb->insert_id;
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$farmacias_table} SET plan_id = %d WHERE plan = %s AND plan_id IS NULL",
					$plan_id,
					$valor
				)
			);
		}

		$wpdb->query( "ALTER TABLE {$farmacias_table} DROP COLUMN plan" );
	}
}
