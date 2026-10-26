<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode [mdf_ca_catalogo_herramientas] de la pagina Herramientas: las
 * herramientas que ve la farmacia del usuario, agrupadas por categoria. Que
 * herramientas ve lo decide unicamente Permissions (filtrar_items_catalogo_
 * visibles()), no se repite aqui ninguna comparacion.
 *
 * Desde el esquema 1.21.0 el catalogo es solo Herramientas: Formacion es una
 * pagina de Elementor con un enlace a la Academia. El atributo seccion se
 * mantiene para no romper lo publicado: sin el, o con "herramientas", pinta
 * las herramientas; con "formacion", no pinta nada.
 *
 * HTML para MKT (documentado en CLAUDE.md, «Herramientas del catalogo»):
 * cada categoria es un <section> con data-categoria (slug) y cada herramienta
 * un <li> con data-categoria, data-etiquetas (slugs separados por espacios)
 * y data-tipo, para anadir despues el buscador y los filtros sin tocar el
 * modelo. Nunca se pinta a que planes o farmacias esta asignada.
 *
 * Sin sesion, sin farmacia o sin nada visible: el mensaje de vacio, nunca un
 * error ni una herramienta que no toca.
 *
 * v0.43.0: cada tarjeta lleva su ventana (<dialog class="mdf-ca-ficha">)
 * con el nombre, el tipo, la descripcion detallada (o la breve si no hay),
 * las etiquetas y los mismos botones. La abre un clic en la tarjeta o en el
 * nombre (un <button>, para el teclado); los botones de la tarjeta siguen
 * yendo directos. Todo sale ya pintado: catalogo-herramientas.js solo abre y
 * cierra, no pide datos. Sin JS, la tarjeta funciona como antes.
 */
class Shortcode_Catalogo_Herramientas {

	private const TAG = 'mdf_ca_catalogo_herramientas';

	private const HANDLE = 'mdf-ca-catalogo-herramientas';

