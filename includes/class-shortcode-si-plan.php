<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode contenedor generico para que MKT condicione CUALQUIER bloque
 * de contenido al plan de la farmacia logueada ("Bloque condicionado por
 * plan", Fase 2). A diferencia de Shortcode_Catalogo_Herramientas (#242),
 * este no sabe nada de herramientas: envuelve contenido arbitrario,
 * incluidos otros shortcodes anidados dentro.
 *
 * La comprobacion de plan pasa por
 * Permissions::puede_ver_bloque_por_plan(), no por una comparacion de
 * arrays suelta aqui. Sin sesion, sin farmacia o farmacia sin plan
 * asignado, el contenido interior simplemente no se renderiza -- nunca un
 * hueco vacio ni un error visible.
 *
 * Uso: [mdf_ca_si_plan planes="basico,premium"]...contenido...[/mdf_ca_si_plan]
 */
class Shortcode_Si_Plan {

	private const TAG = 'mdf_ca_si_plan';

	public static function register_hooks(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array|string $atts
	 */
	public static function render( $atts, ?string $content = null ): string {
		if ( null === $content || '' === $content ) {
			return '';
		}

		$atts               = shortcode_atts( array( 'planes' => '' ), $atts, self::TAG );
		$planes_permitidos = self::parsear_planes( $atts['planes'] );

		if ( ! $planes_permitidos ) {
			return '';
		}

		if ( ! Permissions::puede_ver_bloque_por_plan( wp_get_current_user(), $planes_permitidos ) ) {
			return '';
		}

		// do_shortcode() explicito: el contenido interior de un shortcode
		// "enclosing" no se procesa solo, y MKT necesita poder anidar aqui
		// otros shortcodes (del propio plugin o de Elementor).
		return do_shortcode( $content );
	}

	/**
	 * @return string[]
	 */
	private static function parsear_planes( string $planes_atributo ): array {
		$planes = array_map( 'trim', explode( ',', $planes_atributo ) );

		return array_values( array_filter( $planes ) );
	}
}
