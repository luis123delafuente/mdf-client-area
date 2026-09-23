<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico punto de acceso a $wpdb para la tabla de planes. Nada fuera de esta
 * clase debe construir SQL contra wp_mdf_ca_planes directamente -- mismo
 * patron que Farmacia_Repository y Documento_Repository.
 */
class Plan_Repository {

	/**
	 * Todos los planes, orden alfabetico por nombre: es el listado que
	 * consume la pantalla de backoffice y el desplegable de asignacion de
	 * plan a farmacia, ninguno de los dos necesita orden de insercion.
	 *
	 * @return Plan[]
	 */
	public function find_all(): array {
		global $wpdb;

		$table = DB_Schema::get_planes_table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY nombre ASC" );

		return array_map( array( 'MdfClientArea\\Plan', 'from_db_row' ), $rows );
	}

	public function find_by_id( int $id ): ?Plan {
		global $wpdb;

		$table = DB_Schema::get_planes_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ? Plan::from_db_row( $row ) : null;
	}

	public function find_by_slug( string $slug ): ?Plan {
		global $wpdb;

		$table = DB_Schema::get_planes_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE slug = %s", $slug )
		);

		return $row ? Plan::from_db_row( $row ) : null;
	}

	/**
	 * Usado por Plan_Service para detectar nombres duplicados antes de
	 * escribir. La collation por defecto de $wpdb (utf8mb4_unicode_ci) ya
	 * compara sin distinguir mayusculas/acentos, asi que "Basico" y
	 * "basico" se consideran el mismo nombre sin logica adicional aqui.
	 */
	public function find_by_nombre( string $nombre ): ?Plan {
		global $wpdb;

		$table = DB_Schema::get_planes_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE nombre = %s", $nombre )
		);

		return $row ? Plan::from_db_row( $row ) : null;
	}

	public function insert( string $nombre, string $slug ): ?Plan {
		global $wpdb;

		$table  = DB_Schema::get_planes_table_name();
		$result = $wpdb->insert(
			$table,
			array(
				'nombre' => $nombre,
				'slug'   => $slug,
			),
			array( '%s', '%s' )
		);

		if ( false === $result ) {
			return null;
		}

		return $this->find_by_id( (int) $wpdb->insert_id );
	}

	/**
	 * Solo actualiza el nombre: el slug se fija en el alta y no se toca en
	 * una edicion posterior (ver Plan).
	 */
	public function update( int $id, string $nombre ): bool {
		global $wpdb;

		$table = DB_Schema::get_planes_table_name();

		$result = $wpdb->update(
			$table,
			array( 'nombre' => $nombre ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	public function delete( int $id ): bool {
		global $wpdb;

		$table  = DB_Schema::get_planes_table_name();
		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}
}
