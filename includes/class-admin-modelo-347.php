<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Documentos MDF > Modelo 347" (#307): generador de cartas desde el
 * Excel de SAGE, publicacion con envio por correo y ajustes. Solo
 * controlador: traduce peticiones a llamadas a los servicios Modelo_347_* y
 * pinta las vistas admin-modelo-347*.php.
 *
 * Acceso: capacidad propia Roles::CAP_GESTIONAR_347 en TODA accion (pantalla,
 * admin-post y AJAX), mas un nonce propio por accion (por documento o por
 * envio cuando la accion es sobre uno). Un cliente nunca llega aqui
 * (Role_Restrictions lo saca de wp-admin) y sin sesion admin-post/AJAX
 * rechazan antes. Las descargas (vista previa y ZIP) son GET con nonce en la
 * URL, servidas por PHP con no-store y sin escribir nada en disco.
 */
class Admin_Modelo_347 {

	public const MENU_SLUG          = 'mdf-ca-modelo-347';
	private const PARENT_SLUG       = 'mdf-ca-documentos';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_347_notice_';
	private const PUB_KEY_PREFIX    = 'mdf_ca_347_pub_';
	private const PUB_TTL           = 15 * MINUTE_IN_SECONDS;
	private const MAX_PREVIA        = 3;

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );

		foreach ( array( 'simular', 'cancelar', 'confirmar', 'vista_previa', 'zip', 'publicar_preparar', 'publicar_aplicar', 'publicar_cancelar', 'reenviar', 'reintentar', 'prueba', 'ajustes' ) as $accion ) {
			add_action( 'admin_post_mdf_ca_347_' . $accion, array( __CLASS__, 'gestionar_' . $accion ) );
		}

		add_action( 'wp_ajax_mdf_ca_347_generar_tanda', array( __CLASS__, 'ajax_generar_tanda' ) );
		add_action( 'wp_ajax_mdf_ca_347_enviar_tanda', array( __CLASS__, 'ajax_enviar_tanda' ) );
	}

	public static function registrar_menu(): void {
		add_submenu_page( self::PARENT_SLUG, 'Modelo 347', 'Modelo 347', Roles::CAP_GESTIONAR_347, self::MENU_SLUG, array( __CLASS__, 'render_pagina' ) );
	}

	public static function encolar_assets( string $hook_suffix ): void {
		if ( ! str_ends_with( $hook_suffix, '_page_' . self::MENU_SLUG ) ) {
			return;
		}

		Admin_Zona_Archivos::encolar();
		wp_enqueue_script( 'mdf-ca-admin-modelo-347', Recursos::url( 'assets/js/admin-modelo-347.js' ), array(), Recursos::version( 'assets/js/admin-modelo-347.js' ), true );
	}

	/** Toda accion empieza aqui: capacidad o fuera. */
	private static function exigir_capacidad(): void {
		if ( ! current_user_can( Roles::CAP_GESTIONAR_347 ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.', '', array( 'response' => 403 ) );
		}
	}

	// ------------------------------------------------------------------
	// Pantalla
	// ------------------------------------------------------------------

	public static function render_pagina(): void {
		self::exigir_capacidad();

		$usuario_id = get_current_user_id();
		$emisores   = Modelo_347_Carta::emisores_habilitados();
		$tab        = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'generar'; // phpcs:ignore WordPress.Security.NonceVerification -- solo lectura.
		$tab        = in_array( $tab, array( 'generar', 'envios', 'ajustes' ), true ) ? $tab : 'generar';
		$aviso      = self::consumir_aviso();
		$emisor     = self::emisor_de( $_GET['emisor'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$ejercicio  = self::ejercicio_de( $_GET['ejercicio'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$ejercicio  = $ejercicio ?? max( Documento_Service::EJERCICIO_MINIMO, (int) wp_date( 'Y' ) - 1 );
		$emisor     = $emisor ?? (string) array_key_first( $emisores );

		if ( 'ajustes' === $tab ) {
			$ajustes = Modelo_347_Ajustes::get( $emisor );
			require MDF_CA_PLUGIN_DIR . 'includes/views/admin-modelo-347-ajustes.php';

			return;
		}

		if ( 'envios' === $tab ) {
			self::render_envios( $emisores, $emisor, $ejercicio, $aviso );

			return;
		}

		// Generar: trabajo en curso, simulacion o formulario de subida.
		$job_id = isset( $_GET['job'] ) ? sanitize_key( wp_unslash( $_GET['job'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sim_id = isset( $_GET['sim'] ) ? sanitize_key( wp_unslash( $_GET['sim'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$sim    = '' !== $sim_id ? Modelo_347_Generacion_Service::leer_simulacion( $usuario_id, $sim_id ) : null;

		$simulacion = $sim ? ( new Modelo_347_Simulacion_Service() )->simular( $sim['filas'], $sim['emisor_cif'], (int) $sim['ejercicio'] ) : null;

		if ( $sim ) {
			$emisor    = $sim['emisor_cif'];
			$ejercicio = (int) $sim['ejercicio'];
		}

		$ajustes = Modelo_347_Ajustes::get( $emisor );

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-modelo-347.php';
	}

	/**
	 * @param array<string, string> $emisores
	 * @param array{tipo: string, mensaje: string}|null $aviso
	 */
	private static function render_envios( array $emisores, string $emisor, int $ejercicio, ?array $aviso ): void {
		$usuario_id  = get_current_user_id();
		$pub_id      = isset( $_GET['pub'] ) ? sanitize_key( wp_unslash( $_GET['pub'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$preparada   = '' !== $pub_id ? get_transient( self::PUB_KEY_PREFIX . $usuario_id . '_' . $pub_id ) : false;
		$preparada   = is_array( $preparada ) && $preparada['emisor'] === $emisor && (int) $preparada['ejercicio'] === $ejercicio ? $preparada : null;
		$calculo     = ( new Modelo_347_Publicacion_Service() )->calcular( $emisor, $ejercicio );
		$documentos  = ( new Documento_Repository() )->find_modelo_347_vigentes( $emisor, $ejercicio );
		$envios_repo = new Modelo_347_Envio_Repository();
		$envios      = $envios_repo->por_documentos( array_map( static fn( Documento $d ): int => $d->get_id(), $documentos ) );
		$recuentos   = $envios_repo->recuentos( $emisor, $ejercicio );
		$ajustes     = Modelo_347_Ajustes::get( $emisor );
		$farmacias   = array();

		foreach ( ( new Farmacia_Repository() )->find_all() as $farmacia ) {
			$farmacias[ $farmacia->get_id() ] = $farmacia;
		}

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-modelo-347-envios.php';
	}

	// ------------------------------------------------------------------
	// Generar
	// ------------------------------------------------------------------

	public static function gestionar_simular(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_simular' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- $_FILES se valida campo a campo en simular_subida().
		$archivo = isset( $_FILES['excel'] ) && is_array( $_FILES['excel'] ) ? $_FILES['excel'] : null;
		$tmp     = is_array( $archivo ) && isset( $archivo['tmp_name'] ) && is_string( $archivo['tmp_name'] ) ? $archivo['tmp_name'] : '';

		try {
			$resultado = self::simular_subida( $archivo, $tmp, $_POST['emisor'] ?? '', $_POST['ejercicio'] ?? '' );
		} finally {
			// El Excel nunca se queda en el servidor (redirigir() hace exit:
			// por eso se borra aqui, antes, y no en un finally que no llegaria).
			if ( '' !== $tmp && is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
			self::redirigir();
		}

		self::redirigir( array( 'sim' => $resultado ) );
	}

	/**
	 * Valida la subida, lee el Excel y guarda la simulacion.
	 *
	 * @param array<string, mixed>|null $archivo
	 * @return string|\WP_Error Id de la simulacion.
	 */
	private static function simular_subida( ?array $archivo, string $tmp, $emisor_bruto, $ejercicio_bruto ) {
		$emisor    = self::emisor_de( $emisor_bruto );
		$ejercicio = self::ejercicio_de( $ejercicio_bruto );

		if ( null === $emisor || null === $ejercicio ) {
			return new \WP_Error( 'mdf_ca_347_parametros', 'Selecciona una sociedad habilitada y un ejercicio válido.' );
		}

		if ( ! $archivo || UPLOAD_ERR_OK !== ( $archivo['error'] ?? UPLOAD_ERR_NO_FILE ) || '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			return new \WP_Error( 'mdf_ca_347_sin_fichero', 'Selecciona el Excel del Modelo 347 (.xlsx).' );
		}

		$tipo = wp_check_filetype_and_ext( $tmp, (string) ( $archivo['name'] ?? '' ), array( 'xlsx' => Xlsx_Validator::MIME ) );

		if ( 'xlsx' !== $tipo['ext'] ) {
			return new \WP_Error( 'mdf_ca_347_no_xlsx', 'El fichero no es un Excel (.xlsx).' );
		}

		$leido = ( new Modelo_347_Excel_Lector() )->leer( $tmp );

		if ( is_wp_error( $leido ) ) {
			return $leido;
		}

		if ( ! $leido['filas'] ) {
			return new \WP_Error( 'mdf_ca_347_sin_filas', 'La hoja «347» no tiene ninguna fila de datos.' );
		}

		return Modelo_347_Generacion_Service::guardar_simulacion( get_current_user_id(), $emisor, $ejercicio, $leido['filas'] );
	}

	public static function gestionar_cancelar(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_cancelar' );

		Modelo_347_Generacion_Service::borrar_simulacion( get_current_user_id(), sanitize_key( wp_unslash( $_POST['sim'] ?? '' ) ) );
		self::guardar_aviso( 'success', 'Simulación descartada. No se ha generado nada.' );
		self::redirigir();
	}

	public static function gestionar_confirmar(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_confirmar' );

		$resultado = ( new Modelo_347_Generacion_Service() )->confirmar(
			get_current_user_id(),
			sanitize_key( wp_unslash( $_POST['sim'] ?? '' ) ),
			sanitize_key( wp_unslash( $_POST['huella'] ?? '' ) ),
			! empty( $_POST['sociedad_confirmada'] ),
			// Casillas "Marca de agua" (#321) y "Descargable" (v0.36.0), para
			// toda la generacion; sin marcar no llegan en la peticion.
			empty( $_POST['marca_agua'] ),
			! empty( $_POST['descargable'] )
		);

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
			$datos = $resultado->get_error_data();
			self::redirigir( is_array( $datos ) && isset( $datos['sim_id'] ) ? array( 'sim' => $datos['sim_id'] ) : array() );
		}

		self::redirigir( array( 'job' => $resultado['job_id'] ) );
	}

	public static function ajax_generar_tanda(): void {
		if ( ! current_user_can( Roles::CAP_GESTIONAR_347 ) ) {
			wp_send_json_error( array( 'mensaje' => 'Sin permiso.' ), 403 );
		}

		check_ajax_referer( 'mdf_ca_347_generar_tanda' );

		$resultado = ( new Modelo_347_Generacion_Service() )->procesar_tanda( get_current_user_id(), sanitize_key( wp_unslash( $_POST['job'] ?? '' ) ) );

		if ( is_wp_error( $resultado ) ) {
			wp_send_json_error( array( 'mensaje' => $resultado->get_error_message() ), 400 );
		}

		wp_send_json_success( $resultado );
	}

	/**
	 * Vista previa en un PDF al vuelo (no se guarda nada). Con "linea", la
	 * carta de esa fila; sin ella, las primeras MAX_PREVIA cartas
	 * previsualizables por orden de prioridad (lista, sin cuenta, no esta en
	 * el portal, ya vigente, repetido). Solo filas con identificador valido y
	 * suma correcta (Modelo_347_Simulacion_Service::es_previsualizable()).
	 */
	public static function gestionar_vista_previa(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_vista_previa' );

		$sim   = self::simulacion_de_get();
		$res   = ( new Modelo_347_Simulacion_Service() )->simular( $sim['filas'], $sim['emisor_cif'], (int) $sim['ejercicio'] );
		$linea = isset( $_GET['linea'] ) ? (int) $_GET['linea'] : 0; // phpcs:ignore WordPress.Security.NonceVerification -- nonce verificado arriba.

		if ( $linea > 0 ) {
			$elegidas = array_values( array_filter( $res['filas'], static fn( array $f ): bool => (int) $f['linea'] === $linea && Modelo_347_Simulacion_Service::es_previsualizable( $f ) ) );
		} else {
			$elegidas = array();

			foreach ( Modelo_347_Simulacion_Service::PREVISUALIZABLES as $categoria ) {
				foreach ( $res['filas'] as $f ) {
					if ( $f['categoria'] === $categoria && count( $elegidas ) < self::MAX_PREVIA ) {
						$elegidas[] = $f;
					}
				}
			}
		}

		if ( ! $elegidas ) {
			wp_die( 'No hay ninguna carta que previsualizar.', '', array( 'response' => 404 ) );
		}

		$pdf = Modelo_347_Carta::pdf( $sim['emisor_cif'], (int) $sim['ejercicio'], array_map( array( Modelo_347_Generacion_Service::class, 'datos_carta' ), $elegidas ) );

		if ( is_wp_error( $pdf ) ) {
			wp_die( esc_html( $pdf->get_error_message() ) );
		}

		self::cabeceras_descarga( 'application/pdf', $linea > 0 ? sprintf( 'vista-previa-modelo-347-fila-%d.pdf', $linea ) : 'vista-previa-modelo-347.pdf', false, strlen( $pdf ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binario PDF.
		exit;
	}

	/** Cartas de las filas "sin cuenta" y "no esta en el portal", en un ZIP al vuelo. */
	public static function gestionar_zip(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_zip' );

		$sim     = self::simulacion_de_get();
		$res     = ( new Modelo_347_Simulacion_Service() )->simular( $sim['filas'], $sim['emisor_cif'], (int) $sim['ejercicio'] );
		$filas   = array_values( array_filter( $res['filas'], static fn( array $f ): bool => in_array( $f['categoria'], array( Modelo_347_Simulacion_Service::SIN_CUENTA, Modelo_347_Simulacion_Service::NO_PORTAL ), true ) ) );

		if ( ! $filas ) {
			wp_die( 'No hay cartas que no se puedan enviar.', '', array( 'response' => 404 ) );
		}

		// Todas generadas antes de empezar a enviar bytes: un error a mitad no deja un ZIP roto.
		$pdfs = array();

		foreach ( $filas as $fila ) {
			$pdf = Modelo_347_Carta::pdf( $sim['emisor_cif'], (int) $sim['ejercicio'], array( Modelo_347_Generacion_Service::datos_carta( $fila ) ) );

			if ( is_wp_error( $pdf ) ) {
				wp_die( esc_html( $pdf->get_error_message() ) );
			}

			$nombre = sprintf( '%03d-%s-%s.pdf', count( $pdfs ) + 1, $fila['nif'], sanitize_file_name( remove_accents( $fila['nombre'] ) ) );
			$pdfs[ $nombre ] = $pdf;
		}

		self::cabeceras_descarga( 'application/zip', sprintf( 'modelo-347-%d-no-enviables.zip', (int) $sim['ejercicio'] ), true, null );

		$zip = new Zip_Stream();

		foreach ( $pdfs as $nombre => $pdf ) {
			$zip->anadir( $nombre, $pdf );
		}

		$zip->cerrar();
		exit;
	}

	// ------------------------------------------------------------------
	// Publicar y enviar
	// ------------------------------------------------------------------

	public static function gestionar_publicar_preparar(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_publicar_preparar' );

		list( $emisor, $ejercicio ) = self::emisor_ejercicio_de_post();
		$calculo                    = ( new Modelo_347_Publicacion_Service() )->calcular( $emisor, $ejercicio );

		if ( is_wp_error( $calculo ) ) {
			self::guardar_aviso( 'error', $calculo->get_error_message() );
			self::redirigir_envios( $emisor, $ejercicio );
		}

		if ( ! $calculo['ids'] ) {
			self::guardar_aviso( 'warning', 'No hay cartas pendientes de publicar de esta sociedad y ejercicio.' );
			self::redirigir_envios( $emisor, $ejercicio );
		}

		$pub_id = bin2hex( random_bytes( 16 ) );
		set_transient(
			self::PUB_KEY_PREFIX . get_current_user_id() . '_' . $pub_id,
			array(
				'emisor'    => $emisor,
				'ejercicio' => $ejercicio,
				'huella'    => $calculo['huella'],
			),
			self::PUB_TTL
		);

		self::redirigir_envios( $emisor, $ejercicio, array( 'pub' => $pub_id ) );
	}

	public static function gestionar_publicar_aplicar(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_publicar_aplicar' );

		$usuario_id = get_current_user_id();
		$pub_id     = sanitize_key( wp_unslash( $_POST['pub'] ?? '' ) );
		$clave      = self::PUB_KEY_PREFIX . $usuario_id . '_' . $pub_id;
		$preparada  = 1 === preg_match( '/^[a-f0-9]{32}$/D', $pub_id ) ? get_transient( $clave ) : false;

		// Se consume: de dos envios del formulario solo uno publica.
		if ( ! is_array( $preparada ) || ! delete_transient( $clave ) ) {
			self::guardar_aviso( 'warning', 'La confirmación ha caducado o ya se aplicó. No se ha publicado nada más.' );
			self::redirigir( array( 'tab' => 'envios' ) );
		}

		$resultado = ( new Modelo_347_Publicacion_Service() )->aplicar( $preparada['emisor'], (int) $preparada['ejercicio'], $preparada['huella'], $usuario_id );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso(
				'success',
				sprintf(
					'Publicadas %d cartas (lote %d). %d quedan en cola de envío; pulsa «Enviar pendientes». Las farmacias sin cuenta activa ya la ven en su área cuando entren, sin correo.',
					$resultado['publicados'],
					$resultado['lote_id'],
					$resultado['encolados']
				)
			);
		}

		self::redirigir_envios( $preparada['emisor'], (int) $preparada['ejercicio'] );
	}

	public static function gestionar_publicar_cancelar(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_publicar_cancelar' );

		list( $emisor, $ejercicio ) = self::emisor_ejercicio_de_post();
		$pub_id                     = sanitize_key( wp_unslash( $_POST['pub'] ?? '' ) );

		if ( 1 === preg_match( '/^[a-f0-9]{32}$/D', $pub_id ) ) {
			delete_transient( self::PUB_KEY_PREFIX . get_current_user_id() . '_' . $pub_id );
		}

		self::guardar_aviso( 'success', 'Publicación cancelada. No se ha publicado nada.' );
		self::redirigir_envios( $emisor, $ejercicio );
	}

	public static function ajax_enviar_tanda(): void {
		if ( ! current_user_can( Roles::CAP_GESTIONAR_347 ) ) {
			wp_send_json_error( array( 'mensaje' => 'Sin permiso.' ), 403 );
		}

		check_ajax_referer( 'mdf_ca_347_enviar_tanda' );

		$emisor    = self::emisor_de( $_POST['emisor'] ?? '' );
		$ejercicio = self::ejercicio_de( $_POST['ejercicio'] ?? '' );

		if ( null === $emisor || null === $ejercicio ) {
			wp_send_json_error( array( 'mensaje' => 'Sociedad o ejercicio no válidos.' ), 400 );
		}

		$resultado = ( new Modelo_347_Envio_Service() )->enviar_tanda( $emisor, $ejercicio );

		if ( is_wp_error( $resultado ) ) {
			wp_send_json_error( array( 'mensaje' => $resultado->get_error_message() ), 400 );
		}

		wp_send_json_success( $resultado );
	}

	public static function gestionar_reenviar(): void {
		self::exigir_capacidad();

		$envio_id = (int) ( $_POST['envio_id'] ?? 0 );

		check_admin_referer( 'mdf_ca_347_reenviar_' . $envio_id );
		list( $emisor, $ejercicio ) = self::emisor_ejercicio_de_post();

		if ( empty( $_POST['confirmar'] ) ) {
			self::guardar_aviso( 'error', 'Marca la casilla para confirmar el reenvío. No se ha cambiado nada.' );
		} elseif ( ( new Modelo_347_Envio_Repository() )->reenviar( $envio_id ) ) {
			self::guardar_aviso( 'success', 'Carta puesta de nuevo en cola. Se enviará con «Enviar pendientes».' );
		} else {
			self::guardar_aviso( 'warning', 'Ese envío no se puede reenviar ahora (puede estar en curso).' );
		}

		self::redirigir_envios( $emisor, $ejercicio );
	}

	public static function gestionar_reintentar(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_reintentar' );

		list( $emisor, $ejercicio ) = self::emisor_ejercicio_de_post();
		$n                          = ( new Modelo_347_Envio_Repository() )->reintentar_fallidos( $emisor, $ejercicio );

		self::guardar_aviso( $n > 0 ? 'success' : 'warning', sprintf( 'Envíos fallidos puestos de nuevo en cola: %d.', $n ) );
		self::redirigir_envios( $emisor, $ejercicio );
	}

	public static function gestionar_prueba(): void {
		self::exigir_capacidad();

		$documento_id = (int) ( $_POST['documento_id'] ?? 0 );

		check_admin_referer( 'mdf_ca_347_prueba_' . $documento_id );
		list( $emisor, $ejercicio ) = self::emisor_ejercicio_de_post();

		$resultado = ( new Modelo_347_Envio_Service() )->enviar_prueba( $documento_id );

		if ( is_wp_error( $resultado ) ) {
			$mensaje = $resultado->get_error_message();
			$detalle = $resultado->get_error_data()['detalle'] ?? '';

			// El detalle tecnico del correo, solo al administrador.
			if ( '' !== $detalle && current_user_can( Roles::CAP_ADMINISTRAR ) ) {
				$mensaje .= ' Detalle técnico: ' . $detalle;
			}

			self::guardar_aviso( 'error', $mensaje );
		} else {
			self::guardar_aviso( 'success', 'Correo de prueba enviado a la dirección de prueba de los ajustes. No se ha publicado ni registrado nada.' );
		}

		self::redirigir_envios( $emisor, $ejercicio );
	}

	// ------------------------------------------------------------------
	// Ajustes
	// ------------------------------------------------------------------

	public static function gestionar_ajustes(): void {
		self::exigir_capacidad();
		check_admin_referer( 'mdf_ca_347_ajustes' );

		$emisor = self::emisor_de( $_POST['emisor'] ?? '' );

		if ( null === $emisor ) {
			self::guardar_aviso( 'error', 'Sociedad no habilitada.' );
			self::redirigir( array( 'tab' => 'ajustes' ) );
		}

		$resultado = Modelo_347_Ajustes::guardar(
			$emisor,
			array(
				'razon_social'   => wp_unslash( $_POST['razon_social'] ?? '' ),
				'direccion'      => wp_unslash( $_POST['direccion'] ?? '' ),
				'firma'          => wp_unslash( $_POST['firma'] ?? '' ),
				'correo_admin'   => wp_unslash( $_POST['correo_admin'] ?? '' ),
				'reply_to'       => wp_unslash( $_POST['reply_to'] ?? '' ),
				'correo_prueba'  => wp_unslash( $_POST['correo_prueba'] ?? '' ),
				'por_tanda'      => wp_unslash( $_POST['por_tanda'] ?? '' ),
				'segundos_tanda' => wp_unslash( $_POST['segundos_tanda'] ?? '' ),
				'cuerpo'         => wp_unslash( $_POST['cuerpo'] ?? '' ),
			)
		);

		self::guardar_aviso( is_wp_error( $resultado ) ? 'error' : 'success', is_wp_error( $resultado ) ? $resultado->get_error_message() : 'Ajustes guardados.' );
		self::redirigir(
			array(
				'tab'    => 'ajustes',
				'emisor' => $emisor,
			)
		);
	}

	// ------------------------------------------------------------------
	// Utilidades
	// ------------------------------------------------------------------

	/** CIF de una sociedad habilitada para el generador, o null. */
	private static function emisor_de( $valor ): ?string {
		$cif = is_string( $valor ) ? strtoupper( sanitize_text_field( wp_unslash( $valor ) ) ) : '';

		return Modelo_347_Carta::emisor_habilitado( $cif ) ? $cif : null;
	}

	private static function ejercicio_de( $valor ): ?int {
		$texto = is_scalar( $valor ) ? trim( (string) $valor ) : '';

		return 1 === preg_match( '/^\d{4}$/D', $texto ) && Documento_Service::ejercicio_valido( (int) $texto ) ? (int) $texto : null;
	}

	/** @return array{0: string, 1: int} */
	private static function emisor_ejercicio_de_post(): array {
		$emisor    = self::emisor_de( $_POST['emisor'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification -- ya verificado por quien llama.
		$ejercicio = self::ejercicio_de( $_POST['ejercicio'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( null === $emisor || null === $ejercicio ) {
			self::guardar_aviso( 'error', 'Sociedad o ejercicio no válidos.' );
			self::redirigir( array( 'tab' => 'envios' ) );
		}

		return array( $emisor, $ejercicio );
	}

	/** @return array{emisor_cif: string, ejercicio: int, filas: array<int, array<string, mixed>>} */
	private static function simulacion_de_get(): array {
		$sim = Modelo_347_Generacion_Service::leer_simulacion( get_current_user_id(), sanitize_key( wp_unslash( $_GET['sim'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification -- verificado por quien llama.

		if ( ! $sim ) {
			wp_die( 'La simulación ha caducado. Vuelve a subir el Excel.', '', array( 'response' => 404 ) );
		}

		return $sim;
	}

	private static function cabeceras_descarga( string $mime, string $nombre, bool $adjunto, ?int $tamano ): void {
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: ' . ( $adjunto ? 'attachment' : 'inline' ) . '; filename="' . $nombre . '"' );
		header( 'Cache-Control: no-store, private, max-age=0' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		if ( null !== $tamano ) {
			header( 'Content-Length: ' . $tamano );
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
	}

	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/** @param array<string, string|int> $args */
	private static function redirigir( array $args = array() ): void {
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	/** @param array<string, string|int> $extra */
	private static function redirigir_envios( string $emisor, int $ejercicio, array $extra = array() ): void {
		self::redirigir(
			array_merge(
				array(
					'tab'       => 'envios',
					'emisor'    => $emisor,
					'ejercicio' => $ejercicio,
				),
				$extra
			)
		);
	}

	private static function guardar_aviso( string $tipo, string $mensaje ): void {
		set_transient(
			self::NOTICE_KEY_PREFIX . get_current_user_id(),
			array(
				'tipo'    => $tipo,
				'mensaje' => $mensaje,
			),
			60
		);
	}

	/** @return array{tipo: string, mensaje: string}|null */
	private static function consumir_aviso(): ?array {
		$clave = self::NOTICE_KEY_PREFIX . get_current_user_id();
		$aviso = get_transient( $clave );

		if ( ! is_array( $aviso ) ) {
			return null;
		}

		delete_transient( $clave );

		return $aviso;
	}
}
