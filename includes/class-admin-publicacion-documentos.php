<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Documentos MDF > Pendientes de publicar" (#304). Un documento
 * recibido por la recepcion automatica nace pendiente de publicar (mientras
 * la option de aprobacion este activa): es invisible para toda farmacia hasta
 * que un administrador lo publica aqui. Mismo patron que el resto del
 * backoffice: 'manage_options', admin-post.php con nonce en cada accion,
 * avisos por transient de usuario y salida escapada.
 *
 * Esta clase NO decide visibilidad: publicar o despublicar solo cambia el
 * estado, y quien decide quien ve un documento sigue siendo Permissions.
 *
 * Acciones: publicar un documento, publicar todos los de una farmacia (solo
 * esa), despublicar un documento ya publicado, y la VISTA PREVIA para el
 * administrador, que es el unico camino por el que alguien que no es la
 * farmacia titular lee un documento de cliente:
 * - mdf_ca_vista_previa (GET): manage_options + nonce por documento +
 *   Permissions::puede_previsualizar_documento(). Pinta el visor con marca de
 *   agua de administrador, sin descarga y con un token de un solo uso.
 * - mdf_ca_vista_previa_fichero (GET): lo pide el JS del visor. Mismas
 *   comprobaciones, token consumido (nunca una navegacion directa) y sirve
 *   los bytes con la misma funcion que el endpoint de las farmacias.
 * Ninguna deja una URL estable al fichero. Las rutas normales (visor y
 * endpoint de las farmacias) no cambian: un administrador sigue sin ver por
 * ellas un documento pendiente.
 *
 * Sin logs: ni datos fiscales ni nombres.
 */
class Admin_Publicacion_Documentos {

	private const MENU_SLUG         = 'mdf-ca-publicacion-documentos';
	private const PARENT_SLUG       = 'mdf-ca-documentos';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_publicacion_notice_';

