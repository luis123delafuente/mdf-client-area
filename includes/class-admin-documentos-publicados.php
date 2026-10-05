<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Documentos MDF > Documentos publicados" (#318): buscador
 * paginado de TODOS los documentos publicados (por farmacia y por nombre) para
 * poder corregir cualquiera, tambien uno antiguo, y el HISTORIAL de
 * publicaciones de un documento. Mismo patron que el resto del backoffice:
 * 'manage_options', admin-post.php con nonce en cada accion, avisos por
 * transient de usuario y salida escapada. mdf_cliente y el robot no llegan
 * aqui por ninguna via.
 *
 * - Buscador (GET, solo lectura): farmacia (el selector con filtro de
 *   Documentos MDF) y nombre del documento (LIKE con esc_like). La paginacion
 *   es en SQL (LIMIT/OFFSET, POR_PAGINA por pagina): nunca se cargan todos
 *   los documentos.
 * - Despublicar (POST, nonce por documento): Documento_Publicacion_Service,
 *   que deja el evento en la misma transaccion. Vuelve a esta pantalla con
 *   los mismos filtros, reconstruidos con valores saneados (el destino no
 *   llega del formulario).
 * - Historial (GET ?historial=ID, solo lectura): los eventos del documento
 *   (fecha, accion, origen, quien y lote). Los documentos anteriores a la
 *   marca mdf_ca_eventos_desde no tienen registro y la pantalla lo dice.
 *
 * Esta clase NO decide visibilidad: despublicar solo cambia el estado, y
 * quien decide quien ve un documento sigue siendo Permissions.
 *
 * Sin logs: ni datos fiscales ni nombres.
 */
class Admin_Documentos_Publicados {

	public const MENU_SLUG = 'mdf-ca-documentos-publicados';

	private const PARENT_SLUG       = 'mdf-ca-documentos';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_publicados_notice_';
	private const POR_PAGINA        = 50;
	private const MAX_NOMBRE        = 100;

