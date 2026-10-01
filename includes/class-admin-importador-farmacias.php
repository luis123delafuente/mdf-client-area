<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla de backoffice "Importar CSV" (Fase 4), submenu de Farmacias MDF.
 * Mismo patron que Admin_Planes y Admin_Documentos: capacidad
 * 'manage_options' (mdf_cliente nunca la tiene, y Role_Restrictions ya lo
 * saca de todo wp-admin antes de llegar aqui), admin-post.php con nonce en
 * cada accion, avisos por transient de usuario.
 *
 * Toda la logica vive fuera: Farmacia_Csv_Parser lee el fichero y
 * Farmacia_Import_Service decide y aplica. Este controlador solo encadena
 * los dos pasos:
 *
 * 1. Simular: se parsea el CSV y las FILAS leidas (no el fichero, que nunca
 *    se copia a disco) se guardan en un transient ligado al usuario
 *    (mdf_ca_import_{user_id}_{id aleatorio}, 15 min). El formulario solo
 *    lleva ese id opaco y la huella de la simulacion que se ha pintado.
 * 2. Confirmar: el transient se consume (borrado atomico, como
 *    Documento_Token: un doble envio no aplica dos veces), el servicio
 *    vuelve a simular contra la BD actual y solo aplica si la huella
 *    coincide con lo que el administrador vio. Si no coincide, o falla la
 *    escritura, el transient se repone y se vuelve a la simulacion.
 *
 * Nunca crea usuarios ni envia invitaciones: eso sigue en Admin_Invitaciones.
 */
class Admin_Importador_Farmacias {

