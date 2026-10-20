<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documentos MDF › Subir facturas (#334). Administracion MDF sube aqui las
 * facturas exportadas de SAGE (sustituye al programa de envio del Mac): el
 * portal las lee (Factura_Lectura_Service), las cruza con las farmacias y,
 * si corresponde, las guarda pendientes de publicar (Factura_Recepcion_Service).
 * Desde #336 es la unica entrada de facturas: el robot y su ruta REST se han
 * retirado. Al pie, la actividad de los ultimos 14 dias (Recepcion_Registro),
 * que antes estaba en «Recepcion automatica».
 *
 * - Seleccion con Admin_Zona_Archivos en modo lote: varios PDF, carpetas con
 *   subcarpetas, ocultos y no PDF ignorados, tope de MAX_FACTURAS por subida y
 *   tamano maximo del lector.
 * - Subida de una en una desde el navegador (admin-subir-facturas.js): una
 *   peticion admin-ajax por factura, con nonce y capacidad en cada una.
 *   Volver a subir lo mismo no duplica nada (unicidad por hash y por emisor +
 *   serie + numero).
 * - Cada factura nace pendiente, sin aviso por email, con un evento
 *   "recibido" con origen subida_facturas y el usuario, y cuenta en el
 *   registro de recepcion con origen backoffice.
 * - Las que se leen pero no se pueden asignar van a la Bandeja de facturas
 *   (#335). Las demas no reconocidas no se guardan en el servidor: el resumen
 *   y la lista descargable (nombre de fichero y motivo) se hacen en el
 *   navegador.
 * - Nada se registra con valores de la factura. El nombre del fichero solo se
 *   sanea y se usa para contrastar serie y numero; nunca como ruta.
 */
class Admin_Subir_Facturas {

	public const MENU_SLUG = 'mdf-ca-subir-facturas';

	/** Capacidad de la pantalla y de cada peticion: la de Documentos MDF (Admin_Documentos::CAPACIDAD). */
	public const CAPACIDAD = Admin_Documentos::CAPACIDAD;

	public const ACCION_AJAX = 'mdf_ca_subir_factura';

	/** Nonce de la pantalla, el mismo para todas sus peticiones. */
	private const NONCE = 'mdf_ca_pantalla_subir_facturas';

	private const PARENT_SLUG = 'mdf-ca-documentos';

	/** «Recepcion automatica», retirada en #336: su direccion lleva aqui. */
	private const SLUG_ANTIGUO = 'mdf-ca-recepcion-documentos';

	/** Dias de la tabla de actividad. */
	private const DIAS_ACTIVIDAD = 14;

	/** Facturas por subida. Una exportacion mensual de SAGE ronda las 200 (una por farmacia). */
	public const MAX_FACTURAS = 500;

	private const JS = 'assets/js/admin-subir-facturas.js';

	/** Grupos del resumen. */
	public const SUBIDA        = 'subida';
	public const REPETIDA      = 'repetida';
	public const NO_RECONOCIDA = 'no_reconocida';
	/** Guardada en la Bandeja de facturas (#335) para asignarla a mano. */
	public const BANDEJA = 'bandeja';
	public const ERROR         = 'error';

	/** Motivos de una no reconocida, en lenguaje claro (el estado del cruce no se muestra). */
	private const MOTIVOS = array(
		Factura_Cruce_Service::SIN_TEXTO                => 'el PDF no tiene texto (puede ser un escaneo)',
		Factura_Cruce_Service::FORMATO_INESPERADO       => 'no parece una factura de SAGE de Mediformplus o de Formación',
		Factura_Cruce_Service::NUMERO_DISCREPANTE       => 'el número de factura no coincide con el nombre del fichero',
		Factura_Cruce_Service::SIN_CIF                  => 'no se encuentra el CIF del cliente',
		Factura_Cruce_Service::CIF_INVALIDO             => 'el CIF del cliente no es válido',
		Factura_Cruce_Service::CIF_SIN_FARMACIA         => 'el CIF no corresponde a ninguna farmacia del portal',
		Factura_Recepcion_Service::OTRA_FARMACIA        => 'esta factura ya está subida para otra farmacia',
	);

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Documentos en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
		// Solo con sesion: no hay version nopriv.
		add_action( 'wp_ajax_' . self::ACCION_AJAX, array( __CLASS__, 'ajax_subir' ) );
		add_action( 'admin_page_access_denied', array( __CLASS__, 'redirigir_pagina_antigua' ) );
	}

	/**
	 * Un enlace guardado a «Recepcion automatica» (#336) no acaba en "no
	 * tienes permiso": quien puede usar esta pantalla va a su actividad.
	 * WordPress dispara el hook justo antes de negar una pagina que no existe.
	 */
	public static function redirigir_pagina_antigua(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura del slug.
		if ( isset( $_GET['page'] ) && self::SLUG_ANTIGUO === $_GET['page'] && current_user_can( self::CAPACIDAD ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '#mdf-ca-actividad' ) );
			exit;
		}
	}

	public static function registrar_menu(): void {
		add_submenu_page( self::PARENT_SLUG, 'Subir facturas', 'Subir facturas', self::CAPACIDAD, self::MENU_SLUG, array( __CLASS__, 'render_pagina' ) );
	}

	public static function encolar_assets( string $hook_suffix ): void {
		if ( ! str_ends_with( $hook_suffix, '_page_' . self::MENU_SLUG ) || ! current_user_can( self::CAPACIDAD ) ) {
			return;
		}

		Admin_Zona_Archivos::encolar();
		wp_enqueue_script( 'mdf-ca-admin-subir-facturas', Recursos::url( self::JS ), array( Admin_Zona_Archivos::HANDLE ), Recursos::version( self::JS ), true );
		wp_localize_script(
			'mdf-ca-admin-subir-facturas',
			'mdfCaSubirFacturas',
			array(
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'accion'        => self::ACCION_AJAX,
				'nonce'         => wp_create_nonce( self::NONCE ),
				'maxFacturas'   => self::MAX_FACTURAS,
				'urlPendientes' => admin_url( 'admin.php?page=' . Admin_Publicacion_Documentos::MENU_SLUG ),
				'urlBandeja'    => Admin_Bandeja_Facturas::url(),
			)
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( self::CAPACIDAD ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$requisitos   = Requisitos_Servidor::mensaje( Requisitos_Servidor::LECTURA_FACTURAS );
		$max_bytes    = Pdf_Limites::MAX_BYTES_FICHERO;
		$max_facturas = self::MAX_FACTURAS;
		$recuentos    = Recepcion_Registro::recuentos_recientes( self::DIAS_ACTIVIDAD );
		$dias         = self::DIAS_ACTIVIDAD;

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-subir-facturas.php';
	}

	/**
	 * Una factura por peticion. Responde siempre JSON:
	 * {grupo, motivo, farmacia, parar}. grupo: subida | repetida | bandeja |
	 * no_reconocida | error (se puede reintentar). bandeja (#335): no se ha
	 * podido asignar pero se ha leido entera y se guarda en la Bandeja de
	 * facturas; no_reconocida: ni eso (no es un PDF, supera los topes del
	 * lector, cifrada, no se abre, o ya esta en otra farmacia), no se guarda. parar: el navegador debe
	 * dejar de subir (sin permiso, sesion caducada o servidor sin requisitos).
	 */
	public static function ajax_subir(): void {
		if ( ! current_user_can( self::CAPACIDAD ) || false === check_ajax_referer( self::NONCE, '_ajax_nonce', false ) ) {
			self::responder( self::ERROR, 'No tienes permiso o la sesión ha caducado. Recarga la página y vuelve a subir: lo que ya se subió no se duplicará.', null, true, 403 );
		}

		$requisitos = Requisitos_Servidor::mensaje( Requisitos_Servidor::LECTURA_FACTURAS );

		if ( null !== $requisitos ) {
			self::responder( self::ERROR, $requisitos, null, true, 503 );
		}

		$archivo = self::leer_archivo();

		if ( is_string( $archivo ) ) {
			Recepcion_Registro::registrar( Recepcion_Registro::RECHAZADO, 1, Recepcion_Registro::ORIGEN_BACKOFFICE );
			self::responder( self::NO_RECONOCIDA, $archivo );
		}

		$lector    = new Factura_Lectura_Service();
		$lectura   = $lector->analizar( $archivo['tmp_name'], $archivo['nombre'] );
		// Siempre pendiente de publicar y sin aviso por email a la farmacia. Sin
		// marca de agua (#321) si se marco la casilla de la tanda; el navegador
		// la manda en cada factura y aqui solo se lee "1".
		$sin_marca = isset( $_POST['sin_marca'] ) && '1' === $_POST['sin_marca']; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce comprobado arriba.
		$recepcion = ( new Factura_Recepcion_Service() )->recibir( $archivo, $lectura, Publicacion_Evento_Repository::ORIGEN_SUBIDA_FACTURAS, null, $sin_marca );

		// responder() termina la peticion.
		if ( Factura_Recepcion_Service::ACEPTADO === $recepcion['resultado'] ) {
			self::responder( self::SUBIDA, '', self::nombre_farmacia( $recepcion['farmacia_id'] ) );
		}

		if ( Factura_Recepcion_Service::DUPLICADO === $recepcion['resultado'] ) {
			self::responder( self::REPETIDA, '', self::nombre_farmacia( $recepcion['farmacia_id'] ) );
		}

		if ( Factura_Recepcion_Service::EXCEPCION === $recepcion['resultado'] ) {
			$motivo = self::motivo( (string) $recepcion['estado'], (string) $lectura['motivo'] );

			// A la bandeja solo lo que se ha leido entero y no se ha podido asignar.
			if ( $lector->se_pudo_leer() && in_array( $recepcion['estado'], Bandeja_Facturas_Service::MOTIVOS_QUE_ENTRAN, true ) ) {
				$bandeja = ( new Bandeja_Facturas_Service() )->entrar( $archivo, $archivo['nombre'], $lectura, (string) $recepcion['estado'], get_current_user_id() );

				if ( Bandeja_Facturas_Service::EN_BANDEJA === $bandeja ) {
					self::responder( self::BANDEJA, $motivo );
				}

				if ( Bandeja_Facturas_Service::YA_EN_BANDEJA === $bandeja ) {
					self::responder( self::REPETIDA, '', 'ya estaba en la Bandeja de facturas' );
				}

				if ( Bandeja_Facturas_Service::YA_DOCUMENTO === $bandeja ) {
					self::responder( self::REPETIDA, '', 'ya estaba subida (con otro nombre de fichero)' );
				}

				self::responder( self::ERROR, 'no se ha podido guardar en la bandeja por un fallo del servidor; vuelve a intentarlo' );
			}

			self::responder( self::NO_RECONOCIDA, $motivo );
		}

		if ( $recepcion['interno'] ) {
			self::responder( self::ERROR, 'no se ha podido guardar por un fallo del servidor; vuelve a intentarlo' );
		}

		self::responder( self::NO_RECONOCIDA, 'el fichero no es un PDF válido' );
	}

	/** Motivo claro de una no reconocida: el del cruce y, si el PDF ni se pudo leer, por que. */
	private static function motivo( string $estado, string $motivo_lectura ): string {
		if ( Factura_Cruce_Service::FORMATO_INESPERADO === $estado && Factura_Lectura_Service::CIFRADO === $motivo_lectura ) {
			return 'el PDF está protegido con contraseña';
		}

		if ( Factura_Cruce_Service::FORMATO_INESPERADO === $estado && Factura_Lectura_Service::NO_SE_ABRE === $motivo_lectura ) {
			return 'el PDF no se puede abrir (puede estar dañado o incompleto)';
		}

		return self::MOTIVOS[ $estado ] ?? self::MOTIVOS[ Factura_Cruce_Service::FORMATO_INESPERADO ];
	}

	/**
	 * El PDF subido, comprobado: un solo fichero "factura", subida HTTP real,
	 * tamano dentro del tope del lector y contenido PDF de verdad (fileinfo,
	 * no la extension ni el tipo que declara el navegador).
	 *
	 * @return array{tmp_name: string, size: int, error: int, nombre: string}|string Motivo si no vale.
	 */
	private static function leer_archivo() {
		$f = $_FILES['factura'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verificado en ajax_subir().

		if ( ! is_array( $f ) || ! isset( $f['tmp_name'], $f['error'], $f['size'], $f['name'] ) || ! is_string( $f['tmp_name'] ) || ! is_int( $f['error'] ) || ! is_string( $f['name'] ) ) {
			return 'no se ha recibido bien el fichero; vuelve a intentarlo';
		}

		if ( UPLOAD_ERR_INI_SIZE === $f['error'] || UPLOAD_ERR_FORM_SIZE === $f['error'] ) {
			return sprintf( 'pesa más de lo que admite el servidor (%s)', size_format( Pdf_Limites::MAX_BYTES_FICHERO ) );
		}

		if ( UPLOAD_ERR_OK !== $f['error'] || ! is_uploaded_file( $f['tmp_name'] ) ) {
			return 'no se ha recibido bien el fichero; vuelve a intentarlo';
		}

		if ( (int) $f['size'] > Pdf_Limites::MAX_BYTES_FICHERO ) {
			return sprintf( 'pesa más de %s, el máximo por factura', size_format( Pdf_Limites::MAX_BYTES_FICHERO ) );
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = false === $finfo ? false : finfo_file( $finfo, $f['tmp_name'] );

		if ( 'application/pdf' !== $mime ) {
			return 'no es un PDF';
		}

		return array(
			'tmp_name' => $f['tmp_name'],
			'size'     => (int) $f['size'],
			'error'    => UPLOAD_ERR_OK,
			'nombre'   => self::nombre_seguro( $f['name'] ),
		);
	}

	/**
	 * Nombre del fichero solo para contrastar serie y numero (FAA123.pdf): sin
	 * ruta, saneado y acotado. Nunca se usa como ruta ni se guarda.
	 */
	private static function nombre_seguro( string $nombre ): string {
		$nombre = sanitize_file_name( wp_basename( str_replace( '\\', '/', $nombre ) ) );

		return '' === $nombre ? 'factura.pdf' : substr( $nombre, 0, 200 );
	}

	private static function nombre_farmacia( ?int $farmacia_id ): ?string {
		$farmacia = null === $farmacia_id ? null : ( new Farmacia_Repository() )->find_by_id( $farmacia_id );

		return $farmacia ? $farmacia->get_nombre() : null;
	}

	/** @return never */
	private static function responder( string $grupo, string $motivo, ?string $farmacia = null, bool $parar = false, int $estado = 200 ): void {
		wp_send_json(
			array(
				'grupo'    => $grupo,
				'motivo'   => $motivo,
				'farmacia' => $farmacia,
				'parar'    => $parar,
			),
			$estado
		);
	}
}
