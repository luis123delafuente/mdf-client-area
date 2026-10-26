<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unica entrada HTTP al archivo de una herramienta del catalogo (regla no
 * negociable 1: nunca un enlace directo). Mismo mecanismo que
 * Documento_Endpoint y Documento_Visor (rewrite rule + template_redirect,
 * fuera de wp-admin), tres rutas:
 *
 * - /mdf-ca-herramienta/{id}/ver/: la pagina del visor. Sin sesion, al
 *   login (como el visor de documentos y las paginas del area privada). Con
 *   sesion, si Permissions no deja visualizarla, el 404 uniforme. La marca
 *   de agua sale de lo guardado en la herramienta, nunca de la peticion.
 * - /mdf-ca-herramienta/{id}/fichero/: los bytes, solo para el fetch() del
 *   visor: token de un solo uso de Documento_Token con su propio ambito
 *   (un token de documento o de la bandeja no vale aqui, ni al reves) y
 *   nunca a una navegacion directa (Sec-Fetch-Dest: document).
 * - /mdf-ca-herramienta/{id}/descargar/: el fichero como adjunto, con el
 *   nombre de la herramienta, solo si Permissions::
 *   puede_descargar_item_catalogo() lo permite.
 *
 * Cualquier rechazo (sin sesion en fichero y descargar, herramienta ajena,
 * oculta, inexistente, no descargable, .xlsm con el ajuste apagado, token
 * malo) es el mismo 404 de Documento_Endpoint, sin pista del motivo.
 */
class Herramienta_Endpoint {

	private const QUERY_VAR_ID     = 'mdf_ca_herramienta_id';
	private const QUERY_VAR_ACCION = 'mdf_ca_herramienta_accion';

	public const AMBITO_TOKEN = 'herramienta';

	public const VER       = 'ver';
	public const FICHERO   = 'fichero';
	public const DESCARGAR = 'descargar';

	public static function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'registrar_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'registrar_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'gestionar_peticion' ) );
	}

	public static function registrar_rewrite(): void {
		add_rewrite_rule(
			'^mdf-ca-herramienta/([0-9]+)/(ver|fichero|descargar)/?$',
			'index.php?' . self::QUERY_VAR_ID . '=$matches[1]&' . self::QUERY_VAR_ACCION . '=$matches[2]',
			'top'
		);
	}

	public static function registrar_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR_ID;
		$vars[] = self::QUERY_VAR_ACCION;
		return $vars;
	}

	public static function url( int $id, string $accion ): string {
		return home_url( 'mdf-ca-herramienta/' . $id . '/' . $accion . '/' );
	}

	public static function gestionar_peticion(): void {
		$id = get_query_var( self::QUERY_VAR_ID );

		if ( '' === $id ) {
			return; // No es una peticion para este endpoint.
		}

		$accion = (string) get_query_var( self::QUERY_VAR_ACCION );

		if ( self::VER === $accion && ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( home_url( add_query_arg( null, null ) ) ) );
			exit;
		}

		$item    = ( new Catalogo_Repository() )->find_by_id( (int) $id );
		$usuario = wp_get_current_user();

		switch ( $accion ) {
			case self::VER:
				if ( ! Permissions::puede_visualizar_item_catalogo( $usuario, $item ) ) {
					Documento_Endpoint::responder_no_encontrado();
				}

				self::renderizar_visor( $usuario, $item );
				break;

			case self::FICHERO:
				if ( ! Permissions::puede_visualizar_item_catalogo( $usuario, $item ) || ! self::es_peticion_del_visor( $usuario, $item ) ) {
					Documento_Endpoint::responder_no_encontrado();
				}

				self::servir( $item, false );
				break;

			case self::DESCARGAR:
				if ( ! Permissions::puede_descargar_item_catalogo( $usuario, $item ) ) {
					Documento_Endpoint::responder_no_encontrado();
				}

				self::servir( $item, true );
				break;
		}

		Documento_Endpoint::responder_no_encontrado();
	}

	/**
	 * El visor de documentos, tal cual (canvas, sin imprimir ni guardar, el
	 * fichero por fetch con token), apuntando a la ruta "fichero". Marca de
	 * agua: CIF y nombre de la farmacia que la abre, salvo que la herramienta
	 * este guardada sin marca. Boton "Descargar" solo si Permissions lo
	 * permite, y descarga por su propia ruta (sin marca, a proposito).
	 */
	private static function renderizar_visor( \WP_User $usuario, Catalogo_Item $item ): void {
		$marca = '';

		if ( $item->con_marca_de_agua() ) {
			$farmacia = ( new Farmacia_Repository() )->find_by_wp_user_id( $usuario->ID );
			$marca    = $farmacia ? $farmacia->get_cif() . ' - ' . $farmacia->get_nombre() : '';
		}

		$url_descarga = Permissions::puede_descargar_item_catalogo( $usuario, $item ) ? self::url( $item->get_id(), self::DESCARGAR ) : null;
		$token        = Documento_Token::emitir( (int) $usuario->ID, $item->get_id(), self::AMBITO_TOKEN );

		nocache_headers();
		Documento_Visor::pintar( self::url( $item->get_id(), self::FICHERO ), $token, $marca, $url_descarga, $item->get_nombre(), Documento_Visor::url_pagina_area( Area_Privada_Pages::SLUG_HERRAMIENTAS ) );
		exit;
	}

	/** Token de un solo uso de este ambito y nunca una navegacion directa. */
	private static function es_peticion_del_visor( \WP_User $usuario, Catalogo_Item $item ): bool {
		$destino = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( (string) $_SERVER['HTTP_SEC_FETCH_DEST'] ) : '';

		if ( 'document' === $destino ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- el token de un solo uso es la verificacion.
		$token = isset( $_GET[ Documento_Token::PARAMETRO ] ) ? sanitize_text_field( wp_unslash( $_GET[ Documento_Token::PARAMETRO ] ) ) : '';

		return Documento_Token::consumir( $token, (int) $usuario->ID, $item->get_id(), self::AMBITO_TOKEN );
	}

	/**
	 * Solo la carpeta de herramientas (nombre generado; Descarga_Fichero
	 * comprueba realpath y prefijo). No decide permisos: quien llama ya los ha
	 * comprobado.
	 */
	private static function servir( Catalogo_Item $item, bool $como_adjunto ): void {
		$ruta = $item->get_ruta_fichero() ? Catalogo_Service::ruta_en_carpeta( $item->get_ruta_fichero() ) : null;

		if ( null === $ruta ) {
			Documento_Endpoint::responder_no_encontrado();
		}

		$formato = (string) $item->get_formato();

		Descarga_Fichero::servir(
			Catalogo_Service::get_carpeta(),
			$ruta,
			Catalogo_Formatos::get_mime( $formato ),
			$como_adjunto ? $item->get_nombre() : null,
			Catalogo_Formatos::get_extension( $formato )
		);
	}
}
