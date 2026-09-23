<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico punto de acceso a $wpdb para la tabla de catalogo y su tabla puente
 * con planes (wp_mdf_ca_catalogo_planes). La puente vive aqui, no en
 * Plan_Repository: pertenece conceptualmente al modulo de catalogo (es "los
 * planes de este item"), mismo criterio que Farmacia_Repository::
 * count_by_plan_id() consulta su propia tabla en vez de vivir en
 * Plan_Repository.
 */
class Catalogo_Repository {

	public function find_by_id( int $id ): ?Catalogo_Item {
		global $wpdb;

		$table = DB_Schema::get_catalogo_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ? Catalogo_Item::from_db_row( $row, $this->find_plan_slugs( (int) $row->id ) ) : null;
	}

	/**
	 * Items de una seccion, orden alfabetico por nombre. Usado por
	 * Shortcode_Catalogo_Herramientas: la seccion ya viene fijada en la
	 * consulta, el shortcode no filtra nada aparte de la visibilidad por
	 * plan (que sigue pasando por Permissions).
	 *
	 * @return Catalogo_Item[]
	 */
	public function find_by_seccion( string $seccion ): array {
		global $wpdb;

		$table = DB_Schema::get_catalogo_table_name();
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE seccion = %s ORDER BY nombre ASC", $seccion )
		);

		return $this->hidratar_todos( $rows );
	}

	/**
	 * Todos los items de todas las secciones, para el listado de
	 * Admin_Catalogo.
	 *
	 * @return Catalogo_Item[]
	 */
	public function find_all(): array {
		global $wpdb;

		$table = DB_Schema::get_catalogo_table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY seccion ASC, nombre ASC" );

		return $this->hidratar_todos( $rows );
	}

	public function insert( string $seccion, string $nombre, string $tipo, ?string $enlace_url ): ?Catalogo_Item {
		global $wpdb;

		$table = DB_Schema::get_catalogo_table_name();

		$data   = array(
			'seccion' => $seccion,
			'nombre'  => $nombre,
			'tipo'    => $tipo,
		);
		$format = array( '%s', '%s', '%s' );

		if ( null !== $enlace_url ) {
			$data['enlace_url'] = $enlace_url;
			$format[]           = '%s';
		}

		$result = $wpdb->insert( $table, $data, $format );

		if ( false === $result ) {
			return null;
		}

		return $this->find_by_id( (int) $wpdb->insert_id );
	}

	public function update( int $id, string $seccion, string $nombre, string $tipo, ?string $enlace_url ): bool {
		global $wpdb;

		$table = DB_Schema::get_catalogo_table_name();

		$result = $wpdb->update(
			$table,
			array(
				'seccion'    => $seccion,
				'nombre'     => $nombre,
				'tipo'       => $tipo,
				'enlace_url' => $enlace_url,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s', '%s' ),
			array( '%d' )
		);

		return false !== $result;
	}

	/**
	 * Borra el item y sus filas en la tabla puente. Sin FK real que lo haga
	 * en cascada (ver DB_Schema), asi que el borrado de la puente se hace
	 * aqui a mano antes del borrado del item -- integridad a nivel de
	 * aplicacion, mismo criterio que el resto del plugin.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		$table         = DB_Schema::get_catalogo_table_name();
		$catalogo_planes = DB_Schema::get_catalogo_planes_table_name();

		$wpdb->delete( $catalogo_planes, array( 'catalogo_id' => $id ), array( '%d' ) );

		$result = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

		return false !== $result;
	}

	/**
	 * Sustituye por completo el conjunto de planes de un item: borra las
	 * filas puente existentes y escribe las nuevas. Semantica de "set", no
	 * de "add/remove" -- mas simple para un formulario de checkboxes donde
	 * el admin marca/desmarca planes libremente en cada guardado, sin tener
	 * que calcular el diff contra el estado anterior.
	 *
	 * @param int[] $plan_ids
	 */
	public function set_planes( int $catalogo_id, array $plan_ids ): void {
		global $wpdb;

		$catalogo_planes = DB_Schema::get_catalogo_planes_table_name();

		$wpdb->delete( $catalogo_planes, array( 'catalogo_id' => $catalogo_id ), array( '%d' ) );

		foreach ( array_unique( array_map( 'intval', $plan_ids ) ) as $plan_id ) {
			$wpdb->insert(
				$catalogo_planes,
				array(
					'catalogo_id' => $catalogo_id,
					'plan_id'     => $plan_id,
				),
				array( '%d', '%d' )
			);
		}
	}

	/**
	 * @return int[]
	 */
	public function find_plan_ids( int $catalogo_id ): array {
		global $wpdb;

		$catalogo_planes = DB_Schema::get_catalogo_planes_table_name();

		$ids = $wpdb->get_col(
			$wpdb->prepare( "SELECT plan_id FROM {$catalogo_planes} WHERE catalogo_id = %d", $catalogo_id )
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Slugs (no objetos Plan completos) de los planes de un item, via JOIN
	 * con wp_mdf_ca_planes. Es una lectura de conveniencia acotada a
	 * "resolver la lista de slugs de este item" -- no reconstruye objetos
	 * Plan, que seria responsabilidad de Plan_Repository.
	 *
	 * @return string[]
	 */
	public function find_plan_slugs( int $catalogo_id ): array {
		global $wpdb;

		$catalogo_planes = DB_Schema::get_catalogo_planes_table_name();
		$planes_table    = DB_Schema::get_planes_table_name();

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.slug FROM {$catalogo_planes} cp
				INNER JOIN {$planes_table} p ON p.id = cp.plan_id
				WHERE cp.catalogo_id = %d
				ORDER BY p.slug ASC",
				$catalogo_id
			)
		);
	}

	/**
	 * Cuantos items de catalogo tienen asignado un plan concreto. Usado por
	 * Plan_Service::eliminar() para bloquear el borrado de un plan todavia
	 * en uso, igual que Farmacia_Repository::count_by_plan_id().
	 */
	public function count_items_con_plan( int $plan_id ): int {
		global $wpdb;

		$catalogo_planes = DB_Schema::get_catalogo_planes_table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(DISTINCT catalogo_id) FROM {$catalogo_planes} WHERE plan_id = %d", $plan_id )
		);
	}

	/**
	 * @return Catalogo_Item[]
	 */
	private function hidratar_todos( array $rows ): array {
		return array_map(
			function ( $row ) {
				return Catalogo_Item::from_db_row( $row, $this->find_plan_slugs( (int) $row->id ) );
			},
			$rows
		);
	}
}
