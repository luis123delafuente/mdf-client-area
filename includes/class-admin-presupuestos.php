<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documentos MDF > Presupuestos (v0.44.0): los presupuestos con su estado,
 * quien y cuando respondio, el motivo de un rechazo y si su aviso esta
 * pendiente. Desde aqui MDF pone uno en pendiente o aceptado
 * (Presupuesto_Service::cambiar_por_mdf()), reintenta los avisos que no
 * salieron y, solo el administrador, cambia el correo del responsable.
 *
 * Capacidad de Documentos MDF (gestor y administrador); el ajuste del
 * correo, Roles::CAP_ADMINISTRAR. admin-post con nonce, como el resto.
 */
class Admin_Presupuestos {

	public const MENU_SLUG = 'mdf-ca-presupuestos';

	private const PARENT_SLUG       = 'mdf-ca-documentos';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_presupuestos_notice_';

	private const ACCION_CAMBIAR    = 'mdf_ca_presupuesto_cambiar';
	private const ACCION_REINTENTAR = 'mdf_ca_presupuestos_reintentar';
	private const ACCION_AJUSTES    = 'mdf_ca_presupuestos_ajustes';

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Documentos en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_post_' . self::ACCION_CAMBIAR, array( __CLASS__, 'gestionar_cambiar' ) );
		add_action( 'admin_post_' . self::ACCION_REINTENTAR, array( __CLASS__, 'gestionar_reintentar' ) );
		add_action( 'admin_post_' . self::ACCION_AJUSTES, array( __CLASS__, 'gestionar_ajustes' ) );
	}

	public static function registrar_menu(): void {
		add_submenu_page( self::PARENT_SLUG, 'Presupuestos', 'Presupuestos', Admin_Documentos::CAPACIDAD, self::MENU_SLUG, array( __CLASS__, 'render_pagina' ) );
	}

	public static function url_listado( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function render_pagina(): void {
		self::exigir( Admin_Documentos::CAPACIDAD );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo filtra el listado.
		$filtro = isset( $_GET['estado'] ) ? sanitize_key( wp_unslash( $_GET['estado'] ) ) : '';
		$filtro = Presupuesto_Estado::es_valido( $filtro ) ? $filtro : '';

		$repository   = new Documento_Repository();
		$presupuestos = $repository->find_presupuestos( '' === $filtro ? null : $filtro );
		$con_aviso    = count( $repository->find_presupuestos_aviso_pendiente() );

		$farmacias = array();

		foreach ( ( new Farmacia_Repository() )->find_all() as $farmacia ) {
			$farmacias[ $farmacia->get_id() ] = $farmacia;
		}

		$email_responsable = Presupuesto_Ajustes::email_responsable();
		$es_admin          = current_user_can( Roles::CAP_ADMINISTRAR );
		$aviso             = self::consumir_aviso();
		$acciones          = array(
			'cambiar'    => self::ACCION_CAMBIAR,
			'reintentar' => self::ACCION_REINTENTAR,
			'ajustes'    => self::ACCION_AJUSTES,
		);

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-presupuestos.php';
	}

	public static function gestionar_cambiar(): void {
		self::exigir( Admin_Documentos::CAPACIDAD );

		$id = isset( $_POST['documento_id'] ) ? (int) $_POST['documento_id'] : 0;

		check_admin_referer( self::ACCION_CAMBIAR . '_' . $id );

		$estado    = isset( $_POST['estado'] ) ? sanitize_key( wp_unslash( $_POST['estado'] ) ) : '';
		$resultado = ( new Presupuesto_Service() )->cambiar_por_mdf( $id, $estado, get_current_user_id() );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} elseif ( ! $resultado['cambiado'] ) {
			self::guardar_aviso( 'warning', sprintf( '«%s» ya estaba %s: no se ha cambiado nada.', $resultado['nombre'], mb_strtolower( Presupuesto_Estado::etiqueta_de( $estado ) ) ) );
		} else {
			self::guardar_aviso(
				'success',
				Presupuesto_Estado::PENDIENTE === $estado
					? sprintf( '«%s» vuelve a estar pendiente: la farmacia puede aceptarlo o rechazarlo de nuevo.', $resultado['nombre'] )
					: sprintf( '«%s» marcado como aceptado. La farmacia lo verá aceptado; no se envía ningún aviso.', $resultado['nombre'] )
			);
		}

		self::volver();
	}

	public static function gestionar_reintentar(): void {
		self::exigir( Admin_Documentos::CAPACIDAD );
		check_admin_referer( self::ACCION_REINTENTAR );

		$quedan = ( new Presupuesto_Aviso() )->reintentar_pendientes();

		self::guardar_aviso(
			$quedan ? 'error' : 'success',
			$quedan
				? sprintf( 'Siguen sin salir %d avisos. Revisa el correo del responsable y el envío de correo del sitio; se volverá a intentar cada hora.', $quedan )
				: 'Avisos enviados.'
		);

		self::volver();
	}

	public static function gestionar_ajustes(): void {
		self::exigir( Roles::CAP_ADMINISTRAR );
		check_admin_referer( self::ACCION_AJUSTES );

		$email = isset( $_POST['email_responsable'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['email_responsable'] ) ) ) : '';

		if ( Presupuesto_Ajustes::set_email_responsable( $email ) ) {
			self::guardar_aviso( 'success', '' === $email ? 'Correo del responsable quitado: los avisos quedarán pendientes hasta que se ponga uno.' : 'Correo del responsable guardado.' );
		} else {
			self::guardar_aviso( 'error', 'El correo del responsable no es válido. No se ha cambiado nada.' );
		}

		self::volver();
	}

	private static function exigir( string $capacidad ): void {
		if ( ! current_user_can( $capacidad ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}
	}

	private static function volver(): void {
		wp_safe_redirect( wp_get_referer() ?: self::url_listado() );
		exit;
	}

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

	/** @return array{tipo: string, mensaje: string}|null */
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
