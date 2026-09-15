<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Comportamiento en tiempo de ejecucion asociado al rol mdf_cliente:
 * redirigir tras login y bloquear cualquier acceso directo a wp-admin.
 *
 * El bloqueo se engancha en 'init', no en 'admin_init': con el rol sin
 * ninguna capacidad (ni siquiera 'read'), wp-admin/admin.php mata la
 * peticion con un wp_die(403) propio de WordPress antes de disparar
 * 'admin_init', lo que dejaria ver un 403 en crudo en vez de nuestra
 * redireccion. 'init' se dispara antes de ese gate interno.
 *
 * is_admin() tambien es cierto en admin-ajax.php, por eso se excluye
 * explicitamente wp_doing_ajax(): bloquear esa ruta rompería el heartbeat
 * y cualquier peticion AJAX legitima del propio frontend. No hace falta
 * bloquear admin-ajax.php ni la REST API aparte: cada handler de esas dos
 * superficies ya comprueba capacidades antes de actuar, y este rol no
 * tiene ninguna. admin-post.php si carga wp-admin/admin.php (y por tanto
 * 'init' antes) y queda bloqueado igual que el resto de wp-admin.
 */
class Role_Restrictions {

	public static function register_hooks(): void {
		add_filter( 'login_redirect', array( __CLASS__, 'redirigir_tras_login' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'bloquear_acceso_admin' ) );
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

	public static function get_area_privada_url(): string {
		return apply_filters( 'mdf_ca_area_privada_url', home_url( '/area-privada/' ) );
	}
}
