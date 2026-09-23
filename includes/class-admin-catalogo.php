<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD de backoffice para items de catalogo (Herramientas y Formacion),
 * incluida la asignacion de planes (#247). Mismo patron que Admin_Planes y
 * Admin_Documentos: menu propio, capacidad 'manage_options' (mdf_cliente
 * nunca la tiene, y Role_Restrictions::bloquear_acceso_admin() ya lo saca
 * de todo wp-admin antes de llegar aqui), admin-post.php con nonce.
 *
 * Toda la validacion (seccion, tipo, URL, planes existentes) vive en
 * Catalogo_Service, no aqui: este controlador solo traduce $_POST a
 * parametros tipados y traduce el resultado en un aviso + redirect.
 */
class Admin_Catalogo {

	private const MENU_SLUG            = 'mdf-ca-catalogo';
	private const NONCE_ACTION_GUARDAR = 'mdf_ca_guardar_catalogo';
	private const NOTICE_KEY_PREFIX    = 'mdf_ca_catalogo_notice_';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_mdf_ca_guardar_catalogo', array( __CLASS__, 'gestionar_guardar' ) );
		add_action( 'admin_post_mdf_ca_eliminar_catalogo', array( __CLASS__, 'gestionar_eliminar' ) );
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Catalogo MDF',
			'Catalogo MDF',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-portfolio',
			60
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$planes         = ( new Plan_Repository() )->find_all();
		$items          = ( new Catalogo_Repository() )->find_all();
		$item_en_edicion = self::resolver_item_en_edicion();
		$planes_del_item_en_edicion = $item_en_edicion
			? ( new Catalogo_Repository() )->find_plan_ids( $item_en_edicion->get_id() )
			: array();
		$aviso          = self::consumir_aviso();

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-catalogo.php';
	}

	private static function resolver_item_en_edicion(): ?Catalogo_Item {
		if ( ! isset( $_GET['editar'] ) ) {
			return null;
		}

		return ( new Catalogo_Repository() )->find_by_id( (int) $_GET['editar'] );
	}

	public static function gestionar_guardar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_ACTION_GUARDAR );

		$id         = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$seccion    = isset( $_POST['seccion'] ) ? sanitize_key( wp_unslash( $_POST['seccion'] ) ) : '';
		$nombre     = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$tipo       = isset( $_POST['tipo'] ) ? sanitize_key( wp_unslash( $_POST['tipo'] ) ) : '';
		$enlace_url = isset( $_POST['enlace_url'] ) ? esc_url_raw( wp_unslash( $_POST['enlace_url'] ) ) : '';
		$plan_ids   = isset( $_POST['plan_ids'] ) && is_array( $_POST['plan_ids'] )
			? array_map( 'intval', wp_unslash( $_POST['plan_ids'] ) )
			: array();

		$service   = new Catalogo_Service();
		$resultado = $id > 0
			? $service->actualizar( $id, $seccion, $nombre, $tipo, $enlace_url, $plan_ids )
			: $service->crear( $seccion, $nombre, $tipo, $enlace_url, $plan_ids );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
			self::redirigir( $id > 0 ? array( 'editar' => $id ) : array() );
		}

		self::guardar_aviso( 'success', $id > 0 ? 'Item de catalogo actualizado correctamente.' : 'Item de catalogo creado correctamente.' );
		self::redirigir();
	}

	public static function gestionar_eliminar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		check_admin_referer( 'mdf_ca_eliminar_catalogo_' . $id );

		$resultado = ( new Catalogo_Service() )->eliminar( $id );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', 'Item de catalogo eliminado correctamente.' );
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
	 * Mismo mecanismo de aviso por transient de usuario que Admin_Planes y
	 * Admin_Documentos.
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
