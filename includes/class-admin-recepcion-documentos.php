<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Documentos MDF > Recepcion automatica" (#303). Mismo patron que el
 * resto del backoffice: capacidad 'manage_options', admin-post.php con nonce
 * en cada accion, avisos por transient de usuario. mdf_cliente no llega aqui
 * por ninguna via (no tiene la capacidad y Role_Restrictions lo saca de
 * wp-admin).
 *
 * Interruptor y tope diario de la recepcion, recuentos de los ultimos dias,
 * estado del usuario robot y alta de ese usuario. La application password del
 * robot se crea en su perfil de WordPress (ver Recepcion_Autenticacion): esta
 * pantalla nunca la muestra ni la guarda.
 */
class Admin_Recepcion_Documentos {

	private const MENU_SLUG         = 'mdf-ca-recepcion-documentos';
	private const PARENT_SLUG       = 'mdf-ca-documentos';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_recepcion_notice_';
	public const ROBOT_LOGIN        = 'mdf-robot';

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Documentos en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_post_mdf_ca_recepcion_interruptor', array( __CLASS__, 'gestionar_interruptor' ) );
		add_action( 'admin_post_mdf_ca_recepcion_tope', array( __CLASS__, 'gestionar_tope' ) );
		add_action( 'admin_post_mdf_ca_recepcion_aprobacion', array( __CLASS__, 'gestionar_aprobacion' ) );
		add_action( 'admin_post_mdf_ca_recepcion_crear_robot', array( __CLASS__, 'gestionar_crear_robot' ) );
	}

	public static function registrar_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			'Recepcion automatica de documentos',
			'Recepcion automatica',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' )
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$activa          = Recepcion_Registro::esta_activa();
		$encendida       = '1' === get_option( Recepcion_Registro::OPTION_ACTIVA, '0' );
		$forzada_apagada = Recepcion_Registro::forzada_apagada_por_constante();
		$tope            = Recepcion_Registro::get_tope();
		$requiere_aprobacion    = Recepcion_Registro::requiere_aprobacion();
		$pendientes_publicacion = ( new Documento_Repository() )->contar_pendientes_publicacion();
		$aceptados_hoy   = Recepcion_Registro::total_hoy( Recepcion_Registro::ACEPTADO );
		$recuentos       = Recepcion_Registro::recuentos_recientes( 14 );
		$aviso           = self::consumir_aviso();

		// Estado del robot: solo si existe, cuantas application passwords
		// tiene y cuando se uso la ultima. Nunca la password ni la IP.
		$robot     = get_user_by( 'login', self::ROBOT_LOGIN );
		$robot_info = null;

		if ( $robot ) {
			$passwords  = \WP_Application_Passwords::get_user_application_passwords( $robot->ID );
			$ultimo_uso = 0;

			foreach ( $passwords as $password ) {
				$ultimo_uso = max( $ultimo_uso, (int) ( $password['last_used'] ?? 0 ) );
			}

			$robot_info = array(
				'rol_correcto' => Roles::user_is_robot( $robot ),
				'passwords'    => count( $passwords ),
				'ultimo_uso'   => $ultimo_uso,
				'enlace'       => get_edit_user_link( $robot->ID ),
			);
		}

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-recepcion-documentos.php';
	}

	public static function gestionar_interruptor(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( 'mdf_ca_recepcion_interruptor' );

		$activar = isset( $_POST['activar'] ) && '1' === $_POST['activar'];
		Recepcion_Registro::set_activa( $activar );

		if ( $activar && Recepcion_Registro::forzada_apagada_por_constante() ) {
			self::guardar_aviso( 'warning', 'Recepcion activada en la pantalla, pero sigue apagada por la constante MDF_CA_RECEPCION_DESACTIVADA de wp-config.php.' );
		} else {
			self::guardar_aviso( 'success', $activar ? 'Recepcion automatica activada.' : 'Recepcion automatica desactivada. El endpoint rechaza todo y no escribe nada.' );
		}

		self::redirigir();
	}

	/**
	 * Con la aprobacion activa (por defecto), los documentos recibidos nacen
	 * pendientes de publicar (#304). Apagarla no publica los ya pendientes.
	 */
	public static function gestionar_aprobacion(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( 'mdf_ca_recepcion_aprobacion' );

		$activar = isset( $_POST['activar'] ) && '1' === $_POST['activar'];
		Recepcion_Registro::set_requiere_aprobacion( $activar );

		self::guardar_aviso(
			$activar ? 'success' : 'warning',
			$activar
				? 'Aprobacion activada: los documentos recibidos quedan pendientes de publicar.'
				: 'Aprobacion desactivada: los documentos recibidos a partir de ahora se publican directamente. Los que ya estan pendientes siguen pendientes.'
		);

		self::redirigir();
	}

	public static function gestionar_tope(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( 'mdf_ca_recepcion_tope' );

		$tope = isset( $_POST['tope'] ) ? (int) $_POST['tope'] : 0;

		if ( Recepcion_Registro::set_tope( $tope ) ) {
			self::guardar_aviso( 'success', sprintf( 'Tope diario actualizado: %d documentos.', $tope ) );
		} else {
			self::guardar_aviso( 'error', sprintf( 'El tope debe estar entre 1 y %d.', Recepcion_Registro::TOPE_MAXIMO ) );
		}

		self::redirigir();
	}

	/**
	 * Crea el usuario tecnico del robot: rol mdf_robot, email no entregable y
	 * contrasena aleatoria de 64 caracteres que no se muestra, no se guarda y
	 * no se envia (regla 5). wp_insert_user() no envia ningun email.
	 */
	public static function gestionar_crear_robot(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( 'mdf_ca_recepcion_crear_robot' );

		if ( get_user_by( 'login', self::ROBOT_LOGIN ) ) {
			self::guardar_aviso( 'error', 'El usuario robot ya existe.' );
			self::redirigir();
		}

		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		$user_id = wp_insert_user(
			array(
				'user_login'           => self::ROBOT_LOGIN,
				'user_pass'            => wp_generate_password( 64, true, true ),
				'user_email'           => 'robot@' . ( '' !== $host ? $host : 'localhost' ) . '.invalid',
				'display_name'         => 'Robot MDF',
				'role'                 => Roles::ROLE_ROBOT,
				'show_admin_bar_front' => 'false',
			)
		);

		if ( is_wp_error( $user_id ) ) {
			self::guardar_aviso( 'error', 'No se pudo crear el usuario robot.' );
		} else {
			self::guardar_aviso( 'success', 'Usuario robot creado. Ahora crea su contrasena de aplicacion desde su perfil.' );
		}

		self::redirigir();
	}

	private static function redirigir(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
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
