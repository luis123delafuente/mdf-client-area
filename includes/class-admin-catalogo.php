<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backoffice del catalogo de herramientas: menu "Herramientas MDF" con
 * Herramientas (listado y formulario), Categorias, Etiquetas y Ajustes.
 * Mismo patron que Admin_Planes y Admin_Documentos: capacidad
 * Roles::CAP_GESTIONAR_CATALOGO ("Catalogo y planes"; mdf_cliente nunca la
 * tiene y Role_Restrictions ya lo saca de wp-admin), admin-post.php y un
 * nonce por accion (por herramienta o termino cuando aplica). Ajustes es
 * solo del administrador (Roles::CAP_ADMINISTRAR).
 *
 * Toda la validacion vive en Catalogo_Service, Catalogo_Termino_Service y
 * Catalogo_Archivo_Validator: este controlador solo traduce $_POST a
 * parametros tipados y el resultado a un aviso + redirect.
 */
class Admin_Catalogo {

	public const MENU_SLUG       = 'mdf-ca-catalogo';
	public const SLUG_CATEGORIAS = 'mdf-ca-catalogo-categorias';
	public const SLUG_ETIQUETAS  = 'mdf-ca-catalogo-etiquetas';
	public const SLUG_AJUSTES    = 'mdf-ca-catalogo-ajustes';

	private const NONCE_GUARDAR     = 'mdf_ca_guardar_catalogo';
	private const NONCE_AJUSTES     = 'mdf_ca_ajustes_catalogo';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_catalogo_notice_';
	private const FORM_KEY_PREFIX   = 'mdf_ca_catalogo_form_';

	private const CLASES = array( 'categorias', 'etiquetas' );

	public static function register_hooks(): void {
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
		add_action( 'admin_post_mdf_ca_guardar_catalogo', array( __CLASS__, 'gestionar_guardar' ) );
		add_action( 'admin_post_mdf_ca_eliminar_catalogo', array( __CLASS__, 'gestionar_eliminar' ) );
		add_action( 'admin_post_mdf_ca_guardar_termino', array( __CLASS__, 'gestionar_guardar_termino' ) );
		add_action( 'admin_post_mdf_ca_eliminar_termino', array( __CLASS__, 'gestionar_eliminar_termino' ) );
		add_action( 'admin_post_mdf_ca_ajustes_catalogo', array( __CLASS__, 'gestionar_ajustes' ) );
	}

	public static function registrar_menu(): void {
		add_menu_page( 'Herramientas MDF', 'Herramientas MDF', Roles::CAP_GESTIONAR_CATALOGO, self::MENU_SLUG, array( __CLASS__, 'render_herramientas' ), 'dashicons-portfolio', 60 );
		add_submenu_page( self::MENU_SLUG, 'Herramientas', 'Herramientas', Roles::CAP_GESTIONAR_CATALOGO, self::MENU_SLUG, array( __CLASS__, 'render_herramientas' ) );
		add_submenu_page( self::MENU_SLUG, 'Categorías', 'Categorías', Roles::CAP_GESTIONAR_CATALOGO, self::SLUG_CATEGORIAS, array( __CLASS__, 'render_categorias' ) );
		add_submenu_page( self::MENU_SLUG, 'Etiquetas', 'Etiquetas', Roles::CAP_GESTIONAR_CATALOGO, self::SLUG_ETIQUETAS, array( __CLASS__, 'render_etiquetas' ) );
		add_submenu_page( self::MENU_SLUG, 'Ajustes de herramientas', 'Ajustes', Roles::CAP_ADMINISTRAR, self::SLUG_AJUSTES, array( __CLASS__, 'render_ajustes' ) );
	}