	public static function register_hooks(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array|string $atts
	 */
	public static function render( $atts = array() ): string {
		$atts = shortcode_atts( array( 'seccion' => Catalogo_Secciones::HERRAMIENTAS ), $atts, self::TAG );

		if ( Catalogo_Secciones::FORMACION === $atts['seccion'] ) {
			return '';
		}

		$items = Permissions::filtrar_items_catalogo_visibles( wp_get_current_user(), ( new Catalogo_Repository() )->find_visibles() );

		if ( ! $items ) {
			return '<p class="mdf-ca-herramientas mdf-ca-herramientas--vacio">' .
				esc_html__( 'No hay herramientas disponibles.', 'mdf-client-area' ) .
				'</p>';
		}

		$categorias = array();

		foreach ( Catalogo_Termino_Repository::categorias()->find_all() as $categoria ) {
			$categorias[ $categoria->get_id() ] = $categoria;
		}

		$etiquetas = array();

		foreach ( Catalogo_Termino_Repository::etiquetas()->find_all() as $etiqueta ) {
			$etiquetas[ $etiqueta->get_id() ] = $etiqueta;
		}

		// Agrupadas en el orden de las categorias (alfabetico); dentro, por nombre.
		$grupos = array();

		foreach ( $items as $item ) {
			$grupos[ (int) $item->get_categoria_id() ][] = $item;
		}

		$html = '';

		foreach ( $categorias as $id => $categoria ) {
			if ( isset( $grupos[ $id ] ) ) {
				$html .= self::render_categoria( $categoria, $grupos[ $id ], $etiquetas );
			}
		}

		self::encolar_recursos();

		return '<div class="mdf-ca-herramientas">' . $html . '</div>';
	}

	/** Al pintar el shortcode: solo cargan en la pagina que lo usa. */
	private static function encolar_recursos(): void {
		wp_enqueue_style( self::HANDLE, Recursos::url( 'assets/css/catalogo-herramientas.css' ), array(), Recursos::version( 'assets/css/catalogo-herramientas.css' ) );
		wp_enqueue_script( self::HANDLE, Recursos::url( 'assets/js/catalogo-herramientas.js' ), array(), Recursos::version( 'assets/js/catalogo-herramientas.js' ), true );
	}

	/**
	 * @param Catalogo_Item[]                $items
	 * @param array<int, Catalogo_Termino>   $etiquetas
	 */
	private static function render_categoria( Catalogo_Termino $categoria, array $items, array $etiquetas ): string {
		$li = '';

		foreach ( $items as $item ) {
			$li .= self::render_item( $item, $categoria, $etiquetas );
		}

		return '<section class="mdf-ca-herramientas__categoria" data-categoria="' . esc_attr( $categoria->get_slug() ) . '">'
			. '<h2 class="mdf-ca-herramientas__categoria-titulo">' . esc_html( $categoria->get_nombre() ) . '</h2>'
			. '<ul class="mdf-ca-herramientas__lista">' . $li . '</ul>'
			. '</section>';
	}

	/**
	 * @param array<int, Catalogo_Termino> $etiquetas
	 */
	private static function render_item( Catalogo_Item $item, Catalogo_Termino $categoria, array $etiquetas ): string {
		$suyas = array_values(
			array_filter(
				array_map( static fn( int $id ): ?Catalogo_Termino => $etiquetas[ $id ] ?? null, $item->get_etiqueta_ids() )
			)
		);

		$tipo     = $item->es_archivo() ? (string) $item->get_formato() : Catalogo_Item::RECURSO_ENLACE;
		$ficha_id = 'mdf-ca-ficha-' . $item->get_id();
		$botones  = self::render_botones( $item );

		$html  = '<li class="mdf-ca-herramienta"'
			. ' data-categoria="' . esc_attr( $categoria->get_slug() ) . '"'
			. ' data-etiquetas="' . esc_attr( implode( ' ', array_map( static fn( Catalogo_Termino $e ): string => $e->get_slug(), $suyas ) ) ) . '"'
			. ' data-tipo="' . esc_attr( $tipo ) . '"'
			. ' data-ficha="' . esc_attr( $ficha_id ) . '">';
		$html .= '<h3 class="mdf-ca-herramienta__nombre">'
			. '<button type="button" class="mdf-ca-herramienta__abrir-ficha" aria-haspopup="dialog" aria-controls="' . esc_attr( $ficha_id ) . '">'
			. esc_html( $item->get_nombre() )
			. '<span class="mdf-ca-herramienta__oculto">' . esc_html__( ', más información', 'mdf-client-area' ) . '</span>'
			. '</button></h3>';

		if ( $item->get_descripcion() ) {
			$html .= '<p class="mdf-ca-herramienta__descripcion">' . esc_html( $item->get_descripcion() ) . '</p>';
		}

		$html .= self::render_etiquetas( $suyas );
		$html .= '<div class="mdf-ca-herramienta__acciones">' . $botones . '</div>';
		$html .= self::render_ficha( $item, $ficha_id, $suyas, $botones );

		return $html . '</li>';
	}

	/**
	 * @param Catalogo_Termino[] $suyas
	 */
	private static function render_etiquetas( array $suyas ): string {
		if ( ! $suyas ) {
			return '';
		}

		$html = '<ul class="mdf-ca-herramienta__etiquetas">';

		foreach ( $suyas as $etiqueta ) {
			$html .= '<li class="mdf-ca-herramienta__etiqueta" data-etiqueta="' . esc_attr( $etiqueta->get_slug() ) . '">' . esc_html( $etiqueta->get_nombre() ) . '</li>';
		}

		return $html . '</ul>';
	}

	/**
	 * La ventana de la herramienta. Los botones son los de la tarjeta (mismo
	 * HTML, mismos permisos), asi que el estilo de la tarjeta les vale.
	 *
	 * @param Catalogo_Termino[] $suyas
	 */
	private static function render_ficha( Catalogo_Item $item, string $ficha_id, array $suyas, string $botones ): string {
		$texto = $item->get_descripcion_detallada() ?? $item->get_descripcion();
		$tipo  = $item->es_archivo() ? Catalogo_Formatos::get_etiqueta( $item->get_formato() ) : __( 'Enlace web', 'mdf-client-area' );

		$html  = '<dialog class="mdf-ca-ficha" id="' . esc_attr( $ficha_id ) . '" tabindex="-1" aria-labelledby="' . esc_attr( $ficha_id . '-titulo' ) . '">';
		$html .= '<div class="mdf-ca-ficha__caja">';
		$html .= '<div class="mdf-ca-ficha__cabecera">'
			. '<span class="mdf-ca-ficha__tipo">' . esc_html( $tipo ) . '</span>'
			. '<button type="button" class="mdf-ca-ficha__cerrar" aria-label="' . esc_attr__( 'Cerrar', 'mdf-client-area' ) . '">'
			. '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M18 6 6 18M6 6l12 12"/></svg>'
			. '</button></div>';
		$html .= '<h2 class="mdf-ca-ficha__titulo" id="' . esc_attr( $ficha_id . '-titulo' ) . '">' . esc_html( $item->get_nombre() ) . '</h2>';

		if ( $texto ) {
			$html .= '<p class="mdf-ca-ficha__texto">' . esc_html( $texto ) . '</p>';
		}

		$html .= self::render_etiquetas( $suyas );

		if ( '' !== $botones ) {
			$html .= '<div class="mdf-ca-herramienta__acciones mdf-ca-ficha__acciones">' . $botones . '</div>';
		}

		return $html . '</div></dialog>';
	}

	/**
	 * Botones segun lo que Permissions permite: "Abrir" un enlace (pestana
	 * nueva, sin opener ni referer), "Ver" en el visor y "Descargar".
	 */
	private static function render_botones( Catalogo_Item $item ): string {
		$nombre = $item->get_nombre();

		if ( ! $item->es_archivo() ) {
			return '<a class="mdf-ca-herramienta__boton mdf-ca-herramienta__boton--abrir" href="' . esc_url( (string) $item->get_enlace_url() ) . '" target="_blank" rel="noopener noreferrer"'
				. ' aria-label="' . esc_attr( 'Abrir ' . $nombre . ' (se abre en una pestaña nueva)' ) . '">'
				. esc_html__( 'Abrir', 'mdf-client-area' ) . '</a>';
		}

		$usuario = wp_get_current_user();
		$html    = '';

		if ( Permissions::puede_visualizar_item_catalogo( $usuario, $item ) ) {
			$html .= '<a class="mdf-ca-herramienta__boton mdf-ca-herramienta__boton--ver" href="' . esc_url( Herramienta_Endpoint::url( $item->get_id(), Herramienta_Endpoint::VER ) ) . '"'
				. ' aria-label="' . esc_attr( 'Ver ' . $nombre ) . '">'
				. esc_html__( 'Ver', 'mdf-client-area' ) . '</a>';
		}

		if ( Permissions::puede_descargar_item_catalogo( $usuario, $item ) ) {
			$html .= '<a class="mdf-ca-herramienta__boton mdf-ca-herramienta__boton--descargar" href="' . esc_url( Herramienta_Endpoint::url( $item->get_id(), Herramienta_Endpoint::DESCARGAR ) ) . '"'
				. ' aria-label="' . esc_attr( 'Descargar ' . $nombre . ' (' . Catalogo_Formatos::get_etiqueta( $item->get_formato() ) . ')' ) . '">'
				. esc_html__( 'Descargar', 'mdf-client-area' ) . '</a>';
		}

		return $html;
	}
}
