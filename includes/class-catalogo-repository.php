<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico punto de acceso a $wpdb para la tabla de catalogo (herramientas) y
 * sus tablas puente con planes, farmacias concretas y etiquetas. Las puentes
 * viven aqui, no en Plan_Repository ni en Farmacia_Repository: pertenecen al
 * catalogo ("los planes de esta herramienta"). Las categorias y etiquetas en
 * si las lleva Catalogo_Termino_Repository.
 *
 * Las relaciones se cargan de todas las herramientas a la vez (una consulta
 * por tabla puente), no una por herramienta.
 */
class Catalogo_Repository {

	/** Columnas que escriben insert()/update(), con su formato. */
	private const COLUMNAS = array(
		'nombre'             => '%s',
		'descripcion'        => '%s',
		'descripcion_detallada' => '%s',
		'categoria_id'       => '%d',
		'recurso'            => '%s',
		'enlace_url'         => '%s',
		'formato'            => '%s',
		'ruta_fichero'       => '%s',
		'tipo_mime'          => '%s',
		'tamano'             => '%d',
		'descargable'        => '%d',
		'marca_agua'         => '%d',
		'visible'            => '%d',
		'fecha_modificacion' => '%s',
	);

	public function find_by_id( int $id ): ?Catalogo_Item {
		global $wpdb;

		$table = DB_Schema::get_catalogo_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id )
		);

		return $row ? $this->hidratar_todos( array( $row ) )[0] : null;
	}

	/**
	 * Todas las herramientas por nombre, para el backoffice.
	 *
	 * @return Catalogo_Item[]
	 */
	public function find_all(): array {
		global $wpdb;

		$table = DB_Schema::get_catalogo_table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY nombre ASC, id ASC" );

		return $this->hidratar_todos( $rows ?: array() );
	}

	/**
	 * Solo las visibles, para el area de cliente (que despues filtra con
	 * Permissions, que tambien rechaza las ocultas).
	 *
	 * @return Catalogo_Item[]
	 */
	public function find_visibles(): array {
		global $wpdb;

		$table = DB_Schema::get_catalogo_table_name();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE visible = 1 ORDER BY nombre ASC, id ASC" );

		return $this->hidratar_todos( $rows ?: array() );
	}

	/**
	 * @param array<string, mixed> $datos Columnas de COLUMNAS; null se guarda como NULL.
	 * @return int|null id nuevo
	 */
	public function insert( array $datos ): ?int {
		global $wpdb;

		$datos['seccion']        = Catalogo_Secciones::HERRAMIENTAS;
		list( $datos, $formato ) = self::preparar( $datos, array( 'seccion' => '%s' ) );

		$ok = $wpdb->insert( DB_Schema::get_catalogo_table_name(), $datos, $formato );

		return false === $ok ? null : (int) $wpdb->insert_id;
	}

	/**
	 * @param array<string, mixed> $datos
	 */
	public function update( int $id, array $datos ): bool {
		global $wpdb;

		list( $datos, $formato ) = self::preparar( $datos );

		return false !== $wpdb->update( DB_Schema::get_catalogo_table_name(), $datos, array( 'id' => $id ), $formato, array( '%d' ) );
	}

	/**
	 * Borra la herramienta y sus filas en las tablas puente. Sin FK que lo
	 * haga en cascada: lo hace aqui, dentro de la transaccion de quien llama
	 * (Catalogo_Service::eliminar()).
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		foreach ( self::tablas_puente() as $puente ) {
			if ( false === $wpdb->delete( $puente, array( 'catalogo_id' => $id ), array( '%d' ) ) ) {
				return false;
			}
		}

		return 1 === $wpdb->delete( DB_Schema::get_catalogo_table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Sustituye por completo el conjunto de planes de una herramienta
	 * (semantica de "set", como el formulario de casillas).
	 *
	 * @param int[] $plan_ids
	 */
	public function set_planes( int $catalogo_id, array $plan_ids ): bool {
		return $this->set_puente( DB_Schema::get_catalogo_planes_table_name(), 'plan_id', $catalogo_id, $plan_ids );
	}

	/**
	 * @param int[] $farmacia_ids
	 */
	public function set_farmacias( int $catalogo_id, array $farmacia_ids ): bool {
		return $this->set_puente( DB_Schema::get_catalogo_farmacias_table_name(), 'farmacia_id', $catalogo_id, $farmacia_ids );
	}

	/**
	 * @param int[] $etiqueta_ids
	 */
	public function set_etiquetas( int $catalogo_id, array $etiqueta_ids ): bool {
		return $this->set_puente( DB_Schema::get_catalogo_item_etiquetas_table_name(), 'etiqueta_id', $catalogo_id, $etiqueta_ids );
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
	 * Cuantas herramientas tienen asignado un plan concreto. Usado por
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
	 * @return string[]
	 */
	private static function tablas_puente(): array {
		return array(
			DB_Schema::get_catalogo_planes_table_name(),
			DB_Schema::get_catalogo_farmacias_table_name(),
			DB_Schema::get_catalogo_item_etiquetas_table_name(),
		);
	}

	/**
	 * @param int[] $ids
	 */
	private function set_puente( string $tabla, string $columna, int $catalogo_id, array $ids ): bool {
		global $wpdb;

		if ( false === $wpdb->delete( $tabla, array( 'catalogo_id' => $catalogo_id ), array( '%d' ) ) ) {
			return false;
		}

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			$ok = $wpdb->insert(
				$tabla,
				array(
					'catalogo_id' => $catalogo_id,
					$columna      => $id,
				),
				array( '%d', '%d' )
			);

			if ( false === $ok ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Se queda solo con las columnas conocidas y arma el array de formatos.
	 * wpdb escribe NULL cuando el valor es null.
	 *
	 * @param array<string, mixed>  $datos
	 * @param array<string, string> $extra
	 * @return array{0: array<string, mixed>, 1: string[]}
	 */
	private static function preparar( array $datos, array $extra = array() ): array {
		$columnas = self::COLUMNAS + $extra;
		$limpios  = array();
		$formato  = array();

		foreach ( $datos as $columna => $valor ) {
			if ( ! isset( $columnas[ $columna ] ) ) {
				continue;
			}

			$limpios[ $columna ] = is_bool( $valor ) ? (int) $valor : $valor;
			$formato[]           = $columnas[ $columna ];
		}

		return array( $limpios, $formato );
	}

	/**
	 * @param object[] $rows
	 * @return Catalogo_Item[]
	 */
	private function hidratar_todos( array $rows ): array {
		if ( ! $rows ) {
			return array();
		}

		global $wpdb;

		$ids          = array_map( static fn( $row ): int => (int) $row->id, $rows );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		$catalogo_planes  = DB_Schema::get_catalogo_planes_table_name();
		$planes_table     = DB_Schema::get_planes_table_name();
		$farmacias_table  = DB_Schema::get_catalogo_farmacias_table_name();
		$etiquetas_puente = DB_Schema::get_catalogo_item_etiquetas_table_name();
		$etiquetas_table  = DB_Schema::get_catalogo_etiquetas_table_name();

		$planes = self::agrupar(
			$wpdb->get_results(
				$wpdb->prepare( "SELECT cp.catalogo_id AS item, p.slug AS valor FROM {$catalogo_planes} cp INNER JOIN {$planes_table} p ON p.id = cp.plan_id WHERE cp.catalogo_id IN ({$placeholders}) ORDER BY p.slug ASC", $ids )
			)
		);

		$farmacias = self::agrupar(
			$wpdb->get_results(
				$wpdb->prepare( "SELECT catalogo_id AS item, farmacia_id AS valor FROM {$farmacias_table} WHERE catalogo_id IN ({$placeholders})", $ids )
			)
		);

		$etiquetas = self::agrupar(
			$wpdb->get_results(
				$wpdb->prepare( "SELECT ie.catalogo_id AS item, ie.etiqueta_id AS valor FROM {$etiquetas_puente} ie INNER JOIN {$etiquetas_table} e ON e.id = ie.etiqueta_id WHERE ie.catalogo_id IN ({$placeholders}) ORDER BY e.nombre ASC", $ids )
			)
		);

		return array_map(
			static function ( $row ) use ( $planes, $farmacias, $etiquetas ) {
				$id = (int) $row->id;

				return Catalogo_Item::from_db_row( $row, $planes[ $id ] ?? array(), $farmacias[ $id ] ?? array(), $etiquetas[ $id ] ?? array() );
			},
			$rows
		);
	}

	/**
	 * @param object[]|null $filas
	 * @return array<int, array<int, string>>
	 */
	private static function agrupar( ?array $filas ): array {
		$grupos = array();

		foreach ( $filas ?: array() as $fila ) {
			$grupos[ (int) $fila->item ][] = $fila->valor;
		}

		return $grupos;
	}
}
