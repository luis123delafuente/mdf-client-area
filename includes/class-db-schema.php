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

		// Una consulta por tabla y peticion: el motor no cambia durante una peticion.
		static $motores = array();

		if ( ! isset( $motores[ $tabla ] ) ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$tabla
				)
			);

			$motores[ $tabla ] = is_string( $engine ) && 0 === strcasecmp( $engine, 'InnoDB' );
		}

		return $motores[ $tabla ];
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

	/**
	 * Eventos de publicacion de documentos (#318): libro de SOLO AÑADIR de
	 * quien publico o despublico cada documento y cuando. Ningun camino de
	 * codigo hace UPDATE ni DELETE sobre esta tabla (ver
	 * Publicacion_Evento_Repository). Solo ids de usuario, fecha, accion,
	 * origen y lote: sin IP, user agent ni datos fiscales.
	 */
	public static function get_publicacion_eventos_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_publicacion_eventos';
	}

	/**
	 * Envios por correo de las cartas del Modelo 347 (#307, generador): un
	 * registro por documento con su destinatario, estado, intentos, fechas y
	 * error. Nunca importes.
	 */
	public static function get_modelo_347_envios_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_347_envios';
	}

	/**
	 * Bandeja de facturas no reconocidas (#335): las que "Subir facturas" no
	 * ha podido asignar, con su fichero en la carpeta privada, el motivo, lo
	 * que el lector haya podido sacar (emisor, serie, numero, fecha, CIF
	 * leido, si es abono), el nombre original, el hash y quien y cuando la
	 * subio. Nunca importes. Se vacia sola tras un plazo (Bandeja_Facturas_Service).
	 */
	public static function get_bandeja_facturas_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_bandeja_facturas';
	}

	/**
	 * Eventos de la bandeja (#335), de SOLO AÑADIR: entrada, asignada,
	 * descartada y caducada, con usuario y fecha. Sin datos fiscales; la fila
	 * de la bandeja puede no existir ya.
	 */
	public static function get_bandeja_eventos_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_bandeja_eventos';
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

	/** Categorias de las herramientas (una por herramienta). */
	public static function get_catalogo_categorias_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_catalogo_categorias';
	}

	/** Etiquetas de las herramientas (varias por herramienta, via catalogo_item_etiquetas). */
	public static function get_catalogo_etiquetas_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_catalogo_etiquetas';
	}

	public static function get_catalogo_item_etiquetas_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_catalogo_item_etiquetas';
	}

	/** Farmacias concretas que ven una herramienta, ademas de las de sus planes. */
	public static function get_catalogo_farmacias_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'mdf_ca_catalogo_farmacias';
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
		$categorias_table      = self::get_catalogo_categorias_table_name();
		$etiquetas_table       = self::get_catalogo_etiquetas_table_name();
		$item_etiquetas_table  = self::get_catalogo_item_etiquetas_table_name();
		$cat_farmacias_table   = self::get_catalogo_farmacias_table_name();
		$registro_table        = self::get_recepcion_registro_table_name();
		$lotes_table           = self::get_publicacion_lotes_table_name();
		$eventos_table         = self::get_publicacion_eventos_table_name();
		$envios_347_table      = self::get_modelo_347_envios_table_name();
		$bandeja_table         = self::get_bandeja_facturas_table_name();
		$bandeja_eventos_table = self::get_bandeja_eventos_table_name();

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
		// descargable: la farmacia puede descargar el fichero (cualquier
		// formato desde la v0.36.0; antes, solo Excel). Toda alta lo fija
		// expresamente (por defecto, si: Documento_Service::POR_DEFECTO_*) y la
		// migracion mdf_ca_documentos_descargables_v1 paso los existentes a 1.
		// El DEFAULT 0 de la columna no se cambia: ningun alta depende de el.
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
		// ejercicio, vigente y retirado_en (#307, Modelo 347): solo los rellenan
		// los documentos de tipo modelo_347. ejercicio es el ano fiscal (no se
		// guarda como fecha_documento AAAA-12-31: no es la fecha del documento
		// y el validador de fechas rechazaria el ejercicio en curso). El 347
		// tambien rellena emisor_cif (la sociedad que lo emite). vigente = 1 en
		// el 347 vigente y NULL en todo lo demas (facturas, el resto de tipos y
		// los 347 retirados para correccion): el UNIQUE modelo_347 garantiza
		// un unico 347 vigente por farmacia, emisor y ejercicio, y como una
		// fila con algun NULL nunca choca, los retirados y el resto de
		// documentos no colisionan (mismo comportamiento en MySQL y MariaDB).
		// retirado_en (UTC) marca un 347 retirado: estado terminal, no se
		// puede publicar ni aparece en las listas de pendientes (ver
		// Documento_Repository::PENDIENTE). Sin backfill: todo lo anterior,
		// NULL. Los importes del 347 nunca se guardan en la BD: solo en el PDF.
		// Nullable y sin backfill por dbDelta: la migracion que marca como
		// notificados los documentos ya existentes vive en
		// Activator::maybe_upgrade(), con su propia option. El indice
		// cubre la consulta de pendientes agrupados por farmacia.
		// sin_marca (#321, esquema 1.20.0): 1 = el visor (de la farmacia y la
		// vista previa del administrador) no pinta la marca de agua. DEFAULT 0:
		// todo lo existente y todo lo nuevo la lleva salvo que se quite a mano.
		$documentos_sql = "CREATE TABLE {$documentos_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			farmacia_id BIGINT UNSIGNED NOT NULL,
			nombre VARCHAR(255) NOT NULL,
			tipo_documento VARCHAR(30) NULL,
			ruta_fichero VARCHAR(500) NOT NULL,
			tipo_mime VARCHAR(100) NULL,
			tamano_bytes BIGINT UNSIGNED NULL,
			descargable TINYINT(1) NOT NULL DEFAULT 0,
			sin_marca TINYINT(1) NOT NULL DEFAULT 0,
			fecha_subida DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			notificado_en DATETIME NULL,
			hash_sha256 CHAR(64) NULL,
			publicado TINYINT(1) NOT NULL DEFAULT 1,
			fecha_documento DATE NULL,
			emisor_cif VARCHAR(9) NULL,
			factura_serie VARCHAR(10) NULL,
			factura_numero VARCHAR(20) NULL,
			publicacion_lote_id BIGINT UNSIGNED NULL,
			ejercicio SMALLINT UNSIGNED NULL,
			vigente TINYINT(1) NULL,
			retirado_en DATETIME NULL,
			PRIMARY KEY  (id),
			KEY farmacia_id (farmacia_id),
			KEY notificado_en (notificado_en,farmacia_id),
			KEY publicado (publicado,farmacia_id),
			KEY publicacion_lote (publicacion_lote_id,publicado),
			UNIQUE KEY hash_sha256 (hash_sha256),
			UNIQUE KEY factura (emisor_cif,factura_serie,factura_numero),
			UNIQUE KEY modelo_347 (farmacia_id,emisor_cif,ejercicio,vigente)
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

		// Eventos de publicacion (#318), de solo anadir. accion: publicado |
		// despublicado. origen: individual, por_farmacia, bloque,
		// deshacer_lote, recepcion (solo antiguos: el robot, retirado en
		// #336) o subida_backoffice, y los origenes posteriores. usuario_id
		// NULL si no hay usuario (nunca ocurre hoy: quien actua siempre es un
		// usuario). fecha en UTC. lote_id: el lote que publica, el que se
		// deshace o, al despublicar uno a uno, el que habia publicado el
		// documento (documentos.publicacion_lote_id se pone a NULL, el evento
		// conserva ese dato). Sin FK, como el resto. El indice sirve el
		// historial de un documento.
		$eventos_sql = "CREATE TABLE {$eventos_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			documento_id BIGINT UNSIGNED NOT NULL,
			accion VARCHAR(20) NOT NULL,
			origen VARCHAR(20) NOT NULL,
			usuario_id BIGINT UNSIGNED NULL,
			fecha DATETIME NOT NULL,
			lote_id BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY documento (documento_id,fecha)
		) {$charset_collate};";

		// Envios del Modelo 347 (#307). UNIQUE documento_id: una fila por
		// carta; "reenviar" reutiliza la fila (estado otra vez pendiente), asi
		// que nunca hay dos envios en curso de la misma carta. estado:
		// pendiente | enviando | enviado | fallido | cancelado. "enviando" lo
		// pone el UPDATE condicionado que reclama la fila antes de llamar a
		// wp_mail(): si PHP muere a mitad se queda asi y NO se reintenta solo
		// (el correo pudo salir). destinatario es el email de la cuenta de la
		// farmacia en el momento del envio (dato personal: revision RGPD de la
		// Fase 5). error, saneado y sin importes. Fechas en UTC. Sin FK.
		$envios_347_sql = "CREATE TABLE {$envios_347_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			documento_id BIGINT UNSIGNED NOT NULL,
			farmacia_id BIGINT UNSIGNED NOT NULL,
			lote_id BIGINT UNSIGNED NULL,
			destinatario VARCHAR(190) NOT NULL DEFAULT '',
			estado VARCHAR(12) NOT NULL DEFAULT 'pendiente',
			intentos SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			creado_en DATETIME NOT NULL,
			ultimo_intento_en DATETIME NULL,
			enviado_en DATETIME NULL,
			error VARCHAR(255) NULL,
			usuario_id BIGINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY documento_id (documento_id),
			KEY estado (estado,lote_id)
		) {$charset_collate};";

		// origen (#334, esquema 1.15.0): "backoffice" (Subir facturas, la
		// bandeja y las publicaciones y despublicaciones en bloque) o "rest"
		// (solo filas antiguas: el robot, retirado en #336). Entra en
		// la clave primaria: una fila por dia, resultado y origen. dbDelta no
		// cambia una clave primaria: la migracion de las tablas que ya existian
		// la hace Activator::migrar_registro_origen() antes de dbDelta.
		$registro_sql = "CREATE TABLE {$registro_table} (
			fecha DATE NOT NULL,
			resultado VARCHAR(20) NOT NULL,
			origen VARCHAR(12) NOT NULL DEFAULT 'rest',
			total INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (fecha,resultado,origen)
		) {$charset_collate};";

		// Catalogo de herramientas. seccion y tipo son de antes del esquema
		// 1.21.0 y el codigo ya no los lee (seccion se sigue rellenando: es
		// NOT NULL). Recurso: enlace (enlace_url) o archivo (formato,
		// ruta_fichero dentro de Clientes/private-docs/herramientas/,
		// tipo_mime, tamano). descargable y marca_agua solo aplican a un
		// archivo del visor; visible = 0 es "Oculta".
		$catalogo_sql = "CREATE TABLE {$catalogo_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			seccion VARCHAR(20) NOT NULL,
			nombre VARCHAR(255) NOT NULL,
			tipo VARCHAR(20) NOT NULL DEFAULT 'visualizable',
			enlace_url VARCHAR(500) NULL,
			fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			descripcion VARCHAR(255) NULL,
			categoria_id BIGINT UNSIGNED NULL,
			recurso VARCHAR(10) NOT NULL DEFAULT 'enlace',
			formato VARCHAR(10) NULL,
			ruta_fichero VARCHAR(255) NULL,
			tipo_mime VARCHAR(100) NULL,
			tamano BIGINT UNSIGNED NULL,
			descargable TINYINT(1) NOT NULL DEFAULT 0,
			marca_agua TINYINT(1) NOT NULL DEFAULT 1,
			visible TINYINT(1) NOT NULL DEFAULT 1,
			fecha_modificacion DATETIME NULL,
			PRIMARY KEY  (id),
			KEY seccion (seccion),
			KEY categoria_id (categoria_id)
		) {$charset_collate};";

		// Herramientas (esquema 1.21.0). Desde aqui el catalogo es solo
		// Herramientas: seccion y tipo quedan de la version anterior y el
		// codigo ya no los lee (seccion se sigue rellenando porque es NOT NULL
		// sin valor por defecto). Categorias y etiquetas: nombre editable y
		// slug inmutable, los dos UNIQUE (la collation no distingue mayusculas
		// ni tildes). Las tablas puente con PRIMARY KEY compuesta, como
		// catalogo_planes, y sin FOREIGN KEY: la integridad la lleva el codigo.
		$categorias_sql = "CREATE TABLE {$categorias_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			nombre VARCHAR(100) NOT NULL,
			slug VARCHAR(100) NOT NULL,
			fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY nombre (nombre),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$etiquetas_sql = "CREATE TABLE {$etiquetas_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			nombre VARCHAR(100) NOT NULL,
			slug VARCHAR(100) NOT NULL,
			fecha_creacion DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY nombre (nombre),
			UNIQUE KEY slug (slug)
		) {$charset_collate};";

		$item_etiquetas_sql = "CREATE TABLE {$item_etiquetas_table} (
			catalogo_id BIGINT UNSIGNED NOT NULL,
			etiqueta_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (catalogo_id, etiqueta_id),
			KEY etiqueta_id (etiqueta_id)
		) {$charset_collate};";

		$cat_farmacias_sql = "CREATE TABLE {$cat_farmacias_table} (
			catalogo_id BIGINT UNSIGNED NOT NULL,
			farmacia_id BIGINT UNSIGNED NOT NULL,
			PRIMARY KEY  (catalogo_id, farmacia_id),
			KEY farmacia_id (farmacia_id)
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

		// Bandeja de facturas (#335). hash UNIQUE: el mismo PDF no entra dos
		// veces. UNIQUE factura (emisor_cif, serie, numero): la misma factura
		// con otros bytes tampoco; los NULL no chocan (las que no se pudieron
		// leer solo se distinguen por el hash). motivo: el estado del cruce.
		$bandeja_sql = "CREATE TABLE {$bandeja_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			ruta_fichero VARCHAR(100) NOT NULL,
			nombre_original VARCHAR(255) NOT NULL,
			tamano_bytes INT UNSIGNED NOT NULL DEFAULT 0,
			hash_sha256 CHAR(64) NOT NULL,
			motivo VARCHAR(30) NOT NULL,
			emisor_cif VARCHAR(20) NULL,
			serie VARCHAR(5) NULL,
			numero VARCHAR(20) NULL,
			fecha_documento DATE NULL,
			cif_leido VARCHAR(20) NULL,
			es_abono TINYINT(1) NULL,
			usuario_id BIGINT UNSIGNED NULL,
			subido_en DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY hash_sha256 (hash_sha256),
			UNIQUE KEY factura (emisor_cif,serie,numero),
			KEY subido_en (subido_en)
		) {$charset_collate};";

		$bandeja_eventos_sql = "CREATE TABLE {$bandeja_eventos_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bandeja_id BIGINT UNSIGNED NOT NULL,
			accion VARCHAR(20) NOT NULL,
			usuario_id BIGINT UNSIGNED NULL,
			documento_id BIGINT UNSIGNED NULL,
			fecha DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY bandeja (bandeja_id)
		) {$charset_collate};";

		return array( $planes_sql, $farmacias_sql, $documentos_sql, $catalogo_sql, $catalogo_planes_sql, $categorias_sql, $etiquetas_sql, $item_etiquetas_sql, $cat_farmacias_sql, $registro_sql, $lotes_sql, $eventos_sql, $envios_347_sql, $bandeja_sql, $bandeja_eventos_sql );
	}
}
