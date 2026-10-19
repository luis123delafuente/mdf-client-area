<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documentos MDF › Bandeja de facturas (#335): las facturas que "Subir
 * facturas" no ha podido asignar. Con el numero de pendientes en el menu.
 *
 * - Capacidad de Documentos MDF (Admin_Documentos::CAPACIDAD) en la pantalla
 *   y en cada accion, y un nonce por accion (por factura cuando aplica).
 * - Ver: vista previa de administrador (visor con marca de agua, como en
 *   Pendientes). El fichero se sirve por admin-ajax con un token de un solo
 *   uso de ambito "bandeja": sin sesion, sin capacidad, sin token o pedido
 *   como pagina, el mismo 404 que los documentos. Por admin-ajax y no por
 *   admin-post, porque Role_Restrictions redirige a un cliente que entra en
 *   admin-post y aqui tiene que recibir el 404.
 * - Asignar (pantalla propia con el selector de farmacia), Descartar
 *   (confirmacion), Volver a comprobar todas y el plazo de borrado
 *   automatico: la logica vive en Bandeja_Facturas_Service.
 * - Los avisos van por transient del usuario, sin valores en la URL.
 */
class Admin_Bandeja_Facturas {

	public const MENU_SLUG = 'mdf-ca-bandeja-facturas';

	private const PARENT_SLUG = 'mdf-ca-documentos';

	private const ACCION_ASIGNAR     = 'mdf_ca_bandeja_asignar';
	private const ACCION_DESCARTAR   = 'mdf_ca_bandeja_descartar';
	private const ACCION_RECOMPROBAR = 'mdf_ca_bandeja_recomprobar';
	private const ACCION_AJUSTES     = 'mdf_ca_bandeja_ajustes';
	private const ACCION_VER         = 'mdf_ca_bandeja_ver';
	private const ACCION_FICHERO     = 'mdf_ca_bandeja_fichero';

	/** Ambito del token de la vista previa: un id de la bandeja nunca vale como id de documento. */
	private const AMBITO_TOKEN = 'bandeja';

	private const NOTICE_KEY_PREFIX = 'mdf_ca_bandeja_notice_';

	/**
	 * Lo escrito en "Asignar" cuando el servidor devuelve un error (CIF
	 * distinto, datos no validos...), para no tener que repetirlo. Va en un
	 * transient del usuario y de la factura, no en la URL: serie, numero y
	 * fecha no deben quedar en los registros del servidor ni en el historial.
	 */
	private const FORMULARIO_KEY_PREFIX = 'mdf_ca_bandeja_form_';

	private const FORMULARIO_TTL = 5 * MINUTE_IN_SECONDS;

	/** Motivo de cada factura en lenguaje claro (el mismo texto que el resumen de "Subir facturas"). */
	public const MOTIVOS = array(
		Factura_Cruce_Service::SIN_TEXTO          => 'El PDF no tiene texto (puede ser un escaneo).',
		Factura_Cruce_Service::FORMATO_INESPERADO => 'No parece una factura de SAGE de Mediformplus o de Formación.',
		Factura_Cruce_Service::NUMERO_DISCREPANTE => 'El número de factura no coincide con el nombre del fichero.',
		Factura_Cruce_Service::SIN_CIF            => 'No se encuentra el CIF del cliente.',
		Factura_Cruce_Service::CIF_INVALIDO       => 'El CIF del cliente no es válido.',
		Factura_Cruce_Service::CIF_SIN_FARMACIA   => 'El CIF no corresponde a ninguna farmacia del portal.',
	);

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Documentos en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
		add_action( 'admin_post_' . self::ACCION_ASIGNAR, array( __CLASS__, 'gestionar_asignar' ) );
		add_action( 'admin_post_' . self::ACCION_DESCARTAR, array( __CLASS__, 'gestionar_descartar' ) );
		add_action( 'admin_post_' . self::ACCION_RECOMPROBAR, array( __CLASS__, 'gestionar_recomprobar' ) );
		add_action( 'admin_post_' . self::ACCION_AJUSTES, array( __CLASS__, 'gestionar_ajustes' ) );
		add_action( 'admin_post_' . self::ACCION_VER, array( __CLASS__, 'gestionar_ver' ) );
		add_action( 'wp_ajax_' . self::ACCION_FICHERO, array( __CLASS__, 'servir_fichero' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACCION_FICHERO, array( Documento_Endpoint::class, 'responder_no_encontrado' ) );
	}

	public static function registrar_menu(): void {
		$pendientes = current_user_can( Admin_Documentos::CAPACIDAD ) ? ( new Bandeja_Facturas_Service() )->contar() : 0;
		$titulo     = 'Bandeja de facturas';

		if ( $pendientes > 0 ) {
			$titulo .= sprintf( ' <span class="awaiting-mod count-%1$d"><span class="pending-count" aria-hidden="true">%1$d</span><span class="screen-reader-text">%2$s</span></span>', $pendientes, esc_html( sprintf( '%d pendientes', $pendientes ) ) );
		}

		add_submenu_page( self::PARENT_SLUG, 'Bandeja de facturas', $titulo, Admin_Documentos::CAPACIDAD, self::MENU_SLUG, array( __CLASS__, 'render_pagina' ) );
	}

	public static function encolar_assets( string $hook_suffix ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo decide que encolar.
		if ( str_ends_with( $hook_suffix, '_page_' . self::MENU_SLUG ) && isset( $_GET['asignar'] ) ) {
			Admin_Documentos::encolar_selector_farmacia();
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function url_ver( int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACCION_VER,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACCION_VER . '_' . $id
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( Admin_Documentos::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$servicio = new Bandeja_Facturas_Service();
		$aviso    = self::consumir_aviso();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- pasos de solo lectura; las acciones son POST con nonce.
		if ( isset( $_GET['asignar'] ) ) {
			$fila             = ( new Bandeja_Facturas_Repository() )->find_by_id( (int) $_GET['asignar'] );
			$farmacias        = self::farmacias_ordenadas();
			$farmacia_elegida = isset( $_GET['farmacia'] ) ? ( new Farmacia_Repository() )->find_by_id( (int) $_GET['farmacia'] ) : null;
			$cif_distinto     = $fila && $farmacia_elegida && Bandeja_Facturas_Service::cif_distinto( $fila, $farmacia_elegida );
			$previo           = $fila ? self::consumir_formulario( (int) $fila['id'] ) : self::FORMULARIO_VACIO;
			$accion           = self::ACCION_ASIGNAR;

			require MDF_CA_PLUGIN_DIR . 'includes/views/admin-bandeja-asignar.php';
			return;
		}

		if ( isset( $_GET['descartar'] ) ) {
			$fila   = ( new Bandeja_Facturas_Repository() )->find_by_id( (int) $_GET['descartar'] );
			$accion = self::ACCION_DESCARTAR;

			require MDF_CA_PLUGIN_DIR . 'includes/views/admin-bandeja-descartar.php';
			return;
		}
		// phpcs:enable

		$filas          = ( new Bandeja_Facturas_Repository() )->listar( 500 );
		$total          = $servicio->contar();
		$caducan_pronto = $servicio->contar_caducan_pronto();
		$dias           = Bandeja_Facturas_Service::dias();
		$acciones       = array(
			'recomprobar' => self::ACCION_RECOMPROBAR,
			'ajustes'     => self::ACCION_AJUSTES,
		);

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-bandeja-facturas.php';
	}

	/** "Mediformplus A 123 · 15/09/2026 · CIF leído B1234567 · Abono", o un guion si no se leyo nada. */
	public static function datos_leidos( array $fila ): string {
		$partes = array();

		if ( null !== $fila['emisor_cif'] || null !== $fila['serie'] || null !== $fila['numero'] ) {
			$partes[] = trim( implode( ' ', array_filter( array( null !== $fila['emisor_cif'] ? Factura_Emisores::nombre_corto( (string) $fila['emisor_cif'] ) : null, $fila['serie'], $fila['numero'] ) ) ) );
		}

		if ( null !== $fila['fecha_documento'] ) {
			$partes[] = Documento::formatear_fecha_corta( (string) $fila['fecha_documento'] );
		}

		if ( null !== $fila['cif_leido'] ) {
			$partes[] = 'CIF leído ' . $fila['cif_leido'];
		}

		if ( null !== $fila['es_abono'] && (int) $fila['es_abono'] ) {
			$partes[] = 'Abono';
		}

		return $partes ? implode( ' · ', $partes ) : '—';
	}

	// ------------------------------------------------------------------
	// Vista previa
	// ------------------------------------------------------------------

	public static function gestionar_ver(): void {
		$id = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0;

		if ( ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', self::ACCION_VER . '_' . $id ) ) {
			Documento_Endpoint::responder_no_encontrado();
		}

		$usuario = wp_get_current_user();
		$fila    = ( new Bandeja_Facturas_Repository() )->find_by_id( $id );

		if ( ! Permissions::puede_gestionar_bandeja( $usuario, $fila ) ) {
			Documento_Endpoint::responder_no_encontrado();
		}

		Documento_Visor::renderizar_vista_previa(
			add_query_arg(
				array(
					'action' => self::ACCION_FICHERO,
					'id'     => $id,
				),
				admin_url( 'admin-ajax.php' )
			),
			Documento_Token::emitir( (int) $usuario->ID, $id, self::AMBITO_TOKEN ),
			sprintf( 'BANDEJA - %s - %s', $usuario->user_login, wp_date( 'd/m/Y H:i' ) )
		);
		exit;
	}

	/** Bytes del PDF para el visor: capacidad y token de un solo uso; cualquier otro caso, 404. */
	public static function servir_fichero(): void {
		$id       = isset( $_GET['id'] ) ? (int) $_GET['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- el token es la verificacion.
		$usuario  = wp_get_current_user();
		$servicio = new Bandeja_Facturas_Service();
		$fila     = ( new Bandeja_Facturas_Repository() )->find_by_id( $id );
		$destino  = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( (string) $_SERVER['HTTP_SEC_FETCH_DEST'] ) : '';
		$token    = isset( $_GET[ Documento_Token::PARAMETRO ] ) ? sanitize_text_field( wp_unslash( $_GET[ Documento_Token::PARAMETRO ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$ruta     = $fila ? $servicio->ruta_fichero( $fila ) : null;

		if ( ! Permissions::puede_gestionar_bandeja( $usuario, $fila ) || 'document' === $destino || null === $ruta
			|| ! Documento_Token::consumir( $token, (int) $usuario->ID, $id, self::AMBITO_TOKEN ) ) {
			Documento_Endpoint::responder_no_encontrado();
		}

		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: inline' );
		header( 'Content-Length: ' . filesize( $ruta ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	// ------------------------------------------------------------------
	// Acciones
	// ------------------------------------------------------------------

	public static function gestionar_asignar(): void {
		self::exigir_capacidad();

		$id          = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$farmacia_id = isset( $_POST['farmacia_id'] ) ? (int) $_POST['farmacia_id'] : 0;

		check_admin_referer( self::ACCION_ASIGNAR . '_' . $id );

		$formulario = array(
			'emisor'   => isset( $_POST['emisor'] ) ? sanitize_text_field( wp_unslash( $_POST['emisor'] ) ) : '',
			'serie'    => isset( $_POST['serie'] ) ? sanitize_text_field( wp_unslash( $_POST['serie'] ) ) : '',
			'numero'   => isset( $_POST['numero'] ) ? sanitize_text_field( wp_unslash( $_POST['numero'] ) ) : '',
			'fecha'    => isset( $_POST['fecha'] ) ? sanitize_text_field( wp_unslash( $_POST['fecha'] ) ) : '',
			'es_abono' => ! empty( $_POST['es_abono'] ),
		);

		$resultado = ( new Bandeja_Facturas_Service() )->asignar( $id, $farmacia_id, $formulario, ! empty( $_POST['confirmar_cif'] ), get_current_user_id() );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );

			if ( 'mdf_ca_bandeja_no_existe' !== $resultado->get_error_code() ) {
				self::guardar_formulario( $id, $formulario );
			}

			$args = array( 'asignar' => $id );

			if ( $farmacia_id > 0 ) {
				$args['farmacia'] = $farmacia_id;
			}

			self::redirigir( 'mdf_ca_bandeja_no_existe' === $resultado->get_error_code() ? array() : $args );
		}

		$farmacia = ( new Farmacia_Repository() )->find_by_id( $farmacia_id );
		$nombre   = $farmacia ? $farmacia->get_nombre() : '';

		self::guardar_aviso(
			$resultado['ya_existia'] ? 'warning' : 'success',
			$resultado['ya_existia']
				? sprintf( '«%s» ya estaba subida para %s: no se ha duplicado y se ha quitado de la bandeja.', $resultado['documento']->get_nombre(), $nombre )
				: sprintf( '«%s» asignada a %s. Queda pendiente de publicar.', $resultado['documento']->get_nombre(), $nombre )
		);

		self::redirigir();
	}

	public static function gestionar_descartar(): void {
		self::exigir_capacidad();

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;

		check_admin_referer( self::ACCION_DESCARTAR . '_' . $id );

		$ok = ( new Bandeja_Facturas_Service() )->descartar( $id, get_current_user_id() );

		self::guardar_aviso( $ok ? 'success' : 'warning', $ok ? 'Factura descartada: se han borrado el fichero y su registro en la bandeja.' : 'La factura ya no estaba en la bandeja.' );
		self::redirigir();
	}

	public static function gestionar_recomprobar(): void {
		self::exigir_capacidad();
		check_admin_referer( self::ACCION_RECOMPROBAR );

		$r = ( new Bandeja_Facturas_Service() )->recomprobar_todas( get_current_user_id() );

		$mensaje = sprintf(
			'Comprobadas de nuevo: %d %s a Pendientes de publicar, %d ya %s subidas (se quitan de la bandeja) y %d siguen en la bandeja.',
			$r['asignadas'],
			1 === $r['asignadas'] ? 'ha pasado' : 'han pasado',
			$r['ya_existian'],
			1 === $r['ya_existian'] ? 'estaba' : 'estaban',
			$r['siguen']
		);

		if ( $r['quedan'] > 0 ) {
			$mensaje .= sprintf( ' Quedan %d por comprobar: vuelve a pulsar «Volver a comprobar todas».', $r['quedan'] );
		}

		self::guardar_aviso( $r['asignadas'] > 0 ? 'success' : 'info', $mensaje );
		self::redirigir();
	}

	public static function gestionar_ajustes(): void {
		self::exigir_capacidad();
		check_admin_referer( self::ACCION_AJUSTES );

		$dias = isset( $_POST['dias'] ) ? (int) $_POST['dias'] : 0;

		if ( Bandeja_Facturas_Service::set_dias( $dias ) ) {
			self::guardar_aviso( 'success', sprintf( 'Guardado: lo que lleve más de %d días en la bandeja se borrará solo.', $dias ) );
		} else {
			self::guardar_aviso( 'error', sprintf( 'El plazo tiene que estar entre %d y %d días. No se ha cambiado.', Bandeja_Facturas_Service::DIAS_MINIMO, Bandeja_Facturas_Service::DIAS_MAXIMO ) );
		}

		self::redirigir();
	}

	private static function exigir_capacidad(): void {
		if ( ! current_user_can( Admin_Documentos::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}
	}

	/** @return Farmacia[] Por nombre. */
	private static function farmacias_ordenadas(): array {
		$farmacias = ( new Farmacia_Repository() )->find_all();
		usort( $farmacias, static fn( Farmacia $a, Farmacia $b ): int => strcasecmp( $a->get_nombre(), $b->get_nombre() ) );

		return $farmacias;
	}

	/** @param array<string, int> $args */
	private static function redirigir( array $args = array() ): void {
		wp_safe_redirect( self::url( $args ) );
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

	/** Campos del formulario de "Asignar" sin rellenar. */
	private const FORMULARIO_VACIO = array(
		'emisor'   => '',
		'serie'    => '',
		'numero'   => '',
		'fecha'    => '',
		'es_abono' => false,
	);

	/** @param array{emisor: string, serie: string, numero: string, fecha: string, es_abono: bool} $formulario */
	private static function guardar_formulario( int $id, array $formulario ): void {
		set_transient( self::FORMULARIO_KEY_PREFIX . get_current_user_id() . '_' . $id, $formulario, self::FORMULARIO_TTL );
	}

	/**
	 * Lo escrito en el ultimo intento de asignar esta factura, una sola vez.
	 *
	 * @return array{emisor: string, serie: string, numero: string, fecha: string, es_abono: bool}
	 */
	private static function consumir_formulario( int $id ): array {
		$key        = self::FORMULARIO_KEY_PREFIX . get_current_user_id() . '_' . $id;
		$formulario = get_transient( $key );

		if ( ! is_array( $formulario ) ) {
			return self::FORMULARIO_VACIO;
		}

		delete_transient( $key );

		$previo = self::FORMULARIO_VACIO;

		foreach ( array( 'emisor', 'serie', 'numero', 'fecha' ) as $campo ) {
			$previo[ $campo ] = isset( $formulario[ $campo ] ) && is_string( $formulario[ $campo ] ) ? $formulario[ $campo ] : '';
		}

		$previo['es_abono'] = ! empty( $formulario['es_abono'] );

		return $previo;
	}

	/** @return array{tipo: string, mensaje: string}|null */
	private static function consumir_aviso(): ?array {
		$key   = self::NOTICE_KEY_PREFIX . get_current_user_id();
		$aviso = get_transient( $key );

		if ( ! is_array( $aviso ) ) {
			return null;
		}

		delete_transient( $key );

		return $aviso;
	}
}
