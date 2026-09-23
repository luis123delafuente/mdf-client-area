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
			. esc_html__( 'Bienvenido al Area de Clientes de Mediformplus. Elige tu contrasena para activar tu acceso.', 'mdf-client-area' )
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