	/** Etiquetas de los origenes de un evento, en palabras. */
	private const ORIGENES = array(
		Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL        => 'Uno a uno',
		Publicacion_Evento_Repository::ORIGEN_POR_FARMACIA      => 'Todos los de una farmacia',
		Publicacion_Evento_Repository::ORIGEN_BLOQUE            => 'En bloque («Publicar todos los pendientes»)',
		Publicacion_Evento_Repository::ORIGEN_DESHACER_LOTE     => 'Al deshacer un lote',
		Publicacion_Evento_Repository::ORIGEN_RECEPCION         => 'Recepción automática (nació publicado)',
		Publicacion_Evento_Repository::ORIGEN_SUBIDA_BACKOFFICE => 'Subida desde el backoffice (nació publicado)',
	);

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Documentos en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_post_mdf_ca_despublicar_documento', array( __CLASS__, 'gestionar_despublicar' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
	}

	public static function registrar_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			'Documentos publicados',
			'Documentos publicados',
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' )
		);
	}

	/** Mismo selector de farmacia que Documentos MDF, en su modo opcional. */
	public static function encolar_assets( string $hook_suffix ): void {
		if ( 'documentos-mdf_page_' . self::MENU_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'mdf-ca-admin-selector-farmacia',
			plugins_url( 'assets/css/admin-selector-farmacia.css', MDF_CA_PLUGIN_FILE ),
			array(),
			MDF_CA_VERSION
		);

		wp_enqueue_script(
			'mdf-ca-admin-selector-farmacia',
			plugins_url( 'assets/js/admin-selector-farmacia.js', MDF_CA_PLUGIN_FILE ),
			array(),
			MDF_CA_VERSION,
			true
		);
	}

	public static function url_listado( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::MENU_SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	public static function url_historial( int $documento_id ): string {
		return self::url_listado( array( 'historial' => $documento_id ) );
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$farmacias = ( new Farmacia_Repository() )->find_all();
		$por_id    = array();

		foreach ( $farmacias as $farmacia ) {
			$por_id[ $farmacia->get_id() ] = $farmacia;
		}

		$nombre_farmacia = static function ( int $farmacia_id ) use ( $por_id ): string {
			return isset( $por_id[ $farmacia_id ] )
				? $por_id[ $farmacia_id ]->get_nombre()
				: sprintf( 'Farmacia ya no existe (ID %d)', $farmacia_id );
		};

		// Historial de un documento (solo lectura).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- vista de solo lectura detras de manage_options.
		$historial_id = isset( $_GET['historial'] ) ? absint( wp_unslash( $_GET['historial'] ) ) : 0;

		if ( $historial_id > 0 ) {
			self::render_historial( $historial_id, $nombre_farmacia );
			return;
		}

		// Buscador: filtros saneados; la consulta es preparada y paginada en SQL.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- filtros de solo lectura detras de manage_options.
		// (int) y no absint(): un valor negativo no se convierte en otro valido.
		$farmacia_id = isset( $_GET['farmacia_id'] ) ? max( 0, (int) wp_unslash( $_GET['farmacia_id'] ) ) : 0;
		$q           = isset( $_GET['q'] ) ? mb_substr( trim( sanitize_text_field( wp_unslash( $_GET['q'] ) ) ), 0, self::MAX_NOMBRE ) : '';
		$pagina      = isset( $_GET['paged'] ) ? max( 1, (int) wp_unslash( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$repositorio = new Documento_Repository();
		$total       = $repositorio->contar_publicados( $farmacia_id ?: null, $q );
		$paginas     = max( 1, (int) ceil( $total / self::POR_PAGINA ) );
		$pagina      = min( $pagina, $paginas );
		$documentos  = $repositorio->buscar_publicados( $farmacia_id ?: null, $q, self::POR_PAGINA, ( $pagina - 1 ) * self::POR_PAGINA );

		usort(
			$farmacias,
			static fn( Farmacia $a, Farmacia $b ): int => strcasecmp( $a->get_nombre(), $b->get_nombre() )
		);

		$filtros           = array_filter(
			array(
				'farmacia_id' => $farmacia_id ?: null,
				'q'           => '' !== $q ? $q : null,
			),
			static fn( $v ): bool => null !== $v
		);
		$farmacia_elegida  = $farmacia_id && isset( $por_id[ $farmacia_id ] ) ? $por_id[ $farmacia_id ] : null;
		$aviso             = self::consumir_aviso();
		$url_pagina        = static fn( int $n ): string => self::url_listado( array_merge( $filtros, $n > 1 ? array( 'paged' => $n ) : array() ) );
		$por_pagina        = self::POR_PAGINA;
		$origen_url        = self::url_listado( array_merge( $filtros, $pagina > 1 ? array( 'paged' => $pagina ) : array() ) );

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-documentos-publicados.php';
	}

	/**
	 * @param callable(int): string $nombre_farmacia
	 */
	private static function render_historial( int $documento_id, callable $nombre_farmacia ): void {
		$documento = ( new Documento_Repository() )->find_by_id( $documento_id );
		$repo      = new Publicacion_Evento_Repository();
		$eventos   = $documento ? $repo->find_by_documento( $documento_id ) : array();
		$total     = $documento ? $repo->contar_por_documento( $documento_id ) : 0;

		// Documentos anteriores a la version: no hay registro de lo que paso
		// antes. Se decide por id (monotono), no por fecha: fecha_subida no
		// esta garantizada en UTC. Sin marca (no deberia ocurrir tras migrar),
		// se asume sin registro: nunca se afirma un historial completo.
		$desde_id      = get_option( 'mdf_ca_eventos_desde_doc_id', false );
		$sin_registro  = $documento && ( false === $desde_id || $documento->get_id() <= (int) $desde_id );
		$desde         = (string) get_option( 'mdf_ca_eventos_desde', '' );
		$desde_legible = '' !== $desde ? self::fecha_local( $desde ) : '';

		$filas = array();

		foreach ( $eventos as $evento ) {
			$filas[] = array(
				'fecha'  => self::fecha_local( (string) $evento->fecha ),
				'accion' => Publicacion_Evento_Repository::ACCION_PUBLICADO === $evento->accion ? 'Publicado' : 'Despublicado',
				'origen' => self::ORIGENES[ $evento->origen ] ?? (string) $evento->origen,
				'quien'  => null === $evento->usuario_id ? '—' : self::nombre_usuario( (int) $evento->usuario_id ),
				'lote'   => null === $evento->lote_id ? '—' : (string) (int) $evento->lote_id,
			);
		}

		// fecha_subida se muestra como en el resto de pantallas (sin convertir).
		$recibido      = $documento ? gmdate( 'd/m/Y H:i', (int) strtotime( $documento->get_fecha_subida() . ' UTC' ) ) : '';
		$estado        = $documento ? ( $documento->is_publicado() ? 'Publicado' : 'Pendiente de publicar' ) : '';
		$farmacia      = $documento ? $nombre_farmacia( $documento->get_farmacia_id() ) : '';
		$url_publicados = self::url_listado();
		$url_pendientes = admin_url( 'admin.php?page=mdf-ca-publicacion-documentos' );
		$version_desde  = '0.20.0';
		$truncado       = $total > count( $eventos );

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-historial-documento.php';
	}

	/**
	 * Despublica un documento ya publicado (cualquiera, no solo los recientes).
	 * El evento queda en la misma transaccion (Documento_Publicacion_Service).
	 */
	public static function gestionar_despublicar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		$id = isset( $_POST['documento_id'] ) ? (int) $_POST['documento_id'] : 0;

		check_admin_referer( 'mdf_ca_despublicar_documento_' . $id );

		$cambiados = ( new Documento_Publicacion_Service() )->despublicar_uno( $id, get_current_user_id() );

		if ( is_wp_error( $cambiados ) ) {
			self::guardar_aviso( 'error', $cambiados->get_error_message() );
		} elseif ( $cambiados > 0 ) {
			self::guardar_aviso( 'success', 'Documento despublicado. Su farmacia ya no lo ve.' );
		} else {
			self::guardar_aviso( 'warning', 'El documento no existe o ya estaba pendiente de publicar.' );
		}

		// Misma busqueda y misma pagina, reconstruidas con valores saneados.
		$args = array();

		if ( ! empty( $_POST['farmacia_id'] ) && (int) wp_unslash( $_POST['farmacia_id'] ) > 0 ) {
			$args['farmacia_id'] = (int) wp_unslash( $_POST['farmacia_id'] );
		}

		if ( ! empty( $_POST['q'] ) ) {
			$q = mb_substr( trim( sanitize_text_field( wp_unslash( $_POST['q'] ) ) ), 0, self::MAX_NOMBRE );

			if ( '' !== $q ) {
				$args['q'] = $q;
			}
		}

		if ( ! empty( $_POST['paged'] ) && (int) wp_unslash( $_POST['paged'] ) > 1 ) {
			$args['paged'] = (int) wp_unslash( $_POST['paged'] );
		}

		wp_safe_redirect( self::url_listado( $args ) );
		exit;
	}

	/** Nombre visible de un usuario; si ya no existe, su id. Nunca su email. */
	private static function nombre_usuario( int $usuario_id ): string {
		$usuario = get_userdata( $usuario_id );

		return $usuario ? $usuario->display_name : sprintf( 'usuario borrado (ID %d)', $usuario_id );
	}

	/** Fecha UTC de la base de datos como dd/mm/aaaa hh:mm en la zona de WordPress. */
	private static function fecha_local( string $utc ): string {
		$marca = strtotime( $utc . ' UTC' );

		return false === $marca ? '' : wp_date( 'd/m/Y H:i', $marca );
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

	/** @return array{tipo: string, mensaje: string}|null */
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
