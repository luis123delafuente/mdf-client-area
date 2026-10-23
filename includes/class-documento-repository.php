<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico punto de acceso a $wpdb para la tabla de documentos. La logica de
 * subida de fichero (#231) y el script de servido (#232) construyen sobre
 * esta clase, no directamente sobre $wpdb.
 */
class Documento_Repository {

	/** Ids por UPDATE en la publicacion en bloque (#316) y al deshacer un lote (#317). */
	private const TANDA_PUBLICACION = 500;

	/**
	 * Condicion SQL de "pendiente de publicar" (#307): no publicado y no
	 * retirado para correccion. Un Modelo 347 retirado tambien tiene
	 * publicado = 0, pero no es un pendiente: no se cuenta, no se lista y
	 * cambiar_estado() nunca lo publica. Todas las consultas de pendientes de
	 * esta clase la usan; la version con alias es para las que hacen JOIN.
	 */
	private const PENDIENTE   = 'publicado = 0 AND retirado_en IS NULL';
	private const PENDIENTE_D = 'd.publicado = 0 AND d.retirado_en IS NULL';

	private Publicacion_Evento_Repository $eventos;

	/** El repositorio de eventos es inyectable para poder probar un fallo a mitad de un cambio. */
	public function __construct( ?Publicacion_Evento_Repository $eventos = null ) {
		$this->eventos = $eventos ?? new Publicacion_Evento_Repository();
	}

	public function find_by_id( int $id ): ?Documento {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ? Documento::from_db_row( $row ) : null;
	}

	/**
	 * Documento ya guardado con este SHA-256 (solo los de la recepcion
	 * automatica llevan hash). El UNIQUE de la columna garantiza uno como
	 * maximo.
	 */
	public function find_by_hash( string $hash_sha256 ): ?Documento {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE hash_sha256 = %s", $hash_sha256 )
		);

		return $row ? Documento::from_db_row( $row ) : null;
	}

	/**
	 * Factura recibida con este emisor, serie y numero (#314), sea cual sea
	 * su farmacia. El UNIQUE de las tres columnas garantiza una como maximo.
	 */
	public function find_by_factura( string $emisor_cif, string $serie, string $numero ): ?Documento {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE emisor_cif = %s AND factura_serie = %s AND factura_numero = %s",
				$emisor_cif,
				$serie,
				$numero
			)
		);

		return $row ? Documento::from_db_row( $row ) : null;
	}

	/**
	 * Sociedades de las que cada farmacia tiene alguna factura en el portal
	 * (pendiente, publicada o despublicada), para el cruce del generador del
	 * 347: un Excel de una sociedad no debe generarse como de la otra. Las
	 * facturas sin emisor (subidas a mano antes de la v0.36.0) no cuentan:
	 * emisor_cif = %s ya deja fuera los NULL, a proposito.
	 *
	 * @param int[] $farmacia_ids
	 * @return array<int, string[]> farmacia_id => CIF de los emisores
	 */
	public function emisores_de_facturas( array $farmacia_ids ): array {
		global $wpdb;

		$farmacia_ids = array_values( array_unique( array_filter( array_map( 'intval', $farmacia_ids ) ) ) );

		if ( ! $farmacia_ids ) {
			return array();
		}

		$table        = DB_Schema::get_documentos_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $farmacia_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generados arriba.
				"SELECT DISTINCT farmacia_id, emisor_cif FROM {$table} WHERE tipo_documento = %s AND emisor_cif IS NOT NULL AND farmacia_id IN ({$placeholders})",
				array_merge( array( 'factura' ), $farmacia_ids )
			),
			ARRAY_A
		);

		$emisores = array();

		foreach ( $rows ?: array() as $row ) {
			$emisores[ (int) $row['farmacia_id'] ][] = (string) $row['emisor_cif'];
		}

		return $emisores;
	}

	/**
	 * Modelo 347 vigente de esta farmacia, emisor y ejercicio (#307). El
	 * UNIQUE modelo_347 garantiza uno como maximo; los retirados no cuentan.
	 */
	public function find_modelo_347_vigente( int $farmacia_id, string $emisor_cif, int $ejercicio ): ?Documento {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE farmacia_id = %d AND emisor_cif = %s AND ejercicio = %d AND vigente = 1",
				$farmacia_id,
				$emisor_cif,
				$ejercicio
			)
		);

		return $row ? Documento::from_db_row( $row ) : null;
	}

	/**
	 * Ids de los Modelo 347 pendientes de publicar y vigentes de este emisor y
	 * ejercicio cuya farmacia sigue existiendo (#307, "Publicar y enviar"),
	 * ascendentes. Solo ids: cambiar_estado() vuelve a comprobar el estado.
	 *
	 * @return int[]|null null si fallo la consulta.
	 */
	public function find_ids_modelo_347_publicables( string $emisor_cif, int $ejercicio ): ?array {
		global $wpdb;

		$table     = DB_Schema::get_documentos_table_name();
		$farmacias = DB_Schema::get_farmacias_table_name();
		$ids       = $wpdb->get_col(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- condicion fija de esta clase.
				"SELECT d.id FROM {$table} d INNER JOIN {$farmacias} f ON f.id = d.farmacia_id
				WHERE " . self::PENDIENTE_D . " AND d.tipo_documento = 'modelo_347' AND d.vigente = 1 AND d.emisor_cif = %s AND d.ejercicio = %d
				ORDER BY d.id",
				$emisor_cif,
				$ejercicio
			)
		);

		return '' !== $wpdb->last_error ? null : array_map( 'intval', $ids );
	}

	/**
	 * Modelo 347 vigentes (publicados o pendientes) de este emisor y
	 * ejercicio, para la pantalla de envios del generador (#307). Solo
	 * backoffice: no decide visibilidad.
	 *
	 * @return Documento[]
	 */
	public function find_modelo_347_vigentes( string $emisor_cif, int $ejercicio, int $limite = 1000 ): array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE tipo_documento = 'modelo_347' AND vigente = 1 AND emisor_cif = %s AND ejercicio = %d ORDER BY id LIMIT %d",
				$emisor_cif,
				$ejercicio,
				max( 1, $limite )
			)
		);

		return array_map( array( 'MdfClientArea\\Documento', 'from_db_row' ), $rows ?: array() );
	}

	/**
	 * Documentos de una farmacia, mas recientes primero. Usado por el
	 * listado de front (#241): la consulta ya viene acotada a la farmacia,
	 * asi que quien la consume no necesita repetir la comprobacion de
	 * visibilidad con Permissions (esa sigue siendo la unica puerta para el
	 * endpoint de descarga, ver Documento_Endpoint).
	 *
	 * @return Documento[]
	 */
	public function find_by_farmacia_id( int $farmacia_id ): array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE farmacia_id = %d ORDER BY fecha_subida DESC", $farmacia_id )
		);

		return array_map( array( 'MdfClientArea\\Documento', 'from_db_row' ), $rows );
	}

	/**
	 * Todos los documentos, mas recientes primero. Usado unicamente por el
	 * listado de Admin_Documentos (#246): no acota por farmacia -- a
	 * diferencia de find_by_farmacia_id(), quien la consume es siempre el
	 * backoffice (Admin_Documentos::CAPACIDAD), nunca una pantalla del front
	 * donde haria falta pasar antes por Permissions.
	 *
	 * @return Documento[]
	 */
	public function find_all(): array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY fecha_subida DESC" );

		return array_map( array( 'MdfClientArea\\Documento', 'from_db_row' ), $rows );
	}

	/**
	 * Insercion de metadatos. El fichero en si ya esta escrito en disco
	 * cuando se llama aqui (responsabilidad de Documento_Service, #246):
	 * este metodo no sabe nada de subida ni de validacion de fichero.
	 *
	 * @param array{emisor_cif: string, serie: string, numero: string}|null $factura
	 *        Solo la recepcion automatica (#314): los tres valores, ya
	 *        revalidados, o null (backoffice: las tres columnas en NULL).
	 * @param array{emisor_cif: string, ejercicio: int}|null $modelo_347
	 *        Solo los Modelo 347 (#307): emisor y ejercicio ya validados por
	 *        Documento_Service. Nace vigente (vigente = 1): el UNIQUE
	 *        modelo_347 rechaza un segundo vigente de la misma combinacion.
	 */
	public function insert(
		int $farmacia_id,
		string $nombre,
		string $ruta_fichero,
		?string $tipo_mime = null,
		?int $tamano_bytes = null,
		?string $tipo_documento = null,
		bool $descargable = false,
		bool $ya_notificado = false,
		?string $hash_sha256 = null,
		bool $publicado = true,
		?string $fecha_documento = null,
		?array $factura = null,
		?array $modelo_347 = null,
		bool $sin_marca = false
	): ?Documento {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		$data   = array(
			'farmacia_id'  => $farmacia_id,
			'nombre'       => $nombre,
			'ruta_fichero' => $ruta_fichero,
		);
		$format = array( '%d', '%s', '%s' );

		if ( null !== $tipo_mime ) {
			$data['tipo_mime'] = $tipo_mime;
			$format[]          = '%s';
		}

		if ( null !== $tamano_bytes ) {
			$data['tamano_bytes'] = $tamano_bytes;
			$format[]             = '%d';
		}

		if ( null !== $tipo_documento ) {
			$data['tipo_documento'] = $tipo_documento;
			$format[]               = '%s';
		}

		$data['descargable'] = $descargable ? 1 : 0;
		$format[]            = '%d';

		$data['sin_marca'] = $sin_marca ? 1 : 0;
		$format[]          = '%d';

		// Subida que no debe generar aviso (p. ej. ingesta del historico):
		// nace ya marcada, y Aviso_Documentos_Service nunca la ve.
		if ( $ya_notificado ) {
			$data['notificado_en'] = current_time( 'mysql', true );
			$format[]              = '%s';
		}

		if ( null !== $hash_sha256 ) {
			$data['hash_sha256'] = $hash_sha256;
			$format[]            = '%s';
		}

		if ( null !== $fecha_documento ) {
			$data['fecha_documento'] = $fecha_documento;
			$format[]                = '%s';
		}

		if ( null !== $factura ) {
			$data['emisor_cif']     = $factura['emisor_cif'];
			$data['factura_serie']  = $factura['serie'];
			$data['factura_numero'] = $factura['numero'];
			array_push( $format, '%s', '%s', '%s' );
		}

		if ( null !== $modelo_347 ) {
			$data['emisor_cif'] = $modelo_347['emisor_cif'];
			$data['ejercicio']  = $modelo_347['ejercicio'];
			$data['vigente']    = 1;
			array_push( $format, '%s', '%d', '%d' );
		}

		// Solo la recepcion automatica puede crear documentos pendientes de
		// publicar; la subida de backoffice no pasa este parametro.
		$data['publicado'] = $publicado ? 1 : 0;
		$format[]          = '%d';

		$result = $wpdb->insert( $table, $data, $format );

		if ( false === $result ) {
			return null;
		}

		return $this->find_by_id( (int) $wpdb->insert_id );
	}

	/**
	 * Documentos aun no incluidos en ningun aviso por email, solo con lo
	 * que el aviso necesita (nunca nombre ni fichero). Los pendientes de
	 * publicar quedan fuera (no se avisa de algo invisible) y siguen con
	 * notificado_en NULL: al publicarse entran en el siguiente aviso. Es la
	 * unica consulta, ademas de Permissions, que mira "publicado", y no
	 * decide visibilidad: solo elige de que avisar. El Modelo 347 nunca entra
	 * (#307): tiene su propio correo con la carta adjunta (Modelo_347_Envio_
	 * Service) y nadie debe recibir dos; tampoco los subidos a mano.
	 *
	 * @return array<int, array{id: int, farmacia_id: int, tipo_documento: ?string}>
	 */
	public function find_pendientes_aviso(): array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$rows  = $wpdb->get_results(
			"SELECT id, farmacia_id, tipo_documento FROM {$table} WHERE notificado_en IS NULL AND publicado = 1 AND ( tipo_documento IS NULL OR tipo_documento <> 'modelo_347' ) ORDER BY farmacia_id, id",
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'id'             => (int) $row['id'],
				'farmacia_id'    => (int) $row['farmacia_id'],
				'tipo_documento' => $row['tipo_documento'],
			),
			$rows ?: array()
		);
	}

	/**
	 * Marca como notificados exactamente estos ids. "AND notificado_en IS
	 * NULL": nunca reescribe la fecha de uno ya marcado.
	 *
	 * @param int[] $ids
	 * @return int|false Filas marcadas, o false si fallo la consulta.
	 */
	public function marcar_notificados( array $ids ) {
		global $wpdb;

		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );

		if ( ! $ids ) {
			return 0;
		}

		$table        = DB_Schema::get_documentos_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		return $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generados arriba.
				"UPDATE {$table} SET notificado_en = %s WHERE notificado_en IS NULL AND id IN ({$placeholders})",
				array_merge( array( current_time( 'mysql', true ) ), $ids )
			)
		);
	}

	/**
	 * Migracion de despliegue (Activator::maybe_upgrade()): todo documento
	 * existente cuenta como ya notificado, con su propia fecha de subida,
	 * para que activar los avisos no genere un aviso por lo ya subido.
	 */
	public function marcar_todos_notificados(): bool {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return false !== $wpdb->query( "UPDATE {$table} SET notificado_en = fecha_subida WHERE notificado_en IS NULL" );
	}

	// ------------------------------------------------------------------
	// Publicacion (#304): solo para el backoffice. Ninguna decide visibilidad.
	// ------------------------------------------------------------------

	public function contar_pendientes_publicacion(): int {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE " . self::PENDIENTE );
	}

	/**
	 * Pendientes de publicar candidatos a la publicacion en bloque (#316),
	 * solo con lo que hace falta para contar y publicar (nunca nombre ni
	 * fichero). "existe" = su farmacia sigue en la tabla de farmacias.
	 *
	 * @param string|null $tipo  Clave de Documento_Tipos, o null para todos (tambien sin tipo).
	 * @param string|null $desde 'Y-m-d', fecha de recepcion (fecha_subida) inclusive.
	 * @param string|null $hasta 'Y-m-d', inclusive.
	 * @return array<int, array{id: int, farmacia_id: int, existe: bool, fecha_subida: string}>|null
	 *         null si fallo la consulta.
	 */
	public function find_pendientes_para_bloque( ?string $tipo, ?string $desde, ?string $hasta ): ?array {
		global $wpdb;

		$table     = DB_Schema::get_documentos_table_name();
		$farmacias = DB_Schema::get_farmacias_table_name();
		$where     = array( self::PENDIENTE_D );
		$args      = array();

		if ( null !== $tipo ) {
			$where[] = 'd.tipo_documento = %s';
			$args[]  = $tipo;
		}

		if ( null !== $desde ) {
			$where[] = 'd.fecha_subida >= %s';
			$args[]  = $desde . ' 00:00:00';
		}

		if ( null !== $hasta ) {
			// Hasta inclusive: todo lo anterior al dia siguiente.
			$where[] = 'd.fecha_subida < %s';
			$args[]  = gmdate( 'Y-m-d', strtotime( $hasta . ' 00:00:00 UTC' ) + DAY_IN_SECONDS ) . ' 00:00:00';
		}

		$sql = "SELECT d.id, d.farmacia_id, d.fecha_subida, f.id IS NOT NULL AS existe
			FROM {$table} d LEFT JOIN {$farmacias} f ON f.id = d.farmacia_id
			WHERE " . implode( ' AND ', $where ) . ' ORDER BY d.id';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condiciones fijas de arriba, valores por placeholders.
		$rows = $wpdb->get_results( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );

		if ( null === $rows || '' !== $wpdb->last_error ) {
			return null;
		}

		return array_map(
			static fn( array $row ): array => array(
				'id'           => (int) $row['id'],
				'farmacia_id'  => (int) $row['farmacia_id'],
				'existe'       => (bool) (int) $row['existe'],
				'fecha_subida' => (string) $row['fecha_subida'],
			),
			$rows
		);
	}

	/**
	 * Punto UNICO que cambia publicado (#318): ningun otro UPDATE del plugin
	 * toca esa columna. Cambia EXACTAMENTE estas filas, solo si siguen en el
	 * estado de origen, y deja su evento en la misma transaccion. La
	 * transaccion la abre quien llama (el lote, o Documento_Publicacion_
	 * Service): si algo falla devuelve false y quien llama hace ROLLBACK, asi
	 * que no queda ni un cambio de estado sin evento ni un evento sin cambio.
	 *
	 * Por tandas: SELECT ... FOR UPDATE bloquea y devuelve las filas que de
	 * verdad cambian (las que otra via ya cambio no cuentan ni dejan evento),
	 * UPDATE de esas filas y un INSERT multifila de sus eventos.
	 *
	 * Reglas de publicacion_lote_id (#317): publicar asigna el lote nuevo (o
	 * NULL fuera de un lote); despublicar, por cualquier via, lo pone a NULL.
	 * El evento guarda el lote implicado: el que publica, o el que habia
	 * publicado el documento al despublicarlo.
	 *
	 * @param int[]    $ids
	 * @param bool     $publicar       true: pendiente -> publicado. false: publicado -> pendiente.
	 * @param int|null $lote_id_nuevo  Al publicar, el lote que lo publica (null: sin lote).
	 * @param int|null $lote_id_filtro Al despublicar, solo los publicados por este lote (deshacer).
	 * @return int|false Filas cambiadas, o false si fallo algo (hay que revertir).
	 */
	public function cambiar_estado( array $ids, bool $publicar, string $origen, ?int $usuario_id, ?int $lote_id_nuevo = null, ?int $lote_id_filtro = null ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ), static fn( int $id ): bool => $id > 0 ) ) );

		$table  = DB_Schema::get_documentos_table_name();
		$accion = $publicar ? Publicacion_Evento_Repository::ACCION_PUBLICADO : Publicacion_Evento_Repository::ACCION_DESPUBLICADO;
		$desde  = $publicar ? 0 : 1;
		$total  = 0;

		foreach ( array_chunk( $ids, self::TANDA_PUBLICACION ) as $tanda ) {
			$placeholders = implode( ',', array_fill( 0, count( $tanda ), '%d' ) );
			$filtro_lote  = ! $publicar && null !== $lote_id_filtro ? ' AND publicacion_lote_id = %d' : '';
			// Un 347 retirado (#307) nunca se vuelve a publicar, por ninguna via.
			$sin_retirados = $publicar ? ' AND retirado_en IS NULL' : '';

			$bloqueadas = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generados arriba; condiciones fijas.
					"SELECT id, publicacion_lote_id FROM {$table} WHERE publicado = %d AND id IN ({$placeholders}){$filtro_lote}{$sin_retirados} ORDER BY id FOR UPDATE",
					array_merge( array( $desde ), $tanda, '' !== $filtro_lote ? array( $lote_id_filtro ) : array() )
				),
				ARRAY_A
			);

			if ( null === $bloqueadas || '' !== $wpdb->last_error ) {
				return false;
			}

			if ( ! $bloqueadas ) {
				continue;
			}

			$cambian = array_map( static fn( array $fila ): int => (int) $fila['id'], $bloqueadas );
			$ph2     = implode( ',', array_fill( 0, count( $cambian ), '%d' ) );
			$set     = $publicar
				? ( null === $lote_id_nuevo ? 'publicado = 1, publicacion_lote_id = NULL' : 'publicado = 1, publicacion_lote_id = %d' )
				: 'publicado = 0, publicacion_lote_id = NULL';

			$filas = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $set fijo de esta clase; placeholders generados arriba.
					"UPDATE {$table} SET {$set} WHERE publicado = %d AND id IN ({$ph2})",
					array_merge( $publicar && null !== $lote_id_nuevo ? array( $lote_id_nuevo ) : array(), array( $desde ), $cambian )
				)
			);

			// Bloqueadas por FOR UPDATE: tienen que cambiar todas.
			if ( false === $filas || (int) $filas !== count( $cambian ) ) {
				return false;
			}

			$eventos = array();

			foreach ( $bloqueadas as $fila ) {
				$previo                        = null === $fila['publicacion_lote_id'] ? null : (int) $fila['publicacion_lote_id'];
				$eventos[ (int) $fila['id'] ] = $publicar ? $lote_id_nuevo : $previo;
			}

			if ( false === $this->eventos->insertar_para( $eventos, $accion, $origen, $usuario_id ) ) {
				return false;
			}

			$total += count( $cambian );
		}

		return $total;
	}

	/**
	 * Retira para correccion un Modelo 347 vigente (#307): libera su hueco en
	 * el UNIQUE modelo_347 (vigente = NULL) y lo marca retirado. Solo si sigue
	 * vigente y ya NO esta publicado: quien llama lo despublica antes con
	 * cambiar_estado(), en la misma transaccion. No toca publicado.
	 *
	 * @return bool true si ha retirado exactamente esa fila.
	 */
	public function marcar_retirado( int $documento_id ): bool {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$filas = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET vigente = NULL, retirado_en = %s WHERE id = %d AND tipo_documento = 'modelo_347' AND vigente = 1 AND retirado_en IS NULL AND publicado = 0",
				gmdate( 'Y-m-d H:i:s' ),
				$documento_id
			)
		);

		return 1 === $filas;
	}

	/**
	 * Fila de un documento bloqueada (SELECT ... FOR UPDATE) hasta el final de
	 * la transaccion de quien llama.
	 */
	/**
	 * Borra la fila de un documento NO publicado y que no es un Modelo 347
	 * (v0.28.0). Las dos condiciones van tambien en el DELETE: aunque quien llama
	 * ya lo ha comprobado con la fila bloqueada, nunca se borra un publicado.
	 *
	 * @return int|false Filas borradas (0 o 1), o false si falla.
	 */
	public function eliminar_no_publicado( int $id ) {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return $wpdb->query(
			// tipo_documento es NULL en los documentos anteriores a la Fase 3 ("Sin
			// tipo"): en SQL "NULL <> 'modelo_347'" no es verdadero, y sin el
			// IS NULL el DELETE no borraba nada (v0.32.1).
			$wpdb->prepare( "DELETE FROM {$table} WHERE id = %d AND publicado = 0 AND ( tipo_documento IS NULL OR tipo_documento <> %s )", $id, 'modelo_347' )
		);
	}

	/**
	 * Pone o quita la marca de agua (#321). Solo cambia si el valor es otro:
	 * devuelve las filas cambiadas (0 si ya estaba asi) o false si falla.
	 *
	 * @return int|false
	 */
	public function cambiar_marca( int $id, bool $sin_marca ) {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET sin_marca = %d WHERE id = %d AND sin_marca <> %d", $sin_marca ? 1 : 0, $id, $sin_marca ? 1 : 0 )
		);
	}

	/**
	 * Descargable o no (v0.36.0). Mismo patron que cambiar_marca(): solo
	 * escribe si cambia, y devuelve las filas afectadas (1 o 0) o false.
	 *
	 * @return int|false
	 */
	public function cambiar_descargable( int $id, bool $descargable ) {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET descargable = %d WHERE id = %d AND descargable <> %d", $descargable ? 1 : 0, $id, $descargable ? 1 : 0 )
		);
	}

	/**
	 * Todos los documentos pasan a descargables (migracion de la v0.36.0,
	 * decision de MDF). No toca sin_marca. true si el UPDATE fue bien.
	 */
	public function marcar_todos_descargables(): bool {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return false !== $wpdb->query( "UPDATE {$table} SET descargable = 1 WHERE descargable = 0" );
	}

	public function find_by_id_para_actualizar( int $id ): ?Documento {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d FOR UPDATE", $id )
		);

		return $row ? Documento::from_db_row( $row ) : null;
	}

	/**
	 * Publica EXACTAMENTE estos ids en un lote (#316, #317). Transaccion de
	 * quien llama (ver cambiar_estado()).
	 *
	 * @param int[] $ids
	 * @return int|false
	 */
	public function publicar_ids( array $ids, int $lote_id, ?int $usuario_id ) {
		return $this->cambiar_estado( $ids, true, Publicacion_Evento_Repository::ORIGEN_BLOQUE, $usuario_id, $lote_id );
	}

	/**
	 * Despublica EXACTAMENTE estos ids del lote (#317), solo si siguen
	 * publicados por ese lote. Transaccion de quien llama.
	 *
	 * @param int[] $ids
	 * @return int|false
	 */
	public function despublicar_ids_de_lote( array $ids, int $lote_id, ?int $usuario_id ) {
		return $this->cambiar_estado( $ids, false, Publicacion_Evento_Repository::ORIGEN_DESHACER_LOTE, $usuario_id, null, $lote_id );
	}

	/**
	 * Ids de los pendientes de UNA farmacia (para publicar los de la
	 * farmacia). Solo ids: cambiar_estado() vuelve a comprobar el estado.
	 *
	 * @return int[]|null null si fallo la consulta.
	 */
	public function find_ids_pendientes_de_farmacia( int $farmacia_id ): ?array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE farmacia_id = %d AND " . self::PENDIENTE . ' ORDER BY id', $farmacia_id ) );

		return '' !== $wpdb->last_error ? null : array_map( 'intval', $ids );
	}

	/**
	 * Documentos que siguen publicados por este lote (solo id y farmacia).
	 *
	 * @return array<int, array{id: int, farmacia_id: int}>|null null si fallo la consulta.
	 */
	public function find_publicados_de_lote( int $lote_id ): ?array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, farmacia_id FROM {$table} WHERE publicacion_lote_id = %d AND publicado = 1 ORDER BY id", $lote_id ),
			ARRAY_A
		);

		if ( null === $rows || '' !== $wpdb->last_error ) {
			return null;
		}

		return array_map(
			static fn( array $row ): array => array(
				'id'          => (int) $row['id'],
				'farmacia_id' => (int) $row['farmacia_id'],
			),
			$rows
		);
	}

	/**
	 * Cuantos documentos siguen publicados por cada lote, en una consulta.
	 *
	 * @param int[] $lote_ids
	 * @return array<int, int> lote_id => publicados (los lotes sin ninguno no aparecen).
	 */
	public function contar_publicados_por_lote( array $lote_ids ): array {
		global $wpdb;

		$lote_ids = array_values( array_filter( array_map( 'intval', $lote_ids ) ) );

		if ( ! $lote_ids ) {
			return array();
		}

		$table        = DB_Schema::get_documentos_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $lote_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generados arriba.
				"SELECT publicacion_lote_id AS lote, COUNT(*) AS total FROM {$table} WHERE publicado = 1 AND publicacion_lote_id IN ({$placeholders}) GROUP BY publicacion_lote_id",
				$lote_ids
			),
			ARRAY_A
		);

		$recuentos = array();

		foreach ( $rows ?: array() as $row ) {
			$recuentos[ (int) $row['lote'] ] = (int) $row['total'];
		}

		return $recuentos;
	}

	// ------------------------------------------------------------------
	// Listas paginadas del backoffice: documentos publicados (#318) y
	// pendientes de publicar (#319). Todo en SQL, sin cargar la tabla.
	// ------------------------------------------------------------------

	/**
	 * Condiciones y argumentos de las listas paginadas. $nombre se busca como
	 * texto literal (esc_like): % y _ no son comodines.
	 *
	 * @return array{0: string, 1: array<int, int|string>}
	 */
	private function condiciones_busqueda( bool $publicado, ?int $farmacia_id, string $nombre ): array {
		global $wpdb;

		$where = array( $publicado ? 'publicado = 1' : self::PENDIENTE );
		$args  = array();

		if ( null !== $farmacia_id && $farmacia_id > 0 ) {
			$where[] = 'farmacia_id = %d';
			$args[]  = $farmacia_id;
		}

		if ( '' !== $nombre ) {
			$where[] = 'nombre LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $nombre ) . '%';
		}

		return array( implode( ' AND ', $where ), $args );
	}

	public function contar_publicados( ?int $farmacia_id, string $nombre ): int {
		global $wpdb;

		$table                = DB_Schema::get_documentos_table_name();
		list( $where, $args ) = $this->condiciones_busqueda( true, $farmacia_id, $nombre );
		$sql                  = "SELECT COUNT(*) FROM {$table} WHERE {$where}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condiciones fijas, valores por placeholders.
		return (int) $wpdb->get_var( $args ? $wpdb->prepare( $sql, $args ) : $sql );
	}

	/**
	 * Una pagina de publicados, los mas recientes primero.
	 *
	 * @return Documento[]
	 */
	public function buscar_publicados( ?int $farmacia_id, string $nombre, int $limite, int $desplazamiento ): array {
		global $wpdb;

		$table                = DB_Schema::get_documentos_table_name();
		list( $where, $args ) = $this->condiciones_busqueda( true, $farmacia_id, $nombre );
		$args[]               = max( 1, $limite );
		$args[]               = max( 0, $desplazamiento );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- condiciones fijas, valores por placeholders.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $args )
		);

		return array_map( array( 'MdfClientArea\\Documento', 'from_db_row' ), $rows ?: array() );
	}

	/**
	 * Cuantos pendientes y de cuantas farmacias, con los filtros dados (sin
	 * filtros: todos). Es el recuento de arriba de la pantalla y el que
	 * decide el numero de paginas.
	 *
	 * @return array{documentos: int, farmacias: int}
	 */
	public function totales_pendientes( ?int $farmacia_id = null, string $nombre = '' ): array {
		global $wpdb;

		$table                = DB_Schema::get_documentos_table_name();
		list( $where, $args ) = $this->condiciones_busqueda( false, $farmacia_id, $nombre );
		$sql                  = "SELECT COUNT(*) AS documentos, COUNT(DISTINCT farmacia_id) AS farmacias FROM {$table} WHERE {$where}";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- condiciones fijas, valores por placeholders.
		$fila = $wpdb->get_row( $args ? $wpdb->prepare( $sql, $args ) : $sql, ARRAY_A );

		return array(
			'documentos' => (int) ( $fila['documentos'] ?? 0 ),
			'farmacias'  => (int) ( $fila['farmacias'] ?? 0 ),
		);
	}

	/**
	 * Una pagina de FARMACIAS con pendientes (#319), en orden estable: la que
	 * lleva mas tiempo esperando primero (su pendiente mas antiguo, MIN(id)),
	 * y a igualdad por id de farmacia. Un documento nuevo nunca adelanta a
	 * nadie: va al final de su farmacia, o crea una farmacia al final.
	 * "coinciden" son los pendientes de la farmacia que cumplen los filtros.
	 *
	 * @return array<int, array{farmacia_id: int, coinciden: int}>
	 */
	public function grupos_pendientes( ?int $farmacia_id, string $nombre, int $limite, int $desplazamiento ): array {
		global $wpdb;

		$table                = DB_Schema::get_documentos_table_name();
		list( $where, $args ) = $this->condiciones_busqueda( false, $farmacia_id, $nombre );
		$args[]               = max( 1, $limite );
		$args[]               = max( 0, $desplazamiento );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- condiciones fijas, valores por placeholders.
				"SELECT farmacia_id, MIN(id) AS primero, COUNT(*) AS coinciden FROM {$table} WHERE {$where} GROUP BY farmacia_id ORDER BY primero, farmacia_id LIMIT %d OFFSET %d",
				$args
			),
			ARRAY_A
		);

		return array_map(
			static fn( array $row ): array => array(
				'farmacia_id' => (int) $row['farmacia_id'],
				'coinciden'   => (int) $row['coinciden'],
			),
			$rows ?: array()
		);
	}

	/**
	 * Todos los pendientes de cada una de estas farmacias, sin filtros: el
	 * contador de su cabecera, el mismo en cualquier pagina, y lo que publica
	 * "Publicar todos los de esta farmacia".
	 *
	 * @param int[] $farmacia_ids
	 * @return array<int, int> farmacia_id => pendientes
	 */
	public function contar_pendientes_por_farmacia( array $farmacia_ids ): array {
		global $wpdb;

		$farmacia_ids = array_values( array_filter( array_map( 'intval', $farmacia_ids ) ) );

		if ( ! $farmacia_ids ) {
			return array();
		}

		$table        = DB_Schema::get_documentos_table_name();
		$placeholders = implode( ',', array_fill( 0, count( $farmacia_ids ), '%d' ) );
		$rows         = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generados arriba.
				"SELECT farmacia_id, COUNT(*) AS total FROM {$table} WHERE " . self::PENDIENTE . " AND farmacia_id IN ({$placeholders}) GROUP BY farmacia_id",
				$farmacia_ids
			),
			ARRAY_A
		);

		$recuentos = array();

		foreach ( $rows ?: array() as $row ) {
			$recuentos[ (int) $row['farmacia_id'] ] = (int) $row['total'];
		}

		return $recuentos;
	}

	/**
	 * Pendientes de UNA farmacia que cumplen el filtro de nombre, por id
	 * ascendente (orden estable: los nuevos van al final).
	 *
	 * @return Documento[]
	 */
	public function pendientes_de_farmacia( int $farmacia_id, string $nombre, int $limite, int $desplazamiento ): array {
		global $wpdb;

		$table                = DB_Schema::get_documentos_table_name();
		list( $where, $args ) = $this->condiciones_busqueda( false, $farmacia_id, $nombre );
		$args[]               = max( 1, $limite );
		$args[]               = max( 0, $desplazamiento );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- condiciones fijas, valores por placeholders.
			$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT %d OFFSET %d", $args )
		);

		return array_map( array( 'MdfClientArea\\Documento', 'from_db_row' ), $rows ?: array() );
	}
}
