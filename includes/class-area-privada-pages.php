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

	/**
	 * Paginas adicionales (v0.39.0): la portada del area y una pagina por
	 * tipo de documento, hijas de Documentacion. Van en una lista y una
	 * option aparte para no cambiar lo que ya usan las cuatro secciones (la
	 * navegacion del shortcode, el enlace del aviso por email): solo se suman
	 * a la proteccion de acceso y al menu de perfil, via get_ids_protegidos().
	 */
	public const SLUG_INICIO = 'area-de-clientes';

	private const OPTION_PAGINA_IDS = 'mdf_ca_area_privada_pagina_ids';

	private const OPTION_PAGINAS_ADICIONALES_IDS = 'mdf_ca_area_privada_paginas_adicionales_ids';

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

	/**
	 * Portada y paginas por tipo de documento. Las de tipo son hijas de
	 * Documentacion; su slug sale de la clave del tipo, que es tambien el
	 * valor del atributo tipo del listado.
	 *
	 * @return array<string, array{titulo: string, contenido: string, hija_de_documentacion: bool}>
	 */
	public static function get_paginas_adicionales(): array {
		$nav     = '[mdf_ca_nav_area_privada]';
		$paginas = array(
			self::SLUG_INICIO => array(
				'titulo'                => 'Área de clientes',
				'contenido'             => $nav,
				'hija_de_documentacion' => false,
			),
		);

		$titulos = array(
			'factura'     => 'Facturas',
			'modelo_347'  => 'Modelo 347',
			'contrato'    => 'Contratos',
			'presupuesto' => 'Presupuestos',
			'entregable'  => 'Entregables',
			'otro'        => 'Otros documentos',
		);

		foreach ( Documento_Tipos::get_grupos_listado() as $clave => $etiqueta ) {
			$paginas[ self::slug_de_tipo( $clave ) ] = array(
				'titulo'                => $titulos[ $clave ] ?? $etiqueta,
				'contenido'             => $nav . "\n\n[mdf_ca_listado_documentos tipo=\"" . $clave . '"]',
				'hija_de_documentacion' => true,
			);
		}

		return $paginas;
	}

	/** Slug de la pagina de un tipo, en plural como su titulo: "factura" => "facturas". */
	public static function slug_de_tipo( string $clave ): string {
		$slugs = array(
			'factura'     => 'facturas',
			'modelo_347'  => 'modelo-347',
			'contrato'    => 'contratos',
			'presupuesto' => 'presupuestos',
			'entregable'  => 'entregables',
			'otro'        => 'otros-documentos',
		);

		return $slugs[ $clave ] ?? str_replace( '_', '-', $clave );
	}

	/**
	 * Mismo criterio que crear_paginas(): idempotente y no destructivo. Una
	 * pagina que ya existe (por ID guardado o por slug; la de tipo, como hija
	 * de Documentacion) se adopta sin tocar su contenido ni su estado; solo se
	 * crean, publicadas, las que faltan. Sin la pagina de Documentacion no se
	 * crean las de tipo y devuelve false, para que se reintente.
	 */
	public static function crear_paginas_adicionales(): bool {
		$ids              = get_option( self::OPTION_PAGINAS_ADICIONALES_IDS, array() );
		$documentacion_id = (int) ( self::get_page_ids()[ self::SLUG_DOCUMENTACION ] ?? 0 );
		$completo         = true;

		foreach ( self::get_paginas_adicionales() as $slug => $pagina ) {
			if ( isset( $ids[ $slug ] ) && get_post( $ids[ $slug ] ) ) {
				continue;
			}

			$padre = 0;

			if ( $pagina['hija_de_documentacion'] ) {
				if ( ! $documentacion_id || ! get_post( $documentacion_id ) ) {
					$completo = false;
					continue;
				}

				$padre = $documentacion_id;
			}

			$existente = get_posts(
				array(
					'post_type'      => 'page',
					'name'           => $slug,
					'post_parent'    => $padre,
					'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
					'posts_per_page' => 1,
					'fields'         => 'ids',
				)
			);

			if ( $existente ) {
				$ids[ $slug ] = (int) $existente[0];
				continue;
			}

			$id = wp_insert_post(
				array(
					'post_title'   => $pagina['titulo'],
					'post_name'    => $slug,
					'post_content' => $pagina['contenido'],
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_parent'  => $padre,
				)
			);

			if ( $id && ! is_wp_error( $id ) ) {
				$ids[ $slug ] = $id;
			} else {
				$completo = false;
			}
		}

		update_option( self::OPTION_PAGINAS_ADICIONALES_IDS, $ids );

		return $completo;
	}

	/**
	 * @return array<string, int> slug => ID de post
	 */
	public static function get_paginas_adicionales_ids(): array {
		return get_option( self::OPTION_PAGINAS_ADICIONALES_IDS, array() );
	}

	/**
	 * Todas las paginas del area que exigen sesion y llevan el menu de
	 * perfil: las cuatro secciones y las adicionales.
	 *
	 * @return int[]
	 */
	public static function get_ids_protegidos(): array {
		return array_values( array_map( 'intval', array_merge( array_values( self::get_page_ids() ), array_values( self::get_paginas_adicionales_ids() ) ) ) );
	}
}
