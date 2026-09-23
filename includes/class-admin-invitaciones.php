<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de backoffice para invitar/reinvitar farmacias (#249). Mismo
 * patron que Admin_Planes, Admin_Documentos y Admin_Catalogo: menu propio,
 * capacidad 'manage_options' (mdf_cliente nunca la tiene, y
 * Role_Restrictions::bloquear_acceso_admin() ya lo saca de todo wp-admin
 * antes de llegar aqui), admin-post.php con nonce.
 *
 * Toda la logica (crear usuario si falta, generar el token, enviar el
 * email) vive en Invitacion_Service, no aqui: este controlador solo lista
 * farmacias con su estado de invitacion y traduce $_POST a parametros
 * tipados.
 */
class Admin_Invitaciones {

	private const MENU_SLUG         = 'mdf-ca-invitaciones';
	private const NONCE_ACTION      = 'mdf_ca_enviar_invitacion';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_invitaciones_notice_';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_mdf_ca_enviar_invitacion', array( __CLASS__, 'gestionar_enviar' ) );
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Invitaciones MDF',
			'Invitaciones MDF',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-email-alt',
			61
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$farmacias = ( new Farmacia_Repository() )->find_all();
		$estados   = array();

		foreach ( $farmacias as $farmacia ) {
			$estados[ $farmacia->get_id() ] = self::resolver_estado( $farmacia );
		}

		$aviso = self::consumir_aviso();

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-invitaciones.php';
	}

	/**
	 * Estado de invitacion de una farmacia, derivado por completo de datos
	 * ya nativos de WordPress -- sin tabla propia:
	 * - "sin_cuenta": la farmacia no tiene wp_user_id todavia.
	 * - "usuario_roto": tiene wp_user_id pero el usuario ya no existe
	 *   (caso limite improbable, p. ej. borrado a mano desde wp-admin).
	 * - "pendiente": el usuario tiene una user_activation_key activa (la
	 *   columna que WordPress rellena al generar un token y vacia al
	 *   consumirlo, ver Invitacion_Service) -- invitacion enviada, todavia
	 *   sin usar.
	 * - "activa": tiene usuario y no hay ninguna key pendiente.
	 *
	 * @return array{estado: string, etiqueta: string, email: ?string, enviada_en: ?int}
	 */
	private static function resolver_estado( Farmacia $farmacia ): array {
		if ( ! $farmacia->get_wp_user_id() ) {
			return array(
				'estado'     => 'sin_cuenta',
				'etiqueta'   => 'Sin cuenta',
				'email'      => null,
				'enviada_en' => null,
			);
		}

		$usuario = get_userdata( $farmacia->get_wp_user_id() );

		if ( ! $usuario ) {
			return array(
				'estado'     => 'usuario_roto',
				'etiqueta'   => 'Usuario vinculado no existe',
				'email'      => null,
				'enviada_en' => null,
			);
		}

		$enviada_en_meta = get_user_meta( $usuario->ID, Invitacion_Service::META_INVITACION_ENVIADA, true );
		$enviada_en      = $enviada_en_meta ? (int) $enviada_en_meta : null;

		if ( '' !== (string) $usuario->user_activation_key ) {
			return array(
				'estado'     => 'pendiente',
				'etiqueta'   => 'Invitacion pendiente',
				'email'      => $usuario->user_email,
				'enviada_en' => $enviada_en,
			);
		}

		return array(
			'estado'     => 'activa',
			'etiqueta'   => 'Cuenta activa',
			'email'      => $usuario->user_email,
			'enviada_en' => $enviada_en,
		);
	}

	public static function gestionar_enviar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
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
