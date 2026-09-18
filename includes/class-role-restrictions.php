<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comportamiento en tiempo de ejecucion asociado al rol mdf_cliente:
 * redirigir tras login, bloquear cualquier acceso directo a wp-admin, y
 * (Fase 2, "Estructura de las cuatro secciones") proteger las paginas del
 * area de clientes exigiendo sesion.
 *
 * El bloqueo de wp-admin se engancha en 'init', no en 'admin_init': con
 * el rol sin ninguna capacidad (ni siquiera 'read'), wp-admin/admin.php
 * mata la peticion con un wp_die(403) propio de WordPress antes de
 * disparar 'admin_init', lo que dejaria ver un 403 en crudo en vez de
 * nuestra redireccion. 'init' se dispara antes de ese gate interno.
 *
 * is_admin() tambien es cierto en admin-ajax.php, por eso se excluye
 * explicitamente wp_doing_ajax(): bloquear esa ruta rompería el heartbeat
 * y cualquier peticion AJAX legitima del propio frontend. No hace falta
 * bloquear admin-ajax.php ni la REST API aparte: cada handler de esas dos
 * superficies ya comprueba capacidades antes de actuar, y este rol no
 * tiene ninguna. admin-post.php si carga wp-admin/admin.php (y por tanto
 * 'init' antes) y queda bloqueado igual que el resto de wp-admin.
 *
 * La proteccion de las cuatro paginas exige solo sesion (no rol
 * mdf_cliente): un administrador de WordPress navegando el sitio no debe
 * quedar atrapado en esta redireccion pensada para visitantes anonimos, y
 * cada shortcode de esas paginas ya resuelve su propio contenido vacio si
 * quien mira no tiene farmacia o plan (Permissions). Usa is_user_logged_in()
 * + wp_safe_redirect(), el mismo mecanismo que ya usa
 * bloquear_acceso_admin() en esta misma clase -- no un segundo sistema de
 * redireccion paralelo. Se probo primero con la funcion nucleo
 * auth_redirect(), pero valida la cookie de scheme 'auth' (la de
 * wp-admin), cuyo path de cookie el propio WordPress restringe a
 * /wp-admin: nunca llega en una peticion a una pagina normal del front,
 * asi que siempre habria forzado el login aunque hubiera sesion.
 *
 * La barra de administracion se oculta en el front solo para mdf_cliente
 * (ruido visual de cara a una farmacia real, no un problema de seguridad:
 * el acceso a wp-admin ya esta bloqueado por bloquear_acceso_admin()).
 * Se condiciona el filtro show_admin_bar al rol en vez de desactivarla de
 * forma global, para no afectar a otros roles.
 */
class Role_Restrictions {

	public static function register_hooks(): void {
		add_filter( 'login_redirect', array( __CLASS__, 'redirigir_tras_login' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'bloquear_acceso_admin' ) );
		add_action( 'template_redirect', array( __CLASS__, 'proteger_area_privada' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'ocultar_admin_bar_cliente' ) );
	}

	/**
	 * @param string           $redirect_to
	 * @param string           $requested_redirect_to
	 * @param \WP_User|\WP_Error $user
	 */
	public static function redirigir_tras_login( $redirect_to, $requested_redirect_to, $user ): string {
		if ( $user instanceof \WP_User && Roles::user_is_cliente( $user ) ) {
			return self::get_area_privada_url();
		}

		return $redirect_to;
	}

	public static function bloquear_acceso_admin(): void {
		if ( ! is_admin() || wp_doing_ajax() ) {
			return;
		}

		if ( ! is_user_logged_in() || ! Roles::current_user_is_cliente() ) {
			return;
		}

		wp_safe_redirect( self::get_area_privada_url() );
		exit;
	}

	/**
	 * Sin sesion, redirige a login a cualquiera de las cuatro paginas del
	 * area de clientes, con vuelta a la pagina pedida tras iniciar sesion.
	 * Con sesion -- de cualquier rol, no solo mdf_cliente -- no hace nada:
	 * no es una comprobacion de rol, es "esto no es publico para
	 * anonimos".
	 */
	public static function proteger_area_privada(): void {
		$ids = array_values( Area_Privada_Pages::get_page_ids() );

		if ( ! $ids || ! is_page( $ids ) ) {
			return;
		}

		if ( is_user_logged_in() ) {
			return;
		}

		wp_safe_redirect( wp_login_url( self::url_actual() ) );
		exit;
	}

	private static function url_actual(): string {
		return home_url( add_query_arg( null, null ) );
	}

	/**
	 * @param bool $show
	 */
	public static function ocultar_admin_bar_cliente( $show ): bool {
		if ( Roles::current_user_is_cliente() ) {
			return false;
		}

		return $show;
	}

	/**
	 * Destino tras login para mdf_cliente y punto de referencia general
	 * del "area de clientes": la pagina de Documentacion si ya existe
	 * (Area_Privada_Pages), o el filtro/fallback previo si por lo que sea
	 * todavia no se ha creado.
	 */
	public static function get_area_privada_url(): string {
		$ids               = Area_Privada_Pages::get_page_ids();
		$documentacion_id  = $ids[ Area_Privada_Pages::SLUG_DOCUMENTACION ] ?? 0;
		$url_documentacion = $documentacion_id ? get_permalink( $documentacion_id ) : false;

		return apply_filters( 'mdf_ca_area_privada_url', $url_documentacion ?: home_url( '/area-privada/' ) );
	}
}
