<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de backoffice para subir un documento y asignarlo a una
 * farmacia (#246). Mismo patron que Admin_Planes, no uno distinto: menu
 * propio, capacidad 'manage_options' (mdf_cliente nunca la tiene, y
 * Role_Restrictions::bloquear_acceso_admin() ya lo saca de todo wp-admin
 * antes de llegar aqui), admin-post.php con nonce para procesar la subida.
 *
 * Toda la validacion (farmacia valida, tipo de fichero, tamano, nombre en
 * disco) vive en Documento_Service, no aqui: este controlador solo traduce
 * $_POST/$_FILES a parametros tipados y traduce el resultado en un aviso +
 * redirect, igual que Admin_Planes hace con Plan_Service.
 */
class Admin_Documentos {

	private const MENU_SLUG    = 'mdf-ca-documentos';
	private const NONCE_ACTION = 'mdf_ca_subir_documento';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_documentos_notice_';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_mdf_ca_subir_documento', array( __CLASS__, 'gestionar_subir' ) );
		add_action( 'admin_post_mdf_ca_retirar_modelo_347', array( __CLASS__, 'gestionar_retirar_modelo_347' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Documentos MDF',
			'Documentos MDF',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-media-document',
			59
		);
	}

	public static function encolar_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		self::encolar_selector_farmacia();

		// Muestra ejercicio y sociedad solo con el tipo Modelo 347, y la fecha
		// con el resto. Sin JS se ven todos los campos: decide el servidor.
		Admin_Zona_Archivos::encolar();

		wp_enqueue_script(
			'mdf-ca-admin-documentos',
			Recursos::url( 'assets/js/admin-documentos.js' ),
			array(),
			Recursos::version( 'assets/js/admin-documentos.js' ),
			true
		);
	}

	/**
	 * CSS y JS del selector de farmacia con filtro. Lo usan Documentos MDF,
	 * Documentos publicados y Pendientes de publicar (#318, #319).
	 */
	public static function encolar_selector_farmacia(): void {
		wp_enqueue_style(
			'mdf-ca-admin-selector-farmacia',
			Recursos::url( 'assets/css/admin-selector-farmacia.css' ),
			array(),
			Recursos::version( 'assets/css/admin-selector-farmacia.css' )
		);

		wp_enqueue_script(
			'mdf-ca-admin-selector-farmacia',
			Recursos::url( 'assets/js/admin-selector-farmacia.js' ),
			array(),
			Recursos::version( 'assets/js/admin-selector-farmacia.js' ),
			true
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$farmacias = ( new Farmacia_Repository() )->find_all();
		usort(
			$farmacias,
			static fn( Farmacia $a, Farmacia $b ): int => strcasecmp( $a->get_nombre(), $b->get_nombre() )
		);
		$farmacias_por_id = array();

		foreach ( $farmacias as $farmacia ) {
			$farmacias_por_id[ $farmacia->get_id() ] = $farmacia;
		}

		$documentos = ( new Documento_Repository() )->find_all();
		$aviso      = self::consumir_aviso();

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-documentos.php';
	}

	public static function gestionar_subir(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_ACTION );

		$farmacia_id    = isset( $_POST['farmacia_id'] ) ? (int) $_POST['farmacia_id'] : 0;
		$nombre         = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$tipo_documento = isset( $_POST['tipo_documento'] ) ? sanitize_key( wp_unslash( $_POST['tipo_documento'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- $_FILES no se sanea con wp_unslash/sanitize_*, Documento_Service::subir() valida cada campo antes de usarlo.
		$archivo = isset( $_FILES['documento'] ) ? $_FILES['documento'] : null;

		// Checkbox HTML: solo llega si esta marcado. Sin marcar => false (por defecto no descargable).
		$descargable = ! empty( $_POST['descargable'] );

		// Fecha del documento (la de la factura, no la de subida): vacia = null.
		// Obligatoria para las facturas y valida (formato y rango): lo decide
		// Documento_Service en servidor, no el navegador.
		$fecha_documento = isset( $_POST['fecha_documento'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['fecha_documento'] ) ) ) : '';
		$fecha_documento = '' === $fecha_documento ? null : $fecha_documento;

		// Modelo 347 (#307): ejercicio y sociedad emisora; vacios = null y el
		// servicio los exige y valida. En el resto de tipos se ignoran.
		$ejercicio  = isset( $_POST['ejercicio'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['ejercicio'] ) ) ) : '';
		$ejercicio  = 1 === preg_match( '/^\d{4}$/D', $ejercicio ) ? (int) $ejercicio : null;
		$emisor_cif = isset( $_POST['emisor_cif'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['emisor_cif'] ) ) ) : '';
		$emisor_cif = '' === $emisor_cif ? null : $emisor_cif;

		$resultado = ( new Documento_Service() )->subir( $farmacia_id, $nombre, $tipo_documento, $archivo, $descargable, true, $fecha_documento, $ejercicio, $emisor_cif );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', sprintf( 'Documento "%s" subido y asignado correctamente.', $resultado->get_nombre() ) );
		}

		self::redirigir();
	}

	/**
	 * Retirar para correccion un Modelo 347 (#307). Nonce por documento y
	 * casilla de confirmacion obligatoria; la logica (transaccion, eventos)
	 * vive en Documento_Publicacion_Service::retirar_modelo_347().
	 */
	public static function gestionar_retirar_modelo_347(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		$id = isset( $_POST['documento_id'] ) ? (int) $_POST['documento_id'] : 0;

		check_admin_referer( 'mdf_ca_retirar_modelo_347_' . $id );

		if ( empty( $_POST['confirmar'] ) ) {
			self::guardar_aviso( 'error', 'Marca la casilla de confirmacion para retirar el Modelo 347. No se ha cambiado nada.' );
			self::redirigir();
		}

		$resultado = ( new Documento_Publicacion_Service() )->retirar_modelo_347( $id, get_current_user_id() );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', 'Modelo 347 retirado: su farmacia ya no lo ve. Ya puedes subir el corregido (lo que la farmacia ya vio o descargo no se puede retirar).' );
		}

		self::redirigir();
	}

	private static function redirigir(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Mismo mecanismo de aviso por transient de usuario que Admin_Planes
	 * (ver su cabecera): evita reenvio de formulario y no expone el
	 * mensaje en la URL.
	 */
	private static function guardar_aviso( string $tipo, string $mensaje ): void {
		set_transient(
			self::NOTICE_KEY_PREFIX . get_current_user_id(),
			array(
				'tipo'    => $tipo,
				'mensaje' => $mensaje,
			),
			30
		);
	}

	/**
	 * @return array{tipo: string, mensaje: string}|null
	 */
	private static function consumir_aviso(): ?array {
		$key   = self::NOTICE_KEY_PREFIX . get_current_user_id();
		$aviso = get_transient( $key );

		if ( ! $aviso ) {
			return null;
		}

		delete_transient( $key );

		return $aviso;
	}
}
