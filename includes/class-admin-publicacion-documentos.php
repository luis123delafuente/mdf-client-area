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
 * Despublicar (uno a uno, con buscador y paginacion) y el historial de cada
 * documento viven en Admin_Documentos_Publicados (#318). Todo cambio de
 * estado deja su evento en Publicacion_Evento_Repository, en la misma
 * transaccion.
 *
 * Acciones: publicar un documento, publicar todos los de una farmacia (solo
 * esa), publicar todos los pendientes de una vez (#316, en dos pasos: ver
 * abajo) y la VISTA PREVIA para el
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
 * Publicar todos los pendientes (#316), mismo patron que el importador CSV:
 * 1. Preparar (POST): Publicacion_Bloque_Service::calcular() con los filtros
 *    (tipo y fecha de recepcion) y, en un transient ligado al usuario
 *    (mdf_ca_publicar_bloque_{user_id}_{id aleatorio}, 15 min), los filtros,
 *    la huella del conjunto y los recuentos. La pantalla de confirmacion se
 *    pinta desde ese transient; el formulario solo lleva el id opaco.
 * 2. Aplicar (POST): el transient se consume (de dos envios, solo aplica
 *    uno) y el servicio recalcula con los filtros GUARDADOS y solo publica
 *    si la huella coincide. Si no coincide, se prepara una confirmacion
 *    nueva y se vuelve a ella sin haber escrito nada.
 *
 * Despublicar lote (#317), mismo patron de dos pasos: preparar cuenta lo que
 * sigue publicado por el lote (Despublicacion_Lote_Service::calcular()) y lo
 * guarda en un transient del usuario (mdf_ca_despublicar_lote_{user_id}_{id},
 * 15 min); aplicar lo consume, recalcula y solo escribe si la huella
 * coincide. El historial de lotes se pinta al final de la pantalla.
 *
 * Sin logs: ni datos fiscales ni nombres.
 */
class Admin_Publicacion_Documentos {

	private const MENU_SLUG         = 'mdf-ca-publicacion-documentos';
	private const PARENT_SLUG       = 'mdf-ca-documentos';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_publicacion_notice_';

	private const CONFIRMACION_TTL      = 15 * MINUTE_IN_SECONDS;
	private const BLOQUE_KEY_PREFIX     = 'mdf_ca_publicar_bloque_';
	private const NONCE_BLOQUE_PREPARAR = 'mdf_ca_publicacion_bloque_preparar';
	private const NONCE_BLOQUE_APLICAR  = 'mdf_ca_publicacion_bloque_aplicar';
	private const NONCE_BLOQUE_CANCELAR = 'mdf_ca_publicacion_bloque_cancelar';

	private const DESHACER_KEY_PREFIX     = 'mdf_ca_despublicar_lote_';
	private const NONCE_DESHACER_PREPARAR = 'mdf_ca_despublicar_lote_preparar';
	private const NONCE_DESHACER_APLICAR  = 'mdf_ca_despublicar_lote_aplicar';
	private const NONCE_DESHACER_CANCELAR = 'mdf_ca_despublicar_lote_cancelar';

	/** Lotes que se listan en el historial. */
	private const LIMITE_LOTES = 20;

	/** Lista de pendientes (#319): farmacias por pagina, documentos por grupo y, con filtro de farmacia, documentos por pagina. */
	private const FARMACIAS_POR_PAGINA = 20;
	private const DOCS_POR_GRUPO       = 50;
	private const DOCS_POR_PAGINA      = 50;

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Documentos en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_post_mdf_ca_publicar_documento', array( __CLASS__, 'gestionar_publicar' ) );
		add_action( 'admin_post_mdf_ca_publicar_farmacia', array( __CLASS__, 'gestionar_publicar_farmacia' ) );
		add_action( 'admin_post_mdf_ca_publicacion_bloque_preparar', array( __CLASS__, 'gestionar_bloque_preparar' ) );
		add_action( 'admin_post_mdf_ca_publicacion_bloque_aplicar', array( __CLASS__, 'gestionar_bloque_aplicar' ) );
		add_action( 'admin_post_mdf_ca_publicacion_bloque_cancelar', array( __CLASS__, 'gestionar_bloque_cancelar' ) );
		add_action( 'admin_post_mdf_ca_despublicar_lote_preparar', array( __CLASS__, 'gestionar_deshacer_preparar' ) );
		add_action( 'admin_post_mdf_ca_despublicar_lote_aplicar', array( __CLASS__, 'gestionar_deshacer_aplicar' ) );
		add_action( 'admin_post_mdf_ca_despublicar_lote_cancelar', array( __CLASS__, 'gestionar_deshacer_cancelar' ) );
		add_action( 'admin_post_mdf_ca_vista_previa', array( __CLASS__, 'gestionar_vista_previa' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'encolar_assets' ) );
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

		// Paso de confirmacion de "Publicar todos los pendientes".
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura; el id resuelve en un transient del propio usuario.
		$bloque_id = self::confirmacion_id_de( $_GET['bloque'] ?? '' );
		$bloque    = $bloque_id ? self::leer_confirmacion( self::BLOQUE_KEY_PREFIX, $bloque_id ) : null;

		if ( $bloque ) {
			$aviso          = self::consumir_aviso();
			$tipos          = Documento_Tipos::get_opciones();
			$nonce_preparar = self::NONCE_BLOQUE_PREPARAR;
			$nonce_aplicar  = self::NONCE_BLOQUE_APLICAR;
			$nonce_cancelar = self::NONCE_BLOQUE_CANCELAR;
			$url_volver     = admin_url( 'admin.php?page=' . self::MENU_SLUG );

			require MDF_CA_PLUGIN_DIR . 'includes/views/admin-publicacion-bloque.php';
			return;
		}

		if ( $bloque_id ) {
			self::guardar_aviso( 'warning', 'La confirmación ha caducado o ya se aplicó. Vuelve a pulsar «Publicar todos los pendientes».' );
		}

		// Paso de confirmacion de "Despublicar lote".
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lectura; el id resuelve en un transient del propio usuario.
		$deshacer_id = self::confirmacion_id_de( $_GET['deshacer'] ?? '' );
		$deshacer    = $deshacer_id ? self::leer_confirmacion( self::DESHACER_KEY_PREFIX, $deshacer_id ) : null;

		if ( $deshacer ) {
			$aviso          = self::consumir_aviso();
			$lote           = ( new Publicacion_Lote_Repository() )->find_by_id( (int) $deshacer['lote_id'] );
			$nonce_aplicar  = self::NONCE_DESHACER_APLICAR;
			$nonce_cancelar = self::NONCE_DESHACER_CANCELAR;
			$url_volver     = admin_url( 'admin.php?page=' . self::MENU_SLUG );
			$quien          = $lote ? self::nombre_usuario( (int) $lote->creado_por ) : '';
			$fecha_lote     = $lote ? self::fecha_local( (string) $lote->creado_en ) : '';

			require MDF_CA_PLUGIN_DIR . 'includes/views/admin-despublicacion-lote.php';
			return;
		}

		if ( $deshacer_id ) {
			self::guardar_aviso( 'warning', 'La confirmación ha caducado o ya se aplicó. Vuelve a pulsar «Despublicar lote» en el historial.' );
		}

		$repositorio = new Documento_Repository();

		$farmacias        = ( new Farmacia_Repository() )->find_all();
		$farmacias_por_id = array();

		foreach ( $farmacias as $farmacia ) {
			$farmacias_por_id[ $farmacia->get_id() ] = $farmacia;
		}

		// Lista paginada en SQL (#319): nunca se cargan todos los pendientes.
		// Sin filtro de farmacia, se pagina por FARMACIAS (cada una con hasta
		// DOCS_POR_GRUPO documentos); con filtro de farmacia hay un solo grupo
		// y se pagina por sus documentos. Los contadores salen de SQL sobre
		// todos los pendientes, no de lo que se pinta.
		$filtro           = Filtro_Listado_Documentos::desde( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- filtros de solo lectura.
		$totales          = $repositorio->totales_pendientes();
		$filtrados        = $filtro->hay_filtros() ? $repositorio->totales_pendientes( $filtro->farmacia(), $filtro->q ) : $totales;
		$por_farmacia     = null !== $filtro->farmacia();
		$por_pagina       = $por_farmacia ? self::DOCS_POR_PAGINA : self::FARMACIAS_POR_PAGINA;
		$paginas          = max( 1, (int) ceil( ( $por_farmacia ? $filtrados['documentos'] : $filtrados['farmacias'] ) / $por_pagina ) );
		$filtro           = $filtro->en_pagina( min( $filtro->pagina, $paginas ) );
		$desplazamiento   = ( $filtro->pagina - 1 ) * $por_pagina;
		$grupos           = array();

		if ( $por_farmacia ) {
			if ( $filtrados['documentos'] > 0 ) {
				$grupos[] = array(
					'farmacia_id' => (int) $filtro->farmacia(),
					'coinciden'   => $filtrados['documentos'],
					'documentos'  => $repositorio->pendientes_de_farmacia( (int) $filtro->farmacia(), $filtro->q, self::DOCS_POR_PAGINA, $desplazamiento ),
					'hay_mas'     => false,
				);
			}
		} else {
			foreach ( $repositorio->grupos_pendientes( null, $filtro->q, self::FARMACIAS_POR_PAGINA, $desplazamiento ) as $grupo ) {
				// Uno de mas para saber si la farmacia tiene mas de los que se pintan.
				$documentos = $repositorio->pendientes_de_farmacia( $grupo['farmacia_id'], $filtro->q, self::DOCS_POR_GRUPO + 1, 0 );
				$grupos[]   = array(
					'farmacia_id' => $grupo['farmacia_id'],
					'coinciden'   => $grupo['coinciden'],
					'documentos'  => array_slice( $documentos, 0, self::DOCS_POR_GRUPO ),
					'hay_mas'     => count( $documentos ) > self::DOCS_POR_GRUPO,
				);
			}
		}

		$pendientes_farmacia = $repositorio->contar_pendientes_por_farmacia( array_column( $grupos, 'farmacia_id' ) );
		$farmacia_elegida    = $por_farmacia && isset( $farmacias_por_id[ $filtro->farmacia() ] ) ? $farmacias_por_id[ $filtro->farmacia() ] : null;
		$url_pagina          = static fn( int $n ): string => add_query_arg( $filtro->en_pagina( $n )->args(), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		$url_farmacia        = static fn( int $id ): string => add_query_arg( array_filter( array( 'farmacia_id' => $id, 'q' => $filtro->q ) ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		$url_sin_filtros     = admin_url( 'admin.php?page=' . self::MENU_SLUG );
		$docs_por_grupo      = self::DOCS_POR_GRUPO;
		$total_pendientes    = $totales['documentos'];

		usort(
			$farmacias,
			static fn( Farmacia $a, Farmacia $b ): int => strcasecmp( $a->get_nombre(), $b->get_nombre() )
		);

		$aviso            = self::consumir_aviso();
		$nonce_preparar   = self::NONCE_BLOQUE_PREPARAR;
		$nonce_deshacer   = self::NONCE_DESHACER_PREPARAR;

		// Historial de lotes (#317): recuentos y quien, sin datos fiscales.
		$lotes           = ( new Publicacion_Lote_Repository() )->find_recientes( self::LIMITE_LOTES );
		$siguen_por_lote = $repositorio->contar_publicados_por_lote( array_map( static fn( $l ): int => (int) $l->id, $lotes ) );
		$historial       = array();

		foreach ( $lotes as $lote ) {
			$historial[] = array(
				'id'            => (int) $lote->id,
				'fecha'         => self::fecha_local( (string) $lote->creado_en ),
				'quien'         => self::nombre_usuario( (int) $lote->creado_por ),
				'filtros'       => self::describir_filtros( (string) $lote->filtros ),
				'documentos'    => (int) $lote->documentos,
				'siguen'        => $siguen_por_lote[ (int) $lote->id ] ?? 0,
				'deshecho'      => Publicacion_Lote_Repository::ESTADO_DESHECHO === $lote->estado,
				'deshecho_en'   => $lote->deshecho_en ? self::fecha_local( (string) $lote->deshecho_en ) : '',
				'deshecho_por'  => $lote->deshecho_por ? self::nombre_usuario( (int) $lote->deshecho_por ) : '',
				'despublicados' => (int) $lote->despublicados,
			);
		}

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-publicacion-documentos.php';
	}

	/** El selector de farmacia con filtro, solo en esta pantalla. */
	public static function encolar_assets( string $hook_suffix ): void {
		if ( 'documentos-mdf_page_' . self::MENU_SLUG === $hook_suffix ) {
			Admin_Documentos::encolar_selector_farmacia();
		}
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

		$cambiados = ( new Documento_Publicacion_Service() )->publicar_uno( $id, get_current_user_id() );

		if ( is_wp_error( $cambiados ) ) {
			self::guardar_aviso( 'error', $cambiados->get_error_message() );
		} elseif ( $cambiados > 0 ) {
			self::guardar_aviso( 'success', 'Documento publicado. Ya lo ve su farmacia.' );
		} else {
			self::guardar_aviso( 'warning', 'El documento no existe o ya estaba publicado.' );
		}

		self::redirigir( Filtro_Listado_Documentos::desde_retorno( $_POST )->args() );
	}

	/** Publica los pendientes de UNA farmacia; ninguna otra se toca. */
	public static function gestionar_publicar_farmacia(): void {
		self::exigir_capacidad();

		$farmacia_id = isset( $_POST['farmacia_id'] ) ? (int) $_POST['farmacia_id'] : 0;

		check_admin_referer( 'mdf_ca_publicar_farmacia_' . $farmacia_id );

		if ( $farmacia_id < 1 || ! ( new Farmacia_Repository() )->find_by_id( $farmacia_id ) ) {
			self::guardar_aviso( 'error', 'La farmacia no existe.' );
			self::redirigir( Filtro_Listado_Documentos::desde_retorno( $_POST )->args() );
		}

		$cambiados = ( new Documento_Publicacion_Service() )->publicar_farmacia( $farmacia_id, get_current_user_id() );

		if ( is_wp_error( $cambiados ) ) {
			self::guardar_aviso( 'error', $cambiados->get_error_message() );
		} else {
			self::guardar_aviso( $cambiados > 0 ? 'success' : 'warning', sprintf( 'Documentos publicados: %d.', $cambiados ) );
		}

		self::redirigir( Filtro_Listado_Documentos::desde_retorno( $_POST )->args() );
	}

	/**
	 * Paso 1 de "Publicar todos los pendientes": calcula el conjunto con los
	 * filtros y lleva a la pantalla de confirmacion. No publica nada.
	 */
	public static function gestionar_bloque_preparar(): void {
		self::exigir_capacidad();

		check_admin_referer( self::NONCE_BLOQUE_PREPARAR );

		// Un "Recalcular" desde una confirmacion sustituye la anterior.
		$anterior = self::confirmacion_id_de( $_POST['bloque'] ?? '' );

		$filtros = Publicacion_Bloque_Service::normalizar_filtros(
			isset( $_POST['tipo'] ) ? sanitize_text_field( wp_unslash( $_POST['tipo'] ) ) : '',
			isset( $_POST['desde'] ) ? sanitize_text_field( wp_unslash( $_POST['desde'] ) ) : '',
			isset( $_POST['hasta'] ) ? sanitize_text_field( wp_unslash( $_POST['hasta'] ) ) : ''
		);

		if ( is_wp_error( $filtros ) ) {
			self::guardar_aviso( 'error', $filtros->get_error_message() );
			self::redirigir( $anterior && self::leer_confirmacion( self::BLOQUE_KEY_PREFIX, $anterior ) ? array( 'bloque' => $anterior ) : array() );
		}

		$conjunto = ( new Publicacion_Bloque_Service() )->calcular( $filtros );

		if ( is_wp_error( $conjunto ) ) {
			self::guardar_aviso( 'error', $conjunto->get_error_message() );
			self::redirigir();
		}

		if ( $anterior ) {
			delete_transient( self::clave_confirmacion( self::BLOQUE_KEY_PREFIX, $anterior ) );
		}

		self::redirigir( array( 'bloque' => self::guardar_bloque( $filtros, $conjunto ) ) );
	}

	/**
	 * Paso 2: publica exactamente lo confirmado (ver la cabecera). Lo que se
	 * publica sale del recalculo en servidor, nunca del formulario.
	 */
	public static function gestionar_bloque_aplicar(): void {
		self::exigir_capacidad();

		check_admin_referer( self::NONCE_BLOQUE_APLICAR );

		$bloque_id = self::confirmacion_id_de( $_POST['bloque'] ?? '' );
		$bloque    = $bloque_id ? self::consumir_confirmacion( self::BLOQUE_KEY_PREFIX, $bloque_id ) : null;

		if ( ! $bloque ) {
			self::guardar_aviso( 'warning', 'La confirmación ha caducado o ya se aplicó. No se ha publicado nada más.' );
			self::redirigir();
		}

		$servicio  = new Publicacion_Bloque_Service();
		$resultado = $servicio->aplicar( $bloque['filtros'], $bloque['huella'], get_current_user_id() );

		if ( is_wp_error( $resultado ) ) {
			if ( Publicacion_Bloque_Service::ERROR_CAMBIADO === $resultado->get_error_code() ) {
				$conjunto = $servicio->calcular( $bloque['filtros'] );

				if ( ! is_wp_error( $conjunto ) ) {
					self::guardar_aviso( 'warning', $resultado->get_error_message() );
					self::redirigir( array( 'bloque' => self::guardar_bloque( $bloque['filtros'], $conjunto ) ) );
				}
			}

			// Nada se ha escrito: se repone la confirmacion para reintentar.
			self::reponer_confirmacion( self::BLOQUE_KEY_PREFIX, $bloque_id, $bloque );
			self::guardar_aviso( 'error', $resultado->get_error_message() );
			self::redirigir( array( 'bloque' => $bloque_id ) );
		}

		$mensaje = sprintf(
			'Publicados %s de %s.',
			self::plural( $resultado['publicados'], 'documento', 'documentos' ),
			self::plural( $resultado['farmacias'], 'farmacia', 'farmacias' )
		);

		if ( $resultado['excluidos'] > 0 ) {
			$mensaje .= sprintf( ' Excluidos: %d (su farmacia ya no existe; siguen pendientes).', $resultado['excluidos'] );
		}

		if ( $resultado['ya_publicados'] > 0 ) {
			$mensaje .= sprintf( ' Ya estaban publicados: %d.', $resultado['ya_publicados'] );
		}

		self::guardar_aviso( $resultado['publicados'] > 0 ? 'success' : 'warning', $mensaje );
		self::redirigir();
	}

	public static function gestionar_bloque_cancelar(): void {
		self::exigir_capacidad();

		check_admin_referer( self::NONCE_BLOQUE_CANCELAR );

		$bloque_id = self::confirmacion_id_de( $_POST['bloque'] ?? '' );

		if ( $bloque_id ) {
			self::consumir_confirmacion( self::BLOQUE_KEY_PREFIX, $bloque_id );
		}

		self::guardar_aviso( 'success', 'Publicación cancelada. No se ha publicado nada.' );
		self::redirigir();
	}

	/**
	 * Paso 1 de "Despublicar lote" (#317): cuenta lo que sigue publicado por
	 * el lote y lleva a la confirmacion. No cambia nada.
	 */
	public static function gestionar_deshacer_preparar(): void {
		self::exigir_capacidad();

		check_admin_referer( self::NONCE_DESHACER_PREPARAR );

		$lote_id  = isset( $_POST['lote_id'] ) ? (int) $_POST['lote_id'] : 0;
		$conjunto = $lote_id > 0 ? ( new Despublicacion_Lote_Service() )->calcular( $lote_id ) : new \WP_Error( 'mdf_ca_despublicacion_lote_no_existe', 'El lote no existe.' );

		if ( is_wp_error( $conjunto ) ) {
			self::guardar_aviso( 'error', $conjunto->get_error_message() );
			self::redirigir();
		}

		self::redirigir( array( 'deshacer' => self::guardar_deshacer( $conjunto ) ) );
	}

	/**
	 * Paso 2: despublica exactamente lo confirmado. Lo que se despublica sale
	 * del recalculo en servidor, nunca del formulario.
	 */
	public static function gestionar_deshacer_aplicar(): void {
		self::exigir_capacidad();

		check_admin_referer( self::NONCE_DESHACER_APLICAR );

		$deshacer_id = self::confirmacion_id_de( $_POST['deshacer'] ?? '' );
		$deshacer    = $deshacer_id ? self::consumir_confirmacion( self::DESHACER_KEY_PREFIX, $deshacer_id ) : null;

		if ( ! $deshacer ) {
			self::guardar_aviso( 'warning', 'La confirmación ha caducado o ya se aplicó. No se ha cambiado nada más.' );
			self::redirigir();
		}

		$servicio  = new Despublicacion_Lote_Service();
		$lote_id   = (int) $deshacer['lote_id'];
		$resultado = $servicio->aplicar( $lote_id, (string) $deshacer['huella'], get_current_user_id() );

		if ( is_wp_error( $resultado ) ) {
			if ( Despublicacion_Lote_Service::ERROR_CAMBIADO === $resultado->get_error_code() ) {
				$conjunto = $servicio->calcular( $lote_id );

				if ( ! is_wp_error( $conjunto ) ) {
					self::guardar_aviso( 'warning', $resultado->get_error_message() );
					self::redirigir( array( 'deshacer' => self::guardar_deshacer( $conjunto ) ) );
				}
			}

			// Un lote ya deshecho no se repone: no queda nada que confirmar.
			if ( 'mdf_ca_despublicacion_lote_deshecho' !== $resultado->get_error_code() ) {
				self::reponer_confirmacion( self::DESHACER_KEY_PREFIX, $deshacer_id, $deshacer );
				self::guardar_aviso( 'error', $resultado->get_error_message() );
				self::redirigir( array( 'deshacer' => $deshacer_id ) );
			}

			self::guardar_aviso( 'warning', $resultado->get_error_message() );
			self::redirigir();
		}

		self::guardar_aviso(
			$resultado['despublicados'] > 0 ? 'success' : 'warning',
			sprintf(
				'Lote deshecho: despublicados %s de %s. Sus farmacias ya no los ven (lo que ya vieron o descargaron no se puede retirar).',
				self::plural( $resultado['despublicados'], 'documento', 'documentos' ),
				self::plural( $resultado['farmacias'], 'farmacia', 'farmacias' )
			)
		);
		self::redirigir();
	}

	public static function gestionar_deshacer_cancelar(): void {
		self::exigir_capacidad();

		check_admin_referer( self::NONCE_DESHACER_CANCELAR );

		$deshacer_id = self::confirmacion_id_de( $_POST['deshacer'] ?? '' );

		if ( $deshacer_id ) {
			self::consumir_confirmacion( self::DESHACER_KEY_PREFIX, $deshacer_id );
		}

		self::guardar_aviso( 'success', 'Despublicación del lote cancelada. No se ha cambiado nada.' );
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

	/** @param array<string, string> $args_extra */
	private static function redirigir( array $args_extra = array() ): void {
		wp_safe_redirect( add_query_arg( $args_extra, admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	// ------------------------------------------------------------------
	// Confirmaciones de dos pasos: "Publicar todos los pendientes" (#316) y
	// "Despublicar lote" (#317). Mismo mecanismo, distinto prefijo.
	// ------------------------------------------------------------------

	/**
	 * Id de confirmacion saneado, o '' si no tiene la forma que genera
	 * nueva_confirmacion() (20 alfanumericos).
	 *
	 * @param mixed $valor
	 */
	private static function confirmacion_id_de( $valor ): string {
		$valor = is_string( $valor ) ? wp_unslash( $valor ) : '';

		return 1 === preg_match( '/^[A-Za-z0-9]{20}$/', $valor ) ? $valor : '';
	}

	/** La clave incluye el usuario actual: un id ajeno nunca resuelve. */
	private static function clave_confirmacion( string $prefijo, string $id ): string {
		return $prefijo . get_current_user_id() . '_' . $id;
	}

	/**
	 * Guarda lo que se va a ensenar en una confirmacion (nunca los ids: al
	 * aplicar se recalculan y se comparan por la huella). Devuelve el id.
	 *
	 * @param array<string, mixed> $datos Debe llevar 'huella'.
	 */
	private static function nueva_confirmacion( string $prefijo, array $datos ): string {
		$id = wp_generate_password( 20, false, false );

		self::reponer_confirmacion( $prefijo, $id, $datos );

		return $id;
	}

	/** @param array<string, mixed> $datos */
	private static function reponer_confirmacion( string $prefijo, string $id, array $datos ): void {
		set_transient( self::clave_confirmacion( $prefijo, $id ), $datos, self::CONFIRMACION_TTL );
	}

	/** @return array<string, mixed>|null */
	private static function leer_confirmacion( string $prefijo, string $id ): ?array {
		$datos = get_transient( self::clave_confirmacion( $prefijo, $id ) );

		return is_array( $datos ) && isset( $datos['huella'] ) ? $datos : null;
	}

	/**
	 * Lee y borra. Solo devuelve la confirmacion quien consigue borrarla: de
	 * dos envios simultaneos, solo uno llega a aplicar.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function consumir_confirmacion( string $prefijo, string $id ): ?array {
		$datos = self::leer_confirmacion( $prefijo, $id );

		if ( ! $datos || ! delete_transient( self::clave_confirmacion( $prefijo, $id ) ) ) {
			return null;
		}

		return $datos;
	}

	/**
	 * Confirmacion de "Publicar todos los pendientes".
	 *
	 * @param array{tipo: ?string, desde: ?string, hasta: ?string} $filtros
	 * @param array<string, mixed>                                 $conjunto Salida de Publicacion_Bloque_Service::calcular().
	 */
	private static function guardar_bloque( array $filtros, array $conjunto ): string {
		return self::nueva_confirmacion(
			self::BLOQUE_KEY_PREFIX,
			array(
				'filtros'        => $filtros,
				'huella'         => $conjunto['huella'],
				'documentos'     => $conjunto['documentos'],
				'farmacias'      => $conjunto['farmacias'],
				'excluidos'      => $conjunto['excluidos'],
				'recibido_desde' => $conjunto['recibido_desde'],
				'recibido_hasta' => $conjunto['recibido_hasta'],
			)
		);
	}

	/**
	 * Confirmacion de "Despublicar lote".
	 *
	 * @param array<string, mixed> $conjunto Salida de Despublicacion_Lote_Service::calcular().
	 */
	private static function guardar_deshacer( array $conjunto ): string {
		return self::nueva_confirmacion(
			self::DESHACER_KEY_PREFIX,
			array(
				'lote_id'         => $conjunto['lote_id'],
				'huella'          => $conjunto['huella'],
				'documentos'      => $conjunto['documentos'],
				'farmacias'       => $conjunto['farmacias'],
				'documentos_lote' => $conjunto['documentos_lote'],
			)
		);
	}

	/** Nombre visible de un usuario, o que ya no existe. Nunca su email. */
	private static function nombre_usuario( int $usuario_id ): string {
		$usuario = get_userdata( $usuario_id );

		return $usuario ? $usuario->display_name : sprintf( 'usuario borrado (ID %d)', $usuario_id );
	}

	/** Fecha UTC de la base de datos como dd/mm/aaaa hh:mm en la zona de WordPress. */
	private static function fecha_local( string $utc ): string {
		$marca = strtotime( $utc . ' UTC' );

		return false === $marca ? '' : wp_date( 'd/m/Y H:i', $marca );
	}

	/** Filtros de un lote (JSON guardado) en una linea legible. */
	private static function describir_filtros( string $json ): string {
		$filtros = json_decode( $json, true );

		if ( ! is_array( $filtros ) ) {
			return '';
		}

		$partes = array( empty( $filtros['tipo'] ) ? 'Todos los tipos' : Documento_Tipos::get_etiqueta( (string) $filtros['tipo'] ) );

		if ( ! empty( $filtros['desde'] ) || ! empty( $filtros['hasta'] ) ) {
			$partes[] = sprintf(
				'recibidos %s – %s',
				empty( $filtros['desde'] ) ? '…' : Documento::formatear_fecha_corta( (string) $filtros['desde'] ),
				empty( $filtros['hasta'] ) ? '…' : Documento::formatear_fecha_corta( (string) $filtros['hasta'] )
			);
		}

		return implode( ', ', $partes );
	}

	private static function plural( int $n, string $singular, string $plural ): string {
		return sprintf( '%d %s', $n, 1 === $n ? $singular : $plural );
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