	private const MENU_SLUG         = 'mdf-ca-importar-farmacias';
	private const PARENT_SLUG       = 'mdf-ca-farmacias';
	private const NONCE_SIMULAR     = 'mdf_ca_simular_importacion';
	private const NONCE_CONFIRMAR   = 'mdf_ca_confirmar_importacion';
	private const NONCE_CANCELAR    = 'mdf_ca_cancelar_importacion';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_importador_notice_';
	private const LOTE_KEY_PREFIX   = 'mdf_ca_import_';
	private const LOTE_TTL          = 15 * MINUTE_IN_SECONDS;

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Farmacias en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_post_mdf_ca_simular_importacion', array( __CLASS__, 'gestionar_simular' ) );
		add_action( 'admin_post_mdf_ca_confirmar_importacion', array( __CLASS__, 'gestionar_confirmar' ) );
		add_action( 'admin_post_mdf_ca_cancelar_importacion', array( __CLASS__, 'gestionar_cancelar' ) );
	}

	public static function registrar_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			'Importar farmacias desde CSV',
			'Importar CSV',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' )
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$aviso      = self::consumir_aviso();
		$lote_id    = self::lote_id_de( $_GET['importacion'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- solo lectura; el lote esta ligado al usuario actual.
		$lote       = $lote_id ? self::leer_lote( $lote_id ) : null;
		$resultados = null;
		$huella     = null;

		if ( $lote_id && ! $lote && ! $aviso ) {
			$aviso = array(
				'tipo'    => 'error',
				'mensaje' => 'La simulacion ha caducado o ya se proceso. Vuelve a subir el fichero.',
			);
		}

		if ( $lote ) {
			$resultados = ( new Farmacia_Import_Service() )->simular( $lote['filas'] );
			$huella     = Farmacia_Import_Service::huella( $resultados );
		}

		$max_filas  = Farmacia_Csv_Parser::MAX_FILAS;
		$max_tamano = size_format( Farmacia_Csv_Parser::TAMANO_MAXIMO_BYTES );

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-importador-farmacias.php';
	}

	public static function gestionar_simular(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_SIMULAR );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- $_FILES no se sanea con wp_unslash/sanitize_*, Farmacia_Csv_Parser valida cada campo antes de usarlo.
		$archivo = isset( $_FILES['csv'] ) ? $_FILES['csv'] : null;

		$filas = ( new Farmacia_Csv_Parser() )->parsear( $archivo );

		if ( is_wp_error( $filas ) ) {
			self::guardar_aviso( 'error', $filas->get_error_message() );
			self::redirigir();
		}

		$lote_id = wp_generate_password( 20, false, false );

		self::guardar_lote(
			$lote_id,
			array(
				'filas'   => $filas,
				'fichero' => sanitize_file_name( (string) $archivo['name'] ),
			)
		);

		self::redirigir( array( 'importacion' => $lote_id ) );
	}

	public static function gestionar_confirmar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_CONFIRMAR );

		$lote_id = self::lote_id_de( $_POST['importacion'] ?? '' );
		$huella  = isset( $_POST['huella'] ) ? sanitize_text_field( wp_unslash( $_POST['huella'] ) ) : '';
		$lote    = $lote_id ? self::consumir_lote( $lote_id ) : null;

		if ( ! $lote ) {
			self::guardar_aviso( 'error', 'La simulacion ha caducado o ya se proceso. Vuelve a subir el fichero.' );
			self::redirigir();
		}

		$resultado = ( new Farmacia_Import_Service() )->aplicar( $lote['filas'], $huella );

		if ( is_wp_error( $resultado ) ) {
			// Nada se ha escrito (rollback o ni siquiera se empezo): se
			// repone el lote para poder revisar y reintentar.
			self::guardar_lote( $lote_id, $lote );
			self::guardar_aviso(
				'mdf_ca_import_simulacion_cambiada' === $resultado->get_error_code() ? 'warning' : 'error',
				$resultado->get_error_message()
			);
			self::redirigir( array( 'importacion' => $lote_id ) );
		}

		self::guardar_aviso(
			'success',
			sprintf(
				'Importacion aplicada: %d creadas, %d actualizadas, %d sin cambios, %d rechazadas. No se ha enviado ninguna invitacion.',
				$resultado['creadas'],
				$resultado['actualizadas'],
				$resultado['sin_cambios'],
				$resultado['rechazadas']
			)
		);

		self::redirigir();
	}

	public static function gestionar_cancelar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_CANCELAR );

		$lote_id = self::lote_id_de( $_POST['importacion'] ?? '' );

		if ( $lote_id ) {
			self::consumir_lote( $lote_id );
		}

		self::guardar_aviso( 'success', 'Importacion cancelada. No se ha aplicado nada.' );
		self::redirigir();
	}

	/**
	 * Id de lote saneado, o '' si no tiene la forma que genera
	 * gestionar_simular() (20 alfanumericos).
	 *
	 * @param mixed $valor
	 */
	private static function lote_id_de( $valor ): string {
		$valor = is_string( $valor ) ? wp_unslash( $valor ) : '';

		return 1 === preg_match( '/^[A-Za-z0-9]{20}$/', $valor ) ? $valor : '';
	}

	/**
	 * La clave incluye el usuario actual: un id de lote ajeno nunca
	 * resuelve, aunque se conozca.
	 */
	private static function clave_lote( string $lote_id ): string {
		return self::LOTE_KEY_PREFIX . get_current_user_id() . '_' . $lote_id;
	}

	/**
	 * @param array{filas: array<int, array<string, mixed>>, fichero: string} $lote
	 */
	private static function guardar_lote( string $lote_id, array $lote ): void {
		set_transient( self::clave_lote( $lote_id ), $lote, self::LOTE_TTL );
	}

	/**
	 * @return array{filas: array<int, array{linea: int, cif: string, nombre: string, plan: string}>, fichero: string}|null
	 */
	private static function leer_lote( string $lote_id ): ?array {
		$lote = get_transient( self::clave_lote( $lote_id ) );

		return is_array( $lote ) && isset( $lote['filas'] ) ? $lote : null;
	}

	/**
	 * Lee y borra. Solo devuelve el lote quien consigue borrarlo: de dos
	 * confirmaciones simultaneas, solo una llega a aplicar.
	 *
	 * @return array{filas: array<int, array{linea: int, cif: string, nombre: string, plan: string}>, fichero: string}|null
	 */
	private static function consumir_lote( string $lote_id ): ?array {
		$lote = self::leer_lote( $lote_id );

		if ( ! $lote || ! delete_transient( self::clave_lote( $lote_id ) ) ) {
			return null;
		}

		return $lote;
	}

	/**
	 * @param array<string, string> $args_extra
	 */
	private static function redirigir( array $args_extra = array() ): void {
		$url = add_query_arg( $args_extra, admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Mismo mecanismo de aviso por transient de usuario que el resto de
	 * pantallas de backoffice del plugin.
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
