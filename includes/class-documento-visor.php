<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plantilla propia del visor de documentos (#248): renderiza el HTML/JS que
 * pinta un documento (PDF o imagen) en un <canvas>, sin descarga ni
 * impresion directas y con marca de agua. No sirve el fichero -- eso lo
 * sigue haciendo exclusivamente Documento_Endpoint, sin tocar una sola
 * linea de su logica de permisos ni de su respuesta 404 (regla no
 * negociable 1 de CLAUDE.md y peticion explicita de esta tarea). El
 * navegador pide el fichero por su cuenta, con fetch() autenticado por
 * cookie de sesion, desde el propio JS del visor (Documento_Visor.js).
 *
 * Proteccion en dos capas, cada una en su sitio:
 * 1. Esta clase exige solo sesion (is_user_logged_in()), igual que
 *    Role_Restrictions::proteger_area_privada() protege las cuatro paginas
 *    del area privada -- mismo mecanismo (wp_safe_redirect a
 *    wp_login_url()), no un segundo sistema paralelo. No comprueba de quien
 *    es el documento: hacerlo aqui duplicaria Permissions::
 *    puede_ver_documento() en un segundo sitio, justo lo que CLAUDE.md
 *    prohibe.
 * 2. La farmacia del documento la sigue validando unicamente
 *    Documento_Endpoint via Permissions cuando el JS hace el fetch: un
 *    usuario logueado que visite el visor de un documento ajeno vera la
 *    pagina del visor (sesion valida), pero el fetch recibira el mismo 404
 *    uniforme que ya da el endpoint, y el visor lo muestra como "documento
 *    no encontrado" -- ninguna pista adicional escapa por este camino.
 *
 * Esta plantilla no pasa por get_header()/get_footer() ni por Elementor a
 * proposito (igual que Documento_Endpoint no pasa por el tema para servir
 * el fichero): es una interfaz bloqueada (sin menu contextual, sin atajos
 * de impresion/guardado) y anadir el HTML/CSS/JS del tema por encima solo
 * multiplicaria superficie sin aportar nada al objetivo.
 */
class Documento_Visor {

	private const QUERY_VAR = 'mdf_ca_visor_documento_id';

	public static function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'registrar_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'registrar_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'gestionar_peticion' ) );
	}

	public static function registrar_rewrite(): void {
		add_rewrite_rule(
			'^mdf-ca-visor/([0-9]+)/?$',
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
			return; // No es una peticion para este visor.
		}

		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( self::url_actual() ) );
			exit;
		}

		self::renderizar( (int) $documento_id );
		exit;
	}

	private static function url_actual(): string {
		return home_url( add_query_arg( null, null ) );
	}

	/**
	 * Texto de la marca de agua: CIF y nombre de la farmacia del usuario
	 * logueado, resueltos via Farmacia_Repository -- no via Permissions.
	 * Esto no es una decision de visibilidad sobre el documento (eso sigue
	 * siendo solo del endpoint al hacer el fetch), es leer "cual es mi
	 * propia farmacia", exactamente lo mismo que ya hace
	 * Shortcode_Listado_Documentos::farmacia_actual() para pintar el
	 * listado de cada usuario. Sin farmacia vinculada (p. ej. un
	 * administrador entrando a curiosear la URL), la marca de agua queda
	 * vacia -- inofensivo, porque sin farmacia el fetch al endpoint
	 * devolvera 404 de todos modos y el visor nunca llega a pintar nada.
	 */
	private static function get_marca_agua_texto(): string {
		$farmacia = ( new Farmacia_Repository() )->find_by_wp_user_id( get_current_user_id() );

		if ( ! $farmacia ) {
			return '';
		}

		return $farmacia->get_cif() . ' - ' . $farmacia->get_nombre();
	}

	private static function renderizar( int $documento_id ): void {
		nocache_headers();

		$endpoint_url  = home_url( 'mdf-ca-documento/' . $documento_id . '/' );
		$pdfjs_base    = plugins_url( 'assets/vendor/pdfjs', MDF_CA_PLUGIN_FILE );
		$visor_css_url = plugins_url( 'assets/css/documento-visor.css', MDF_CA_PLUGIN_FILE );
		$visor_js_url  = plugins_url( 'assets/js/documento-visor.js', MDF_CA_PLUGIN_FILE );
		$marca_agua    = self::get_marca_agua_texto();

		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="robots" content="noindex, nofollow" />
	<title>Documento</title>
	<link rel="stylesheet" href="<?php echo esc_url( $visor_css_url ); ?>" />
</head>
<body>
	<div id="mdf-ca-visor" class="mdf-ca-visor">
		<div class="mdf-ca-visor__barra">
			<button id="mdf-ca-visor-anterior" type="button" class="mdf-ca-visor__boton" disabled>&larr; Anterior</button>
			<span id="mdf-ca-visor-pagina" class="mdf-ca-visor__pagina"></span>
			<button id="mdf-ca-visor-siguiente" type="button" class="mdf-ca-visor__boton" disabled>Siguiente &rarr;</button>
		</div>
		<div id="mdf-ca-visor-lienzo" class="mdf-ca-visor__lienzo">
			<canvas id="mdf-ca-visor-canvas" class="mdf-ca-visor__canvas"></canvas>
		</div>
		<p id="mdf-ca-visor-estado" class="mdf-ca-visor__estado" hidden></p>
	</div>

	<script>
		window.MDF_CA_VISOR = {
			endpointUrl: <?php echo wp_json_encode( $endpoint_url ); ?>,
			pdfjsBase: <?php echo wp_json_encode( $pdfjs_base ); ?>,
			marcaAgua: <?php echo wp_json_encode( $marca_agua ); ?>
		};
	</script>
	<script type="module" src="<?php echo esc_url( $visor_js_url ); ?>"></script>
</body>
</html>
		<?php
	}
}
