<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de backoffice para invitar/reinvitar farmacias (#249). Mismo
 * patron que Admin_Planes, Admin_Documentos y Admin_Catalogo: menu propio,
 * capacidad Admin_Farmacias::CAPACIDAD (mdf_cliente nunca la tiene, y
 * Role_Restrictions::bloquear_acceso_admin() ya lo saca de todo wp-admin
 * antes de llegar aqui), admin-post.php con nonce.
 *
 * Toda la logica (crear usuario si falta, generar el token, enviar el
 * email) vive en Invitacion_Service, no aqui: este controlador solo lista
 * farmacias con su estado de invitacion y traduce $_POST a parametros
 * tipados.
 */
class Admin_Invitaciones {

	public const MENU_SLUG          = 'mdf-ca-invitaciones';
	private const NONCE_ACTION      = 'mdf_ca_enviar_invitacion';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_invitaciones_notice_';

	/** Invitar a todas (v0.47.0): un nonce por accion. */
	private const NONCE_MASIVA_INICIAR    = 'mdf_ca_invitacion_masiva_iniciar';
	private const NONCE_MASIVA_REINTENTAR = 'mdf_ca_invitacion_masiva_reintentar';
	private const NONCE_MASIVA_CERRAR     = 'mdf_ca_invitacion_masiva_cerrar';
	private const NONCE_MASIVA_TANDA      = 'mdf_ca_invitacion_masiva_tanda';
	private const ACCION_AJAX_TANDA       = 'mdf_ca_invitacion_masiva_tanda';
	private const JS                      = 'assets/js/admin-invitaciones-masiva.js';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_mdf_ca_enviar_invitacion', array( __CLASS__, 'gestionar_enviar' ) );
		add_action( 'admin_post_' . self::NONCE_MASIVA_INICIAR, array( __CLASS__, 'gestionar_masiva_iniciar' ) );
		add_action( 'admin_post_' . self::NONCE_MASIVA_REINTENTAR, array( __CLASS__, 'gestionar_masiva_reintentar' ) );
		add_action( 'admin_post_' . self::NONCE_MASIVA_CERRAR, array( __CLASS__, 'gestionar_masiva_cerrar' ) );
		add_action( 'wp_ajax_' . self::ACCION_AJAX_TANDA, array( __CLASS__, 'ajax_masiva_tanda' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
	}

	public static function encolar_assets( string $hook_suffix ): void {
		if ( ! str_ends_with( $hook_suffix, '_page_' . self::MENU_SLUG ) || ! current_user_can( Admin_Farmacias::CAPACIDAD ) ) {
			return;
		}

		if ( ! ( new Invitacion_Masiva_Service() )->trabajo( get_current_user_id() ) ) {
			return;
		}

		wp_enqueue_script( 'mdf-ca-admin-invitaciones-masiva', Recursos::url( self::JS ), array(), Recursos::version( self::JS ), true );
		wp_localize_script(
			'mdf-ca-admin-invitaciones-masiva',
			'mdfCaInvitacionMasiva',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'accion'  => self::ACCION_AJAX_TANDA,
				'nonce'   => wp_create_nonce( self::NONCE_MASIVA_TANDA ),
			)
		);
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Invitaciones MDF',
			'Invitaciones MDF',
			Admin_Farmacias::CAPACIDAD,
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-email-alt',
			61
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( Admin_Farmacias::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$farmacias = ( new Farmacia_Repository() )->find_all();
		$estados   = array();

		foreach ( $farmacias as $farmacia ) {
			$estados[ $farmacia->get_id() ] = Invitacion_Service::estado_cuenta( $farmacia );
		}

		$aviso = self::consumir_aviso();

		$masiva         = new Invitacion_Masiva_Service();
		$trabajo        = $masiva->trabajo( get_current_user_id() );
		$masiva_resumen = $trabajo ? $masiva->resumen( get_current_user_id() ) : null;
		$revision       = null;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo elige que se pinta; no escribe nada.
		if ( ! $trabajo && isset( $_GET['masiva'] ) && 'revisar' === $_GET['masiva'] ) {
			$revision = $masiva->revisar();
		}

		$nombres_farmacias = array();

		foreach ( $farmacias as $farmacia ) {
			$nombres_farmacias[ $farmacia->get_id() ] = $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')';
		}

		$sin_cuenta = count( array_filter( $estados, static fn( $e ) => 'sin_cuenta' === $e['estado'] ) );

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-invitaciones.php';
	}

	public static function gestionar_masiva_iniciar(): void {
		self::exigir_permiso( self::NONCE_MASIVA_INICIAR );

		$huella    = isset( $_POST['huella'] ) ? sanitize_text_field( wp_unslash( $_POST['huella'] ) ) : '';
		$resultado = ( new Invitacion_Masiva_Service() )->iniciar( get_current_user_id(), $huella );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		}

		self::redirigir();
	}

	public static function gestionar_masiva_reintentar(): void {
		self::exigir_permiso( self::NONCE_MASIVA_REINTENTAR );

		$n = ( new Invitacion_Masiva_Service() )->reintentar_fallidas( get_current_user_id() );
		self::guardar_aviso( 'success', sprintf( '%d invitaciones fallidas vuelven a la cola. Pulsa «Continuar» para enviarlas.', $n ) );

		self::redirigir();
	}

	public static function gestionar_masiva_cerrar(): void {
		self::exigir_permiso( self::NONCE_MASIVA_CERRAR );

		( new Invitacion_Masiva_Service() )->cerrar( get_current_user_id() );

		self::redirigir();
	}

	public static function ajax_masiva_tanda(): void {
		if ( ! current_user_can( Admin_Farmacias::CAPACIDAD ) || false === check_ajax_referer( self::NONCE_MASIVA_TANDA, '_ajax_nonce', false ) ) {
			wp_send_json_error( array( 'mensaje' => 'No tienes permiso o la sesion ha caducado. Recarga la pagina.' ), 403 );
		}

		$resultado = ( new Invitacion_Masiva_Service() )->tanda( get_current_user_id() );

		if ( is_wp_error( $resultado ) ) {
			wp_send_json_error( array( 'mensaje' => $resultado->get_error_message() ), 409 );
		}

		$procesadas = array();

		foreach ( $resultado['procesadas'] as $fila ) {
			$farmacia     = ( new Farmacia_Repository() )->find_by_id( $fila['id'] );
			$procesadas[] = array(
				'farmacia' => $farmacia ? $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')' : 'ID ' . $fila['id'],
				'estado'   => $fila['estado'],
				'motivo'   => $fila['motivo'],
			);
		}

		wp_send_json_success(
			array(
				'resumen'    => $resultado['resumen'],
				'espera'     => $resultado['espera'],
				'procesadas' => $procesadas,
			)
		);
	}

	private static function exigir_permiso( string $nonce ): void {
		if ( ! current_user_can( Admin_Farmacias::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}

		check_admin_referer( $nonce );
	}

	public static function gestionar_enviar(): void {
		if ( ! current_user_can( Admin_Farmacias::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_ACTION );

		$farmacia_id = isset( $_POST['farmacia_id'] ) ? (int) $_POST['farmacia_id'] : 0;
		$user_login  = isset( $_POST['user_login'] ) ? sanitize_text_field( wp_unslash( $_POST['user_login'] ) ) : '';
		$email       = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';

		$resultado = ( new Invitacion_Service() )->invitar( $farmacia_id, $user_login, $email );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', sprintf( 'Invitacion enviada a "%s" correctamente.', $resultado->get_nombre() ) );
		}

		self::redirigir();
	}

	private static function redirigir(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Mismo mecanismo de aviso por transient de usuario que el resto de
	 * pantallas de backoffice del plugin. Publico (no privado como en las
	 * demas pantallas) para que Admin_Farmacias pueda mostrar aqui su
	 * propio aviso de exito: tras crear una farmacia redirige directamente
	 * a esta pantalla (ver su cabecera), asi que el aviso tiene que vivir
	 * bajo la clave de transient que ESTA pantalla lee, no bajo la de
	 * Admin_Farmacias.
	 */
	public static function guardar_aviso( string $tipo, string $mensaje ): void {
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
