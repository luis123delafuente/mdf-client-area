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

	/**
	 * Todas las farmacias, orden alfabetico por nombre. Usado por el
	 * selector de farmacia de Admin_Documentos (#246): con el volumen de
	 * farmacias piloto esperado (un puñado, no cientos) un <select> con
	 * todas ellas es mas usable que un buscador con autocompletado, que
	 * seria sobre-ingenieria para este volumen de datos.
	 *
	 * @return Farmacia[]
	 */
	public function find_all(): array {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY nombre ASC" );

		return array_map( array( 'MdfClientArea\\Farmacia', 'from_db_row' ), $rows );
	}

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
	 * Vincula una farmacia a un usuario de WordPress. Usado por
	 * Invitacion_Service (#249) cuando una farmacia sin usuario todavia
	 * (wp_user_id NULL) recibe su primera invitacion: el usuario se crea
	 * primero (sin contrasena utilizable, ver Invitacion_Service) y este
	 * metodo es quien lo vincula despues, sin tener que pasar por
	 * Farmacia_Service::crear() de nuevo.
	 */
	public function update_wp_user_id( int $farmacia_id, int $wp_user_id ): bool {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();

		$result = $wpdb->update(
			$table,
			array( 'wp_user_id' => $wp_user_id ),
			array( 'id' => $farmacia_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Fija el plan de una farmacia. Null desasigna el plan (vuelve al mismo
	 * estado "sin plan" de un alta nueva).
	 */
	public function update_plan( int $farmacia_id, ?int $plan_id ): bool {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();

		$result = $wpdb->update(
			$table,
			array( 'plan_id' => $plan_id ),
			array( 'id' => $farmacia_id ),
			array( '%d' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Cuantas farmacias tienen asignado un plan concreto. Usado por
	 * Plan_Service::eliminar() para bloquear el borrado de un plan todavia
	 * en uso (ver su cabecera): consulta contra la tabla propia de esta
	 * clase, no contra wp_mdf_ca_planes, siguiendo el mismo criterio de "un
	 * repositorio, su tabla" que el resto del plugin.
	 */
	public function count_by_plan_id( int $plan_id ): int {
		global $wpdb;

		$table = DB_Schema::get_farmacias_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE plan_id = %d", $plan_id )
		);
	}
}
