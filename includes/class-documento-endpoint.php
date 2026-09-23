<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico punto de entrada HTTP para descargar un documento privado
 * (CLAUDE.md, regla no negociable 1: nunca un enlace directo).
 *
 * Se implementa como rewrite rule + template_redirect, no como
 * admin-post.php: admin-post.php carga wp-admin/admin.php, que mata con su
 * propio wp_die(403) a cualquier usuario logueado sin la capacidad 'read'
 * (hallazgo de #229) -- y mdf_cliente no tiene ninguna capacidad a
 * proposito. template_redirect corre en el ciclo de vida normal del
 * front-end, fuera de wp-admin, asi que ni ese gate ni el bloqueo de
 * wp-admin de Role_Restrictions (que solo actua si is_admin() es cierto)
 * interfieren, y funciona igual con o sin sesion sin variantes "nopriv".
 *
 * Denegado (sin sesion, documento ajeno o documento inexistente) responde
 * siempre 404, nunca 403: un 403 confirmaria que el ID corresponde a un
 * documento real que existe pero no es tuyo, que es justo la pista de
 * enumeracion que se quiere evitar. 404 no confirma ni niega la existencia
 * del recurso.
 */
class Documento_Endpoint {

	private const QUERY_VAR = 'mdf_ca_documento_id';

	public static function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'registrar_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'registrar_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'gestionar_peticion' ) );
	}

	public static function registrar_rewrite(): void {
		add_rewrite_rule(
			'^mdf-ca-documento/([0-9]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function registrar_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function gestionar_peticion(): void {
		$documento_id = get_query_var( self::QUERY_VAR );

		if ( '' === $documento_id ) {
			return; // No es una peticion para este endpoint.
		}

		$documento = ( new Documento_Repository() )->find_by_id( (int) $documento_id );
		$usuario   = wp_get_current_user();

		if ( ! Permissions::puede_ver_documento( $usuario, $documento ) ) {
			self::responder_no_encontrado();
		}

		self::servir_fichero( $documento );
	}

	private static function responder_no_encontrado(): void {
		status_header( 404 );
		nocache_headers();
		wp_die( 'Documento no encontrado.', 'Documento no encontrado', array( 'response' => 404 ) );
	}

	private static function servir_fichero( Documento $documento ): void {
		$carpeta_real = realpath( self::get_carpeta_documentos() );
		$ruta_real    = $carpeta_real ? realpath( $carpeta_real . DIRECTORY_SEPARATOR . $documento->get_ruta_fichero() ) : false;

		// Defensa en profundidad: el fichero resuelto debe seguir dentro de
		// la carpeta privada, aunque ruta_fichero venga de nuestra propia
		// BD y no de entrada directa del usuario.
		if ( ! $carpeta_real || ! $ruta_real || 0 !== strpos( $ruta_real, $carpeta_real . DIRECTORY_SEPARATOR ) || ! is_file( $ruta_real ) ) {
			self::responder_no_encontrado();
		}

		nocache_headers();
		header( 'Content-Type: ' . ( $documento->get_tipo_mime() ?: 'application/octet-stream' ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $documento->get_nombre() ) . '"' );
		header( 'Content-Length: ' . filesize( $ruta_real ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $ruta_real );
		exit;
	}

	/**
	 * Publico (antes privado) para que Documento_Service la reutilice al
	 * guardar un fichero recien subido: una unica fuente de verdad para la
	 * ruta de la carpeta privada, en vez de duplicar la cadena en dos
	 * sitios. No cambia nada de la logica de permisos de esta clase.
	 */
	public static function get_carpeta_documentos(): string {
		return ABSPATH . 'Clientes/private-docs';
	}
}
