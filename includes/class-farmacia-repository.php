<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico punto de acceso a $wpdb para la tabla de farmacias. Nada fuera de
 * esta clase debe construir SQL contra wp_mdf_ca_farmacias directamente.
 */
class Farmacia_Repository {

	public function find_by_cif( string $cif ): ?Farmacia {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE cif = %s", $cif )
		);

		return $row ? Farmacia::from_db_row( $row ) : null;
	}

	public function find_by_id( int $id ): ?Farmacia {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ? Farmacia::from_db_row( $row ) : null;
	}

	/**
	 * Inserta una farmacia nueva. wp_user_id es opcional a nivel de
	 * persistencia (ver Farmacia): la obligatoriedad de vincular un usuario
	 * en el alta actual la exige Farmacia_Service, no este repositorio.
	 *
	 * @return Farmacia|null Null si la insercion en BD falla.
	 */
	public function insert( string $cif, string $nombre, ?int $wp_user_id = null ): ?Farmacia {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();

		$data   = array(
			'cif'    => $cif,
			'nombre' => $nombre,
		);
		$format = array( '%s', '%s' );

		if ( null !== $wp_user_id ) {
			$data['wp_user_id'] = $wp_user_id;
			$format[]           = '%d';
		}

		$result = $wpdb->insert( $table, $data, $format );

		if ( false === $result ) {
			return null;
		}

		return $this->find_by_id( (int) $wpdb->insert_id );
	}
}
