<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de backoffice para subir un documento y asignarlo a una
 * farmacia (#246). Mismo patron que Admin_Planes, no uno distinto: menu
 * propio, capacidad Admin_Documentos::CAPACIDAD (mdf_cliente nunca la tiene, y
 * Role_Restrictions::bloquear_acceso_admin() ya lo saca de todo wp-admin
 * antes de llegar aqui), admin-post.php con nonce para procesar la subida.
 *
 * Toda la validacion (farmacia valida, tipo de fichero, tamano, nombre en
 * disco) vive en Documento_Service, no aqui: este controlador solo traduce
 * $_POST/$_FILES a parametros tipados y traduce el resultado en un aviso +
 * redirect, igual que Admin_Planes hace con Plan_Service.
 */
class Admin_Documentos {

	public const MENU_SLUG     = 'mdf-ca-documentos';
	private const NONCE_ACTION = 'mdf_ca_subir_documento';
	/**
	 * Capacidad de todas las pantallas y acciones de Documentos MDF
	 * (Documentos MDF, Pendientes de publicar, Documentos publicados, Subir
	 * facturas, Bandeja de facturas y Eliminar documento). Unico sitio que la
	 * decide: desde la #337, la capacidad propia del plugin, que tienen el rol
	 * Gestion MDF y el administrador.
	 */
	public const CAPACIDAD = Roles::CAP_GESTIONAR_DOCUMENTOS;

	/** Poner o quitar la marca de agua (#321): admin-post con nonce por documento. */
	public const ACCION_MARCA = 'mdf_ca_cambiar_marca';

	/** Activar o desactivar la descarga (v0.36.0): admin-post con nonce por documento. */
	public const ACCION_DESCARGA = 'mdf_ca_cambiar_descarga';

	/** Eliminar un documento no publicado (v0.28.0): admin-post con nonce por documento. */
	private const ACCION_ELIMINAR = 'mdf_ca_eliminar_documento';

	/** Pantallas desde las que se llega a "Eliminar" y a las que se vuelve. */
	private const VOLVER_PENDIENTES = 'pendientes';
	private const VOLVER_DOCUMENTOS = 'documentos';

	private const NOTICE_KEY_PREFIX = 'mdf_ca_documentos_notice_';

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_mdf_ca_subir_documento', array( __CLASS__, 'gestionar_subir' ) );
		add_action( 'admin_post_mdf_ca_retirar_modelo_347', array( __CLASS__, 'gestionar_retirar_modelo_347' ) );
		add_action( 'admin_post_' . self::ACCION_ELIMINAR, array( __CLASS__, 'gestionar_eliminar' ) );
		add_action( 'admin_post_' . self::ACCION_MARCA, array( __CLASS__, 'gestionar_cambiar_marca' ) );
		add_action( 'admin_post_' . self::ACCION_DESCARGA, array( __CLASS__, 'gestionar_cambiar_descarga' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
	}

	public static function registrar_menu(): void {
		add_menu_page(
			'Documentos MDF',
			'Documentos MDF',
			Admin_Documentos::CAPACIDAD,
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' ),
			'dashicons-media-document',
			59
		);
	}

	public static function encolar_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		self::encolar_selector_farmacia();
		Admin_Zona_Archivos::encolar();
	}

	/**
	 * CSS y JS del selector de farmacia con filtro. Lo usan Documentos MDF,
	 * Documentos publicados y Pendientes de publicar (#318, #319).
	 */
	public static function encolar_selector_farmacia(): void {
		wp_enqueue_style(
			'mdf-ca-admin-selector-farmacia',
			Recursos::url( 'assets/css/admin-selector-farmacia.css' ),
			array(),
			Recursos::version( 'assets/css/admin-selector-farmacia.css' )
		);

		wp_enqueue_script(
			'mdf-ca-admin-selector-farmacia',
			Recursos::url( 'assets/js/admin-selector-farmacia.js' ),
			array(),
			Recursos::version( 'assets/js/admin-selector-farmacia.js' ),
			true
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( Admin_Documentos::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		// Paso de confirmacion de "Eliminar" (v0.28.0): solo lectura, la accion
		// es un POST con nonce por documento.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo muestra la confirmacion.
		if ( isset( $_GET['eliminar'] ) ) {
			self::render_confirmar_eliminar( (int) $_GET['eliminar'], self::volver( $_GET['volver'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$farmacias = ( new Farmacia_Repository() )->find_all();
		usort(
			$farmacias,
			static fn( Farmacia $a, Farmacia $b ): int => strcasecmp( $a->get_nombre(), $b->get_nombre() )
		);
		$farmacias_por_id = array();

		foreach ( $farmacias as $farmacia ) {
			$farmacias_por_id[ $farmacia->get_id() ] = $farmacia;
		}

		$documentos = ( new Documento_Repository() )->find_all();
		$aviso      = self::consumir_aviso();

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-documentos.php';
	}

	public static function gestionar_subir(): void {
		if ( ! current_user_can( Admin_Documentos::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_ACTION );

		$farmacia_id    = isset( $_POST['farmacia_id'] ) ? (int) $_POST['farmacia_id'] : 0;
		$nombre         = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		$tipo_documento = isset( $_POST['tipo_documento'] ) ? sanitize_key( wp_unslash( $_POST['tipo_documento'] ) ) : '';

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- $_FILES no se sanea con wp_unslash/sanitize_*, Documento_Service::subir() valida cada campo antes de usarlo.
		$archivo = isset( $_FILES['documento'] ) ? $_FILES['documento'] : null;

		// Casillas (v0.36.0): salen marcadas como "Descargable" si y "Marca de
		// agua" no (Documento_Service::POR_DEFECTO_*). Una casilla sin marcar
		// no llega en la peticion.
		$descargable = ! empty( $_POST['descargable'] );
		$sin_marca   = empty( $_POST['marca_agua'] );

		// Fecha del documento, opcional: vacia = null; si viene, la valida
		// Documento_Service.
		$fecha_documento = isset( $_POST['fecha_documento'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['fecha_documento'] ) ) ) : '';
		$fecha_documento = '' === $fecha_documento ? null : $fecha_documento;

		// Facturas y Modelo 347 no entran por aqui (v0.36.0): lo rechaza
		// Documento_Service::subir() aunque se fuerce el tipo.
		// Estado del presupuesto (v0.44.0): solo cuenta si el tipo es
		// presupuesto; lo valida Documento_Service::subir().
		$estado_presupuesto = isset( $_POST['presupuesto_estado'] ) ? sanitize_key( wp_unslash( $_POST['presupuesto_estado'] ) ) : '';

		$resultado = ( new Documento_Service() )
			->descargable( $descargable )
			->sin_marca_de_agua( $sin_marca )
			->estado_presupuesto( $estado_presupuesto )
			->subir( $farmacia_id, $nombre, $tipo_documento, $archivo, true, $fecha_documento );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', sprintf( 'Documento "%s" subido y asignado correctamente.', $resultado->get_nombre() ) );
		}

		self::redirigir();
	}

	/**
	 * Retirar para correccion un Modelo 347 (#307). Nonce por documento y
	 * casilla de confirmacion obligatoria; la logica (transaccion, eventos)
	 * vive en Documento_Publicacion_Service::retirar_modelo_347(). Exige
	 * ademas la capacidad del Modelo 347 (#337): es una correccion del 347.
	 */
	public static function gestionar_retirar_modelo_347(): void {
		if ( ! current_user_can( Admin_Documentos::CAPACIDAD ) || ! current_user_can( Roles::CAP_GESTIONAR_347 ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		$id = isset( $_POST['documento_id'] ) ? (int) $_POST['documento_id'] : 0;

		check_admin_referer( 'mdf_ca_retirar_modelo_347_' . $id );

		if ( empty( $_POST['confirmar'] ) ) {
			self::guardar_aviso( 'error', 'Marca la casilla de confirmacion para retirar el Modelo 347. No se ha cambiado nada.' );
			self::redirigir();
		}

		$resultado = ( new Documento_Publicacion_Service() )->retirar_modelo_347( $id, get_current_user_id() );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', 'Modelo 347 retirado: su farmacia ya no lo ve. Para corregirlo, vuelve a generarlo desde el Excel en «Modelo 347» (lo que la farmacia ya vio o descargó no se puede retirar).' );
		}

		self::redirigir();
	}

	/** Enlace al paso de confirmacion de "Eliminar" de un documento (v0.28.0). */
	public static function url_eliminar( int $documento_id, string $volver ): string {
		return add_query_arg(
			array(
				'page'     => self::MENU_SLUG,
				'eliminar' => $documento_id,
				'volver'   => self::volver( $volver ),
			),
			admin_url( 'admin.php' )
		);
	}

	/** Si en esta pantalla se ofrece "Eliminar" para el documento (no publicado y no Modelo 347). */
	public static function se_puede_eliminar( Documento $documento ): bool {
		return null === Documento_Eliminacion_Service::motivo_no_eliminable( $documento );
	}

	private static function volver( $volver ): string {
		return self::VOLVER_PENDIENTES === $volver ? self::VOLVER_PENDIENTES : self::VOLVER_DOCUMENTOS;
	}

	private static function render_confirmar_eliminar( int $documento_id, string $volver ): void {
		$documento = ( new Documento_Repository() )->find_by_id( $documento_id );
		$motivo    = Documento_Eliminacion_Service::motivo_no_eliminable( $documento );
		$farmacia  = $documento ? ( new Farmacia_Repository() )->find_by_id( $documento->get_farmacia_id() ) : null;
		$que       = $documento && 'factura' === $documento->get_tipo_documento() ? 'la factura' : 'el documento';
		$accion    = self::ACCION_ELIMINAR;
		$url_volver = self::url_volver( $volver );

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-eliminar-documento.php';
	}

	private static function url_volver( string $volver ): string {
		return admin_url( 'admin.php?page=' . ( self::VOLVER_PENDIENTES === $volver ? Admin_Publicacion_Documentos::MENU_SLUG : self::MENU_SLUG ) );
	}

	/**
	 * Eliminar un documento no publicado (v0.28.0). Capacidad de Documentos MDF y
	 * nonce por documento; la logica (transaccion, fichero, evento) vive en
	 * Documento_Eliminacion_Service. Vuelve a la pantalla de la que se vino,
	 * con su aviso.
	 */
	public static function gestionar_eliminar(): void {
		if ( ! current_user_can( self::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}

		$id     = isset( $_POST['documento_id'] ) ? (int) $_POST['documento_id'] : 0;
		$volver = self::volver( isset( $_POST['volver'] ) ? sanitize_key( wp_unslash( $_POST['volver'] ) ) : '' );

		check_admin_referer( self::ACCION_ELIMINAR . '_' . $id );

		$resultado = ( new Documento_Eliminacion_Service() )->eliminar( $id, get_current_user_id() );

		if ( is_wp_error( $resultado ) ) {
			$tipo    = 'error';
			$mensaje = $resultado->get_error_message();
		} elseif ( ! $resultado['fichero_estaba'] ) {
			$tipo    = 'warning';
			$mensaje = sprintf( 'Documento «%s» eliminado, pero su fichero ya no estaba en la carpeta privada del servidor. Se ha borrado igualmente el registro; avisa a desarrollo por si falta algún otro fichero.', $resultado['nombre'] );
		} elseif ( ! $resultado['fichero_borrado'] ) {
			$tipo    = 'warning';
			$mensaje = sprintf( 'Documento «%s» eliminado, pero su fichero no se ha podido borrar del servidor (sigue en la carpeta privada, inaccesible). Avisa a desarrollo.', $resultado['nombre'] );
		} else {
			$tipo    = 'success';
			$mensaje = sprintf( 'Documento «%s» eliminado. Si era una factura, ya se puede volver a subir.', $resultado['nombre'] );
		}

		if ( self::VOLVER_PENDIENTES === $volver ) {
			Admin_Publicacion_Documentos::avisar( $tipo, $mensaje );
		} else {
			self::guardar_aviso( $tipo, $mensaje );
		}

		wp_safe_redirect( self::url_volver( $volver ) );
		exit;
	}

	/**
	 * Poner o quitar la marca de agua de un documento (#321), desde Documentos
	 * MDF o Pendientes de publicar. Capacidad de documentos y nonce por
	 * documento; vuelve a la misma pantalla (con sus filtros) con su aviso.
	 */
	public static function gestionar_cambiar_marca(): void {
		self::gestionar_cambio_configuracion( self::ACCION_MARCA );
	}

	public static function gestionar_cambiar_descarga(): void {
		self::gestionar_cambio_configuracion( self::ACCION_DESCARGA );
	}

	/**
	 * Poner o quitar la marca (#321) o activar o desactivar la descarga
	 * (v0.36.0) de un documento: capacidad, nonce por documento y accion, y
	 * Documento_Configuracion_Service. Vuelve a la pantalla de origen.
	 */
	private static function gestionar_cambio_configuracion( string $accion ): void {
		if ( ! current_user_can( self::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}

		$id     = isset( $_POST['documento_id'] ) ? (int) $_POST['documento_id'] : 0;
		$volver = self::volver( isset( $_POST['volver'] ) ? sanitize_key( wp_unslash( $_POST['volver'] ) ) : '' );

		check_admin_referer( $accion . '_' . $id );

		$servicio = new Documento_Configuracion_Service();

		if ( self::ACCION_MARCA === $accion ) {
			$sin_marca = isset( $_POST['sin_marca'] ) && '1' === $_POST['sin_marca'];
			$resultado = $servicio->cambiar_marca( $id, $sin_marca, get_current_user_id() );
			$textos    = array(
				sprintf( '«%%s» ya estaba %s marca de agua: no se ha cambiado nada.', $sin_marca ? 'sin' : 'con' ),
				$sin_marca ? 'Marca de agua quitada de «%s»: la farmacia lo verá sin marca.' : 'Marca de agua puesta en «%s»: la farmacia lo verá con su CIF y nombre.',
			);
		} else {
			$descargable = isset( $_POST['descargable'] ) && '1' === $_POST['descargable'];
			$resultado   = $servicio->cambiar_descarga( $id, $descargable, get_current_user_id() );
			$textos      = array(
				sprintf( '«%%s» ya %s descargable: no se ha cambiado nada.', $descargable ? 'era' : 'no era' ),
				$descargable ? 'Descarga activada en «%s»: la farmacia puede descargarlo.' : 'Descarga desactivada en «%s»: la farmacia solo puede verlo en el visor.',
			);
		}

		if ( is_wp_error( $resultado ) ) {
			$tipo    = 'error';
			$mensaje = $resultado->get_error_message();
		} else {
			$tipo    = $resultado['cambiado'] ? 'success' : 'warning';
			$mensaje = sprintf( $resultado['cambiado'] ? $textos[1] : $textos[0], $resultado['nombre'] );
		}

		if ( self::VOLVER_PENDIENTES === $volver ) {
			Admin_Publicacion_Documentos::avisar( $tipo, $mensaje );
		} else {
			self::guardar_aviso( $tipo, $mensaje );
		}

		wp_safe_redirect( wp_get_referer() ?: self::url_volver( $volver ) );
		exit;
	}

	private static function redirigir(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * Mismo mecanismo de aviso por transient de usuario que Admin_Planes
	 * (ver su cabecera): evita reenvio de formulario y no expone el
	 * mensaje en la URL.
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
