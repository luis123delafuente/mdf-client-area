<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	public static function activate() {
		self::create_tables();
		update_option( 'mdf_ca_db_version', MDF_CA_DB_VERSION );
		self::marcar_inicio_de_eventos();
		self::migrar_planes_desde_varchar();
		Roles::register();
		Roles::conceder_gestion_347();
		update_option( 'mdf_ca_cap_347_v1', '1' );
		Roles::conceder_capacidades_administrador();
		update_option( 'mdf_ca_rol_gestor_v1', '1' );
		update_option( 'mdf_ca_cap_catalogo_v1', '1' );
		if ( self::migrar_catalogo_a_herramientas() ) {
			update_option( 'mdf_ca_herramientas_v1', '1' );
		}
		if ( ( new Documento_Repository() )->marcar_todos_descargables() ) {
			update_option( 'mdf_ca_documentos_descargables_v1', '1' );
		}
		if ( ( new Documento_Repository() )->marcar_presupuestos_sin_estado_pendientes() ) {
			update_option( 'mdf_ca_presupuestos_estado_v1', '1' );
		}
		Area_Privada_Pages::crear_paginas();
		if ( Area_Privada_Pages::crear_paginas_adicionales() ) {
			update_option( 'mdf_ca_area_privada_paginas_adicionales_v1', '1' );
		}

		// Proteccion de la carpeta privada (#327): crea lo que falte y hace
		// la comprobacion HTTP (como mucho 5 s, solo al activar). La option
		// evita que maybe_upgrade() la repita y tire la cache recien hecha.
		Carpeta_Privada_Proteccion::comprobar( true );
		update_option( 'mdf_ca_proteccion_carpeta_v1', '1' );

		// Las rewrite rules de Documento_Endpoint y Documento_Visor se
		// registran en cada carga via 'init', pero el flush (caro, no se
		// debe hacer en cada peticion) solo debe pasar al activar/desactivar.
		Documento_Endpoint::registrar_rewrite();
		Documento_Visor::registrar_rewrite();
		Herramienta_Endpoint::registrar_rewrite();
		flush_rewrite_rules();
		update_option( 'mdf_ca_herramientas_rewrite_v1', '1' );
		update_option( 'mdf_ca_documento_descarga_rewrite_v1', '1' );
	}

	/**
	 * dbDelta() en activate() no llega a las instalaciones donde el plugin
	 * ya estaba activo antes de un cambio de esquema (p. ej. la columna
	 * plan de #242): activate() no se vuelve a disparar solo por desplegar
	 * codigo nuevo. Enganchado en plugins_loaded, compara la version de BD
	 * guardada contra la constante y, si difiere, repite dbDelta() -- que
	 * es idempotente y solo aplica el ALTER TABLE que falte. El chequeo en
	 * si es una simple lectura de option, barato en cada carga.
	 *
	 * Las cuatro paginas del area de clientes (#244) se crean por el mismo
	 * motivo fuera de activate(): un despliegue de codigo nuevo sobre una
	 * instalacion ya activa no vuelve a disparar el hook de activacion.
	 * Area_Privada_Pages::crear_paginas() es idempotente por si misma (no
	 * pisa paginas ya creadas), asi que aqui basta con una option propia
	 * que evite repetir esa comprobacion en cada peticion una vez hecha.
	 */
	/**
	 * Options que marcan cada tarea de un solo uso de migrar() como hecha. Si
	 * falta alguna (o la version de BD no coincide), hay trabajo pendiente.
	 */
	private const MARCAS_MIGRACION = array(
		'mdf_ca_eventos_desde',
		'mdf_ca_avisos_backfill_hecho',
		'mdf_ca_robot_retirado_v1',
		'mdf_ca_cap_347_v1',
		'mdf_ca_rol_gestor_v1',
		'mdf_ca_cap_catalogo_v1',
		'mdf_ca_proteccion_carpeta_v1',
		'mdf_ca_planes_migrados',
		'mdf_ca_area_privada_paginas_creadas',
		'mdf_ca_area_privada_paginas_adicionales_v1',
		'mdf_ca_visor_rewrite_flushed',
		'mdf_ca_herramientas_v1',
		'mdf_ca_herramientas_rewrite_v1',
		'mdf_ca_documentos_descargables_v1',
		'mdf_ca_documento_descarga_rewrite_v1',
		'mdf_ca_presupuestos_estado_v1',
	);

	/**
	 * Cerrojo de maybe_upgrade() (#307): GET_LOCK es por conexion y existe
	 * igual en MySQL y en MariaDB; se libera solo si PHP muere.
	 */
	private const CERROJO_MIGRACION = 'mdf_ca_upgrade';

	/**
	 * Comprobacion barata en cada peticion (options ya cargadas en memoria).
	 * Solo si queda algo pendiente se pide el cerrojo, sin esperar: si otra
	 * peticion lo tiene, esta sigue sin migrar (lo esta haciendo la otra) en
	 * vez de repetir el ALTER TABLE a la vez (antes de #307, dos peticiones
	 * simultaneas tras un despliegue dejaban "Duplicate column" en el log).
	 * Con el cerrojo se vacia la cache de options de esta peticion y se
	 * vuelve a comprobar contra la BD: la otra peticion puede haber terminado
	 * justo antes.
	 */
	public static function maybe_upgrade(): void {
		if ( ! self::hay_migracion_pendiente() ) {
			return;
		}

		global $wpdb;

		$cerrojo = $wpdb->prefix . self::CERROJO_MIGRACION;

		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $cerrojo ) ) ) {
			return;
		}

		try {
			self::olvidar_options_en_cache();

			if ( self::hay_migracion_pendiente() ) {
				self::migrar();
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $cerrojo ) );
		}
	}

	private static function hay_migracion_pendiente(): bool {
		if ( get_option( 'mdf_ca_db_version' ) !== MDF_CA_DB_VERSION ) {
			return true;
		}

		foreach ( self::MARCAS_MIGRACION as $marca ) {
			if ( false === get_option( $marca ) ) {
				return true;
			}
		}

		return false;
	}

	/** Para que get_option() vuelva a leer de la BD lo que otra peticion haya escrito. */
	private static function olvidar_options_en_cache(): void {
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		foreach ( array_merge( array( 'mdf_ca_db_version' ), self::MARCAS_MIGRACION ) as $option ) {
			wp_cache_delete( $option, 'options' );
		}
	}

	/** Lo que hacia maybe_upgrade() antes del cerrojo, ahora siempre dentro de el. */
	private static function migrar(): void {
		if ( get_option( 'mdf_ca_db_version' ) !== MDF_CA_DB_VERSION ) {
			self::create_tables();
			update_option( 'mdf_ca_db_version', MDF_CA_DB_VERSION );
		}

		// Eventos de publicacion (#318): desde cuando existe el registro.
		// Va despues de create_tables() (que crea la tabla). Lo anterior a
		// esta marca no tiene eventos y la pantalla lo dice.
		self::marcar_inicio_de_eventos();

		// Avisos de documentos nuevos (Fase 4): los documentos que ya
		// existen al desplegar cuentan como notificados, para que activar
		// los avisos no envie nada por ellos. Va despues de create_tables()
		// (que anade notificado_en) en esta misma peticion, y solo marca la
		// option si el UPDATE ha ido bien (si la columna no existiera,
		// fallaria y se reintentaria en la siguiente peticion).
		if ( ! get_option( 'mdf_ca_avisos_backfill_hecho' )
			&& ( new Documento_Repository() )->marcar_todos_notificados() ) {
			update_option( 'mdf_ca_avisos_backfill_hecho', '1' );
		}

		// Robot de recepcion retirado (#336, esquema 1.17.0): revoca sus
		// contrasenas de aplicacion, borra el usuario si solo tiene ese rol,
		// quita el rol y las options de la recepcion REST. Idempotente; en una
		// instalacion nueva no encuentra nada. Tambien borra la marca
		// mdf_ca_roles_v2 de la migracion que daba de alta el rol.
		if ( ! get_option( 'mdf_ca_robot_retirado_v1' ) ) {
			Robot_Retirado::ejecutar();
			update_option( 'mdf_ca_robot_retirado_v1', '1' );
		}

		// Generador del Modelo 347 (#307): su capacidad propia, al rol
		// administrator, una sola vez.
		if ( ! get_option( 'mdf_ca_cap_347_v1' ) ) {
			Roles::conceder_gestion_347();
			update_option( 'mdf_ca_cap_347_v1', '1' );
		}

		// Rol Gestion MDF (#337, esquema 1.18.0): lo da de alta con 'read' y
		// las capacidades del plugin, y estas se anaden al administrador, que
		// no pierde nada. Solo la API de roles (option wp_user_roles), igual en
		// MySQL y MariaDB; repetirlo deja lo mismo.
		if ( ! get_option( 'mdf_ca_rol_gestor_v1' ) ) {
			Roles::registrar_gestor();
			Roles::conceder_capacidades_administrador();
			update_option( 'mdf_ca_rol_gestor_v1', '1' );
		}

		// Capacidad "Catalogo y planes" (esquema 1.19.0): Planes MDF y Catalogo
		// MDF dejan de ser solo del administrador. Vuelve a registrar el rol
		// Gestion MDF con su lista completa de capacidades y la anade al
		// administrador; repetirlo deja lo mismo.
		if ( ! get_option( 'mdf_ca_cap_catalogo_v1' ) ) {
			Roles::registrar_gestor();
			Roles::conceder_capacidades_administrador();
			update_option( 'mdf_ca_cap_catalogo_v1', '1' );
		}

		// Proteccion de la carpeta privada (#327) en las instalaciones ya
		// activas: crea solo lo que falte, una vez. Sin sonda HTTP, porque
		// esto corre en 'init' y puede ser la peticion de un visitante: se
		// borra la cache y la sonda la hace la primera pantalla del plugin
		// que abra un administrador. La option se marca aunque falle (sin
		// permisos de escritura): esa pantalla lo reintenta y lo avisa.
		if ( ! get_option( 'mdf_ca_proteccion_carpeta_v1' ) ) {
			Carpeta_Privada_Proteccion::asegurar();
			Carpeta_Privada_Proteccion::invalidar_cache();
			update_option( 'mdf_ca_proteccion_carpeta_v1', '1' );
		}

		if ( ! get_option( 'mdf_ca_planes_migrados' ) ) {
			self::migrar_planes_desde_varchar();
			update_option( 'mdf_ca_planes_migrados', '1' );
		}

		if ( ! get_option( 'mdf_ca_area_privada_paginas_creadas' ) ) {
			Area_Privada_Pages::crear_paginas();
			update_option( 'mdf_ca_area_privada_paginas_creadas', '1' );
		}

		// Portada del area y paginas por tipo de documento (v0.39.0). Va
		// despues de crear_paginas(): las de tipo cuelgan de Documentacion. La
		// marca solo se pone si estan todas; si falta Documentacion, se
		// reintenta en la siguiente peticion.
		if ( ! get_option( 'mdf_ca_area_privada_paginas_adicionales_v1' )
			&& Area_Privada_Pages::crear_paginas_adicionales() ) {
			update_option( 'mdf_ca_area_privada_paginas_adicionales_v1', '1' );
		}

		// La rewrite rule de Documento_Visor (#248) se registra en cada
		// carga via 'init' igual que la de Documento_Endpoint, pero
		// WordPress cachea las reglas activas en la option "rewrite_rules"
		// hasta el proximo flush -- sin este flush una sola vez, una
		// instalacion ya activada (produccion) devolveria 404 en
		// /mdf-ca-visor/*/ hasta que alguien reguardara los enlaces
		// permanentes a mano en wp-admin. Gate por option, mismo patron que
		// el resto de maybe_upgrade(): barato de comprobar en cada peticion,
		// solo flushea de verdad la primera vez tras el despliegue.
		if ( ! get_option( 'mdf_ca_visor_rewrite_flushed' ) ) {
			flush_rewrite_rules();
			update_option( 'mdf_ca_visor_rewrite_flushed', '1' );
		}

		// Herramientas del catalogo (esquema 1.21.0): los elementos que ya
		// existen pasan a ser enlaces con categoria. Va despues de
		// create_tables() (columnas y tablas nuevas). Solo se marca si todas
		// las escrituras fueron bien; si no, se reintenta en la siguiente
		// peticion, y repetirla no cambia nada.
		if ( ! get_option( 'mdf_ca_herramientas_v1' ) && self::migrar_catalogo_a_herramientas() ) {
			update_option( 'mdf_ca_herramientas_v1', '1' );
		}

		// Rutas /mdf-ca-herramienta/{id}/(ver|fichero|descargar)/: mismo motivo
		// que el visor. Antes de wp_loaded, WordPress aplaza el flush hasta
		// wp_loaded, cuando la regla ya esta registrada en 'init'.
		if ( ! get_option( 'mdf_ca_herramientas_rewrite_v1' ) ) {
			flush_rewrite_rules();
			update_option( 'mdf_ca_herramientas_rewrite_v1', '1' );
		}

		// Documentos descargables por defecto (v0.36.0, decision de MDF): todos
		// los existentes pasan a descargables; la marca de cada uno no se toca.
		// Sin evento por documento (cambio masivo de configuracion, ver
		// CLAUDE.md). Solo se marca si el UPDATE fue bien; repetirlo no cambia
		// nada.
		if ( ! get_option( 'mdf_ca_documentos_descargables_v1' ) && ( new Documento_Repository() )->marcar_todos_descargables() ) {
			update_option( 'mdf_ca_documentos_descargables_v1', '1' );
		}

		// Ruta /mdf-ca-documento/{id}/descargar/ (v0.36.0): mismo motivo que el
		// visor; WordPress aplaza el flush hasta wp_loaded.
		if ( ! get_option( 'mdf_ca_documento_descarga_rewrite_v1' ) ) {
			flush_rewrite_rules();
			update_option( 'mdf_ca_documento_descarga_rewrite_v1', '1' );
		}

		// Estado de los presupuestos (v0.44.0, esquema 1.23.0): los que ya
		// hay pasan a pendiente. Sin evento por documento, como la migracion
		// de descargables. Repetida no cambia nada.
		if ( ! get_option( 'mdf_ca_presupuestos_estado_v1' ) && ( new Documento_Repository() )->marcar_presupuestos_sin_estado_pendientes() ) {
			update_option( 'mdf_ca_presupuestos_estado_v1', '1' );
		}
	}

	/**
	 * Herramientas (esquema 1.21.0). El catalogo pasa a ser solo
	 * Herramientas: cada elemento antiguo (los que aun no tienen categoria)
	 * pasa a ser de tipo enlace y conserva sus planes (la tabla puente no se
	 * toca):
	 * - los de la seccion Formacion, ocultos, en la categoria "Formacion
	 *   (antigua)" (decision de MDF: Formacion es ahora una pagina de
	 *   Elementor con un enlace a la Academia);
	 * - los que no tienen enlace, ocultos, en "General": no se pueden abrir y
	 *   el listado del backoffice los marca para completarlos;
	 * - el resto, visibles, en "General".
	 *
	 * Las categorias se crean por slug solo si hacen falta. Idempotente: solo
	 * toca filas con categoria_id IS NULL, y una segunda ejecucion no
	 * encuentra ninguna. Solo INSERT y UPDATE simples, igual en MySQL 8.4 y
	 * MariaDB 11.8. Devuelve false si alguna escritura falla.
	 */
	private static function migrar_catalogo_a_herramientas(): bool {
		global $wpdb;

		$catalogo = DB_Schema::get_catalogo_table_name();

		$pendientes = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$catalogo} WHERE categoria_id IS NULL" );

		if ( 0 === $pendientes ) {
			return null !== $wpdb->get_var( "SELECT COUNT(*) FROM {$catalogo}" );
		}

		$categorias = Catalogo_Termino_Repository::categorias();

		$hay_formacion = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$catalogo} WHERE categoria_id IS NULL AND seccion = %s", Catalogo_Secciones::FORMACION )
		);

		if ( $hay_formacion > 0 ) {
			$formacion = $categorias->find_by_slug( 'formacion-antigua' ) ?? $categorias->insert( 'Formación (antigua)', 'formacion-antigua' );

			if ( ! $formacion
				|| false === $wpdb->query(
					$wpdb->prepare(
						"UPDATE {$catalogo} SET categoria_id = %d, recurso = 'enlace', visible = 0 WHERE categoria_id IS NULL AND seccion = %s",
						$formacion->get_id(),
						Catalogo_Secciones::FORMACION
					)
				) ) {
				return false;
			}
		}

		$general = $categorias->find_by_slug( 'general' ) ?? $categorias->insert( 'General', 'general' );

		if ( ! $general ) {
			return false;
		}

		// Sin enlace (NULL o vacio): oculta. Despues, el resto: visible.
		$sin_enlace = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$catalogo} SET categoria_id = %d, recurso = 'enlace', visible = 0 WHERE categoria_id IS NULL AND ( enlace_url IS NULL OR enlace_url = '' )",
				$general->get_id()
			)
		);

		$con_enlace = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$catalogo} SET categoria_id = %d, recurso = 'enlace', visible = 1 WHERE categoria_id IS NULL",
				$general->get_id()
			)
		);

		return false !== $sin_enlace && false !== $con_enlace;
	}

	/**
	 * Fija, una sola vez, desde cuando se registran los eventos de
	 * publicacion: el instante (UTC, para mostrarlo) y el id mas alto de
	 * documento en ese momento. Un documento con id <= ese no tiene registro
	 * de lo ocurrido antes. Se usa el id y no fecha_subida porque esta sale
	 * del CURRENT_TIMESTAMP de MySQL, que no tiene por que estar en UTC. Va
	 * despues de create_tables(). Autoload: se lee en cada carga sin consulta.
	 */
	private static function marcar_inicio_de_eventos(): void {
		global $wpdb;

		if ( false !== get_option( 'mdf_ca_eventos_desde' ) ) {
			return;
		}

		$ultimo_id = (int) $wpdb->get_var( 'SELECT COALESCE(MAX(id), 0) FROM ' . DB_Schema::get_documentos_table_name() );

		add_option( 'mdf_ca_eventos_desde_doc_id', $ultimo_id, '', true );
		add_option( 'mdf_ca_eventos_desde', gmdate( 'Y-m-d H:i:s' ), '', true );
	}

	private static function create_tables() {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		self::migrar_registro_origen();

		foreach ( DB_Schema::get_create_table_statements() as $sql ) {
			dbDelta( $sql );
		}
	}

	/**
	 * Registro de recepcion (#334, esquema 1.15.0): columna origen dentro de
	 * la clave primaria. dbDelta anadiria la columna pero no sabe cambiar la
	 * clave primaria, asi que se hace aqui, antes de dbDelta, en un solo
	 * ALTER (atomico en MySQL y MariaDB). Idempotente: si la tabla no existe
	 * (instalacion nueva, la crea dbDelta) o ya tiene la columna, no hace
	 * nada. Lo anterior era todo del robot (retirado en #336) salvo las
	 * publicaciones y despublicaciones en bloque, que se hacen desde el
	 * backoffice.
	 */
	private static function migrar_registro_origen(): void {
		global $wpdb;

		$tabla = DB_Schema::get_recepcion_registro_table_name();

		if ( $tabla !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tabla ) ) )
			|| null !== $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM {$tabla} LIKE %s", 'origen' ) ) ) {
			return;
		}

		$ok = $wpdb->query( "ALTER TABLE {$tabla} ADD COLUMN origen VARCHAR(12) NOT NULL DEFAULT 'rest' AFTER resultado, DROP PRIMARY KEY, ADD PRIMARY KEY (fecha,resultado,origen)" );

		if ( false !== $ok ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$tabla} SET origen = %s WHERE resultado IN (%s, %s)",
					Recepcion_Registro::ORIGEN_BACKOFFICE,
					Recepcion_Registro::PUBLICADO_BLOQUE,
					Recepcion_Registro::DESPUBLICADO_LOTE
				)
			);
		}
	}

	/**
	 * Migracion de la Fase 3 #245: sustituye la columna "plan" (VARCHAR) de
	 * farmacias por "plan_id", referencia a la nueva tabla wp_mdf_ca_planes.
	 *
	 * Se apoya en create_tables() (llamado antes que esto tanto en
	 * activate() como en maybe_upgrade()) para que la tabla de planes y la
	 * columna plan_id ya existan al llegar aqui -- dbDelta() las crea/anade
	 * pero nunca elimina la columna antigua, asi que ese ultimo paso se hace
	 * aqui a mano, una vez migrados los datos que contenga.
	 *
	 * Idempotente y seguro de repetir: si la columna "plan" ya no existe
	 * (instalacion nueva que nunca la tuvo, o una ya migrada), no hace nada
	 * y devuelve enseguida. Si existe, por cada valor distinto no vacio crea
	 * el plan correspondiente en wp_mdf_ca_planes si todavia no existe (por
	 * slug), vuelca plan_id en cada farmacia a partir de ese valor, y solo
	 * entonces elimina la columna. Las farmacias con "plan" NULL o vacio no
	 * generan ningun plan ni tocan plan_id: quedan sin plan asignado, mismo
	 * estado final que ya tenian (ver Permissions, que ya trata "sin plan"
	 * como "no ve nada de catalogo").
	 */
	private static function migrar_planes_desde_varchar(): void {
		global $wpdb;

		$farmacias_table = DB_Schema::get_farmacias_table_name();
		$planes_table    = DB_Schema::get_planes_table_name();

		$columna_antigua_existe = $wpdb->get_results( "SHOW COLUMNS FROM {$farmacias_table} LIKE 'plan'" );

		if ( ! $columna_antigua_existe ) {
			return;
		}

		$valores_plan = $wpdb->get_col( "SELECT DISTINCT plan FROM {$farmacias_table} WHERE plan IS NOT NULL AND plan != ''" );

		// Resolucion del plan_id por valor de texto en PHP, no con un JOIN
		// en SQL: slug sale de sanitize_title(), que hace mucho mas que un
		// LOWER(TRIM()) (normaliza acentos, colapsa espacios y caracteres
		// especiales a guiones...), y ese algoritmo no tiene un equivalente
		// directo en SQL. Al ser pocos valores distintos por instalacion
		// (un plan por farmacia, y las farmacias piloto son unas pocas),
		// una consulta UPDATE por valor no es un problema de rendimiento.
		foreach ( $valores_plan as $valor ) {
			$slug = sanitize_title( $valor );

			if ( '' === $slug ) {
				continue;
			}

			$plan_id = $wpdb->get_var(
				$wpdb->prepare( "SELECT id FROM {$planes_table} WHERE slug = %s", $slug )
			);

			if ( ! $plan_id ) {
				$wpdb->insert(
					$planes_table,
					array(
						'nombre' => $valor,
						'slug'   => $slug,
					),
					array( '%s', '%s' )
				);

				$plan_id = $wpdb->insert_id;
			}

			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$farmacias_table} SET plan_id = %d WHERE plan = %s AND plan_id IS NULL",
					$plan_id,
					$valor
				)
			);
		}

		$wpdb->query( "ALTER TABLE {$farmacias_table} DROP COLUMN plan" );
	}
}
