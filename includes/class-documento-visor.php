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

	/** Respuesta a un presupuesto (v0.44.0): POST al propio visor con nonce por documento. */
	private const CAMPO_PRESUPUESTO  = 'mdf_ca_presupuesto';
	private const NONCE_PRESUPUESTO  = 'mdf_ca_presupuesto_';
	private const PARAMETRO_RESULTADO = 'respuesta';

	/** Mensajes tras responder, por la clave que viaja en la URL (nunca el texto). */
	private const MENSAJES_PRESUPUESTO = array(
		'aceptado'  => array( 'ok', 'Has aceptado el presupuesto. Hemos avisado a nuestro equipo y te enviaremos una confirmación por correo.' ),
		'rechazado' => array( 'ok', 'Has rechazado el presupuesto. Hemos enviado tu motivo a nuestro equipo y te enviaremos una confirmación por correo.' ),
		'ya'        => array( 'aviso', 'Este presupuesto ya tenía una respuesta: no se ha cambiado nada.' ),
		'motivo'    => array( 'error', 'Para rechazar el presupuesto, escribe el motivo (como mucho 1.000 caracteres).' ),
		'error'     => array( 'error', 'No se ha podido registrar tu respuesta. Inténtalo de nuevo más tarde.' ),
	);

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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- el nonce se comprueba en gestionar_presupuesto().
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST[ self::CAMPO_PRESUPUESTO ] ) ) {
			self::gestionar_presupuesto( (int) $documento_id );
			exit;
		}

		self::renderizar( (int) $documento_id );
		exit;
	}

	public static function url_visor( int $documento_id ): string {
		return home_url( 'mdf-ca-visor/' . $documento_id . '/' );
	}

	/**
	 * Aceptar o rechazar un presupuesto (v0.44.0). Nonce por documento y
	 * Presupuesto_Service, que decide todo (Permissions, tipo, pendiente).
	 * Un documento ajeno, inexistente o que no es un presupuesto: el 404
	 * uniforme de Documento_Endpoint. Despues, de vuelta al visor (PRG) con
	 * la clave del resultado.
	 */
	private static function gestionar_presupuesto( int $documento_id ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- se verifica justo aqui.
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::NONCE_PRESUPUESTO . $documento_id ) ) {
			Documento_Endpoint::responder_no_encontrado();
		}

		$accion  = sanitize_key( wp_unslash( $_POST[ self::CAMPO_PRESUPUESTO ] ) );
		$usuario = wp_get_current_user();
		$service = new Presupuesto_Service();

		if ( Presupuesto_Estado::ACEPTADO === $accion ) {
			$resultado = $service->aceptar( $documento_id, $usuario );
		} elseif ( Presupuesto_Estado::RECHAZADO === $accion ) {
			$motivo    = isset( $_POST['motivo'] ) ? sanitize_textarea_field( wp_unslash( $_POST['motivo'] ) ) : '';
			$resultado = $service->rechazar( $documento_id, $usuario, $motivo );
		} else {
			Documento_Endpoint::responder_no_encontrado();
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( is_wp_error( $resultado ) ) {
			$codigo = $resultado->get_error_code();

			if ( Presupuesto_Service::NO_ENCONTRADO === $codigo ) {
				Documento_Endpoint::responder_no_encontrado();
			}

			$clave = Presupuesto_Service::YA_RESPONDIDO === $codigo ? 'ya' : ( str_starts_with( (string) $codigo, 'mdf_ca_presupuesto_motivo' ) ? 'motivo' : 'error' );
		} else {
			$clave = $resultado['estado'];
		}

		wp_safe_redirect( add_query_arg( self::PARAMETRO_RESULTADO, $clave, self::url_visor( $documento_id ) ) );
		exit;
	}

	/**
	 * Datos del bloque del presupuesto en el visor, o null si no toca
	 * (no es un presupuesto). Solo se llama si el usuario puede verlo.
	 *
	 * @return array{estado: string, texto: string, motivo: ?string, accion: string, nonce: string, mensaje: ?array{0: string, 1: string}}|null
	 */
	private static function datos_presupuesto( Documento $documento ): ?array {
		$presupuesto = $documento->get_presupuesto();

		if ( ! $presupuesto ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo elige un mensaje fijo.
		$clave = isset( $_GET[ self::PARAMETRO_RESULTADO ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAMETRO_RESULTADO ] ) ) : '';

		return array(
			'estado'  => $presupuesto->get_estado(),
			'texto'   => $presupuesto->texto_para_farmacia(),
			'motivo'  => Presupuesto_Estado::RECHAZADO === $presupuesto->get_estado() ? $presupuesto->get_motivo() : null,
			'accion'  => self::url_visor( $documento->get_id() ),
			'nonce'   => wp_create_nonce( self::NONCE_PRESUPUESTO . $documento->get_id() ),
			'mensaje' => self::MENSAJES_PRESUPUESTO[ $clave ] ?? null,
		);
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

		$endpoint_url = home_url( 'mdf-ca-documento/' . $documento_id . '/' );
		$documento    = ( new Documento_Repository() )->find_by_id( $documento_id );
		$usuario      = wp_get_current_user();
		$puede_verlo  = Permissions::puede_ver_documento( $usuario, $documento );
		// Sin marca de agua (#321) solo si el documento lo tiene guardado asi;
		// ningun parametro de la peticion lo decide. Y solo se mira si el
		// usuario puede verlo: con un documento ajeno, la pagina tiene que ser
		// identica a la de un id inexistente (v0.37.0; antes, un ajeno sin
		// marca dejaba la marca vacia y delataba que el id existia).
		$marca_agua = $puede_verlo && $documento->is_sin_marca_de_agua() ? '' : self::get_marca_agua_texto();

		// Boton "Descargar" solo si Permissions lo permite (v0.36.0: cualquier
		// documento marcado como descargable). Descarga por su propia ruta.
		$url_descarga = Permissions::puede_descargar_documento( $usuario, $documento ) ? Documento_Endpoint::url_descarga( $documento_id ) : null;

		// Token de un solo uso para el fetch() del visor (ver Documento_Token),
		// siempre que el documento sea visible: desde la 0.36.0 el endpoint lo
		// exige tambien a los descargables. Si no es visible, el endpoint dara
		// 404 igualmente y no se emite nada.
		$token = $puede_verlo
			? Documento_Token::emitir( (int) $usuario->ID, $documento_id )
			: '';

		// v0.41.0: el nombre del documento y "Volver" a la pagina de su tipo, solo si
		// puede verlo. Con uno ajeno o inexistente, la pagina sigue siendo
		// identica en los dos casos (titulo "Documento", sin enlace propio).
		$titulo = $puede_verlo ? $documento->get_nombre() : 'Documento';
		$volver = $puede_verlo ? self::url_volver_documento( $documento ) : null;

		// v0.44.0: aceptar o rechazar, solo si puede verlo (con uno ajeno o
		// inexistente la pagina sigue siendo identica en los dos casos).
		$presupuesto = $puede_verlo ? self::datos_presupuesto( $documento ) : null;

		self::pintar( $endpoint_url, $token, $marca_agua, $url_descarga, $titulo, $volver, $presupuesto );
	}

	/**
	 * v0.42.0: "Volver" a la pagina del tipo del documento (una factura, a
	 * Facturas). Un documento sin tipo o de un tipo sin pagina propia va a
	 * "Otros documentos", como en el listado; si esa pagina no esta publicada,
	 * a Documentacion.
	 */
	private static function url_volver_documento( Documento $documento ): ?string {
		$clave = (string) $documento->get_tipo_documento();

		if ( ! isset( Documento_Tipos::get_grupos_listado()[ $clave ] ) ) {
			$clave = 'otro';
		}

		$id = (int) ( Area_Privada_Pages::get_paginas_adicionales_ids()[ Area_Privada_Pages::slug_de_tipo( $clave ) ] ?? 0 );

		if ( $id && 'publish' === get_post_status( $id ) ) {
			$url = get_permalink( $id );

			if ( $url ) {
				return $url;
			}
		}

		return self::url_pagina_area( Area_Privada_Pages::SLUG_DOCUMENTACION );
	}

	/** Enlace de una de las cuatro secciones del area, o null si no existe. */
	public static function url_pagina_area( string $slug ): ?string {
		$id  = (int) ( Area_Privada_Pages::get_page_ids()[ $slug ] ?? 0 );
		$url = $id ? get_permalink( $id ) : false;

		return $url ? $url : null;
	}

	/**
	 * Vista previa para un administrador (#304): el mismo visor, pero con el
	 * fichero servido por la accion de vista previa del backoffice, con su
	 * propio token y marca de agua, y sin boton de descarga. Quien la llama
	 * (Admin_Publicacion_Documentos) ya ha comprobado Admin_Documentos::CAPACIDAD, nonce y
	 * Permissions::puede_previsualizar_documento().
	 */
	public static function renderizar_vista_previa( string $endpoint_url, string $token, string $marca_agua ): void {
		nocache_headers();

		self::pintar( $endpoint_url, $token, $marca_agua, null );
	}

	/**
	 * La pagina del visor, comun al visor de las farmacias, a la vista
	 * previa del administrador y a las herramientas del catalogo
	 * (Herramienta_Endpoint). Solo marcado: no decide nada. $url_descarga
	 * null = sin boton "Descargar"; quien llama ya ha preguntado a
	 * Permissions. $url_volver null = sin enlace "Volver" (vista previa del
	 * administrador).
	 */
	public static function pintar( string $endpoint_url, string $token, string $marca_agua, ?string $url_descarga, string $titulo = 'Documento', ?string $url_volver = null, ?array $presupuesto = null ): void {
		$puede_descargar = null !== $url_descarga;
		$logo_url        = Recursos::url_versionada( 'assets/img/mdf-logo-verde.png' );

		// Todas con "?ver=" por fecha del fichero (Recursos): tras un despliegue
		// ningun navegador sigue con una copia vieja. PDF.js va como dos URL
		// completas (modulo y worker) para que tambien lleven su version.
		$pdfjs_url        = Recursos::url_versionada( 'assets/vendor/pdfjs/pdf.min.mjs' );
		$pdfjs_worker_url = Recursos::url_versionada( 'assets/vendor/pdfjs/pdf.worker.min.mjs' );
		$sheetjs_url      = Recursos::url_versionada( 'assets/vendor/sheetjs/xlsx.mini.min.js' );
		$visor_css_url    = Recursos::url_versionada( 'assets/css/documento-visor.css' );
		$visor_js_url     = Recursos::url_versionada( 'assets/js/documento-visor.js' );

		header( 'Content-Type: text/html; charset=UTF-8' );
		?>
<!DOCTYPE html>
<html lang="es">
<head>
	<meta charset="UTF-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1.0" />
	<meta name="robots" content="noindex, nofollow" />
	<title><?php echo esc_html( $titulo . ' | Área de clientes MDF' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $visor_css_url ); ?>" />
</head>
<body>
	<div id="mdf-ca-visor" class="mdf-ca-visor">
		<header class="mdf-ca-visor__cabecera">
			<img class="mdf-ca-visor__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="MDF Mediformplus" width="346" height="154" />
			<?php if ( $url_volver ) : ?>
				<a class="mdf-ca-visor__volver" href="<?php echo esc_url( $url_volver ); ?>">
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12l14 0"/><path d="M5 12l6 6"/><path d="M5 12l6 -6"/></svg>
					<span>Volver</span>
				</a>
			<?php endif; ?>
			<h1 class="mdf-ca-visor__titulo"><?php echo esc_html( $titulo ); ?></h1>
			<?php if ( $puede_descargar ) : ?>
				<a id="mdf-ca-visor-descargar" class="mdf-ca-visor__descargar" href="<?php echo esc_url( (string) $url_descarga ); ?>" hidden>
					<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/></svg>
					<span>Descargar</span>
				</a>
			<?php endif; ?>
		</header>
		<?php
		if ( $presupuesto ) {
			self::pintar_presupuesto( $presupuesto, $titulo );
		}
		?>
		<div class="mdf-ca-visor__barra">
			<button id="mdf-ca-visor-anterior" type="button" class="mdf-ca-visor__boton" disabled>&larr; Anterior</button>
			<select id="mdf-ca-visor-hoja" class="mdf-ca-visor__hoja" aria-label="Hoja" hidden></select>
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
			tokenParam: <?php echo wp_json_encode( Documento_Token::PARAMETRO ); ?>,
			token: <?php echo wp_json_encode( $token ); ?>,
			pdfjsUrl: <?php echo wp_json_encode( $pdfjs_url ); ?>,
			pdfjsWorkerUrl: <?php echo wp_json_encode( $pdfjs_worker_url ); ?>,
			sheetjsUrl: <?php echo wp_json_encode( $sheetjs_url ); ?>,
			marcaAgua: <?php echo wp_json_encode( $marca_agua ); ?>,
			puedeDescargar: <?php echo wp_json_encode( $puede_descargar ); ?>
		};
	</script>
	<script type="module" src="<?php echo esc_url( $visor_js_url ); ?>"></script>
	<?php if ( $presupuesto && Presupuesto_Estado::PENDIENTE === $presupuesto['estado'] ) : ?>
		<script src="<?php echo esc_url( Recursos::url_versionada( 'assets/js/documento-visor-presupuesto.js' ) ); ?>" defer></script>
	<?php endif; ?>
