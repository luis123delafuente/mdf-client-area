<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode de front para el catalogo de herramientas filtrado por el plan
 * de la farmacia del usuario logueado (Fase 2, tarea #242). El catalogo en
 * si es un placeholder de prueba (ver Catalogo_Herramientas); que items
 * son visibles para que plan lo decide unicamente
 * Permissions::puede_ver_item_catalogo(), no se repite aqui esa
 * comparacion.
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

	public static function render(): string {
		$usuario = wp_get_current_user();

		$items = array_filter(
			Catalogo_Herramientas::get_items(),
			static function ( array $item ) use ( $usuario ) {
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
	 * @param array<int, array{id: string, nombre: string, planes: string[]}> $items
	 */
	private static function render_lista( array $items ): string {
		$li = '';

		foreach ( $items as $item ) {
			$li .= '<li class="mdf-ca-catalogo-herramientas__item">' . esc_html( $item['nombre'] ) . '</li>';
		}

		return '<ul class="mdf-ca-catalogo-herramientas">' . $li . '</ul>';
	}
}
