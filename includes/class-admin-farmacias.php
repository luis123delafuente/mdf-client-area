<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Alta manual de farmacias (nombre, CIF/NIF y plan obligatorio). Desde
 * Fase 4 pasa por Farmacia_Import_Service::crear_una(), el mismo servicio
 * que el importador CSV: esta clase no valida nada ni llama a
 * Farmacia_Service::crear(), solo traduce $_POST y muestra el resultado.
 * Si el CIF/NIF ya existe, el formulario no actualiza (eso es del
 * importador).
 *
 * Mismo patron que Admin_Planes, Admin_Documentos, Admin_Catalogo y
 * Admin_Invitaciones: menu propio, capacidad Admin_Farmacias::CAPACIDAD
 * (mdf_cliente nunca la tiene, y Role_Restrictions::bloquear_acceso_admin()
 * ya lo saca de todo wp-admin antes de llegar aqui), admin-post.php con
 * nonce. Sin edicion. Desde la v0.46.0, "Eliminar" una farmacia sin
 * documentos ni cuenta, solo el administrador (ver
 * Farmacia_Eliminacion_Service), con pantalla de confirmacion. El alta
 * masiva por CSV (Fase 4) vive en Admin_Importador_Farmacias, submenu de
 * esta pantalla, sobre Farmacia_Import_Service.
 *
 * Tras crear una farmacia, se redirige directamente a Admin_Invitaciones
 * (no de vuelta a esta pantalla) para que invitarla sea el siguiente paso
 * sin ningun intermedio -- la farmacia recien creada ya aparece ahi como
 * "Sin cuenta" sin necesitar ningun pegamento adicional, porque las dos
 * pantallas leen de Farmacia_Repository::find_all().
 */
class Admin_Farmacias {

	public const MENU_SLUG          = 'mdf-ca-farmacias';

	/**
	 * Capacidad de Farmacias MDF, del importador CSV y de las invitaciones
	 * (#337): la tienen el rol Gestion MDF y el administrador.
	 */
	public const CAPACIDAD = Roles::CAP_GESTIONAR_FARMACIAS;
	private const NONCE_ACTION      = 'mdf_ca_crear_farmacia';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_farmacias_notice_';

	/**
	 * Eliminar una farmacia (v0.46.0): solo el administrador, como borrar
	 * usuarios. Nonce por farmacia: ACCION_ELIMINAR . '_' . id.
	 */
	public const CAPACIDAD_ELIMINAR = Roles::CAP_ADMINISTRAR;
	private const ACCION_ELIMINAR   = 'mdf_ca_eliminar_farmacia';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_mdf_ca_crear_farmacia', array( __CLASS__, 'gestionar_crear' ) );
		add_action( 'admin_post_' . self::ACCION_ELIMINAR, array( __CLASS__, 'gestionar_eliminar' ) );
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Farmacias MDF',
			'Farmacias MDF',
			self::CAPACIDAD,
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-store',
			58
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( self::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		// Solo pinta la confirmacion; el borrado (gestionar_eliminar) exige nonce.
		if ( isset( $_GET['eliminar'] ) && current_user_can( self::CAPACIDAD_ELIMINAR ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::render_confirmar_eliminar( (int) $_GET['eliminar'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$planes           = ( new Plan_Repository() )->find_all();
		$farmacias        = ( new Farmacia_Repository() )->find_all();
		$aviso            = self::consumir_aviso();
		$puede_eliminar   = current_user_can( self::CAPACIDAD_ELIMINAR );
		$bloqueos         = array();
		$documentos_total = ( new Documento_Repository() )->contar_todos_por_farmacia();

		if ( $puede_eliminar ) {
			cache_users( array_filter( array_map( fn( Farmacia $f ) => (int) $f->get_wp_user_id(), $farmacias ) ) );

			foreach ( $farmacias as $farmacia ) {
				$bloqueos[ $farmacia->get_id() ] = Farmacia_Eliminacion_Service::bloqueo( $farmacia, $documentos_total[ $farmacia->get_id() ] ?? 0 );
			}
		}

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-farmacias.php';
	}

	public static function url_eliminar( int $farmacia_id ): string {
		return add_query_arg(
			array(
				'page'     => self::MENU_SLUG,
				'eliminar' => $farmacia_id,
			),
			admin_url( 'admin.php' )
		);
	}

	private static function render_confirmar_eliminar( int $farmacia_id ): void {
		$farmacia   = ( new Farmacia_Repository() )->find_by_id( $farmacia_id );
		$bloqueo    = Farmacia_Eliminacion_Service::bloqueo( $farmacia, $farmacia ? ( new Documento_Repository() )->contar_de_farmacia( $farmacia_id ) : 0 );
		$accion     = self::ACCION_ELIMINAR;
		$url_volver = admin_url( 'admin.php?page=' . self::MENU_SLUG );

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-eliminar-farmacia.php';
	}

	public static function gestionar_eliminar(): void {
		if ( ! current_user_can( self::CAPACIDAD_ELIMINAR ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}

		$id = isset( $_POST['farmacia_id'] ) ? (int) $_POST['farmacia_id'] : 0;

		check_admin_referer( self::ACCION_ELIMINAR . '_' . $id );

		$resultado = ( new Farmacia_Eliminacion_Service() )->eliminar( $id );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', sprintf( 'Farmacia «%s» (%s) eliminada.', $resultado['nombre'], $resultado['cif'] ) );
		}

		self::redirigir( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
	}

	public static function gestionar_crear(): void {
		if ( ! current_user_can( self::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_ACTION );

		$nombre  = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$cif     = isset( $_POST['cif'] ) ? sanitize_text_field( wp_unslash( $_POST['cif'] ) ) : '';
		$plan    = isset( $_POST['plan'] ) ? sanitize_text_field( wp_unslash( $_POST['plan'] ) ) : '';

		$resultado = ( new Farmacia_Import_Service() )->crear_una( $cif, $nombre, $plan );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
			self::redirigir( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		}

		// Directo a Invitaciones, no de vuelta a esta pantalla: es el
		// siguiente paso real tras crear una farmacia piloto, sin
		// intermedios (ver cabecera de esta clase). El aviso se guarda con
		// el mecanismo de Admin_Invitaciones (no el de esta clase), porque
		// es esa pantalla la que lo va a leer y mostrar tras la redireccion.
		Admin_Invitaciones::guardar_aviso(
			'success',
			sprintf( 'Farmacia "%s" creada correctamente. Ya puedes invitarla.', $resultado->get_nombre() )
		);

		self::redirigir( admin_url( 'admin.php?page=mdf-ca-invitaciones' ) );
	}

	private static function redirigir( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Mismo mecanismo de aviso por transient de usuario que el resto de
	 * pantallas de backoffice del plugin. Solo para el camino de error,
	 * que redirige de vuelta a esta misma pantalla (el de exito usa
	 * Admin_Invitaciones::guardar_aviso(), ver gestionar_crear()): cada
	 * pantalla lee su propio prefijo de transient, asi que el aviso tiene
	 * que guardarse con el mecanismo de quien lo va a leer despues.
	 */
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
