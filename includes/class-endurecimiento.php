<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Endurecimiento de WordPress antes de la entrega (#329). Los nombres de
 * usuario de las farmacias son su CIF/NIF: cualquier forma de descubrir
 * usuarios desde fuera revela la cartera de clientes de MDF.
 *
 * - Usuarios por REST: las rutas /wp/v2/users solo existen para quien tiene
 *   list_users (el administrador). Para el resto, ni en el indice ni por id:
 *   el mismo 404 rest_no_route para un usuario que existe y uno que no (antes,
 *   un id existente daba 401 y uno inexistente 404).
 * - Archivos de autor (?author=N, /author/<slug>/, tambien sus feeds): 404
 *   uniforme, sin la redireccion al slug (el slug es el login). Los enlaces de
 *   autor apuntan a la portada.
 * - Mapas del sitio: sin el proveedor de usuarios.
 * - Feeds y oEmbed: sin autor (el nombre del sitio en su lugar).
 * - Login y "He olvidado mi contrasena": el mismo mensaje y la misma
 *   respuesta exista o no el usuario o el correo.
 * - XML-RPC desactivado entero: xmlrpc.php responde 403 sin cargar nada mas.
 * - Version de WordPress fuera del HTML, los feeds y las URL de los recursos
 *   del front y del login (se sustituye por una huella que sigue cambiando al
 *   actualizar, para no romper la cache); y fuera la cabecera X-Powered-By.
 *
 * El administrador sigue gestionando usuarios en wp-admin (no usa estas
 * rutas REST sin list_users) y las invitaciones no pasan por aqui.
 */
class Endurecimiento {

	/** Errores de wp_authenticate() que dicen si existe el usuario o el correo. */
	private const ERRORES_LOGIN_REVELADORES = array( 'invalid_username', 'invalid_email', 'incorrect_password' );

	public static function register_hooks(): void {
		// XML-RPC: antes que nada (xmlrpc.php define XMLRPC_REQUEST antes de cargar WordPress).
		self::cortar_xmlrpc();
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );
		add_filter( 'wp_headers', array( __CLASS__, 'quitar_cabeceras' ) );
		remove_action( 'wp_head', 'rsd_link' );

		// Usuarios por REST.
		add_filter( 'rest_endpoints', array( __CLASS__, 'quitar_rutas_de_usuarios' ) );

		// Archivos y enlaces de autor.
		add_action( 'template_redirect', array( __CLASS__, 'autor_404' ), 0 );
		add_filter( 'author_link', array( __CLASS__, 'enlace_autor' ) );

		// Mapas del sitio, feeds y oEmbed.
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'sin_mapa_de_usuarios' ), 10, 2 );
		add_filter( 'the_author', array( __CLASS__, 'autor_en_feeds' ) );
		add_filter( 'oembed_response_data', array( __CLASS__, 'oembed_sin_autor' ) );

		// Login y recuperar contrasena.
		add_filter( 'authenticate', array( __CLASS__, 'error_de_login_uniforme' ), 99 );
		add_action( 'lostpassword_post', array( __CLASS__, 'recuperar_uniforme' ), 99, 2 );

		// Version de WordPress y de PHP.
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );
		add_filter( 'style_loader_src', array( __CLASS__, 'ocultar_version_en_recurso' ), 9999 );
		add_filter( 'script_loader_src', array( __CLASS__, 'ocultar_version_en_recurso' ), 9999 );
		add_action( 'login_init', array( __CLASS__, 'login_sin_concatenar' ) );
		add_action( 'init', array( __CLASS__, 'quitar_x_powered_by' ), 0 );
		add_action( 'send_headers', array( __CLASS__, 'quitar_x_powered_by' ) );
	}

	/** xmlrpc.php: 403 y fin, sin cargar el servidor XML-RPC ni listar metodos. */
	private static function cortar_xmlrpc(): void {
		if ( ! defined( 'XMLRPC_REQUEST' ) || ! XMLRPC_REQUEST ) {
			return;
		}

		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'XML-RPC desactivado.';
		exit;
	}

	/**
	 * @param array<string, string> $cabeceras
	 * @return array<string, string>
	 */
	public static function quitar_cabeceras( $cabeceras ): array {
		unset( $cabeceras['X-Pingback'] );

		return (array) $cabeceras;
	}

	/**
	 * Sin list_users, las rutas de usuarios no existen (tampoco /users/me ni
	 * en el indice de la API): 404 rest_no_route, igual exista o no el id.
	 *
	 * @param array<string, mixed> $rutas
	 * @return array<string, mixed>
	 */
	public static function quitar_rutas_de_usuarios( $rutas ): array {
		if ( current_user_can( 'list_users' ) ) {
			return (array) $rutas;
		}

		foreach ( array_keys( (array) $rutas ) as $ruta ) {
			if ( str_starts_with( (string) $ruta, '/wp/v2/users' ) ) {
				unset( $rutas[ $ruta ] );
			}
		}

		return (array) $rutas;
	}

	/**
	 * Cualquier peticion de autor en el front (archivo, ?author=, feed de autor)
	 * da el mismo 404 exista o no, y sin la redireccion canonica al slug.
	 */
	public static function autor_404(): void {
		global $wp_query;

		if ( is_admin() || ! ( is_author() || '' !== (string) get_query_var( 'author' ) || '' !== (string) get_query_var( 'author_name' ) ) ) {
			return;
		}

		remove_action( 'template_redirect', 'redirect_canonical' );
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/** Los enlaces de autor (temas, feeds) no llevan el slug: van a la portada. */
	public static function enlace_autor(): string {
		return home_url( '/' );
	}

	/**
	 * @param mixed  $proveedor
	 * @param string $nombre
	 * @return mixed
	 */
	public static function sin_mapa_de_usuarios( $proveedor, $nombre ) {
		return 'users' === $nombre ? false : $proveedor;
	}

	/**
	 * En los feeds, el autor es el sitio.
	 *
	 * @param mixed $autor
	 * @return mixed
	 */
	public static function autor_en_feeds( $autor ) {
		return is_feed() ? get_bloginfo( 'name' ) : $autor;
	}

	/**
	 * @param array<string, mixed> $datos
	 * @return array<string, mixed>
	 */
	public static function oembed_sin_autor( $datos ): array {
		unset( $datos['author_name'], $datos['author_url'] );

		return (array) $datos;
	}

	/**
	 * Usuario inexistente, correo inexistente o contrasena incorrecta: el mismo
	 * error, sin repetir el usuario ni el correo. Los demas (campos vacios,
	 * etc.) no revelan nada y se dejan como estan.
	 *
	 * @param \WP_User|\WP_Error|null $usuario
	 * @return \WP_User|\WP_Error|null
	 */
	public static function error_de_login_uniforme( $usuario ) {
		if ( ! is_wp_error( $usuario ) || ! array_intersect( $usuario->get_error_codes(), self::ERRORES_LOGIN_REVELADORES ) ) {
			return $usuario;
		}

		return new \WP_Error(
			'mdf_ca_credenciales_incorrectas',
			sprintf(
				'<strong>Error:</strong> el usuario, el correo electrónico o la contraseña no son correctos. <a href="%s">¿Has olvidado tu contraseña?</a>',
				esc_url( wp_lostpassword_url() )
			)
		);
	}

	/**
	 * "He olvidado mi contrasena" en wp-login.php: si el usuario o el correo
	 * no existen, la misma respuesta que si existen (ir a "revisa tu correo"),
	 * sin enviar nada. Los otros errores (campo vacio) no revelan nada.
	 *
	 * @param \WP_Error           $errores
	 * @param \WP_User|false|null $usuario
	 */
	public static function recuperar_uniforme( $errores, $usuario ): void {
		global $pagenow;

		if ( 'wp-login.php' !== $pagenow || $usuario instanceof \WP_User ) {
			return;
		}

		if ( $errores instanceof \WP_Error && array_diff( $errores->get_error_codes(), array( 'invalid_email', 'invalidcombo' ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- mismo destino que el nucleo de wp-login.php.
		$destino = ! empty( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : 'wp-login.php?checkemail=confirm';

		wp_safe_redirect( $destino );
		exit;
	}

	/**
	 * ?ver=<version de WordPress> en los recursos del front y del login: se
	 * cambia por una huella de la version y la sal del sitio, que sigue
	 * cambiando al actualizar (cache) pero no dice cual es. Los recursos del
	 * plugin llevan su propia version (Recursos) y no se tocan.
	 */
	public static function ocultar_version_en_recurso( $src ) {
		if ( is_admin() || ! is_string( $src ) || '' === $src ) {
			return $src;
		}

		$version = (string) get_bloginfo( 'version' );
		$query   = (string) wp_parse_url( $src, PHP_URL_QUERY );

		parse_str( $query, $parametros );

		if ( ! isset( $parametros['ver'] ) || $version !== (string) $parametros['ver'] ) {
			return $src;
		}

		return add_query_arg( 'ver', substr( wp_hash( 'wp-' . $version ), 0, 10 ), $src );
	}

	/**
	 * El login concatena sus recursos en load-styles.php/load-scripts.php con
	 * ?ver=<version de WordPress>, sin pasar por los filtros de arriba. Sin
	 * concatenar (solo en wp-login.php), cada recurso lleva su URL y su
	 * version pasa por ocultar_version_en_recurso().
	 */
	public static function login_sin_concatenar(): void {
		$GLOBALS['concatenate_scripts'] = false; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- es el interruptor que lee script_concat_settings().
	}

	public static function quitar_x_powered_by(): void {
		if ( ! headers_sent() ) {
			header_remove( 'X-Powered-By' );
		}
	}
}
