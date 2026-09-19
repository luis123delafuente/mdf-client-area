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
 *
 * Las cuatro paginas tambien se ocultan de la API REST para peticiones
 * sin autenticar (hallazgo de la bateria de cierre de Fase 2:
 * template_redirect, donde vive proteger_area_privada(), no se ejecuta en
 * peticiones REST, asi que sin este filtro las paginas eran visibles via
 * /wp-json/wp/v2/pages aunque su contenido ya se autoprotegiera). Dos
 * hooks distintos porque son dos rutas de codigo distintas dentro de
 * WP_REST_Posts_Controller: el listado construye una WP_Query (filtrable
 * con rest_page_query, aqui se excluyen los 4 IDs via post__not_in), pero
 * el endpoint individual (/wp/v2/pages/{id}) resuelve el post
 * directamente por ID, sin pasar por esa query.
 *
 * Para el endpoint individual se probo primero con el filtro
 * "estandar" para esto, rest_prepare_page, devolviendo un WP_Error --
 * pero en esta version de WordPress WP_REST_Posts_Controller::get_item()
 * llama a $response->link_header(...) sin comprobar antes is_wp_error(),
 * asi que un WP_Error ahi provoca un 500 (fatal: WP_Error no tiene
 * link_header()), no el 404 buscado. Se detecto en la verificacion en
 * local, no solo en teoria. Se usa en su lugar rest_pre_dispatch, que
 * intercepta antes de que el controlador llegue a ejecutarse: si el
 * resultado no es null, el propio WP_REST_Server lo normaliza con
 * is_wp_error() correctamente (class-wp-rest-server.php,
 * WP_REST_Server::dispatch()), sin pasar nunca por ese camino roto.
 * Devuelve el mismo WP_Error que ya usa el nucleo de WordPress para un ID
 * que no existe -- misma respuesta para "esta pagina esta oculta" y
 * "esta pagina no existe", sin dar pistas, igual que el 404 de
 * Documento_Endpoint. Solo afecta a /wp/v2/pages/{id} para los 4 IDs de
 * Area_Privada_Pages y solo sin sesion: no se toca la REST API de forma
 * global, ni se restringe nada para un usuario autenticado (Elementor la
 * sigue usando con normalidad desde wp-admin), ni afecta a ninguna otra
 * pagina del sitio ni a otras rutas REST.
 */
class Role_Restrictions {

	public static function register_hooks(): void {
		add_filter( 'login_redirect', array( __CLASS__, 'redirigir_tras_login' ), 10, 3 );
		add_action( 'init', array( __CLASS__, 'bloquear_acceso_admin' ) );
		add_action( 'template_redirect', array( __CLASS__, 'proteger_area_privada' ) );
		add_filter( 'show_admin_bar', array( __CLASS__, 'ocultar_admin_bar_cliente' ) );
		add_filter( 'rest_page_query', array( __CLASS__, 'ocultar_area_privada_en_rest_listado' ), 10, 2 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'ocultar_area_privada_en_rest_individual' ), 10, 3 );
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
	 * Excluye las cuatro paginas del listado /wp/v2/pages para peticiones
	 * sin autenticar. Cualquier sesion (no solo mdf_cliente, igual que
	 * proteger_area_privada()) deja el listado tal cual.
	 *
	 * post__in y post__not_in son excluyentes en WP_Query -- un elseif en
	 * class-wp-query.php, no un AND de ambos -- asi que si la peticion ya
	 * trae post__in (via ?include[]=), anadir aqui a post__not_in no hace
	 * nada: WP_Query lo ignora por completo mientras post__in este
	 * presente. Detectado probando en local con ?include[]=<id oculto>,
	 * no solo en teoria. Cuando post__in esta presente hay que quitar los
	 * IDs de ahi directamente; si eso lo deja vacio, se fuerza con
	 * array(0) (mismo truco que usa el propio WP_REST_Posts_Controller
	 * para su filtro de sticky posts) para que la consulta no devuelva
	 * nada, en vez de caer en la query por defecto sin restriccion.
	 *
	 * @param array<string, mixed> $args
	 */
	public static function ocultar_area_privada_en_rest_listado( array $args, \WP_REST_Request $request ): array {
		if ( is_user_logged_in() ) {
			return $args;
		}

		$ids = array_values( Area_Privada_Pages::get_page_ids() );

		if ( ! $ids ) {
			return $args;
		}

		if ( ! empty( $args['post__in'] ) ) {
			$args['post__in'] = array_diff( $args['post__in'], $ids );

			if ( ! $args['post__in'] ) {
				$args['post__in'] = array( 0 );
			}
		} else {
			$args['post__not_in'] = array_merge( $args['post__not_in'] ?? array(), $ids );
		}

		return $args;
	}

	/**
	 * Bloquea /wp/v2/pages/{id} para los 4 IDs cuando no hay sesion. El
	 * listado se filtra en la query (arriba), pero el endpoint individual
	 * resuelve el post directamente por ID sin pasar por ahi, asi que
	 * necesita su propia comprobacion -- ver el porque de rest_pre_dispatch
	 * (y no rest_prepare_page) en la cabecera de esta clase.
	 *
	 * @param mixed            $result
	 * @return mixed
	 */
	public static function ocultar_area_privada_en_rest_individual( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}

		if ( ! preg_match( '#^/wp/v2/pages/(\d+)$#', $request->get_route(), $coincidencias ) ) {
			return $result;
		}

		$ids = array_values( Area_Privada_Pages::get_page_ids() );

		if ( ! in_array( (int) $coincidencias[1], $ids, true ) ) {
			return $result;
		}

		// Mismo error que usa el nucleo de WordPress para un post que no
		// existe (WP_REST_Posts_Controller::get_post()): una de estas 4
		// paginas ocultas y un ID inexistente deben ser indistinguibles.
		return new \WP_Error(
			'rest_post_invalid_id',
			__( 'Invalid post ID.' ),
			array( 'status' => 404 )
		);
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
