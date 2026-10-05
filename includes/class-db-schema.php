<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Define el esquema de las tablas propias del plugin.
 *
 * No se definen restricciones FOREIGN KEY reales: dbDelta() no las gestiona
 * de forma fiable (no genera los ALTER TABLE necesarios ni las revierte bien
 * en actualizaciones posteriores). La integridad farmacia <-> documento y,
 * desde Fase 3, farmacia <-> plan, se garantiza a nivel de aplicacion en las
 * tareas de logica de negocio, no en el esquema de base de datos: mismo
 * criterio que ya se aplico a documentos.farmacia_id, ahora tambien a
 * farmacias.plan_id (ver Activator::migrar_planes_desde_varchar() y
 * Plan_Service::eliminar(), que impide borrar un plan todavia en uso en vez
 * de depender de un ON DELETE RESTRICT que dbDelta no sabria mantener).
 */
class DB_Schema {

	public static function get_farmacias_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_farmacias';
	}

	public static function get_documentos_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_documentos';
	}

	/**
	 * Si una tabla propia admite transacciones (InnoDB). Con MyISAM, START
	 * TRANSACTION/ROLLBACK no fallan pero tampoco deshacen nada: quien
	 * promete un "todo o nada" (importador, publicacion en bloque) lo
	 * comprueba aqui en cada aplicacion en vez de darlo por supuesto.
	 */
	public static function tabla_es_innodb( string $tabla ): bool {
		global $wpdb;

		$engine = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
				$tabla
			)
		);

		return is_string( $engine ) && 0 === strcasecmp( $engine, 'InnoDB' );
	}

	public static function get_planes_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_planes';
	}

	/**
	 * Recuentos diarios de la recepcion automatica de documentos (#303),
	 * por resultado. Solo numeros: nunca CIF, nombres ni importes.
	 */
	public static function get_recepcion_registro_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_recepcion_registro';
	}

	/**
	 * Lotes de "Publicar todos los pendientes" (#317): quien y cuando
	 * publico, cuantos documentos, con que filtros, y si se deshizo, quien y
	 * cuando. Sin datos fiscales.
	 */
	public static function get_publicacion_lotes_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_publicacion_lotes';
	}

	public static function get_catalogo_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_catalogo';
	}

	/**
	 * Tabla puente catalogo <-> planes (relacion N:M, ver cabecera de esta
	 * clase y Catalogo_Repository). Conceptualmente pertenece al modulo de
	 * catalogo, no al de planes: Catalogo_Repository es quien construye SQL
	 * contra ella, igual que Farmacia_Repository lo hace contra su propia
	 * tabla aunque cuente filas por plan_id.
	 */
	public static function get_catalogo_planes_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_catalogo_planes';
	}

	/**
	 * Sentencias CREATE TABLE listas para pasar a dbDelta().
	 *
	 * dbDelta() nunca elimina columnas que ya no aparezcan aqui: la columna
	 * "plan" (VARCHAR) que sustituye este esquema no desaparece sola de una
	 * instalacion ya activada solo con este cambio. El DROP COLUMN explicito
	 * vive en Activator::migrar_planes_desde_varchar(), despues de migrar
	 * los datos que contenga -- dbDelta() se limita aqui a crear la tabla de
	 * planes y anadir la columna plan_id que la sustituye.
	 *
	 * @return string[]
	 */
	public static function get_create_table_statements() {
		global $wpdb;

		$charset_collate       = $wpdb->get_charset_collate();
		$farmacias_table       = self::get_farmacias_table_name();
		$documentos_table      = self::get_documentos_table_name();
		$planes_table          = self::get_planes_table_name();
		$catalogo_table        = self::get_catalogo_table_name();
		$catalogo_planes_table = self::get_catalogo_planes_table_name();
		$registro_table        = self::get_recepcion_registro_table_name();
		$lotes_table           = self::get_publicacion_lotes_table_name();

		// slug es el identificador estable para comparar visibilidad
		// (Permissions::puede_ver_bloque_por_plan(), atributo "planes" de
		// [mdf_ca_si_plan] y de los items de catalogo via
		// wp_mdf_ca_catalogo_planes): se fija al crear el
		// plan y no cambia si se edita el nombre desde el backoffice (ver
		// Plan_Service), mismo criterio que Area_Privada_Pages usa para sus
		// IDs de pagina frente al slug/titulo editable por MKT.
		$planes_sql = "CREATE TABLE {$planes_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			nombre VARCHAR(100) NOT NULL,
			slug VARCHAR(50) NOT NULL,
			fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		// plan_id es BIGINT UNSIGNED NULL, no FK real (ver cabecera de esta
		// clase). Nullable por el mismo motivo que el VARCHAR que sustituye:
		// no exigir backfill de las farmacias ya existentes; sin plan
		// asignado el catalogo por plan simplemente no muestra nada
		// (Permissions).
		$farmacias_sql = "CREATE TABLE {$farmacias_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			cif VARCHAR(9) NOT NULL,
			nombre VARCHAR(255) NOT NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			plan_id BIGINT UNSIGNED NULL,
			fecha_alta DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY cif (cif),
			KEY wp_user_id (wp_user_id),
			KEY plan_id (plan_id)
		) {$charset_collate};";

		// tipo_documento es VARCHAR nullable, no una tabla propia (ver
		// Documento_Tipos): a diferencia de plan_id, es una lista fija de
		// Fase 3 sin ningun flujo de alta/edicion previsto. Nullable por el
		// mismo motivo que plan_id: no exigir backfill a los documentos de
		// prueba ya existentes de fases anteriores.
		// descargable solo tiene efecto en documentos Excel (PDF e imagenes
		// nunca se descargan, van al visor). NOT NULL DEFAULT 0: todo
		// documento, incluidos los ya existentes, es no descargable salvo
		// que alguien lo marque expresamente al subirlo.
		// notificado_en (Fase 4, UTC): NULL = documento aun no incluido en
		// ningun aviso por email a la farmacia (Aviso_Documentos_Service).
		// hash_sha256 (#303): SHA-256 del fichero, solo lo rellena la recepcion
		// automatica, para que reenviar el mismo PDF no lo duplique. UNIQUE
		// sobre la columna sola (varias NULL no chocan: los documentos de
		// backoffice y los anteriores no tienen hash) y global, no por
		// farmacia: asi el mismo PDF con el CIF de OTRA farmacia tambien se
		// detecta y no se escribe.
		// publicado (#304): 0 = pendiente de publicar. Un documento no publicado
		// es invisible para toda farmacia hasta que un administrador lo
		// publica (Permissions::puede_ver_documento() es el unico punto que
		// lo decide). NOT NULL DEFAULT 1: todo lo existente y todo lo subido
		// desde el backoffice sigue visible; solo la recepcion automatica
		// crea documentos con 0 (si la option de aprobacion esta activa).
		// fecha_documento (#fecha): la fecha DEL DOCUMENTO (p. ej. la de la
		// factura), no la de subida. NULL en todo lo anterior y en lo que no
		// tiene una (un contrato, p. ej.). Sin indice propio: el listado de
		// Documentacion hace una sola consulta por farmacia_id (ya cubierta
		// por KEY farmacia_id) y agrupa y ordena en memoria; solo tendria
		// sentido (farmacia_id, fecha_documento) si algun dia se pagina o se
		// filtra por mes en SQL.
		// emisor_cif, factura_serie y factura_numero (#314): que sociedad del
		// grupo emitio la factura y con que serie y numero. Solo los rellena
		// la recepcion automatica, siempre los tres a la vez y ya revalidados
		// (Factura_Cruce_Service + Factura_Emisores). El UNIQUE sobre los tres
		// detecta la misma factura aunque SAGE regenere el PDF con otros bytes
		// (el hash no lo veria). Una fila con algun NULL nunca choca en un
		// UNIQUE de InnoDB: los documentos de backoffice y los anteriores
		// (los tres en NULL, sin backfill) no colisionan entre si. Global,
		// no por farmacia, por el mismo motivo que el hash. factura_serie es
		// mas ancha que las 3 letras que se aceptan hoy para no tener que
		// cambiar el esquema si se amplia.
		// publicacion_lote_id (#317): el lote de "Publicar todos los
		// pendientes" que publico el documento, SOLO mientras siga publicado
		// desde entonces. Deshacer el lote y cualquier otro cambio de estado
		// (publicar o despublicar uno, publicar los de una farmacia) lo ponen
		// a NULL: asi deshacer un lote nunca toca un documento que alguien ha
		// vuelto a publicar o despublicar despues por otra via. NULL en todo
		// lo anterior (sin backfill: no se puede deshacer en bloque). Sin FK,
		// como el resto. El indice cubre "publicados de este lote".
		// Nullable y sin backfill por dbDelta: la migracion que marca como
		// notificados los documentos ya existentes vive en
		// Activator::maybe_upgrade(), con su propia option. El indice
		// cubre la consulta de pendientes agrupados por farmacia.
		$documentos_sql = "CREATE TABLE {$documentos_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			farmacia_id BIGINT UNSIGNED NOT NULL,
			nombre VARCHAR(255) NOT NULL,
			tipo_documento VARCHAR(30) NULL,
			ruta_fichero VARCHAR(500) NOT NULL,
			tipo_mime VARCHAR(100) NULL,
			tamano_bytes BIGINT UNSIGNED NULL,
			descargable TINYINT(1) NOT NULL DEFAULT 0,
			fecha_subida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			notificado_en DATETIME NULL,
			hash_sha256 CHAR(64) NULL,
			publicado TINYINT(1) NOT NULL DEFAULT 1,
			fecha_documento DATE NULL,
			emisor_cif VARCHAR(9) NULL,
			factura_serie VARCHAR(10) NULL,
			factura_numero VARCHAR(20) NULL,
			publicacion_lote_id BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY farmacia_id (farmacia_id),
			KEY notificado_en (notificado_en,farmacia_id),
			KEY publicado (publicado,farmacia_id),
			KEY publicacion_lote (publicacion_lote_id,publicado),
			UNIQUE KEY hash_sha256 (hash_sha256),
			UNIQUE KEY factura (emisor_cif,factura_serie,factura_numero)
		) {$charset_collate};";

		// Lotes de publicacion en bloque (#317). creado_en y deshecho_en en
		// UTC. documentos = publicados al crear el lote (lo que siga
		// publicado se cuenta en vivo en documentos.publicacion_lote_id).
		// filtros = JSON de los filtros ya saneados. estado: aplicado o
		// deshecho. Sin FK a usuarios: un usuario borrado se muestra como tal.
		$lotes_sql = "CREATE TABLE {$lotes_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			creado_por BIGINT UNSIGNED NOT NULL,
			creado_en DATETIME NOT NULL,
			documentos INT UNSIGNED NOT NULL DEFAULT 0,
			filtros VARCHAR(255) NULL,
			estado VARCHAR(20) NOT NULL DEFAULT 'aplicado',
			deshecho_por BIGINT UNSIGNED NULL,
			deshecho_en DATETIME NULL,
			despublicados INT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY creado_en (creado_en)
		) {$charset_collate};";

		$registro_sql = "CREATE TABLE {$registro_table} (
			fecha DATE NOT NULL,
			resultado VARCHAR(20) NOT NULL,
			total INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (fecha,resultado)
		) {$charset_collate};";

		// seccion y tipo son VARCHAR de lista fija (ver Catalogo_Secciones y
		// Catalogo_Tipos), no tablas propias: mismo criterio que
		// tipo_documento en Documentos -- MKT definio un numero cerrado de
		// secciones (Herramientas, Formacion) y de tipos (descargable,
		// visualizable), sin ningun flujo de alta/edicion de esas listas.
		// enlace_url es nullable y generico (no exclusivo de "descargable"
		// ni de Formacion): cubre tanto un recurso descargable como el
		// enlace a la Academia de un item de Formacion, sin acoplar el
		// esquema a un caso de uso concreto.
		$catalogo_sql = "CREATE TABLE {$catalogo_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			seccion VARCHAR(20) NOT NULL,
			nombre VARCHAR(255) NOT NULL,
			tipo VARCHAR(20) NOT NULL DEFAULT 'visualizable',
			enlace_url VARCHAR(500) NULL,
			fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY seccion (seccion)
		) {$charset_collate};";

		// Tabla puente para la relacion N:M item <-> plan (ver cabecera de
		// esta clase): una tabla propia, no una lista de slugs en una
		// columna del catalogo. Una columna de texto serializado seria
		// volver al mismo patron denormalizado que la columna "plan" de
		// farmacias, que esta misma Fase 3 elimino a proposito; ademas
		// impediria un JOIN real para "que items ve este plan" o "cuantos
		// items usan este plan todavia" (Plan_Service::eliminar()), y
		// complicaria evitar asignar el mismo plan dos veces al mismo item.
		// PRIMARY KEY compuesta (catalogo_id, plan_id): impide la
		// duplicidad sin logica adicional en el codigo.
		$catalogo_planes_sql = "CREATE TABLE {$catalogo_planes_table} (
			catalogo_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (catalogo_id, plan_id),
			KEY plan_id (plan_id)
		) {$charset_collate};";

		return array( $planes_sql, $farmacias_sql, $documentos_sql, $catalogo_sql, $catalogo_planes_sql, $registro_sql, $lotes_sql );
	}
}
