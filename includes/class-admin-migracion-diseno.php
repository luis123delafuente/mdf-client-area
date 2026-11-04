<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Herramientas > Migracion del diseno (v0.45.0, TEMPORAL). Lanza desde el
 * escritorio los pasos de Migracion_Diseno, para instalaciones sin SSH. Solo
 * el administrador (Roles::CAP_ADMINISTRAR), admin-post con nonce por paso y
 * casilla de confirmacion en los que tocan las paginas reales. Solo aparece
 * si el paquete (migracion-diseno/datos/) esta desplegado.
 *
 * Se quita (esta clase, su vista y la carpeta migracion-diseno/) cuando el
 * diseno este en produccion: el estado queda en la option y el script de
 * WP-CLI del paquete sigue pudiendo deshacer.
 */
class Admin_Migracion_Diseno {

	public const MENU_SLUG = 'mdf-ca-migracion-diseno';

	private const ACCION            = 'mdf_ca_migracion_diseno';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_migracion_notice_';
	private const PASOS             = array( 'revision', 'aplicar', 'deshacer', 'limpiar' );

	public static function register_hooks(): void {
		if ( ! is_dir( Migracion_Diseno::dir_datos_plugin() ) ) {
			return;
		}

		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_post_' . self::ACCION, array( __CLASS__, 'gestionar' ) );
	}

	public static function registrar_menu(): void {
		add_management_page( 'Migración del diseño', 'Migración del diseño', Roles::CAP_ADMINISTRAR, self::MENU_SLUG, array( __CLASS__, 'render_pagina' ) );
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( Roles::CAP_ADMINISTRAR ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$aviso       = self::consumir_aviso();
		$error       = null;
		$comprobacion = null;

		try {
			$comprobacion = ( new Migracion_Diseno( Migracion_Diseno::dir_datos_plugin() ) )->comprobar();
		} catch ( \RuntimeException $e ) {
			$error = $e->getMessage();
		}

		$accion = self::ACCION;

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-migracion-diseno.php';
	}

	public static function gestionar(): void {
		if ( ! current_user_can( Roles::CAP_ADMINISTRAR ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}

		$paso = isset( $_POST['paso'] ) ? sanitize_key( wp_unslash( $_POST['paso'] ) ) : '';

		if ( ! in_array( $paso, self::PASOS, true ) ) {
			wp_die( 'Paso no valido.', '', array( 'response' => 400 ) );
		}

		check_admin_referer( self::ACCION . '_' . $paso );

		// Aplicar y deshacer cambian lo que ven las farmacias: casilla obligatoria.
		if ( in_array( $paso, array( 'aplicar', 'deshacer' ), true ) && empty( $_POST['confirmar'] ) ) {
			self::guardar_aviso( 'error', 'Marca la casilla de confirmación. No se ha cambiado nada.', array() );
			self::volver();
		}

		try {
			$migracion = new Migracion_Diseno( Migracion_Diseno::dir_datos_plugin() );

			switch ( $paso ) {
				case 'revision':
					$migracion->revision( ! empty( $_POST['sustituir'] ) );
					$mensaje = 'Copias de revisión listas, en borrador. Ábrelas con «Vista previa» y revísalas. Las páginas reales no se han tocado.';
					break;
				case 'aplicar':
					$migracion->aplicar();
					$mensaje = 'Diseño aplicado a las páginas reales. Pruébalo con una cuenta de farmacia; si algo va mal, «Deshacer».';
					break;
				case 'deshacer':
					$migracion->deshacer();
					$mensaje = 'Páginas reales devueltas a como estaban. Las copias de revisión siguen en borrador.';
					break;
				default:
					$migracion->limpiar();
					$mensaje = 'Copias de revisión en la papelera.';
			}

			self::guardar_aviso( 'success', $mensaje, $migracion->registro() );
		} catch ( \RuntimeException $e ) {
			self::guardar_aviso( 'error', $e->getMessage(), isset( $migracion ) ? $migracion->registro() : array() );
		}

		self::volver();
	}

	private static function volver(): void {
		wp_safe_redirect( admin_url( 'tools.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/** @param string[] $detalles */
	private static function guardar_aviso( string $tipo, string $mensaje, array $detalles ): void {
		set_transient(
			self::NOTICE_KEY_PREFIX . get_current_user_id(),
			array(
				'tipo'     => $tipo,
				'mensaje'  => $mensaje,
				'detalles' => $detalles,
			),
			60
		);
	}

	/** @return array{tipo: string, mensaje: string, detalles: string[]}|null */
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
