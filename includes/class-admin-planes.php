<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Primera pantalla propia de backoffice del plugin: CRUD de planes para el
 * administrador de WordPress. Capacidad 'manage_options', que mdf_cliente
 * nunca tiene (Roles::register() lo da de alta sin capacidades) -- doble
 * barrera junto con Role_Restrictions::bloquear_acceso_admin(), que ya
 * saca a mdf_cliente de todo wp-admin antes de llegar aqui.
 *
 * Usa admin-post.php para procesar alta/edicion/baja, a diferencia de
 * Documento_Endpoint (que lo evita a proposito, ver su cabecera): aqui SI
 * queremos el gate de capacidad que admin-post.php aplica via
 * wp-admin/admin.php, es precisamente la proteccion que necesita un
 * formulario de administracion. Patron estandar de WordPress: formulario
 * con nonce -> admin-post.php -> redirect con mensaje de resultado.
 *
 * Sin tabla propia tipo WP_List_Table: el numero de planes esperado es
 * pequeno (un puñado, no cientos), una tabla HTML simple es toda la UI que
 * hace falta.
 */
class Admin_Planes {

	private const MENU_SLUG    = 'mdf-ca-planes';
	private const NONCE_ACTION = 'mdf_ca_guardar_plan';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_planes_notice_';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_mdf_ca_guardar_plan', array( __CLASS__, 'gestionar_guardar' ) );
		add_action( 'admin_post_mdf_ca_eliminar_plan', array( __CLASS__, 'gestionar_eliminar' ) );
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Planes MDF',
			'Planes MDF',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-tag',
			58
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$planes         = ( new Plan_Repository() )->find_all();
		$plan_en_edicion = self::resolver_plan_en_edicion();
		$aviso          = self::consumir_aviso();

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-planes.php';
	}

	private static function resolver_plan_en_edicion(): ?Plan {
		if ( ! isset( $_GET['editar'] ) ) {
			return null;
		}

		return ( new Plan_Repository() )->find_by_id( (int) $_GET['editar'] );
	}

	public static function gestionar_guardar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_ACTION );

		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';

		$service = new Plan_Service();
		$resultado = $id > 0 ? $service->actualizar( $id, $nombre ) : $service->crear( $nombre );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
			self::redirigir( $id > 0 ? array( 'editar' => $id ) : array() );
		}

		self::guardar_aviso( 'success', $id > 0 ? 'Plan actualizado correctamente.' : 'Plan creado correctamente.' );
		self::redirigir();
	}

	public static function gestionar_eliminar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		check_admin_referer( 'mdf_ca_eliminar_plan_' . $id );

		$resultado = ( new Plan_Service() )->eliminar( $id );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', 'Plan eliminado correctamente.' );
		}

		self::redirigir();
	}

	/**
	 * @param array<string, mixed> $args_extra
	 */
	private static function redirigir( array $args_extra = array() ): void {
		$url = add_query_arg( $args_extra, admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * El aviso de resultado viaja en un transient por usuario, no por query
	 * string: evita reenviar el formulario o mostrar un mensaje de error
	 * ajeno en un refresco de pagina, y no expone el texto en la URL.
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
