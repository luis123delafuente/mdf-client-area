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
 * Dos rutas (v0.36.0):
 * - /mdf-ca-documento/{id}/: los bytes, solo para el fetch() del visor
 *   (token de un solo uso siempre, nunca una navegacion directa);
 * - /mdf-ca-documento/{id}/descargar/: el fichero como adjunto, con el
 *   nombre del documento, solo si Permissions::puede_descargar_documento().
 *   Desde la 0.36.0 cualquier documento puede ser descargable (antes, solo
 *   un Excel); lo decide su casilla, nunca un parametro de la peticion.
 *
 * Denegado (sin sesion, documento ajeno o documento inexistente) responde
 * siempre 404, nunca 403: un 403 confirmaria que el ID corresponde a un
 * documento real que existe pero no es tuyo, que es justo la pista de
 * enumeracion que se quiere evitar. 404 no confirma ni niega la existencia
 * del recurso.
 */
class Documento_Endpoint {

	private const QUERY_VAR = 'mdf_ca_documento_id';

	private const QUERY_VAR_DESCARGAR = 'mdf_ca_documento_descargar';

	/**
	 * Ruta de la carpeta privada relativa a ABSPATH. La usa tambien
	 * Carpeta_Privada_Proteccion para la URL de la sonda y para los avisos
	 * (que nunca muestran la ruta absoluta).
	 */
	public const CARPETA_RELATIVA = 'Clientes/private-docs';

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
		add_rewrite_rule(
			'^mdf-ca-documento/([0-9]+)/descargar/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]&' . self::QUERY_VAR_DESCARGAR . '=1',
			'top'
		);
	}

	public static function url_descarga( int $documento_id ): string {
		return home_url( 'mdf-ca-documento/' . $documento_id . '/descargar/' );
	}

	public static function registrar_query_var( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_DESCARGAR;
		return $vars;
	}

	public static function gestionar_peticion(): void {
		$documento_id = get_query_var( self::QUERY_VAR );

		if ( '' === $documento_id ) {
			return; // No es una peticion para este endpoint.
		}

		$documento = ( new Documento_Repository() )->find_by_id( (int) $documento_id );
		$usuario   = wp_get_current_user();

		if ( '1' === (string) get_query_var( self::QUERY_VAR_DESCARGAR ) ) {
			if ( ! Permissions::puede_descargar_documento( $usuario, $documento ) ) {
				self::responder_no_encontrado();
			}

			self::servir_fichero( $documento, true );
		}

		// Los bytes, solo para el visor: token de un solo uso y nunca una
		// navegacion directa, sea o no descargable. Mismo 404 uniforme que el
		// resto de rechazos, sin pista de por que.
		if ( ! Permissions::puede_ver_documento( $usuario, $documento ) || ! self::es_peticion_del_visor( $usuario, $documento ) ) {
			self::responder_no_encontrado();
		}

		self::servir_fichero( $documento, false );
	}

	/**
	 * Refuerzo: una navegacion directa a la URL (barra de direcciones,
	 * enlace) llega con Sec-Fetch-Dest: document y se rechaza aunque el
	 * token fuera valido. Si el navegador no envia la cabecera (antiguo), se
	 * ignora y decide solo el token: su ausencia no rompe nada.
	 */
	/**
	 * Publico para que la vista previa del administrador (#304) aplique la
	 * misma regla: token de un solo uso y nunca una navegacion directa.
	 */
	public static function es_peticion_del_visor( \WP_User $usuario, Documento $documento ): bool {
		$destino = isset( $_SERVER['HTTP_SEC_FETCH_DEST'] ) ? strtolower( (string) $_SERVER['HTTP_SEC_FETCH_DEST'] ) : '';

		if ( 'document' === $destino ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- el token de un solo uso es la verificacion.
		$token = isset( $_GET[ Documento_Token::PARAMETRO ] ) ? sanitize_text_field( wp_unslash( $_GET[ Documento_Token::PARAMETRO ] ) ) : '';

		return Documento_Token::consumir( $token, (int) $usuario->ID, $documento->get_id() );
	}

	public static function responder_no_encontrado(): void {
		status_header( 404 );
		nocache_headers();
		wp_die( 'Documento no encontrado.', 'Documento no encontrado', array( 'response' => 404 ) );
	}

	/**
	 * Publico para la vista previa del administrador (#304). Lee solo de la
	 * carpeta privada (Descarga_Fichero: realpath + prefijo) y no decide
	 * permisos: quien lo llama ya los ha comprobado. Como adjunto, con el
	 * nombre del documento y la extension de su tipo.
	 */
	public static function servir_fichero( Documento $documento, bool $como_adjunto ): void {
		$carpeta = self::get_carpeta_documentos();

		Descarga_Fichero::servir(
			$carpeta,
			$carpeta . DIRECTORY_SEPARATOR . basename( (string) $documento->get_ruta_fichero() ),
			$documento->get_tipo_mime() ?: 'application/octet-stream',
			$como_adjunto ? $documento->get_nombre() : null
		);
	}

	/**
	 * Publico (antes privado) para que Documento_Service la reutilice al
	 * guardar un fichero recien subido: una unica fuente de verdad para la
	 * ruta de la carpeta privada, en vez de duplicar la cadena en dos
	 * sitios. No cambia nada de la logica de permisos de esta clase.
	 */
	public static function get_carpeta_documentos(): string {
		return ABSPATH . self::CARPETA_RELATIVA;
	}
}