	public static function encolar_assets(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo decide que recursos cargar.
		$pagina = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		if ( self::MENU_SLUG !== $pagina || ( ! isset( $_GET['editar'] ) && ! isset( $_GET['accion'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		Admin_Documentos::encolar_selector_farmacia();
		Admin_Zona_Archivos::encolar();
		wp_enqueue_style( 'mdf-ca-admin-catalogo', Recursos::url( 'assets/css/admin-catalogo.css' ), array(), Recursos::version( 'assets/css/admin-catalogo.css' ) );
		wp_enqueue_script( 'mdf-ca-admin-catalogo', Recursos::url( 'assets/js/admin-catalogo.js' ), array(), Recursos::version( 'assets/js/admin-catalogo.js' ), true );
	}

	/* ---------- Herramientas ---------- */

	public static function render_herramientas(): void {
		self::exigir( Roles::CAP_GESTIONAR_CATALOGO );

		$aviso = self::consumir_aviso();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura.
		$editar_id = isset( $_GET['editar'] ) ? (int) $_GET['editar'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$es_nueva = isset( $_GET['accion'] ) && 'nueva' === $_GET['accion'];

		$categorias = Catalogo_Termino_Repository::categorias()->find_all();
		$etiquetas  = Catalogo_Termino_Repository::etiquetas()->find_all();
		$planes     = ( new Plan_Repository() )->find_all();

		if ( $editar_id > 0 || $es_nueva ) {
			$item = $editar_id > 0 ? ( new Catalogo_Repository() )->find_by_id( $editar_id ) : null;

			if ( $editar_id > 0 && ! $item ) {
				self::guardar_aviso( 'error', 'La herramienta que intentas editar ya no existe.' );
				$aviso = self::consumir_aviso();
				$items = ( new Catalogo_Repository() )->find_all();
				require MDF_CA_PLUGIN_DIR . 'includes/views/admin-catalogo.php';
				return;
			}

			$farmacias      = ( new Farmacia_Repository() )->find_all();
			$valores        = self::valores_formulario( $item );
			$permitir_xlsm  = Catalogo_Ajustes::permite_xlsm();
			$selector_id    = 'mdf-ca-herramienta-farmacia';

			require MDF_CA_PLUGIN_DIR . 'includes/views/admin-catalogo-form.php';
			return;
		}

		$items = ( new Catalogo_Repository() )->find_all();

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-catalogo.php';
	}

	/**
	 * Valores con los que se pinta el formulario: lo que el usuario mando en
	 * el intento anterior que fallo (transient de 5 minutos, se consume al
	 * pintar), o lo guardado, o los valores por defecto de una nueva. El
	 * archivo no se conserva entre intentos: hay que volver a elegirlo.
	 *
	 * @return array<string, mixed>
	 */
	private static function valores_formulario( ?Catalogo_Item $item ): array {
		$id    = $item ? $item->get_id() : 0;
		$clave = self::FORM_KEY_PREFIX . get_current_user_id() . '_' . $id;
		$previo = get_transient( $clave );

		if ( is_array( $previo ) ) {
			delete_transient( $clave );
			return $previo;
		}

		if ( ! $item ) {
			return array(
				'nombre'         => '',
				'descripcion'    => '',
				'descripcion_detallada' => '',
				'categoria_id'   => 0,
				'etiqueta_ids'   => array(),
				'recurso'        => Catalogo_Item::RECURSO_ARCHIVO,
				'enlace_url'     => '',
				'descargable'    => false,
				'marca_agua'     => true,
				'plan_ids'       => array(),
				'solo_farmacias' => false,
				'farmacia_ids'   => array(),
				'visible'        => true,
			);
		}

		return array(
			'nombre'         => $item->get_nombre(),
			'descripcion'    => (string) $item->get_descripcion(),
			'descripcion_detallada' => (string) $item->get_descripcion_detallada(),
			'categoria_id'   => (int) $item->get_categoria_id(),
			'etiqueta_ids'   => $item->get_etiqueta_ids(),
			'recurso'        => $item->get_recurso(),
			'enlace_url'     => (string) $item->get_enlace_url(),
			'descargable'    => $item->is_descargable(),
			'marca_agua'     => $item->con_marca_de_agua(),
			'plan_ids'       => ( new Catalogo_Repository() )->find_plan_ids( $item->get_id() ),
			'solo_farmacias' => (bool) $item->get_farmacia_ids(),
			'farmacia_ids'   => $item->get_farmacia_ids(),
			'visible'        => $item->is_visible(),
		);
	}

	public static function gestionar_guardar(): void {
		self::exigir( Roles::CAP_GESTIONAR_CATALOGO );
		check_admin_referer( self::NONCE_GUARDAR );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verificado arriba.
		$id      = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$entrada = array(
			'nombre'         => isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '',
			'descripcion'    => isset( $_POST['descripcion'] ) ? sanitize_text_field( wp_unslash( $_POST['descripcion'] ) ) : '',
			// Un solo parrafo: sanitize_text_field() convierte los saltos de linea en espacios.
			'descripcion_detallada' => isset( $_POST['descripcion_detallada'] ) ? sanitize_text_field( wp_unslash( $_POST['descripcion_detallada'] ) ) : '',
			'categoria_id'   => isset( $_POST['categoria_id'] ) ? (int) $_POST['categoria_id'] : 0,
			'etiqueta_ids'   => self::ids_de_post( 'etiqueta_ids' ),
			'recurso'        => isset( $_POST['recurso'] ) ? sanitize_key( wp_unslash( $_POST['recurso'] ) ) : '',
			'enlace_url'     => isset( $_POST['enlace_url'] ) ? esc_url_raw( trim( wp_unslash( $_POST['enlace_url'] ) ), array( 'http', 'https' ) ) : '',
			'descargable'    => ! empty( $_POST['descargable'] ),
			'marca_agua'     => ! empty( $_POST['marca_agua'] ),
			'plan_ids'       => self::ids_de_post( 'plan_ids' ),
			'solo_farmacias' => ! empty( $_POST['solo_farmacias'] ),
			'farmacia_ids'   => self::ids_de_post( 'farmacia_ids' ),
			'visible'        => isset( $_POST['visible'] ) && '1' === $_POST['visible'],
		);
		// phpcs:enable

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- verificado arriba; lo valida Catalogo_Archivo_Validator.
		$archivo = isset( $_FILES['archivo'] ) && is_array( $_FILES['archivo'] ) ? $_FILES['archivo'] : null;

		$resultado = ( new Catalogo_Service() )->guardar( $id, $entrada, $archivo );

		if ( is_wp_error( $resultado ) ) {
			set_transient( self::FORM_KEY_PREFIX . get_current_user_id() . '_' . $id, $entrada, 5 * MINUTE_IN_SECONDS );
			self::guardar_aviso( 'error', $resultado->get_error_message() );
			self::redirigir( $id > 0 ? array( 'editar' => $id ) : array( 'accion' => 'nueva' ) );
		}

		$mensaje = $id > 0 ? 'Herramienta guardada.' : 'Herramienta creada.';

		self::guardar_aviso( $resultado['avisos'] ? 'warning' : 'success', $mensaje, $resultado['avisos'] );
		self::redirigir();
	}

	public static function gestionar_eliminar(): void {
		self::exigir( Roles::CAP_GESTIONAR_CATALOGO );

		$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- se verifica a continuacion, con el id.

		check_admin_referer( 'mdf_ca_eliminar_catalogo_' . $id );

		$resultado = ( new Catalogo_Service() )->eliminar( $id );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', 'Herramienta eliminada, con su archivo.' );
		}

		self::redirigir();
	}

	/* ---------- Categorias y etiquetas ---------- */

	public static function render_categorias(): void {
		self::render_terminos( 'categorias' );
	}

	public static function render_etiquetas(): void {
		self::render_terminos( 'etiquetas' );
	}

	private static function render_terminos( string $clase ): void {
		self::exigir( Roles::CAP_GESTIONAR_CATALOGO );

		$repository = self::repository_de( $clase );
		$aviso      = self::consumir_aviso();
		$terminos   = $repository->find_all();
		$uso        = $repository->count_herramientas_todos();
		$slug       = 'categorias' === $clase ? self::SLUG_CATEGORIAS : self::SLUG_ETIQUETAS;

		// Confirmacion de borrado de una etiqueta en uso: pantalla propia.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura; el borrado lleva su nonce.
		$eliminar_id = 'etiquetas' === $clase && isset( $_GET['eliminar'] ) ? (int) $_GET['eliminar'] : 0;
		$a_eliminar  = $eliminar_id > 0 ? $repository->find_by_id( $eliminar_id ) : null;

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-catalogo-terminos.php';
	}

	public static function gestionar_guardar_termino(): void {
		self::exigir( Roles::CAP_GESTIONAR_CATALOGO );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- se verifica a continuacion, con la clase y el id.
		$clase  = isset( $_POST['clase'] ) ? sanitize_key( wp_unslash( $_POST['clase'] ) ) : '';
		$id     = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		$nombre = isset( $_POST['nombre'] ) ? sanitize_text_field( wp_unslash( $_POST['nombre'] ) ) : '';
		// phpcs:enable

		$repository = self::repository_de( $clase );

		check_admin_referer( 'mdf_ca_guardar_termino_' . $clase . '_' . $id );

		$service   = new Catalogo_Termino_Service( $repository );
		$resultado = $id > 0 ? $service->renombrar( $id, $nombre ) : $service->crear( $nombre );
		$que       = 'categorias' === $clase ? 'Categoría' : 'Etiqueta';

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', $id > 0 ? $que . ' renombrada.' : $que . ' creada.' );
		}

		self::redirigir_a( 'categorias' === $clase ? self::SLUG_CATEGORIAS : self::SLUG_ETIQUETAS );
	}

	public static function gestionar_eliminar_termino(): void {
		self::exigir( Roles::CAP_GESTIONAR_CATALOGO );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- se verifica a continuacion, con la clase y el id.
		$clase = isset( $_POST['clase'] ) ? sanitize_key( wp_unslash( $_POST['clase'] ) ) : '';
		$id    = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
		// phpcs:enable

		$repository = self::repository_de( $clase );

		check_admin_referer( 'mdf_ca_eliminar_termino_' . $clase . '_' . $id );

		$resultado = ( new Catalogo_Termino_Service( $repository ) )->eliminar( $id );

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso( 'success', 'categorias' === $clase ? 'Categoría borrada.' : 'Etiqueta borrada.' );
		}

		self::redirigir_a( 'categorias' === $clase ? self::SLUG_CATEGORIAS : self::SLUG_ETIQUETAS );
	}

	/* ---------- Ajustes (solo administrador) ---------- */

	public static function render_ajustes(): void {
		self::exigir( Roles::CAP_ADMINISTRAR );

		$aviso         = self::consumir_aviso();
		$permitir_xlsm = Catalogo_Ajustes::permite_xlsm();
		$con_xlsm      = count(
			array_filter(
				( new Catalogo_Repository() )->find_all(),
				static fn( Catalogo_Item $i ): bool => $i->es_archivo() && Catalogo_Formatos::XLSM === $i->get_formato()
			)
		);

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-catalogo-ajustes.php';
	}

	public static function gestionar_ajustes(): void {
		self::exigir( Roles::CAP_ADMINISTRAR );
		check_admin_referer( self::NONCE_AJUSTES );

		Catalogo_Ajustes::set_permite_xlsm( ! empty( $_POST['permitir_xlsm'] ) );

		self::guardar_aviso(
			'success',
			Catalogo_Ajustes::permite_xlsm()
				? 'Ajustes guardados: se admiten herramientas Excel con macros (.xlsm).'
				: 'Ajustes guardados: no se admiten herramientas Excel con macros. Las ya subidas no las ve ninguna farmacia.'
		);
		self::redirigir_a( self::SLUG_AJUSTES );
	}

	/* ---------- Comun ---------- */

	private static function exigir( string $capacidad ): void {
		if ( ! current_user_can( $capacidad ) ) {
			wp_die( 'No tienes permiso para acceder a esta página.', '', array( 'response' => 403 ) );
		}
	}

	/** Nunca un nombre de tabla de la peticion: solo las dos clases conocidas. */
	private static function repository_de( string $clase ): Catalogo_Termino_Repository {
		if ( ! in_array( $clase, self::CLASES, true ) ) {
			wp_die( 'Petición no válida.', '', array( 'response' => 400 ) );
		}

		return 'categorias' === $clase ? Catalogo_Termino_Repository::categorias() : Catalogo_Termino_Repository::etiquetas();
	}

	/**
	 * @return int[]
	 */
	private static function ids_de_post( string $campo ): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- quien llama ya verifico el nonce.
		if ( ! isset( $_POST[ $campo ] ) || ! is_array( $_POST[ $campo ] ) ) {
			return array();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return array_values( array_filter( array_map( 'intval', wp_unslash( $_POST[ $campo ] ) ), static fn( int $id ): bool => $id > 0 ) );
	}

	/**
	 * @param array<string, mixed> $args_extra
	 */
	private static function redirigir( array $args_extra = array() ): void {
		self::redirigir_a( self::MENU_SLUG, $args_extra );
	}

	/**
	 * @param array<string, mixed> $args_extra
	 */
	private static function redirigir_a( string $pagina, array $args_extra = array() ): void {
		wp_safe_redirect( add_query_arg( $args_extra, admin_url( 'admin.php?page=' . $pagina ) ) );
		exit;
	}

	/**
	 * Mismo mecanismo de aviso por transient de usuario que Admin_Planes y
	 * Admin_Documentos, con una lista opcional de detalles.
	 *
	 * @param string[] $detalles
	 */
	private static function guardar_aviso( string $tipo, string $mensaje, array $detalles = array() ): void {
		set_transient(
			self::NOTICE_KEY_PREFIX . get_current_user_id(),
			array(
				'tipo'     => $tipo,
				'mensaje'  => $mensaje,
				'detalles' => $detalles,
			),
			30
		);
	}

	/**
	 * @return array{tipo: string, mensaje: string, detalles: string[]}|null
	 */
	private static function consumir_aviso(): ?array {
		$key   = self::NOTICE_KEY_PREFIX . get_current_user_id();
		$aviso = get_transient( $key );

		if ( ! $aviso ) {
			return null;
		}

		delete_transient( $key );

		return $aviso + array( 'detalles' => array() );
	}
}
