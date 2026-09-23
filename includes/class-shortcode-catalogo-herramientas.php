<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode de front para el catalogo (Herramientas o Formacion) filtrado
 * por el plan de la farmacia del usuario logueado. Desde Fase 3 (#247) lee
 * de Catalogo_Repository, no del array hardcodeado de Catalogo_Herramientas
 * (retirada); que items son visibles para que plan lo sigue decidiendo
 * unicamente Permissions::puede_ver_item_catalogo(), no se repite aqui esa
 * comparacion.
 *
 * El nombre del shortcode y su publicacion sin atributos
 * ([mdf_ca_catalogo_herramientas], ya usado por MKT en la pagina
 * Herramientas) no cambian: el atributo "seccion" es opcional y por
 * defecto vale "herramientas", asi que la sintaxis ya publicada sigue
 * funcionando identica. Se anade porque la tabla de catalogo ahora
 * modela tambien la seccion Formacion (ver DB_Schema); sin este atributo,
 * esos items quedarian sin ningun shortcode capaz de mostrarlos.
 *
 * Mismo patron que Shortcode_Listado_Documentos: sin sesion, sin farmacia
 * o farmacia sin plan asignado, el catalogo se muestra vacio, nunca un
 * error visible ni un item que no toca.
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
		$atts    = shortcode_atts( array( 'seccion' => Catalogo_Secciones::HERRAMIENTAS ), $atts, self::TAG );
		$seccion = Catalogo_Secciones::es_valida( $atts['seccion'] ) ? $atts['seccion'] : Catalogo_Secciones::HERRAMIENTAS;

		$usuario = wp_get_current_user();

		$items = array_filter(
			( new Catalogo_Repository() )->find_by_seccion( $seccion ),
			static function ( Catalogo_Item $item ) use ( $usuario ) {
				return Permissions::puede_ver_item_catalogo( $usuario, $item );
			}
		);

		if ( ! $items ) {
			return self::render_estado_vacio();
		}

		return self::render_lista( $items );
	}

	private static function render_estado_vacio(): string {
		return '<p class="mdf-ca-catalogo-herramientas mdf-ca-catalogo-herramientas--vacio">' .
			esc_html__( 'No hay herramientas disponibles.', 'mdf-client-area' ) .
			'</p>';
	}

	/**
	 * @param Catalogo_Item[] $items
	 */
	private static function render_lista( array $items ): string {
		$li = '';

		foreach ( $items as $item ) {
			$li .= self::render_item( $item );
		}

		return '<ul class="mdf-ca-catalogo-herramientas">' . $li . '</ul>';
	}

	/**
	 * El enlace (si lo tiene) se renderiza tal cual: no es un documento
	 * privado servido por Documento_Endpoint, es un recurso de catalogo
	 * (p. ej. la Academia, o un recurso externo) que MKT/el administrador
	 * decide explicitamente al dar de alta el item -- la regla de "nunca
	 * un enlace directo" (CLAUDE.md) es sobre documentos privados por
	 * farmacia, no sobre esto.
	 */
	private static function render_item( Catalogo_Item $item ): string {
		$atributo_tipo = esc_attr( $item->get_tipo() );
		$nombre        = esc_html( $item->get_nombre() );

		if ( $item->get_enlace_url() ) {
			$contenido = '<a href="' . esc_url( $item->get_enlace_url() ) . '" target="_blank" rel="noopener noreferrer">' . $nombre . '</a>';
		} else {
			$contenido = $nombre;
		}

		return '<li class="mdf-ca-catalogo-herramientas__item" data-tipo="' . $atributo_tipo . '">' . $contenido . '</li>';
	}
}
