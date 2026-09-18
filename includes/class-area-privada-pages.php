<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fuente unica de las cuatro paginas base del area de clientes (Fase 2,
 * "Estructura de las cuatro secciones"): titulo y contenido inicial de
 * cada una. La usan la creacion de paginas en activacion, la proteccion
 * de acceso (Role_Restrictions::proteger_area_privada()) y la navegacion
 * (Shortcode_Nav_Area_Privada), para no repetir la lista en tres sitios.
 *
 * Formacion y Cuota llevan solo un placeholder de texto: el enlace real a
 * "la Academia" depende de SSO (fuera de alcance) y la Cuota en modo
 * lectura llega en Fase 3 -- no se inventa aqui ningun shortcode que
 * todavia no existe.
 *
 * Las paginas se identifican por ID, guardado en la option
 * OPTION_PAGINA_IDS tras crearlas, no por slug: MKT va a maquetar estas
 * paginas con Elementor en cuanto se le entreguen, y renombrar el slug
 * (o el permalink) es un cambio habitual de esa fase. Atar la proteccion
 * de acceso y la navegacion al slug se habria roto con el primer cambio
 * de URL; el ID de post no cambia nunca.
 */
class Area_Privada_Pages {

	public const SLUG_DOCUMENTACION = 'documentacion';
	public const SLUG_HERRAMIENTAS  = 'herramientas';
	public const SLUG_FORMACION     = 'formacion';
	public const SLUG_CUOTA         = 'cuota';

	private const OPTION_PAGINA_IDS = 'mdf_ca_area_privada_pagina_ids';

	/**
	 * @return array<string, array{titulo: string, contenido: string}>
	 */
	public static function get_paginas(): array {
		$nav = '[mdf_ca_nav_area_privada]';

		return array(
			self::SLUG_DOCUMENTACION => array(
				'titulo'    => 'Documentacion',
				'contenido' => $nav . "\n\n[mdf_ca_listado_documentos]",
			),
			self::SLUG_HERRAMIENTAS => array(
				'titulo'    => 'Herramientas',
				'contenido' => $nav . "\n\n[mdf_ca_catalogo_herramientas]",
			),
			self::SLUG_FORMACION => array(
				'titulo'    => 'Formacion',
				'contenido' => $nav . "\n\n<p>Proximamente: acceso a la Academia. Pendiente de la integracion SSO.</p>",
			),
			self::SLUG_CUOTA => array(
				'titulo'    => 'Cuota',
				'contenido' => $nav . "\n\n<p>Proximamente: consulta de tu cuota de tokens. Disponible en una fase posterior.</p>",
			),
		);
	}

	/**
	 * Crea las paginas que falten y guarda sus IDs. Idempotente y no
	 * destructivo: si una pagina ya existe (por ID guardado o, la primera
	 * vez, por slug) no se toca su contenido -- esto solo rellena el
	 * hueco, nunca pisa un maquetado de MKT ya hecho.
	 */
	public static function crear_paginas(): void {
		$ids = get_option( self::OPTION_PAGINA_IDS, array() );

		foreach ( self::get_paginas() as $slug => $pagina ) {
			if ( isset( $ids[ $slug ] ) && get_post( $ids[ $slug ] ) ) {
				continue;
			}

			$existente = get_page_by_path( $slug );

			if ( $existente ) {
				$ids[ $slug ] = $existente->ID;
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_title'   => $pagina['titulo'],
					'post_name'    => $slug,
					'post_content' => $pagina['contenido'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);

			if ( $id && ! is_wp_error( $id ) ) {
				$ids[ $slug ] = $id;
			}
		}

		update_option( self::OPTION_PAGINA_IDS, $ids );
	}

	/**
	 * @return array<string, int> slug => ID de post
	 */
	public static function get_page_ids(): array {
		return get_option( self::OPTION_PAGINA_IDS, array() );
	}
}
