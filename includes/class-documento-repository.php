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
		?string $tipo_documento = null
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

		$result = $wpdb->insert( $table, $data, $format );

		if ( false === $result ) {
			return null;
		}

		return $this->find_by_id( (int) $wpdb->insert_id );
	}
}
