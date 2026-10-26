<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dos cosas sobre el flujo nativo de wp-login.php, sin sustituirlo por uno
 * propio (#249): un mensaje de bienvenida en la pantalla de
 * activar-cuenta/restablecer-contrasena cuando el enlace viene de una
 * invitacion, y la politica de duracion de sesion. El login en si
 * (formulario, redireccion tras autenticar) no se toca -- eso sigue siendo
 * Role_Restrictions::redirigir_tras_login(), sin cambios.
 */
class Login_Personalizado {

	/**
	 * Politica de sesion: 8 horas (una jornada laboral), la misma para
	 * todo el mundo con rol mdf_cliente sin importar si marco "Recuerdame".
	 * El area privada expone documentacion fiscal/financiera de farmacias
	 * reales (facturas, presupuestos, contratos) -- mas sensible que una
	 * sesion de compra tipica, donde el estandar de 14 dias de WordPress
	 * tiene sentido. Una sesion mas corta significa volver a autenticarse
	 * mas a menudo, y se acepta ese coste de usabilidad a cambio de que una
	 * sesion olvidada abierta en un ordenador compartido de la farmacia no
	 * quede accesible durante casi dos semanas.
	 *
	 * No se quita la casilla "Recuerdame" del formulario nativo: WordPress
	 * no sabe que rol va a autenticar antes de que la peticion de login se
	 * procese, asi que no hay forma limpia de ocultarla solo para
	 * mdf_cliente sin reescribir el formulario entero. En su lugar se
	 * ignora el valor que el usuario haya elegido y se aplica siempre el
	 * mismo limite -- el usuario puede marcarla, no tiene efecto.
	 *
	 * El administrador (y cualquier otro rol futuro) no se toca: esta
	 * tarea resuelve la exposicion de datos de farmacias vista por
	 * mdf_cliente, no cambia como trabaja el equipo en wp-admin.
	 */
	private const DURACION_SESION_CLIENTE_SEGUNDOS = 8 * HOUR_IN_SECONDS;

	private const MARCADOR_INVITACION = 'mdf_invitacion';

	public static function register_hooks(): void {
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'filtrar_duracion_sesion' ), 10, 3 );
		add_filter( 'login_message', array( __CLASS__, 'anadir_mensaje_invitacion' ) );
		add_action( 'resetpass_form', array( __CLASS__, 'preservar_marcador_invitacion' ) );

		// Imagen del area de clientes en wp-login.php (v0.40.0). Solo
		// presentacion: el formulario, los errores y la redireccion siguen
		// siendo los nativos (y los de Endurecimiento).
		add_action( 'login_enqueue_scripts', array( __CLASS__, 'encolar_estilos' ) );
		add_action( 'login_header', array( __CLASS__, 'pintar_panel_marca' ) );
		add_filter( 'login_message', array( __CLASS__, 'anadir_cabecera' ), 20 );
		add_action( 'login_footer', array( __CLASS__, 'pintar_pie' ) );
		add_filter( 'login_headerurl', array( __CLASS__, 'url_logo' ) );
		add_filter( 'login_headertext', array( __CLASS__, 'texto_logo' ) );
		add_filter( 'login_title', array( __CLASS__, 'titulo_pestana' ), 10, 2 );
		add_filter( 'login_display_language_dropdown', '__return_false' );
	}

	private const CSS_LOGIN = 'assets/css/login.css';

	public static function encolar_estilos(): void {
		wp_enqueue_style( 'mdf-ca-login', Recursos::url( self::CSS_LOGIN ), array( 'login' ), Recursos::version( self::CSS_LOGIN ) );
	}

	/**
	 * Panel verde de la izquierda (arriba en movil), con el logo y la marca
	 * de agua de mediformplus.com. 'login_header' se dispara justo antes de
	 * <div id="login">, asi que el panel queda como hermano del formulario.
	 */
	public static function pintar_panel_marca(): void {
		$logo = Recursos::url_versionada( 'assets/img/mdf-logo-blanco.png' );
		?>
		<aside class="mdf-login-marca">
			<img class="mdf-login-marca__logo" src="<?php echo esc_url( $logo ); ?>" alt="MDF Mediformplus" width="600" height="266">
			<div class="mdf-login-marca__cuerpo">
				<p class="mdf-login-marca__titulo">Área de clientes</p>
				<p class="mdf-login-marca__texto">Tus facturas, documentos y herramientas de MDF, en un solo sitio.</p>
				<ul class="mdf-login-marca__lista">
					<li>Facturas y Modelo 347</li>
					<li>Herramientas incluidas en tu plan</li>
					<li>Formación en la Academia MDF</li>
				</ul>
			</div>
			<p class="mdf-login-marca__ayuda">¿Necesitas ayuda? Llama a MDF al <a href="tel:+34916364818">91 636 48 18</a></p>
			<img class="mdf-login-marca__agua" src="<?php echo esc_url( $logo ); ?>" alt="" aria-hidden="true" width="600" height="266">
		</aside>
		<?php
	}

	/**
	 * Titulo y pista encima del formulario, segun la pantalla. Prioridad 20:
	 * va delante del aviso de invitacion (prioridad 10) y del mensaje nativo.
	 * Las acciones que no estan aqui (confirmar el correo del administrador,
	 * etc.) se quedan como estan.
	 *
	 * @param string $mensaje
	 */
	public static function anadir_cabecera( $mensaje ) {
		global $action;

		$recuperar = array( 'Recupera tu contraseña', 'Escribe tu usuario o tu correo electrónico y te enviaremos un enlace para elegir una nueva.' );
		$elegir    = array( 'Elige tu contraseña', 'Usa una contraseña segura que no utilices en otros sitios.' );
		$cabeceras = array(
			'login'            => array( 'Accede a tu área', 'Entra con tu usuario (tu CIF o NIF) o tu correo electrónico, y tu contraseña.' ),
			'lostpassword'     => $recuperar,
			'retrievepassword' => $recuperar,
			'rp'               => $elegir,
			'resetpass'        => $elegir,
			'checkemail'       => array( 'Revisa tu correo', 'Si la cuenta existe, te hemos enviado un enlace.' ),
		);

		$clave = is_string( $action ) && '' !== $action ? $action : 'login';

		if ( ! isset( $cabeceras[ $clave ] ) ) {
			return $mensaje;
		}

		list( $titulo, $texto ) = $cabeceras[ $clave ];

		return '<div class="mdf-login-cabecera">'
			. '<h2 class="mdf-login-cabecera__titulo">' . esc_html( $titulo ) . '</h2>'
			. '<p class="mdf-login-cabecera__texto">' . esc_html( $texto ) . '</p>'
			. '</div>'
			. $mensaje;
	}

	public static function pintar_pie(): void {
		$enlaces = array(
			'Aviso legal'         => 'https://www.mediformplus.com/aviso-legal/',
			'LOPD'                => 'https://www.mediformplus.com/lopd/',
			'Política de cookies' => 'https://www.mediformplus.com/politica-de-cookies/',
			'mediformplus.com'    => 'https://www.mediformplus.com/',
		);
		?>
		<footer class="mdf-login-pie">
			<span>&copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> MEDIFORMPLUS</span>
			<?php foreach ( $enlaces as $texto => $url ) : ?>
				<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $texto ); ?></a>
			<?php endforeach; ?>
		</footer>
		<?php
	}

	/** El logo nativo (oculto por CSS) deja de apuntar a wordpress.org. */
	public static function url_logo(): string {
		return 'https://www.mediformplus.com/';
	}

	public static function texto_logo(): string {
		return 'MDF Mediformplus';
	}

	/**
	 * "Acceder | Area de clientes MDF" en vez de "Acceder < Sitio — WordPress".
	 *
	 * @param string $titulo_completo
	 * @param string $titulo
	 */
	public static function titulo_pestana( $titulo_completo, $titulo ): string {
		return $titulo . ' | Área de clientes MDF';
	}

	/**
	 * @param int  $duracion
	 * @param int  $user_id
	 * @param bool $remember
	 */
	public static function filtrar_duracion_sesion( $duracion, $user_id, $remember ) {
		$usuario = get_userdata( $user_id );

		if ( ! $usuario || ! Roles::user_is_cliente( $usuario ) ) {
			return $duracion;
		}

		return self::DURACION_SESION_CLIENTE_SEGUNDOS;
	}

	/**
	 * Mensaje propio en las pantallas de activar-cuenta/restablecer
	 * (accion "rp"/"resetpass") cuando el enlace viene de una invitacion.
	 * Se ANADE encima del mensaje nativo de WordPress en vez de sustituir
	 * sus cadenas por coincidencia de texto exacto (fragil: una traduccion
	 * que cambie de una version de WP a otra romperia el reemplazo en
	 * silencio). Verificado leyendo wp-login.php de esta instalacion (WP
	 * 7.1.1): tanto la pantalla del formulario como la de exito pasan su
	 * mensaje por 'login_message' antes de mostrarlo, asi que un solo
	 * filtro cubre las dos.
	 *
	 * @param string $mensaje
	 */
	public static function anadir_mensaje_invitacion( $mensaje ) {
		global $action;

		if ( ! isset( $_REQUEST[ self::MARCADOR_INVITACION ] ) ) {
			return $mensaje;
		}

		if ( ! in_array( $action, array( 'rp', 'resetpass' ), true ) ) {
			return $mensaje;
		}

		$aviso = '<p class="message">'
			. esc_html__( 'Bienvenido al Área de clientes de Mediformplus. Elige tu contraseña para activar tu acceso.', 'mdf-client-area' )
			. '</p>';

		return $aviso . $mensaje;
	}

	/**
	 * El formulario de la accion "resetpass" postea siempre a una URL fija
	 * (wp-login.php?action=resetpass, fija en el nucleo de WordPress), asi
	 * que el marcador de la URL de invitacion original no sobrevive al
	 * envio del formulario salvo que se propague aqui como campo oculto --
	 * unico enganche que el nucleo ofrece dentro de ese <form> (accion
	 * "resetpass_form", justo antes del boton de enviar).
	 */
	public static function preservar_marcador_invitacion(): void {
		if ( isset( $_REQUEST[ self::MARCADOR_INVITACION ] ) ) {
			echo '<input type="hidden" name="' . esc_attr( self::MARCADOR_INVITACION ) . '" value="1" />';
		}
	}
}
