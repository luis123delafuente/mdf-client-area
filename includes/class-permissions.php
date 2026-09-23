<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Punto unico de decision de visibilidad del plugin (ver CLAUDE.md, regla
 * no negociable 2). Ningun otro lugar del plugin debe decidir si algo es
 * visible para un usuario: el servido de documentos (#232), el catalogo
 * de herramientas por plan (#242) y el bloque condicionado por plan
 * ("Bloque condicionado por plan") llaman aqui, no reimplementan la
 * logica.
 *
 * Cada tipo de recurso tiene su propio metodo con la firma
 * (usuario, recurso): bool, reutilizando farmacia_del_usuario() sin que
 * quien ya llama a un metodo existente tenga que cambiar nada al anadir
 * otro. La logica de "el plan de esta farmacia esta en esta lista de
 * planes permitidos" vive en un unico sitio (puede_ver_bloque_por_plan())
 * y puede_ver_item_catalogo() la reutiliza en vez de repetirla: los dos
 * metodos existen porque el recurso que reciben es distinto (un item de
 * catalogo vs. una lista de planes suelta de un shortcode generico), no
 * porque la comprobacion de fondo sea distinta. Los documentos nunca
 * dependen del plan (CLAUDE.md), asi que puede_ver_documento() no toca
 * ninguno de los dos.
 *
 * Toda condicion de entrada dudosa (sin sesion, sin recurso, sin farmacia
 * vinculada) devuelve false explicitamente. Ninguna excepcion sin capturar
 * debe poder escapar de esta clase.
 */
class Permissions {

	public static function puede_ver_documento( ?\WP_User $usuario, ?Documento $documento ): bool {
		if ( ! self::usuario_autenticado( $usuario ) || ! $documento ) {
			return false;
		}

		$farmacia = self::farmacia_del_usuario( $usuario );

		if ( ! $farmacia ) {
			return false;
		}

		return $farmacia->get_id() === $documento->get_farmacia_id();
	}

	/**
	 * $item es ahora un Catalogo_Item real (Fase 3, #247), ya no el array
	 * placeholder de Catalogo_Herramientas (Fase 2). El contrato hacia
	 * puede_ver_bloque_por_plan() no cambia: sigue siendo "lista de slugs
	 * permitidos", solo cambia de donde sale esa lista -- antes una clave
	 * de un array hardcodeado, ahora Catalogo_Item::get_planes_slugs(),
	 * resuelta por Catalogo_Repository contra la tabla puente
	 * wp_mdf_ca_catalogo_planes. Un item sin ningun plan asignado trae un
	 * array vacio, y puede_ver_bloque_por_plan() ya trata una lista vacia
	 * como "nadie pasa" (mismo criterio restrictivo por defecto que
	 * "farmacia sin plan"): no hizo falta anadir ningun caso especial aqui
	 * para eso.
	 */
	public static function puede_ver_item_catalogo( ?\WP_User $usuario, ?Catalogo_Item $item ): bool {
		if ( ! $item ) {
			return false;
		}

		return self::puede_ver_bloque_por_plan( $usuario, $item->get_planes_slugs() );
	}

	/**
	 * Version generica: "¿el plan de esta farmacia esta en esta lista de
	 * planes permitidos?", sin asumir nada sobre el recurso que envuelve
	 * (bloque de contenido arbitrario de Shortcode_Si_Plan, o el catalogo
	 * a traves de puede_ver_item_catalogo()). $planes_permitidos son slugs
	 * de plan (el atributo "planes" de [mdf_ca_si_plan], y desde
	 * Catalogo_Item::get_planes_slugs() para el catalogo -- ambos usaban ya
	 * ese formato desde Fase 2; Fase 3 solo cambia de donde sale el slug de
	 * la farmacia y del item, no el contrato hacia fuera). Sin plan
	 * asignado a la farmacia, o con un plan_id que ya no
	 * resuelve a ningun plan (p. ej. quedo huerfano antes de que
	 * Plan_Service::eliminar() empezara a impedirlo), no pasa ninguna lista:
	 * mismo criterio conservador que el resto de esta clase. Este es el
	 * unico punto del plugin que resuelve un plan_id contra Plan_Repository
	 * para comparar planes.
	 *
	 * @param string[] $planes_permitidos
	 */
	public static function puede_ver_bloque_por_plan( ?\WP_User $usuario, array $planes_permitidos ): bool {
		if ( ! self::usuario_autenticado( $usuario ) || ! $planes_permitidos ) {
			return false;
		}

		$farmacia = self::farmacia_del_usuario( $usuario );

		if ( ! $farmacia || ! $farmacia->get_plan_id() ) {
			return false;
		}

		$plan = ( new Plan_Repository() )->find_by_id( $farmacia->get_plan_id() );

		if ( ! $plan ) {
			return false;
		}

		return in_array( $plan->get_slug(), $planes_permitidos, true );
	}

	private static function usuario_autenticado( ?\WP_User $usuario ): bool {
		return null !== $usuario && $usuario->exists();
	}

	/**
	 * Resuelve la farmacia del usuario a traves de Farmacia_Repository, sin
	 * duplicar la consulta por wp_user_id en ningun otro punto del plugin.
	 */
	private static function farmacia_del_usuario( \WP_User $usuario ): ?Farmacia {
		return ( new Farmacia_Repository() )->find_by_wp_user_id( $usuario->ID );
	}
}
