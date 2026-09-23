<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invitacion por token y recuperacion de contrasena (#249), ambas sobre el
 * MISMO mecanismo nativo de WordPress (get_password_reset_key() /
 * check_password_reset_key() / wp-login.php?action=rp), no un sistema de
 * tokens propio. Se ha verificado leyendo el codigo fuente real de esta
 * instalacion (WP 7.1.1, wp-includes/user.php y wp-login.php) en vez de dar
 * por supuesto el comportamiento de versiones anteriores:
 *
 * - get_password_reset_key() genera una clave aleatoria de 20 caracteres
 *   (wp_generate_password(20, false)) y guarda SU HASH mas un timestamp en
 *   la columna user_activation_key de wp_users (no en usermeta). Generar
 *   una clave nueva para el mismo usuario SOBRESCRIBE la anterior sin mas
 *   codigo: asi se resuelve solo el caso limite de "invitacion nueva sobre
 *   una pendiente sin usar" -- la pendiente deja de ser valida en el mismo
 *   instante en que se genera la nueva.
 * - check_password_reset_key( $key, $login ) exige la clave Y el login
 *   exactos y compara el hash con hash_equals() (tiempo constante): una
 *   clave de la farmacia A nunca valida para el usuario de la farmacia B,
 *   y no es adivinable ni manipulable por fuerza bruta razonable.
 * - wp_set_password() (llamada por reset_password() al completar el
 *   formulario) VACIA user_activation_key al guardar la contrasena nueva:
 *   la clave es de un solo uso de forma nativa, sin logica adicional aqui.
 * - password_reset_expiration filtra la caducidad (24h por defecto). Para
 *   la invitacion (no urgente como un "olvide mi contrasena") se alarga a
 *   7 dias -- el filtro nativo no recibe el usuario ni el contexto de la
 *   llamada, asi que se activa solo durante la propia llamada a
 *   get_password_reset_key() de invitar() con una bandera estatica
 *   efimera (no hay concurrencia real dentro de una peticion PHP
 *   sincrona), documentado aqui para que quede claro que no es un
 *   descuido.
 *
 * Reutilizar esto en vez de construir una tabla de tokens propia evita
 * reimplementar codigo de seguridad (generacion aleatoria, comparacion en
 * tiempo constante, hash, expiracion) que WordPress ya mantiene con anos de
 * endurecimiento -- justo el motivo por el que la tarea pide valorar esta
 * opcion antes de construir una propia.
 */
class Invitacion_Service {

	/**
	 * 7 dias: una invitacion no es un "olvide mi contrasena" urgente, es un
	 * enlace que una farmacia puede tardar dias en abrir (fin de semana,
	 * quien gestiona el correo no es quien atiende el mostrador...). El
	 * "olvide mi contrasena" nativo (wp-login.php?action=lostpassword) NO
	 * pasa por este filtro con la bandera activa, asi que sigue con las
	 * 24h por defecto de WordPress -- ahi si tiene sentido que caduque
	 * rapido, es alguien intentando entrar ahora mismo.
	 */
	private const CADUCIDAD_INVITACION_SEGUNDOS = 7 * DAY_IN_SECONDS;

	/**
	 * Timestamp de la ultima invitacion enviada a un usuario. Unico dato
	 * propio que se guarda (usermeta estandar, no una tabla nueva): sirve
	 * solo para mostrar "enviada el..." en Admin_Invitaciones, la validez
	 * real del token la resuelve WordPress por su cuenta via
	 * user_activation_key.
	 */
	public const META_INVITACION_ENVIADA = 'mdf_ca_invitacion_enviada_en';

	private static bool $generando_invitacion = false;

	private Farmacia_Repository $farmacia_repository;

	public function __construct( ?Farmacia_Repository $farmacia_repository = null ) {
		$this->farmacia_repository = $farmacia_repository ?? new Farmacia_Repository();
	}

	public static function register_hooks(): void {
		add_filter( 'password_reset_expiration', array( __CLASS__, 'filtrar_caducidad_invitacion' ) );
		add_filter( 'retrieve_password_title', array( __CLASS__, 'filtrar_asunto_recuperacion' ), 10, 3 );
		add_filter( 'retrieve_password_message', array( __CLASS__, 'filtrar_mensaje_recuperacion' ), 10, 4 );
	}

	public static function filtrar_caducidad_invitacion( $expiracion ) {
		return self::$generando_invitacion ? self::CADUCIDAD_INVITACION_SEGUNDOS : $expiracion;
	}

	/**
	 * Reskin del asunto del email nativo de "olvide mi contrasena", solo
	 * para mdf_cliente -- el de cualquier otro rol (administrator) no se
	 * toca, sigue con el email generico de WordPress.
	 *
	 * @param string  $titulo
	 * @param string  $user_login
	 * @param \WP_User $usuario
	 */
	public static function filtrar_asunto_recuperacion( $titulo, $user_login, $usuario ) {
		if ( ! Roles::user_is_cliente( $usuario ) ) {
			return $titulo;
		}

		return 'Restablecer tu contrasena del Area de Clientes MDF';
	}

	/**
	 * @param string  $mensaje
	 * @param string  $key
	 * @param string  $user_login
	 * @param \WP_User $usuario
	 */
	public static function filtrar_mensaje_recuperacion( $mensaje, $key, $user_login, $usuario ) {
		if ( ! Roles::user_is_cliente( $usuario ) ) {
			return $mensaje;
		}

		return self::cuerpo_email(
			'Has solicitado restablecer la contrasena de tu cuenta del Area de Clientes de Mediformplus.',
			self::url_establecer_password( $user_login, $key, false ),
			'Si no has sido tu, ignora este correo: tu contrasena actual seguira funcionando.'
		);
	}

	/**
	 * Genera y envia la invitacion (o reinvitacion) para una farmacia. Si
	 * la farmacia no tiene todavia usuario de WordPress vinculado, lo crea
	 * con una contrasena aleatoria de 64 caracteres que no se guarda, no se
	 * muestra ni se envia por ningun canal (CLAUDE.md, regla no negociable
	 * 5) -- sale de scope nada mas pasarsela a wp_insert_user(), que la
	 * hashea. El titular fija su propia contrasena al consumir el enlace.
	 *
	 * Reinvitar una farmacia que ya tiene cuenta activa esta permitido a
	 * proposito (no se exige pasar por el "olvide mi contrasena"): es un
	 * reset iniciado por el administrador, mismo mecanismo, solo cambia el
	 * texto del email para reflejar que la cuenta ya existia.
	 *
	 * @return Farmacia|\WP_Error
	 */
	public function invitar( int $farmacia_id, string $user_login_nuevo = '', string $email_nuevo = '' ) {
		$farmacia = $this->farmacia_repository->find_by_id( $farmacia_id );

		if ( ! $farmacia ) {
			return new \WP_Error( 'mdf_ca_invitacion_farmacia_no_existe', 'La farmacia no existe.' );
		}

		$es_alta_nueva = ! $farmacia->get_wp_user_id();

		if ( $es_alta_nueva ) {
			$usuario = $this->crear_usuario_sin_password_utilizable( $user_login_nuevo, $email_nuevo );

			if ( is_wp_error( $usuario ) ) {
				return $usuario;
			}

			if ( ! $this->farmacia_repository->update_wp_user_id( $farmacia->get_id(), $usuario->ID ) ) {
				return new \WP_Error( 'mdf_ca_error_bd', 'La cuenta se creo pero no se pudo vincular a la farmacia.' );
			}

			$farmacia = $this->farmacia_repository->find_by_id( $farmacia->get_id() );
		} else {
			$usuario = get_userdata( $farmacia->get_wp_user_id() );

			if ( ! $usuario ) {
				return new \WP_Error( 'mdf_ca_invitacion_usuario_no_existe', 'El usuario vinculado a esta farmacia ya no existe.' );
			}
		}

		self::$generando_invitacion = true;
		$key                         = get_password_reset_key( $usuario );
		self::$generando_invitacion = false;

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$enviado = $this->enviar_email_invitacion( $usuario, $key, $farmacia, $es_alta_nueva );

		if ( ! $enviado ) {
			return new \WP_Error( 'mdf_ca_invitacion_email_no_enviado', 'No se pudo enviar el correo de invitacion. Comprueba la configuracion de envio de email del sitio.' );
		}

		update_user_meta( $usuario->ID, self::META_INVITACION_ENVIADA, time() );

		return $farmacia;
	}

	/**
	 * @return \WP_User|\WP_Error
	 */
	private function crear_usuario_sin_password_utilizable( string $user_login, string $email ) {
		$user_login = sanitize_user( trim( $user_login ) );
		$email      = sanitize_email( trim( $email ) );

		if ( '' === $user_login ) {
			return new \WP_Error( 'mdf_ca_invitacion_login_vacio', 'Indica un nombre de usuario para la nueva cuenta.' );
		}

		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'mdf_ca_invitacion_email_invalido', 'Indica un email valido para la nueva cuenta.' );
		}

		if ( username_exists( $user_login ) ) {
			return new \WP_Error( 'mdf_ca_invitacion_login_duplicado', sprintf( 'Ya existe un usuario con el nombre "%s". Elige otro.', $user_login ) );
		}

		if ( email_exists( $email ) ) {
			return new \WP_Error( 'mdf_ca_invitacion_email_duplicado', sprintf( 'Ya existe un usuario con el email "%s".', $email ) );
		}

		// wp_insert_user() exige un user_pass; se genera aleatorio y no se
		// retiene en ningun sitio (ni variable de clase, ni log, ni email).
		$password_no_utilizable = wp_generate_password( 64, true, true );

		$user_id = wp_insert_user(
			array(
				'user_login' => $user_login,
				'user_email' => $email,
				'user_pass'  => $password_no_utilizable,
				'role'       => Roles::ROLE_CLIENTE,
			)
		);

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		return get_userdata( $user_id );
	}

	private function enviar_email_invitacion( \WP_User $usuario, string $key, Farmacia $farmacia, bool $es_alta_nueva ): bool {
		$url = self::url_establecer_password( $usuario->user_login, $key );

		if ( $es_alta_nueva ) {
			$asunto = 'Activa tu acceso al Area de Clientes de Mediformplus';
			$cuerpo = self::cuerpo_email(
				'Se ha creado un acceso al Area de Clientes de Mediformplus para la farmacia "' . $farmacia->get_nombre() . '" (CIF ' . $farmacia->get_cif() . ').'
					. "\r\n\r\n" . 'Para activar tu cuenta y establecer tu contrasena, entra en el siguiente enlace. Caduca en 7 dias.',
				$url,
				'Si no esperabas este correo, puedes ignorarlo: la cuenta no quedara activa hasta que alguien use el enlace.'
			);
		} else {
			$asunto = 'Nuevo enlace de acceso al Area de Clientes de Mediformplus';
			$cuerpo = self::cuerpo_email(
				'Se ha generado un nuevo enlace para establecer la contrasena de tu cuenta del Area de Clientes (farmacia "' . $farmacia->get_nombre() . '").'
					. "\r\n\r\n" . 'Cualquier enlace anterior que no hayas usado ha quedado invalidado. Este nuevo enlace caduca en 7 dias.',
				$url,
				'Si no has solicitado esto, contacta con Mediformplus.'
			);
		}

		return (bool) wp_mail( $usuario->user_email, $asunto, $cuerpo );
	}

	private static function cuerpo_email( string $introduccion, string $url, string $pie ): string {
		return $introduccion . "\r\n\r\n" . $url . "\r\n\r\n" . $pie . "\r\n";
	}

	/**
	 * URL de la pantalla nativa de WordPress para establecer contrasena
	 * (accion "rp"). $es_invitacion anade el marcador propio
	 * "mdf_invitacion" para que Login_Personalizado muestre el mensaje de
	 * bienvenida de activacion -- solo cuando el enlace viene de
	 * enviar_email_invitacion() (alta nueva o reinvitacion admin-iniciada),
	 * nunca del "olvide mi contrasena" nativo (filtrar_mensaje_recuperacion()
	 * pasa false): un usuario que genuinamente olvido su contrasena no esta
	 * "activando una cuenta", y mostrarle ese mensaje seria confuso. Ver la
	 * cabecera de Login_Personalizado para como sobrevive el marcador a la
	 * redireccion y al envio del formulario nativos.
	 */
	public static function url_establecer_password( string $user_login, string $key, bool $es_invitacion = true ): string {
		$args = array(
			'action' => 'rp',
			'key'    => $key,
			'login'  => rawurlencode( $user_login ),
		);

		if ( $es_invitacion ) {
			$args['mdf_invitacion'] = '1';
		}

		return add_query_arg(
			$args,
			wp_login_url()
		);
	}
}
