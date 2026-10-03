<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Autenticacion y aislamiento del robot de recepcion de documentos (#303).
 * Es el UNICO sitio que sabe como se autentica el robot: si mas adelante se
 * cambia Application Passwords por otro mecanismo, solo hay que tocar esta
 * clase (autorizar() y los filtros), no la ruta ni el resto.
 *
 * Mecanismo: Application Passwords de WordPress (HTTP Basic) sobre un usuario
 * tecnico con el rol mdf_robot, que solo tiene la capacidad
 * mdf_ca_subir_facturas. WordPress las exige sobre HTTPS en produccion
 * (en local valen por WP_ENVIRONMENT_TYPE=local): no se desactiva con ningun
 * filtro.
 *
 * Aislamiento del robot:
 * - Solo puede usar rutas REST del namespace mdf-ca/v1 (rest_pre_dispatch),
 *   tambien dentro de /batch/v1 y en el indice.
 * - No tiene acceso interactivo: login con contrasena rechazado
 *   (wp-login.php y XML-RPC), recuperacion de contrasena desactivada y
 *   wp-admin cerrado (Role_Restrictions). Su contrasena es aleatoria y no
 *   se guarda ni se envia (regla 5).
 * - Las application passwords solo valen en REST (XML-RPC desactivado para
 *   ellas) y solo existen para este usuario: ningun otro, ni cliente ni
 *   administrador, puede crearlas ni usarlas.
 *
 * Alta del robot (la hace un administrador, manage_options):
 * 1. Documentos MDF > Recepcion automatica > "Crear usuario robot".
 * 2. Usuarios > editar "mdf-robot" > "Contrasenas de aplicacion": crear una y
 *    guardarla en el gestor de contrasenas (WordPress la muestra una sola
 *    vez). Nunca en el repo, en el codigo ni en chats.
 * 3. Rotar = crear una nueva, cambiarla en el robot y revocar la anterior.
 *    Revocar = borrarla en ese mismo perfil; WordPress muestra el ultimo uso.
 *
 * Si en produccion la cabecera Authorization no llega a PHP (hosting
 * compartido con PHP via FastCGI), las credenciales no se reciben y todas las
 * peticiones del robot salen 401. WordPress ya reconstruye las credenciales
 * desde HTTP_AUTHORIZATION o REDIRECT_HTTP_AUTHORIZATION: basta con que
 * Apache la pase, anadiendo al .htaccess de la RAIZ de WordPress (fuera de
 * lo que despliega deploy.sh, es un paso manual):
 *     SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1
 * o bien
 *     RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
 * Esto se comprueba aparte en produccion; aqui no se da por supuesto.
 */
class Recepcion_Autenticacion {

	public static function register_hooks(): void {
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'aislar_robot_en_rest' ), 10, 3 );
		add_filter( 'wp_authenticate_user', array( __CLASS__, 'rechazar_login_con_contrasena' ), 10, 1 );
		add_filter( 'allow_password_reset', array( __CLASS__, 'impedir_recuperar_contrasena_robot' ), 10, 2 );
		add_filter( 'wp_is_application_passwords_available_for_user', array( __CLASS__, 'application_passwords_solo_para_robot' ), 10, 2 );
		add_filter( 'application_password_is_api_request', array( __CLASS__, 'application_passwords_solo_en_rest' ) );
	}

	/**
	 * permission_callback de la ruta de recepcion: usuario autenticado (por
	 * application password) con el rol robot y su capacidad. Cualquier otro
	 * caso es el mismo rechazo, sin distinguir el motivo.
	 *
	 * @return true|\WP_Error
	 */
	public static function autorizar() {
		$usuario = wp_get_current_user();

		if ( $usuario->exists() && Roles::user_is_robot( $usuario ) && user_can( $usuario, Roles::CAP_SUBIR_FACTURAS ) ) {
			return true;
		}

		return new \WP_Error( 'mdf_ca_peticion_rechazada', 'Peticion rechazada.', array( 'status' => 401 ) );
	}

	/**
	 * El robot no puede usar ninguna ruta REST fuera de nuestro namespace.
	 *
	 * @param mixed $result
	 * @return mixed
	 */
	public static function aislar_robot_en_rest( $result, \WP_REST_Server $server, \WP_REST_Request $request ) {
		$usuario = wp_get_current_user();

		if ( ! $usuario->exists() || ! Roles::user_is_robot( $usuario ) ) {
			return $result;
		}

		if ( 0 === strpos( $request->get_route(), '/' . Recepcion_Documentos_Endpoint::REST_NAMESPACE . '/' ) ) {
			return $result;
		}

		return new \WP_Error( 'mdf_ca_peticion_rechazada', 'Peticion rechazada.', array( 'status' => 401 ) );
	}

	/**
	 * Login con contrasena (o email y contrasena) rechazado para el robot,
	 * en wp-login.php y en XML-RPC. No afecta a las application passwords,
	 * que no pasan por este filtro.
	 *
	 * @param \WP_User|\WP_Error $usuario
	 * @return \WP_User|\WP_Error
	 */
	public static function rechazar_login_con_contrasena( $usuario ) {
		if ( $usuario instanceof \WP_User && Roles::user_is_robot( $usuario ) ) {
			return new \WP_Error( 'mdf_ca_login_no_permitido', 'Acceso no permitido.' );
		}

		return $usuario;
	}

	/**
	 * @param bool $permitir
	 * @param int  $user_id
	 */
	public static function impedir_recuperar_contrasena_robot( $permitir, $user_id ): bool {
		$usuario = get_userdata( (int) $user_id );

		if ( $usuario && Roles::user_is_robot( $usuario ) ) {
			return false;
		}

		return (bool) $permitir;
	}

	/**
	 * Las application passwords solo existen para el robot. Sin esto,
	 * cualquier usuario (un mdf_cliente incluido) podria crearse una desde
	 * su perfil o por REST y saltarse la sesion de 8 horas.
	 *
	 * @param bool          $disponible
	 * @param \WP_User|null $usuario
	 */
	public static function application_passwords_solo_para_robot( $disponible, $usuario ): bool {
		return $usuario instanceof \WP_User && Roles::user_is_robot( $usuario );
	}

	/** Solo REST: XML-RPC queda fuera. */
	public static function application_passwords_solo_en_rest( $es_peticion_api ): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}
}
