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

	public static function get_planes_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_planes';
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
		$documentos_sql = "CREATE TABLE {$documentos_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			farmacia_id BIGINT UNSIGNED NOT NULL,
			nombre VARCHAR(255) NOT NULL,
			tipo_documento VARCHAR(30) NULL,
			ruta_fichero VARCHAR(500) NOT NULL,
			tipo_mime VARCHAR(100) NULL,
			tamano_bytes BIGINT UNSIGNED NULL,
			fecha_subida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY farmacia_id (farmacia_id)
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

		return array( $planes_sql, $farmacias_sql, $documentos_sql, $catalogo_sql, $catalogo_planes_sql );
	}
}
