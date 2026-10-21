<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico acceso a $wpdb para las categorias y las etiquetas del catalogo.
 * Una sola clase para las dos porque las tablas tienen la misma forma; lo
 * que cambia es como se cuenta su uso (la categoria es una columna del
 * catalogo, la etiqueta una tabla puente). Se construye con categorias() o
 * etiquetas(), nunca con un nombre de tabla que venga de fuera.
 */
class Catalogo_Termino_Repository {

	private const CATEGORIAS = 'categorias';
	private const ETIQUETAS  = 'etiquetas';

	private string $clase;

	private function __construct( string $clase ) {
		$this->clase = $clase;
	}

	public static function categorias(): self {
		return new self( self::CATEGORIAS );
	}

	public static function etiquetas(): self {
		return new self( self::ETIQUETAS );
	}

	public function es_categorias(): bool {
		return self::CATEGORIAS === $this->clase;
	}

	private function tabla(): string {
		return $this->es_categorias()
			? DB_Schema::get_catalogo_categorias_table_name()
			: DB_Schema::get_catalogo_etiquetas_table_name();
	}

	/**
	 * Por nombre, sin distinguir mayusculas ni tildes (lo hace la collation
	 * de la tabla, la misma en MySQL y MariaDB).
	 *
	 * @return Catalogo_Termino[]
	 */
	public function find_all(): array {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT id, nombre, slug FROM {$this->tabla()} ORDER BY nombre ASC, id ASC" );

		return array_map( array( Catalogo_Termino::class, 'from_db_row' ), $rows ?: array() );
	}

	public function find_by_id( int $id ): ?Catalogo_Termino {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, nombre, slug FROM {$this->tabla()} WHERE id = %d", $id ) );

		return $row ? Catalogo_Termino::from_db_row( $row ) : null;
	}

	public function find_by_slug( string $slug ): ?Catalogo_Termino {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, nombre, slug FROM {$this->tabla()} WHERE slug = %s", $slug ) );

		return $row ? Catalogo_Termino::from_db_row( $row ) : null;
	}

	/** Otro termino con este nombre (misma collation que el UNIQUE), sin contar $excluir_id. */
	public function existe_nombre( string $nombre, int $excluir_id = 0 ): bool {
		global $wpdb;

		return null !== $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$this->tabla()} WHERE nombre = %s AND id <> %d LIMIT 1", $nombre, $excluir_id )
		);
	}

	public function insert( string $nombre, string $slug ): ?Catalogo_Termino {
		global $wpdb;

		$ok = $wpdb->insert(
			$this->tabla(),
			array(
				'nombre' => $nombre,
				'slug'   => $slug,
			),
			array( '%s', '%s' )
		);

		return false === $ok ? null : $this->find_by_id( (int) $wpdb->insert_id );
	}

	/** Cambia solo el nombre: el slug no cambia nunca. */
	public function renombrar( int $id, string $nombre ): bool {
		global $wpdb;

		return false !== $wpdb->update( $this->tabla(), array( 'nombre' => $nombre ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Herramientas que usan el termino. Sin FOREIGN KEY: este recuento es lo
	 * que impide borrar una categoria en uso (Catalogo_Termino_Service).
	 */
	public function count_herramientas( int $id ): int {
		global $wpdb;

		if ( $this->es_categorias() ) {
			$catalogo = DB_Schema::get_catalogo_table_name();
			return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$catalogo} WHERE categoria_id = %d", $id ) );
		}

		$puente = DB_Schema::get_catalogo_item_etiquetas_table_name();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$puente} WHERE etiqueta_id = %d", $id ) );
	}

	/**
	 * Recuento de uso de todos los terminos de una vez, para el listado.
	 *
	 * @return array<int, int> id => herramientas
	 */
	public function count_herramientas_todos(): array {
		global $wpdb;

		if ( $this->es_categorias() ) {
			$catalogo = DB_Schema::get_catalogo_table_name();
			$rows     = $wpdb->get_results( "SELECT categoria_id AS id, COUNT(*) AS n FROM {$catalogo} WHERE categoria_id IS NOT NULL GROUP BY categoria_id" );
		} else {
			$puente = DB_Schema::get_catalogo_item_etiquetas_table_name();
			$rows   = $wpdb->get_results( "SELECT etiqueta_id AS id, COUNT(*) AS n FROM {$puente} GROUP BY etiqueta_id" );
		}

		$recuento = array();

		foreach ( $rows ?: array() as $row ) {
			$recuento[ (int) $row->id ] = (int) $row->n;
		}

		return $recuento;
	}

	/**
	 * Borra el termino. Una etiqueta se quita antes de las herramientas que
	 * la llevan, en la misma transaccion. Una categoria solo llega aqui sin
	 * uso (lo comprueba el servicio), y el DELETE lo repite en SQL para que
	 * una herramienta guardada entre medias no se quede sin categoria.
	 */
	public function delete( int $id ): bool {
		global $wpdb;

		if ( $this->es_categorias() ) {
			$catalogo = DB_Schema::get_catalogo_table_name();
			$borradas = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->tabla()} WHERE id = %d AND NOT EXISTS ( SELECT 1 FROM {$catalogo} WHERE categoria_id = %d )",
					$id,
					$id
				)
			);

			return 1 === $borradas;
		}

		$wpdb->query( 'START TRANSACTION' );

		$puente = DB_Schema::get_catalogo_item_etiquetas_table_name();

		if ( false === $wpdb->delete( $puente, array( 'etiqueta_id' => $id ), array( '%d' ) )
			|| 1 !== $wpdb->delete( $this->tabla(), array( 'id' => $id ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			return false;
		}

		return false !== $wpdb->query( 'COMMIT' );
	}

	/**
	 * Slug libre a partir del nombre: sanitize_title() y, si ya existe,
	 * -2, -3... Solo al crear.
	 */
	public function slug_libre( string $nombre ): string {
		$base = sanitize_title( $nombre );
		$base = '' !== $base ? substr( $base, 0, 90 ) : ( $this->es_categorias() ? 'categoria' : 'etiqueta' );
		$slug = $base;

		for ( $n = 2; null !== $this->find_by_slug( $slug ); $n++ ) {
			$slug = $base . '-' . $n;
		}

		return $slug;
	}
}
