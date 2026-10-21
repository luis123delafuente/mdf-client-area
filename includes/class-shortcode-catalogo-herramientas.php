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
 */
class Shortcode_Catalogo_Herramientas {

	private const TAG = 'mdf_ca_catalogo_herramientas';

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

		return '<div class="mdf-ca-herramientas">' . $html . '</div>';
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

		$tipo = $item->es_archivo() ? (string) $item->get_formato() : Catalogo_Item::RECURSO_ENLACE;

		$html  = '<li class="mdf-ca-herramienta"'
			. ' data-categoria="' . esc_attr( $categoria->get_slug() ) . '"'
			. ' data-etiquetas="' . esc_attr( implode( ' ', array_map( static fn( Catalogo_Termino $e ): string => $e->get_slug(), $suyas ) ) ) . '"'
			. ' data-tipo="' . esc_attr( $tipo ) . '">';
		$html .= '<h3 class="mdf-ca-herramienta__nombre">' . esc_html( $item->get_nombre() ) . '</h3>';

		if ( $item->get_descripcion() ) {
			$html .= '<p class="mdf-ca-herramienta__descripcion">' . esc_html( $item->get_descripcion() ) . '</p>';
		}

		if ( $suyas ) {
			$html .= '<ul class="mdf-ca-herramienta__etiquetas">';

			foreach ( $suyas as $etiqueta ) {
				$html .= '<li class="mdf-ca-herramienta__etiqueta" data-etiqueta="' . esc_attr( $etiqueta->get_slug() ) . '">' . esc_html( $etiqueta->get_nombre() ) . '</li>';
			}

			$html .= '</ul>';
		}

		$html .= '<div class="mdf-ca-herramienta__acciones">' . self::render_botones( $item ) . '</div>';

		return $html . '</li>';
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
