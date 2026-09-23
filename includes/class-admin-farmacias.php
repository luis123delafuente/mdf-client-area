<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alta MINIMA de farmacias para el piloto de Fase 3 (#250) -- decision de
 * alcance consciente, no un descuido: solo nombre, CIF y plan, sin
 * importador, sin CSV, sin simulacion previa de reglas. El alta manual
 * COMPLETA como formulario que alimenta la misma logica que el futuro
 * importador CSV es tarea explicita de Fase 4 (ver CLAUDE.md) y esta
 * clase no la sustituye -- solo desbloquea poder dar de alta 3-5 farmacias
 * piloto reales ahora mismo, reutilizando Farmacia_Service::crear() (la
 * misma logica que ya se uso para la farmacia de Fase 1 via la pagina de
 * seed temporal de la tarea #233, ya retirada del repositorio).
 *
 * Mismo patron que Admin_Planes, Admin_Documentos, Admin_Catalogo y
 * Admin_Invitaciones: menu propio, capacidad 'manage_options'
 * (mdf_cliente nunca la tiene, y Role_Restrictions::bloquear_acceso_admin()
 * ya lo saca de todo wp-admin antes de llegar aqui), admin-post.php con
 * nonce. Sin edicion ni borrado de farmacias aqui: tambien Fase 4.
 *
 * Tras crear una farmacia, se redirige directamente a Admin_Invitaciones
 * (no de vuelta a esta pantalla) para que invitarla sea el siguiente paso
 * sin ningun intermedio -- la farmacia recien creada ya aparece ahi como
 * "Sin cuenta" sin necesitar ningun pegamento adicional, porque las dos
 * pantallas leen de Farmacia_Repository::find_all().
 */
class Admin_Farmacias {

	private const MENU_SLUG         = 'mdf-ca-farmacias';
	private const NONCE_ACTION      = 'mdf_ca_crear_farmacia';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_farmacias_notice_';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_mdf_ca_crear_farmacia', array( __CLASS__, 'gestionar_crear' ) );
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Farmacias MDF',
			'Farmacias MDF',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-store',
			58
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$planes    = ( new Plan_Repository() )->find_all();
		$farmacias = ( new Farmacia_Repository() )->find_all();
		$aviso     = self::consumir_aviso();

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-farmacias.php';
	}

	public static function gestionar_crear(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_ACTION );

		$nombre  = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$cif     = isset( $_POST['cif'] ) ? sanitize_text_field( wp_unslash( $_POST['cif'] ) ) : '';
		$plan_id = isset( $_POST['plan_id'] ) && '' !== $_POST['plan_id'] ? (int) $_POST['plan_id'] : null;

		$resultado = ( new Farmacia_Service() )->crear( $cif, $nombre, null, $plan_id );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
			self::redirigir( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		}

		// Directo a Invitaciones, no de vuelta a esta pantalla: es el
		// siguiente paso real tras crear una farmacia piloto, sin
		// intermedios (ver cabecera de esta clase). El aviso se guarda con
		// el mecanismo de Admin_Invitaciones (no el de esta clase), porque
		// es esa pantalla la que lo va a leer y mostrar tras la redireccion.
		Admin_Invitaciones::guardar_aviso(
			'success',
			sprintf( 'Farmacia "%s" creada correctamente. Ya puedes invitarla.', $resultado->get_nombre() )
		);

		self::redirigir( admin_url( 'admin.php?page=mdf-ca-invitaciones' ) );
	}

	private static function redirigir( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Mismo mecanismo de aviso por transient de usuario que el resto de
	 * pantallas de backoffice del plugin. Solo para el camino de error,
	 * que redirige de vuelta a esta misma pantalla (el de exito usa
	 * Admin_Invitaciones::guardar_aviso(), ver gestionar_crear()): cada
	 * pantalla lee su propio prefijo de transient, asi que el aviso tiene
	 * que guardarse con el mecanismo de quien lo va a leer despues.
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
