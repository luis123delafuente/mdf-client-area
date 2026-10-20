<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Portada "Inicio" del backoffice y vista simplificada para el rol Gestion
 * MDF (#337).
 *
 * - "Inicio" (admin.php?page=mdf-ca-inicio, Roles::CAP_BACKOFFICE): accesos a
 *   las tareas habituales con sus contadores (bandeja y pendientes de
 *   publicar), solo los que el usuario puede usar. El administrador tambien
 *   la ve, junto a los demas menus del plugin.
 * - Vista simplificada (vista_simplificada(): CAP_BACKOFFICE sin
 *   Roles::CAP_ADMINISTRAR): al entrar va a Inicio en lugar del Escritorio
 *   (login_redirect y load-index.php), el menu lateral solo conserva los del
 *   plugin y el Perfil (lista blanca, tambien frente a otros plugins) y la
 *   barra superior pierde "Anadir", comentarios y accesos de edicion.
 *
 * Esto es solo presentacion: lo que impide al gestor abrir una pantalla de
 * WordPress es que su rol no tiene la capacidad (solo 'read'), y WordPress
 * se la niega aunque escriba la URL a mano. El Escritorio, que solo exige
 * 'read', se redirige a Inicio.
 */
class Admin_Inicio {

	public const MENU_SLUG = 'mdf-ca-inicio';

	private const CSS = 'assets/css/admin-inicio.css';

	/** Menus de primer nivel que conserva la vista simplificada. */
	private const MENUS_PERMITIDOS = array(
		self::MENU_SLUG,
		Admin_Documentos::MENU_SLUG,
		Admin_Farmacias::MENU_SLUG,
		Admin_Invitaciones::MENU_SLUG,
		'profile.php',
	);

	/** Nodos de la barra superior que se quitan en la vista simplificada. */
	private const NODOS_BARRA_FUERA = array( 'wp-logo', 'new-content', 'comments', 'edit', 'updates', 'customize', 'view', 'site-editor', 'search', 'command-palette' );

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_menu', array( __CLASS__, 'simplificar_menu' ), PHP_INT_MAX );
		// Al final: el nucleo anade algunos nodos (el buscador) con prioridad alta.
		add_action( 'admin_bar_menu', array( __CLASS__, 'simplificar_barra' ), PHP_INT_MAX );
		add_action( 'load-index.php', array( __CLASS__, 'redirigir_escritorio' ) );
		add_filter( 'login_redirect', array( __CLASS__, 'redirigir_tras_login' ), 20, 3 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
	}

	/** El usuario entra al backoffice del plugin y no es administrador. */
	public static function vista_simplificada( ?\WP_User $usuario = null ): bool {
		$usuario = $usuario ?? wp_get_current_user();

		return $usuario->exists() && user_can( $usuario, Roles::CAP_BACKOFFICE ) && ! user_can( $usuario, Roles::CAP_ADMINISTRAR );
	}

	public static function url(): string {
		return admin_url( 'admin.php?page=' . self::MENU_SLUG );
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Inicio',
			'Inicio',
			Roles::CAP_BACKOFFICE,
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-admin-home',
			// Arriba del todo para el gestor; junto a los menus del plugin para el administrador.
			self::vista_simplificada() ? 2 : 57
		);
	}

	public static function encolar_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'mdf-ca-admin-inicio', Recursos::url( self::CSS ), array(), Recursos::version( self::CSS ) );
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( Roles::CAP_BACKOFFICE ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$tarjetas = array();

		if ( current_user_can( Admin_Documentos::CAPACIDAD ) ) {
			$tarjetas[] = array(
				'titulo' => 'Subir facturas',
				'texto'  => 'Sube las facturas exportadas de SAGE. Quedan pendientes de publicar.',
				'url'    => admin_url( 'admin.php?page=' . Admin_Subir_Facturas::MENU_SLUG ),
				'cuenta' => null,
			);
			$tarjetas[] = array(
				'titulo' => 'Bandeja de facturas',
				'texto'  => 'Facturas que no se han podido asignar a una farmacia.',
				'url'    => Admin_Bandeja_Facturas::url(),
				'cuenta' => ( new Bandeja_Facturas_Service() )->contar(),
			);
			$tarjetas[] = array(
				'titulo' => 'Pendientes de publicar',
				'texto'  => 'Revisa y publica los documentos para que las farmacias los vean.',
				'url'    => admin_url( 'admin.php?page=' . Admin_Publicacion_Documentos::MENU_SLUG ),
				'cuenta' => ( new Documento_Repository() )->contar_pendientes_publicacion(),
			);
		}

		if ( current_user_can( Roles::CAP_GESTIONAR_347 ) ) {
			$tarjetas[] = array(
				'titulo' => 'Modelo 347',
				'texto'  => 'Genera, publica y envía las cartas del Modelo 347.',
				'url'    => admin_url( 'admin.php?page=' . Admin_Modelo_347::MENU_SLUG ),
				'cuenta' => null,
			);
		}

		if ( current_user_can( Admin_Farmacias::CAPACIDAD ) ) {
			$tarjetas[] = array(
				'titulo' => 'Alta de farmacia',
				'texto'  => 'Da de alta una farmacia, o varias con el importador.',
				'url'    => admin_url( 'admin.php?page=' . Admin_Farmacias::MENU_SLUG ),
				'cuenta' => null,
			);
			$tarjetas[] = array(
				'titulo' => 'Invitaciones',
				'texto'  => 'Envía a cada farmacia el enlace para crear su contraseña.',
				'url'    => admin_url( 'admin.php?page=' . Admin_Invitaciones::MENU_SLUG ),
				'cuenta' => null,
			);
		}

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-inicio.php';
	}

	/** Lista blanca de menus de primer nivel; los demas se quitan (solo presentacion). */
	public static function simplificar_menu(): void {
		global $menu;

		if ( ! self::vista_simplificada() || ! is_array( $menu ) ) {
			return;
		}

		foreach ( $menu as $elemento ) {
			$slug = (string) ( $elemento[2] ?? '' );

			if ( ! in_array( $slug, self::MENUS_PERMITIDOS, true ) ) {
				remove_menu_page( $slug );
			}
		}
	}

	public static function simplificar_barra( \WP_Admin_Bar $barra ): void {
		if ( ! self::vista_simplificada() ) {
			return;
		}

		foreach ( self::NODOS_BARRA_FUERA as $nodo ) {
			$barra->remove_node( $nodo );
		}
	}

	/** El Escritorio solo exige 'read': el gestor va a Inicio. */
	public static function redirigir_escritorio(): void {
		if ( self::vista_simplificada() ) {
			wp_safe_redirect( self::url() );
			exit;
		}
	}

	/**
	 * Tras iniciar sesion, el gestor que no pidio una pagina concreta va a
	 * Inicio. Si pidio una (p. ej. un enlace a Pendientes de publicar), se
	 * respeta: WordPress ya la ha validado y su capacidad decide.
	 *
	 * @param string             $redirect_to
	 * @param string             $requested_redirect_to
	 * @param \WP_User|\WP_Error $user
	 */
	public static function redirigir_tras_login( $redirect_to, $requested_redirect_to, $user ): string {
		if ( ! $user instanceof \WP_User || ! self::vista_simplificada( $user ) ) {
			return (string) $redirect_to;
		}

		$pedido      = untrailingslashit( (string) $requested_redirect_to );
		$por_defecto = array( '', untrailingslashit( admin_url() ), admin_url( 'index.php' ) );

		return in_array( $pedido, $por_defecto, true ) ? self::url() : (string) $redirect_to;
	}
}
