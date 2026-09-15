<?php
/**
 * ============================================================
 * TEMPORAL -- TAREA #233 (primer despliegue en produccion)
 * ============================================================
 * BORRAR ESTE FICHERO ENTERO (y el require_once que lo carga en
 * mdf-client-area.php) en cuanto se confirme que la farmacia y el
 * documento de prueba quedaron creados en produccion. No forma parte
 * de la arquitectura permanente del plugin.
 *
 * Da de alta, desde wp-admin y solo para un administrador real, los
 * mismos datos de prueba que ya existen en local (#231): una farmacia
 * (CIF B12345674, "Farmacia Prueba Fase 1") y un documento ("sol.pdf").
 * Usa exclusivamente el codigo ya validado -- Farmacia_Service::crear()
 * y Documento_Repository::insert() -- nada de SQL directo ni logica de
 * alta nueva. El fichero fisico sol.pdf se sube aparte por SFTP; aqui
 * solo se registran los metadatos en BD.
 *
 * Acceso: pagina de administracion oculta (sin entrada de menu visible,
 * parent_slug null), protegida por la capacidad manage_options Y por un
 * nonce de WordPress verificado con check_admin_referer(). Sin las dos
 * cosas a la vez, no se ejecuta nada.
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const TEMP_233_SLUG  = 'mdf-ca-temp-seed-233';
const TEMP_233_NONCE = 'mdf_ca_temp_seed_233';

add_action( 'admin_menu', __NAMESPACE__ . '\\temp_233_registrar_pagina' );

function temp_233_registrar_pagina(): void {
	add_submenu_page(
		null, // Sin padre: no aparece en ningun menu visible de wp-admin.
		'MDF - Seed temporal #233',
		'MDF Seed #233',
		'manage_options',
		TEMP_233_SLUG,
		__NAMESPACE__ . '\\temp_233_render_pagina'
	);
}

function temp_233_render_pagina(): void {
	// Comprobacion explicita ademas de la que ya aplica WordPress al
	// registrar la pagina con manage_options: nunca ejecutable sin ella.
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'No tienes permiso para acceder a esta pagina.', 'Acceso denegado', array( 'response' => 403 ) );
	}

	echo '<div class="wrap"><h1>MDF Client Area — Seed temporal de producción (#233)</h1>';
	echo '<p><strong>Este formulario es temporal y debe eliminarse del repositorio tras usarlo.</strong></p>';

	if ( isset( $_POST['mdf_ca_temp_233_submit'] ) ) {
		check_admin_referer( TEMP_233_NONCE ); // Muere con "¿Estás seguro?" si el nonce falta o no es valido.
		temp_233_ejecutar_seed();
	} else {
		echo '<form method="post">';
		wp_nonce_field( TEMP_233_NONCE );
		echo '<p>Este formulario va a crear en esta base de datos:</p><ul>';
		echo '<li>Usuario de WordPress <code>qa_farmacia_mdf</code> (se reutiliza si ya existe), rol <code>mdf_cliente</code></li>';
		echo '<li>Farmacia CIF <code>B12345674</code>, "Farmacia Prueba Fase 1", vinculada a ese usuario (se reutiliza si el CIF ya existe)</li>';
		echo '<li>Documento "Documento Prueba Fase 1" (<code>sol.pdf</code>), vinculado a esa farmacia (se crea siempre; no es idempotente, no lo envíes dos veces)</li>';
		echo '</ul>';
		submit_button( 'Crear datos de prueba', 'primary', 'mdf_ca_temp_233_submit' );
		echo '</form>';
	}

	echo '</div>';
}

function temp_233_ejecutar_seed(): void {
	$login = 'qa_farmacia_mdf';
	$user  = get_user_by( 'login', $login );

	if ( $user ) {
		$user_id = $user->ID;
		echo '<div class="notice notice-info"><p>Usuario ya existente reutilizado: ID ' . (int) $user_id . ' (' . esc_html( $login ) . ')</p></div>';
	} else {
		$user_id = wp_insert_user(
			array(
				'user_login' => $login,
				'user_pass'  => wp_generate_password( 24, true, true ),
				'user_email' => 'qa-farmacia-mdf@example.test',
				'role'       => Roles::ROLE_CLIENTE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			echo '<div class="notice notice-error"><p>Error creando el usuario: ' . esc_html( $user_id->get_error_message() ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-success"><p>Usuario creado: ID ' . (int) $user_id . ' (' . esc_html( $login ) . ')</p></div>';
	}

	$farmacia_repo      = new Farmacia_Repository();
	$farmacia_existente = $farmacia_repo->find_by_cif( 'B12345674' );

	if ( $farmacia_existente ) {
		$farmacia = $farmacia_existente;
		echo '<div class="notice notice-info"><p>La farmacia con CIF B12345674 ya existía: ID ' . (int) $farmacia->get_id() . '. No se crea de nuevo.</p></div>';
	} else {
		$farmacia_service = new Farmacia_Service();
		$farmacia         = $farmacia_service->crear( 'B12345674', 'Farmacia Prueba Fase 1', (int) $user_id );

		if ( is_wp_error( $farmacia ) ) {
			echo '<div class="notice notice-error"><p>Error creando la farmacia: ' . esc_html( $farmacia->get_error_code() . ' - ' . $farmacia->get_error_message() ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-success"><p>Farmacia creada: ID ' . (int) $farmacia->get_id() . ', CIF ' . esc_html( $farmacia->get_cif() ) . '</p></div>';
	}

	$documento_repo = new Documento_Repository();
	$documento      = $documento_repo->insert(
		$farmacia->get_id(),
		'Documento Prueba Fase 1',
		'sol.pdf',
		'application/pdf'
	);

	if ( ! $documento ) {
		global $wpdb;
		echo '<div class="notice notice-error"><p>Error creando el documento en BD: ' . esc_html( $wpdb->last_error ) . '</p></div>';
		return;
	}

	echo '<div class="notice notice-success"><p>Documento registrado: ID ' . (int) $documento->get_id() . ', ruta_fichero "' . esc_html( $documento->get_ruta_fichero() ) . '", farmacia_id ' . (int) $documento->get_farmacia_id() . '</p></div>';
	echo '<p><strong>Resumen -> usuario WP: ' . (int) $user_id . ', farmacia: ' . (int) $farmacia->get_id() . ', documento: ' . (int) $documento->get_id() . '</strong></p>';
	echo '<p>Si aún no lo has hecho, sube el fichero físico por SFTP a <code>Clientes/private-docs/sol.pdf</code> en producción.</p>';
}
