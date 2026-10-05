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
	 * backoffice (capacidad manage_options), nunca una pantalla del front
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
		?string $fecha_documento = null
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
	 * decide visibilidad: solo elige de que avisar.
	 *
	 * @return array<int, array{id: int, farmacia_id: int, tipo_documento: ?string}>
	 */
	public function find_pendientes_aviso(): array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$rows  = $wpdb->get_results(
			"SELECT id, farmacia_id, tipo_documento FROM {$table} WHERE notificado_en IS NULL AND publicado = 1 ORDER BY farmacia_id, id",
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

	/**
	 * Pendientes de publicar, agrupables por farmacia.
	 *
	 * @return Documento[]
	 */
	public function find_pendientes_publicacion(): array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE publicado = 0 ORDER BY farmacia_id, fecha_subida, id" );

		return array_map( array( 'MdfClientArea\\Documento', 'from_db_row' ), $rows ?: array() );
	}

	/**
	 * Los ultimos publicados que llegaron por la recepcion automatica (los
	 * unicos con hash), para poder despublicar uno si se detecta un error.
	 *
	 * @return Documento[]
	 */
	public function find_publicados_recibidos( int $limite = 50 ): array {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE publicado = 1 AND hash_sha256 IS NOT NULL ORDER BY id DESC LIMIT %d", max( 1, $limite ) )
		);

		return array_map( array( 'MdfClientArea\\Documento', 'from_db_row' ), $rows ?: array() );
	}

	public function contar_pendientes_publicacion(): int {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE publicado = 0" );
	}

	/**
	 * Publica o despublica un documento. Devuelve las filas realmente
	 * cambiadas (0 si ya estaba en ese estado o no existe), o false si fallo
	 * la consulta.
	 *
	 * @return int|false
	 */
	public function set_publicado( int $id, bool $publicado ) {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET publicado = %d WHERE id = %d AND publicado = %d", $publicado ? 1 : 0, $id, $publicado ? 0 : 1 )
		);
	}

	/**
	 * Publica todos los pendientes de UNA farmacia. Devuelve cuantos publico.
	 *
	 * @return int|false
	 */
	public function publicar_todos_de_farmacia( int $farmacia_id ) {
		global $wpdb;

		$table = DB_Schema::get_documentos_table_name();

		return $wpdb->query(
			$wpdb->prepare( "UPDATE {$table} SET publicado = 1 WHERE farmacia_id = %d AND publicado = 0", $farmacia_id )
		);
	}
}
