<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aviso de la proteccion de la carpeta privada (#327). Solo en las
 * pantallas del plugin y solo para 'manage_options': nunca en el front-end
 * ni en el resto de wp-admin. El resultado sale de la cache de
 * Carpeta_Privada_Proteccion::comprobar(); si ha caducado, se recalcula
 * aqui (incluida la sonda HTTP, como mucho 5 s cada 1-12 h).
 *
 * "Volver a comprobar": admin-post.php con 'manage_options' + nonce, fuerza
 * la comprobacion y vuelve a la pantalla del plugin de la que venia.
 *
 * Los textos solo nombran la ruta relativa (Clientes/private-docs).
 */
class Admin_Proteccion_Carpeta {

	public const ACCION             = 'mdf_ca_proteccion_comprobar';
	private const NONCE             = 'mdf_ca_proteccion_comprobar';
	private const PREFIJO_PANTALLA  = 'page_mdf-ca-';
	private const PREFIJO_PAGINA    = 'mdf-ca-';
	private const PAGINA_DEFECTO    = 'mdf-ca-documentos';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_proteccion_notice_';

	public static function register_hooks(): void {
		add_action( 'admin_notices', array( __CLASS__, 'mostrar_aviso' ) );
		add_action( 'admin_post_' . self::ACCION, array( __CLASS__, 'gestionar_comprobar' ) );
	}

	public static function mostrar_aviso(): void {
		if ( ! current_user_can( 'manage_options' ) || ! self::es_pantalla_del_plugin() ) {
			return;
		}

		$resultado = Carpeta_Privada_Proteccion::comprobar();
		$aviso     = self::consumir_aviso();
		$mensajes  = Carpeta_Privada_Proteccion::todo_bien( $resultado ) ? array() : self::mensajes( $resultado );

		if ( ! $aviso && ! $mensajes ) {
			return;
		}

		$nivel   = in_array( 'error', array_column( $mensajes, 'nivel' ), true ) ? 'error' : 'warning';
		$pagina  = self::pagina_actual();
		$carpeta = Documento_Endpoint::CARPETA_RELATIVA;
		$accion  = self::ACCION;
		$nonce   = self::NONCE;
		$fecha   = wp_date( 'd/m/Y H:i', $resultado['comprobado'] );

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-proteccion-carpeta.php';
	}

	public static function gestionar_comprobar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::NONCE );

		$resultado = Carpeta_Privada_Proteccion::comprobar( true );

		if ( Carpeta_Privada_Proteccion::todo_bien( $resultado ) ) {
			self::guardar_aviso(
				sprintf(
					'Comprobado: la carpeta %s esta protegida (la prueba por HTTP ha recibido HTTP %d).',
					Documento_Endpoint::CARPETA_RELATIVA,
					$resultado['http_codigo']
				)
			);
		}

		$pagina = isset( $_POST['pagina'] ) ? sanitize_key( wp_unslash( $_POST['pagina'] ) ) : '';

		if ( 0 !== strpos( $pagina, self::PREFIJO_PAGINA ) ) {
			$pagina = self::PAGINA_DEFECTO;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . $pagina ) );
		exit;
	}

	/**
	 * Un mensaje por problema, con que hacer. 'error' = los documentos
	 * pueden estar expuestos o no se pueden guardar; 'warning' = hay que
	 * mirarlo, pero no hay prueba de exposicion.
	 *
	 * @param array{carpeta: string, escribible: bool, htaccess: string, index: string, http: string, http_codigo: ?int, http_motivo: ?string} $r
	 * @return list<array{nivel: string, texto: string}>
	 */
	private static function mensajes( array $r ): array {
		$carpeta  = Documento_Endpoint::CARPETA_RELATIVA;
		$mensajes = array();

		if ( 'no_existe' === $r['carpeta'] ) {
			return array(
				array(
					'nivel' => 'error',
					'texto' => "La carpeta {$carpeta} no existe y el plugin no ha podido crearla. No se pueden guardar documentos (ni desde aqui ni los que envia el robot) hasta que exista. Revisa los permisos de la carpeta Clientes del servidor o creala a mano por SFTP, y pulsa \"Volver a comprobar\".",
				),
			);
		}

		if ( Carpeta_Privada_Proteccion::ESTADO_NO_PROTEGIDA === $r['http'] ) {
			$mensajes[] = array(
				'nivel' => 'error',
				'texto' => "ALERTA: los ficheros de {$carpeta} se pueden descargar por enlace directo, sin pasar por el plugin (la prueba por HTTP ha recibido HTTP {$r['http_codigo']} con el contenido del fichero de prueba). Revisa ya el .htaccess de esa carpeta y que el hosting lo respete (AllowOverride), y avisa a desarrollo.",
			);
		}

		if ( 'no_deniega' === $r['htaccess'] ) {
			$mensajes[] = array(
				'nivel' => 'error',
				'texto' => "El fichero .htaccess de {$carpeta} existe pero no deniega el acceso (no contiene \"Require all denied\" ni \"Deny from all\"). El plugin no lo modifica: revisalo a mano por SFTP, deja la denegacion total y pulsa \"Volver a comprobar\".",
			);
		} elseif ( 'ilegible' === $r['htaccess'] ) {
			$mensajes[] = array(
				'nivel' => 'error',
				'texto' => "El fichero .htaccess de {$carpeta} existe pero el plugin no puede leerlo, asi que no sabe si deniega el acceso. Revisa sus permisos (0644) por SFTP y pulsa \"Volver a comprobar\".",
			);
		} elseif ( 'falta' === $r['htaccess'] ) {
			$mensajes[] = array(
				'nivel' => 'error',
				'texto' => "Falta el fichero .htaccess de {$carpeta} y el plugin no ha podido crearlo. Sin el, los documentos pueden quedar accesibles por enlace directo. Crealo a mano por SFTP con la denegacion total (\"Require all denied\") y pulsa \"Volver a comprobar\".",
			);
		}

		if ( ! $r['escribible'] ) {
			$mensajes[] = array(
				'nivel' => 'error',
				'texto' => "La carpeta {$carpeta} no admite escritura: no se pueden guardar documentos nuevos (ni desde aqui ni los que envia el robot)" . ( 'falta' === $r['index'] ? ', y el plugin no ha podido crear su index.php' : '' ) . '. Revisa sus permisos y propietario en el servidor (normalmente 0755, del usuario del servidor web) y pulsa "Volver a comprobar".',
			);
		}

		if ( Carpeta_Privada_Proteccion::ESTADO_NO_COMPROBABLE === $r['http'] ) {
			$mensajes[] = array(
				'nivel' => 'warning',
				'texto' => "No se ha podido comprobar por HTTP que {$carpeta} este bloqueada (" . self::motivo( $r ) . '). Esto no significa que este expuesta. Si se repite, compruebalo a mano: sube por SFTP un fichero .txt de prueba a esa carpeta, pidelo en el navegador, confirma que da 403 y borralo.',
			);
		}

		return $mensajes;
	}

	private static function motivo( array $r ): string {
		switch ( $r['http_motivo'] ) {
			case 'sin_sonda':
				return 'no se ha podido crear el fichero de prueba';
			case 'red':
				return 'error de conexion o tiempo agotado, sin codigo HTTP';
			case 'contenido':
				return "HTTP {$r['http_codigo']}, pero con un contenido distinto del fichero de prueba";
			default:
				return null !== $r['http_codigo'] ? "respuesta inesperada: HTTP {$r['http_codigo']}" : 'respuesta sin codigo HTTP';
		}
	}

	private static function es_pantalla_del_plugin(): bool {
		$pantalla = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $pantalla && false !== strpos( $pantalla->id, self::PREFIJO_PANTALLA );
	}

	private static function pagina_actual(): string {
		$pagina = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		return 0 === strpos( $pagina, self::PREFIJO_PAGINA ) ? $pagina : self::PAGINA_DEFECTO;
	}

	private static function guardar_aviso( string $mensaje ): void {
		set_transient( self::NOTICE_KEY_PREFIX . get_current_user_id(), $mensaje, 30 );
	}

	private static function consumir_aviso(): ?string {
		$key   = self::NOTICE_KEY_PREFIX . get_current_user_id();
		$aviso = get_transient( $key );

		if ( ! is_string( $aviso ) || '' === $aviso ) {
			return null;
		}

		delete_transient( $key );

		return $aviso;
	}
}