</body>
</html>
		<?php
	}

	/**
	 * Franja del presupuesto bajo la cabecera (v0.44.0): el estado y, si esta
	 * pendiente, "Rechazar" y "Aceptar presupuesto", cada uno con su ventana
	 * de confirmacion (<dialog>, documento-visor-presupuesto.js). Los botones
	 * nacen ocultos y el JS los muestra: sin JS no hay visor (canvas) y
	 * tampoco botones. Solo marcado.
	 *
	 * @param array{estado: string, texto: string, motivo: ?string, accion: string, nonce: string, mensaje: ?array{0: string, 1: string}} $presupuesto
	 */
	private static function pintar_presupuesto( array $presupuesto, string $titulo ): void {
		$pendiente = Presupuesto_Estado::PENDIENTE === $presupuesto['estado'];
		?>
		<section class="mdf-ca-visor__presupuesto" aria-label="Estado del presupuesto">
			<?php if ( $presupuesto['mensaje'] ) : ?>
				<p class="mdf-ca-visor__mensaje mdf-ca-visor__mensaje--<?php echo esc_attr( $presupuesto['mensaje'][0] ); ?>" role="status"><?php echo esc_html( $presupuesto['mensaje'][1] ); ?></p>
			<?php endif; ?>
			<div class="mdf-ca-visor__presupuesto-fila">
				<p class="mdf-ca-visor__presupuesto-estado">
					<span class="mdf-ca-visor__pildora mdf-ca-visor__pildora--<?php echo esc_attr( $presupuesto['estado'] ); ?>"><?php echo esc_html( $presupuesto['texto'] ); ?></span>
					<?php if ( $pendiente ) : ?>
						<span class="mdf-ca-visor__presupuesto-ayuda">Revisa el presupuesto y acéptalo o recházalo.</span>
					<?php endif; ?>
				</p>
				<?php if ( $pendiente ) : ?>
					<div class="mdf-ca-visor__presupuesto-acciones">
						<button type="button" class="mdf-ca-visor__boton mdf-ca-visor__boton--rechazar" data-mdf-ca-abrir="mdf-ca-presupuesto-rechazar" hidden>Rechazar</button>
						<button type="button" class="mdf-ca-visor__aceptar" data-mdf-ca-abrir="mdf-ca-presupuesto-aceptar" hidden>
							<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12l5 5l10 -10"/></svg>
							Aceptar presupuesto
						</button>
					</div>
				<?php endif; ?>
			</div>
			<?php if ( $presupuesto['motivo'] ) : ?>
				<p class="mdf-ca-visor__presupuesto-motivo"><strong>Tu motivo:</strong> <?php echo nl2br( esc_html( $presupuesto['motivo'] ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php if ( $pendiente ) : ?>
			<dialog id="mdf-ca-presupuesto-aceptar" class="mdf-ca-visor__dialogo" aria-labelledby="mdf-ca-presupuesto-aceptar-titulo">
				<form method="post" action="<?php echo esc_url( $presupuesto['accion'] ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $presupuesto['nonce'] ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( self::CAMPO_PRESUPUESTO ); ?>" value="<?php echo esc_attr( Presupuesto_Estado::ACEPTADO ); ?>" />
					<h2 id="mdf-ca-presupuesto-aceptar-titulo" class="mdf-ca-visor__dialogo-titulo">¿Aceptas el presupuesto?</h2>
					<p class="mdf-ca-visor__dialogo-texto">Vas a aceptar «<?php echo esc_html( $titulo ); ?>». Avisaremos a nuestro equipo y ya no podrás cambiar tu respuesta desde aquí.</p>
					<div class="mdf-ca-visor__dialogo-acciones">
						<button type="button" class="mdf-ca-visor__boton" data-mdf-ca-cerrar>Cancelar</button>
						<button type="submit" class="mdf-ca-visor__aceptar">Sí, aceptar</button>
					</div>
				</form>
			</dialog>
			<dialog id="mdf-ca-presupuesto-rechazar" class="mdf-ca-visor__dialogo" aria-labelledby="mdf-ca-presupuesto-rechazar-titulo">
				<form method="post" action="<?php echo esc_url( $presupuesto['accion'] ); ?>">
					<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( $presupuesto['nonce'] ); ?>" />
					<input type="hidden" name="<?php echo esc_attr( self::CAMPO_PRESUPUESTO ); ?>" value="<?php echo esc_attr( Presupuesto_Estado::RECHAZADO ); ?>" />
					<h2 id="mdf-ca-presupuesto-rechazar-titulo" class="mdf-ca-visor__dialogo-titulo">Rechazar el presupuesto</h2>
					<p class="mdf-ca-visor__dialogo-texto">Cuéntanos por qué no te encaja «<?php echo esc_html( $titulo ); ?>». Se lo enviaremos a nuestro equipo.</p>
					<label class="mdf-ca-visor__dialogo-etiqueta" for="mdf-ca-presupuesto-motivo">Motivo del rechazo</label>
					<textarea id="mdf-ca-presupuesto-motivo" class="mdf-ca-visor__dialogo-motivo" name="motivo" rows="5" maxlength="<?php echo esc_attr( (string) Presupuesto_Estado::MAX_MOTIVO ); ?>" required></textarea>
					<div class="mdf-ca-visor__dialogo-acciones">
						<button type="button" class="mdf-ca-visor__boton" data-mdf-ca-cerrar>Cancelar</button>
						<button type="submit" class="mdf-ca-visor__rechazar">Rechazar presupuesto</button>
					</div>
				</form>
			</dialog>
		<?php endif; ?>
		<?php
	}
}