	/** Publicados recientes que se listan para poder despublicar. */
	private const LIMITE_RECIENTES = 50;

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Documentos en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_post_mdf_ca_publicar_documento', array( __CLASS__, 'gestionar_publicar' ) );
		add_action( 'admin_post_mdf_ca_publicar_farmacia', array( __CLASS__, 'gestionar_publicar_farmacia' ) );
		add_action( 'admin_post_mdf_ca_despublicar_documento', array( __CLASS__, 'gestionar_despublicar' ) );
		add_action( 'admin_post_mdf_ca_vista_previa', array( __CLASS__, 'gestionar_vista_previa' ) );
		add_action( 'admin_post_mdf_ca_vista_previa_fichero', array( __CLASS__, 'gestionar_vista_previa_fichero' ) );
	}

	public static function registrar_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			'Documentos pendientes de publicar',
			'Pendientes de publicar',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' )
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$repositorio = new Documento_Repository();

		$farmacias_por_id = array();

		foreach ( ( new Farmacia_Repository() )->find_all() as $farmacia ) {
			$farmacias_por_id[ $farmacia->get_id() ] = $farmacia;
		}

		$pendientes_por_farmacia = array();

		foreach ( $repositorio->find_pendientes_publicacion() as $documento ) {
			$pendientes_por_farmacia[ $documento->get_farmacia_id() ][] = $documento;
		}

		$recientes        = $repositorio->find_publicados_recibidos( self::LIMITE_RECIENTES );
		$total_pendientes = $repositorio->contar_pendientes_publicacion();
		$aviso            = self::consumir_aviso();

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-publicacion-documentos.php';
	}

	/** URL de la vista previa de un documento (con nonce por documento). */
	public static function url_vista_previa( int $documento_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => 'mdf_ca_vista_previa',
					'documento' => $documento_id,
				),
				admin_url( 'admin-post.php' )
			),
			'mdf_ca_vista_previa_' . $documento_id
		);
	}

	public static function gestionar_publicar(): void {
		self::exigir_capacidad();

		$id = isset( $_POST['documento_id'] ) ? (int) $_POST['documento_id'] : 0;

		check_admin_referer( 'mdf_ca_publicar_documento_' . $id );

		$cambiados = ( new Documento_Repository() )->set_publicado( $id, true );

		if ( false === $cambiados ) {
			self::guardar_aviso( 'error', 'No se pudo publicar el documento.' );
		} elseif ( $cambiados > 0 ) {
			self::guardar_aviso( 'success', 'Documento publicado. Ya lo ve su farmacia.' );
		} else {
			self::guardar_aviso( 'warning', 'El documento no existe o ya estaba publicado.' );
		}

		self::redirigir();
	}

	/** Publica los pendientes de UNA farmacia; ninguna otra se toca. */
	public static function gestionar_publicar_farmacia(): void {
		self::exigir_capacidad();

		$farmacia_id = isset( $_POST['farmacia_id'] ) ? (int) $_POST['farmacia_id'] : 0;

		check_admin_referer( 'mdf_ca_publicar_farmacia_' . $farmacia_id );

		if ( $farmacia_id < 1 || ! ( new Farmacia_Repository() )->find_by_id( $farmacia_id ) ) {
			self::guardar_aviso( 'error', 'La farmacia no existe.' );
			self::redirigir();
		}

		$cambiados = ( new Documento_Repository() )->publicar_todos_de_farmacia( $farmacia_id );

		if ( false === $cambiados ) {
			self::guardar_aviso( 'error', 'No se pudieron publicar los documentos.' );
		} else {
			self::guardar_aviso( $cambiados > 0 ? 'success' : 'warning', sprintf( 'Documentos publicados: %d.', $cambiados ) );
		}

		self::redirigir();
	}

	public static function gestionar_despublicar(): void {
		self::exigir_capacidad();

		$id = isset( $_POST['documento_id'] ) ? (int) $_POST['documento_id'] : 0;

		check_admin_referer( 'mdf_ca_despublicar_documento_' . $id );

		$cambiados = ( new Documento_Repository() )->set_publicado( $id, false );

		if ( false === $cambiados ) {
			self::guardar_aviso( 'error', 'No se pudo despublicar el documento.' );
		} elseif ( $cambiados > 0 ) {
			self::guardar_aviso( 'success', 'Documento despublicado. Su farmacia ya no lo ve.' );
		} else {
			self::guardar_aviso( 'warning', 'El documento no existe o ya estaba pendiente de publicar.' );
		}

		self::redirigir();
	}

	/** Pagina de vista previa: el visor con marca de agua de administrador. */
	public static function gestionar_vista_previa(): void {
		self::exigir_capacidad();

		$id = isset( $_GET['documento'] ) ? (int) $_GET['documento'] : 0;

		check_admin_referer( 'mdf_ca_vista_previa_' . $id );

		$usuario   = wp_get_current_user();
		$documento = ( new Documento_Repository() )->find_by_id( $id );

		if ( ! Permissions::puede_previsualizar_documento( $usuario, $documento ) ) {
			Documento_Endpoint::responder_no_encontrado();
		}

		$endpoint_url = add_query_arg(
			array(
				'action'    => 'mdf_ca_vista_previa_fichero',
				'documento' => $id,
			),
			admin_url( 'admin-post.php' )
		);

		Documento_Visor::renderizar_vista_previa(
			$endpoint_url,
			Documento_Token::emitir( (int) $usuario->ID, $id ),
			sprintf( 'VISTA PREVIA - %s - %s', $usuario->user_login, wp_date( 'd/m/Y H:i' ) )
		);
		exit;
	}

	/** Bytes del documento para el JS de la vista previa: token de un solo uso. */
	public static function gestionar_vista_previa_fichero(): void {
		self::exigir_capacidad();

		$id        = isset( $_GET['documento'] ) ? (int) $_GET['documento'] : 0;
		$usuario   = wp_get_current_user();
		$documento = ( new Documento_Repository() )->find_by_id( $id );

		if ( ! Permissions::puede_previsualizar_documento( $usuario, $documento )
			|| ! Documento_Endpoint::es_peticion_del_visor( $usuario, $documento ) ) {
			Documento_Endpoint::responder_no_encontrado();
		}

		Documento_Endpoint::servir_fichero( $documento, false );
	}

	private static function exigir_capacidad(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}
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
