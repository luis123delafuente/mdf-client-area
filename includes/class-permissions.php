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
	 * Admin_Documentos::CAPACIDAD (gestor y administrador, #337), y solo por las acciones de vista previa del backoffice
	 * (con marca de agua, nonce y token de un solo uso), nunca por el visor
	 * ni el endpoint de las farmacias.
	 */
	public static function puede_previsualizar_documento( ?\WP_User $usuario, ?Documento $documento ): bool {
		return self::usuario_autenticado( $usuario ) && $documento && user_can( $usuario, Admin_Documentos::CAPACIDAD );
	}

	/**
	 * Ver (vista previa), asignar o descartar una factura de la bandeja de no
	 * reconocidas (#335): la capacidad de Documentos MDF. Ninguna farmacia la
	 * alcanza: no es todavia de nadie.
	 */
	public static function puede_gestionar_bandeja( ?\WP_User $usuario, ?array $fila_bandeja ): bool {
		return self::usuario_autenticado( $usuario ) && null !== $fila_bandeja && user_can( $usuario, Admin_Documentos::CAPACIDAD );
	}

	/**
	 * Descargar el fichero (no verlo en el visor): puede verlo y el documento
	 * esta marcado como descargable. Desde la 0.36.0 vale para cualquier
	 * formato (antes solo un Excel) y es el valor por defecto en todas las
	 * vias de alta (decision de MDF). Se decide con lo guardado, nunca con un
	 * parametro de la peticion.
	 */
	public static function puede_descargar_documento( ?\WP_User $usuario, ?Documento $documento ): bool {
		return self::puede_ver_documento( $usuario, $documento ) && $documento->is_descargable();
	}

	/**
	 * De una lista de documentos, los ids de los que este usuario puede
	 * descargar. Misma regla que puede_descargar_documento(), resolviendo la
	 * farmacia una sola vez (el listado de Documentacion tiene cientos).
	 *
	 * @param Documento[] $documentos
	 * @return array<int, true> id => true
	 */
	public static function filtrar_documentos_descargables( ?\WP_User $usuario, array $documentos ): array {
		$ids = array();

		foreach ( self::filtrar_documentos_visibles( $usuario, $documentos ) as $documento ) {
			if ( $documento->is_descargable() ) {
				$ids[ $documento->get_id() ] = true;
			}
		}

		return $ids;
	}

	/**
	 * Una herramienta del catalogo la ve una farmacia si (#herramientas,
	 * esquema 1.21.0):
	 * - la herramienta esta visible (una oculta no la ve nadie);
	 * - su recurso esta completo (un elemento antiguo sin enlace no se
	 *   ofrece);
	 * - no es un Excel con macros con el ajuste apagado (Catalogo_Ajustes);
	 * - y el plan de la farmacia esta entre los de la herramienta, o la
	 *   farmacia esta en su lista de farmacias concretas. Las dos vias se
	 *   suman. Sin ningun plan ni farmacia, no la ve nadie.
	 *
	 * Es la unica regla: el listado del area de cliente, el visor, el
	 * fichero y la descarga preguntan aqui.
	 */
	public static function puede_ver_item_catalogo( ?\WP_User $usuario, ?Catalogo_Item $item ): bool {
		if ( ! $item || ! self::usuario_autenticado( $usuario ) ) {
			return false;
		}

		$farmacia = self::farmacia_del_usuario( $usuario );

		return $farmacia && self::item_visible_para_farmacia( $farmacia, self::slug_plan_de_farmacia( $farmacia ), $item );
	}

	/**
	 * De una lista de herramientas, las que este usuario puede ver. Misma
	 * regla que puede_ver_item_catalogo() (comparten
	 * item_visible_para_farmacia()), resolviendo la farmacia y su plan una
	 * sola vez.
	 *
	 * @param Catalogo_Item[] $items
	 * @return Catalogo_Item[]
	 */
	public static function filtrar_items_catalogo_visibles( ?\WP_User $usuario, array $items ): array {
		if ( ! self::usuario_autenticado( $usuario ) ) {
			return array();
		}

		$farmacia = self::farmacia_del_usuario( $usuario );

		if ( ! $farmacia ) {
			return array();
		}

		$slug_plan = self::slug_plan_de_farmacia( $farmacia );

		return array_values(
			array_filter(
				$items,
				static fn( $item ): bool => $item instanceof Catalogo_Item && self::item_visible_para_farmacia( $farmacia, $slug_plan, $item )
			)
		);
	}

	/**
	 * Abrirla en el visor: puede verla y es un archivo que el visor sabe
	 * pintar (PDF, Excel sin macros, JPG o PNG). Word, PowerPoint y .xlsm
	 * nunca van al visor.
	 */
	public static function puede_visualizar_item_catalogo( ?\WP_User $usuario, ?Catalogo_Item $item ): bool {
		return self::puede_ver_item_catalogo( $usuario, $item )
			&& $item->es_archivo()
			&& Catalogo_Formatos::es_visualizable( $item->get_formato() );
	}

	/**
	 * Descargar el archivo: puede verla y se marco como descargable, o su
	 * formato no tiene visor (Word, PowerPoint y .xlsm se descargan
	 * siempre). Se decide con lo guardado, nunca con un parametro de la
	 * peticion. La marca de agua es del visor: lo descargado va sin ella.
	 */
	public static function puede_descargar_item_catalogo( ?\WP_User $usuario, ?Catalogo_Item $item ): bool {
		return self::puede_ver_item_catalogo( $usuario, $item )
			&& $item->es_archivo()
			&& ( $item->is_descargable() || Catalogo_Formatos::es_siempre_descargable( $item->get_formato() ) );
	}

	/**
	 * Version generica: "¿el plan de esta farmacia esta en esta lista de
	 * planes permitidos?", sin asumir nada sobre el recurso que envuelve
	 * (bloque de contenido arbitrario de Shortcode_Si_Plan). El catalogo
	 * usa la misma resolucion del plan (slug_plan_de_farmacia()) y suma
	 * ademas las farmacias concretas. $planes_permitidos son slugs
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

		$farmacia  = self::farmacia_del_usuario( $usuario );
		$slug_plan = $farmacia ? self::slug_plan_de_farmacia( $farmacia ) : null;

		return null !== $slug_plan && in_array( $slug_plan, $planes_permitidos, true );
	}

	/**
	 * El predicado de visibilidad de una herramienta, compartido por
	 * puede_ver_item_catalogo() y filtrar_items_catalogo_visibles().
	 */
	private static function item_visible_para_farmacia( Farmacia $farmacia, ?string $slug_plan, Catalogo_Item $item ): bool {
		if ( ! $item->is_visible() || ! $item->recurso_completo() ) {
			return false;
		}

		if ( $item->es_archivo() && Catalogo_Formatos::XLSM === $item->get_formato() && ! Catalogo_Ajustes::permite_xlsm() ) {
			return false;
		}

		if ( in_array( $farmacia->get_id(), $item->get_farmacia_ids(), true ) ) {
			return true;
		}

		return null !== $slug_plan && in_array( $slug_plan, $item->get_planes_slugs(), true );
	}

	/**
	 * Slug del plan de la farmacia, o null si no tiene plan o su plan_id ya
	 * no resuelve a ninguno. Unico punto del plugin que resuelve un plan_id
	 * contra Plan_Repository para comparar planes.
	 */
	private static function slug_plan_de_farmacia( Farmacia $farmacia ): ?string {
		if ( ! $farmacia->get_plan_id() ) {
			return null;
		}

		$plan = ( new Plan_Repository() )->find_by_id( $farmacia->get_plan_id() );

		return $plan ? $plan->get_slug() : null;
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
