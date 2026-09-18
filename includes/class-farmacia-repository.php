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
	 * Resuelve la farmacia vinculada a un usuario de WordPress. wp_user_id
	 * no tiene indice unico a proposito (ver Farmacia): hoy la relacion es
	 * 1:1 y esto devuelve como mucho una fila, pero si en el futuro un
	 * usuario pudiera vincularse a varias farmacias, este es el unico
	 * metodo a cambiar (por una lista), sin tocar a quien lo consume.
	 */
	public function find_by_wp_user_id( int $wp_user_id ): ?Farmacia {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE wp_user_id = %d", $wp_user_id )
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

	/**
	 * Fija el plan de una farmacia. Placeholder de prueba (ver
	 * Catalogo_Herramientas): en Fase 2 no hay flujo de negocio que llame a
	 * esto todavia, solo el seed de datos de prueba. Null borra el plan
	 * asignado.
	 */
	public function update_plan( int $farmacia_id, ?string $plan ): bool {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();

		$result = $wpdb->update(
			$table,
			array( 'plan' => $plan ),
			array( 'id' => $farmacia_id ),
			array( '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}
}
