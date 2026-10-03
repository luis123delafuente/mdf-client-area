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

	/**
	 * Un documento lo ve solo su farmacia titular y solo si esta publicado
	 * (#304): uno pendiente de publicar es invisible para todas las farmacias
	 * hasta que un administrador lo publica. Es el UNICO sitio que decide
	 * esto: el visor, el endpoint y el listado de Documentacion preguntan
	 * aqui, ninguno comprueba "publicado" por su cuenta.
	 */
	public static function puede_ver_documento( ?\WP_User $usuario, ?Documento $documento ): bool {
		if ( ! self::usuario_autenticado( $usuario ) || ! $documento ) {
			return false;
		}

		$farmacia = self::farmacia_del_usuario( $usuario );

		if ( ! $farmacia ) {
			return false;
		}

		return self::documento_visible_para_farmacia( $farmacia, $documento );
	}

	/**
	 * De una lista de documentos, los que este usuario puede ver. Misma
	 * regla que puede_ver_documento() (comparten documento_visible_para_
	 * farmacia()), pero resolviendo la farmacia del usuario UNA vez: el
	 * listado de Documentacion tiene cientos de documentos tras la ingesta
	 * del historico y no debe hacer una consulta por cada uno.
	 *
	 * @param Documento[] $documentos
	 * @return Documento[]
	 */
	public static function filtrar_documentos_visibles( ?\WP_User $usuario, array $documentos ): array {
		if ( ! self::usuario_autenticado( $usuario ) ) {
			return array();
		}

		$farmacia = self::farmacia_del_usuario( $usuario );

		if ( ! $farmacia ) {
			return array();
		}

		return array_values(
			array_filter(
				$documentos,
				static fn( $documento ): bool => $documento instanceof Documento && self::documento_visible_para_farmacia( $farmacia, $documento )
			)
		);
	}

	/**
	 * Vista previa de un documento para un administrador (#304), publicado o
	 * no, antes de decidir publicarlo. Es el unico camino por el que alguien
	 * que no es la farmacia titular puede leer un documento de cliente: solo
	 * manage_options, y solo por las acciones de vista previa del backoffice
	 * (con marca de agua, nonce y token de un solo uso), nunca por el visor
	 * ni el endpoint de las farmacias.
	 */
	public static function puede_previsualizar_documento( ?\WP_User $usuario, ?Documento $documento ): bool {
		return self::usuario_autenticado( $usuario ) && $documento && user_can( $usuario, 'manage_options' );
	}

	/**
	 * Descargar el fichero crudo (no verlo en el visor). Un Excel solo se
	 * descarga si, ademas de poder verlo, se marco como descargable al
	 * subirlo; el resto de documentos nunca se descargan. Vive aqui, junto
	 * a puede_ver_documento(), para que "que puede hacer esta farmacia con
	 * este documento" se decida en un unico sitio.
	 */
	public static function puede_descargar_documento( ?\WP_User $usuario, ?Documento $documento ): bool {
		return self::puede_ver_documento( $usuario, $documento )
			&& Documento_Service::MIME_XLSX === $documento->get_tipo_mime()
			&& $documento->is_descargable();
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

	/**
	 * El predicado de visibilidad de un documento: misma farmacia y
	 * publicado. Compartido por puede_ver_documento() y
	 * filtrar_documentos_visibles() para que no puedan divergir.
	 */
	private static function documento_visible_para_farmacia( Farmacia $farmacia, Documento $documento ): bool {
		return $farmacia->get_id() === $documento->get_farmacia_id() && $documento->is_publicado();
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
