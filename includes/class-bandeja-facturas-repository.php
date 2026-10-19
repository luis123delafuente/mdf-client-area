<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico acceso a las tablas de la bandeja de facturas no reconocidas (#335):
 * wp_mdf_ca_bandeja_facturas (una fila por factura) y
 * wp_mdf_ca_bandeja_eventos (libro de solo anadir: ningun UPDATE ni DELETE
 * sobre ella). Filas como arrays con los nombres de columna.
 */
class Bandeja_Facturas_Repository {

	public const EVENTO_ENTRADA       = 'entrada';
	public const EVENTO_ASIGNADA      = 'asignada';
	public const EVENTO_ASIGNADA_AUTO = 'asignada_auto';
	public const EVENTO_YA_EXISTIA    = 'ya_existia';
	public const EVENTO_DESCARTADA    = 'descartada';
	public const EVENTO_CADUCADA      = 'caducada';

	/**
	 * @param array{ruta_fichero: string, nombre_original: string, tamano_bytes: int, hash_sha256: string, motivo: string, emisor_cif: ?string, serie: ?string, numero: ?string, fecha_documento: ?string, cif_leido: ?string, es_abono: ?bool, usuario_id: int} $datos
	 * @return int|null Id, o null si falla (p. ej. el UNIQUE de hash o de factura).
	 */
	public function insert( array $datos ): ?int {
		global $wpdb;

		// Un INSERT que pierde la carrera choca con un UNIQUE y $wpdb escribiria
		// en el log la serie y el numero: se silencia.
		$errores = $wpdb->suppress_errors( true );
		$ok      = $wpdb->insert(
			DB_Schema::get_bandeja_facturas_table_name(),
			array(
				'ruta_fichero'    => $datos['ruta_fichero'],
				'nombre_original' => $datos['nombre_original'],
				'tamano_bytes'    => $datos['tamano_bytes'],
				'hash_sha256'     => $datos['hash_sha256'],
				'motivo'          => $datos['motivo'],
				'emisor_cif'      => $datos['emisor_cif'],
				'serie'           => $datos['serie'],
				'numero'          => $datos['numero'],
				'fecha_documento' => $datos['fecha_documento'],
				'cif_leido'       => $datos['cif_leido'],
				'es_abono'        => null === $datos['es_abono'] ? null : (int) $datos['es_abono'],
				'usuario_id'      => $datos['usuario_id'] > 0 ? $datos['usuario_id'] : null,
				'subido_en'       => gmdate( 'Y-m-d H:i:s' ),
			)
		);
		$wpdb->suppress_errors( $errores );

		return $ok ? (int) $wpdb->insert_id : null;
	}

	public function find_by_id( int $id, bool $para_actualizar = false ): ?array {
		global $wpdb;

		$table = DB_Schema::get_bandeja_facturas_table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d" . ( $para_actualizar ? ' FOR UPDATE' : '' ), $id ), ARRAY_A );

		return $row ?: null;
	}

	/** Ya hay en la bandeja el mismo PDF (hash) o la misma factura (emisor, serie y numero, si se leyeron). */
	public function find_existente( string $hash, ?string $emisor, ?string $serie, ?string $numero ): ?array {
		global $wpdb;

		$table = DB_Schema::get_bandeja_facturas_table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE hash_sha256 = %s", $hash ), ARRAY_A );

		if ( ! $row && null !== $emisor && null !== $serie && null !== $numero ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE emisor_cif = %s AND serie = %s AND numero = %s", $emisor, $serie, $numero ), ARRAY_A );
		}

		return $row ?: null;
	}

	/** @return array<int, array<string, mixed>> De la mas antigua a la mas reciente. */
	public function listar( int $limite = 200, int $offset = 0 ): array {
		global $wpdb;

		$table = DB_Schema::get_bandeja_facturas_table_name();

		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY subido_en ASC, id ASC LIMIT %d OFFSET %d", max( 1, $limite ), max( 0, $offset ) ), ARRAY_A ) ?: array();
	}

	public function contar(): int {
		global $wpdb;

		$table = DB_Schema::get_bandeja_facturas_table_name();

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Las subidas antes de $fecha_utc (Y-m-d H:i:s). */
	public function contar_anteriores_a( string $fecha_utc ): int {
		global $wpdb;

		$table = DB_Schema::get_bandeja_facturas_table_name();

		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE subido_en < %s", $fecha_utc ) );
	}

	/** @return int[] Ids subidos antes de $fecha_utc, los mas antiguos primero. */
	public function ids_anteriores_a( string $fecha_utc, int $limite ): array {
		global $wpdb;

		$table = DB_Schema::get_bandeja_facturas_table_name();

		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE subido_en < %s ORDER BY subido_en ASC LIMIT %d", $fecha_utc, max( 1, $limite ) ) ) );
	}

	/** @return int[] Todos los ids, los mas antiguos primero. */
	public function ids(): array {
		global $wpdb;

		$table = DB_Schema::get_bandeja_facturas_table_name();

		return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$table} ORDER BY subido_en ASC, id ASC" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return int|false Filas borradas. */
	public function borrar( int $id ) {
		global $wpdb;

		return $wpdb->delete( DB_Schema::get_bandeja_facturas_table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	/** Evento de la bandeja: solo ids, accion y fecha (UTC). */
	public function registrar_evento( int $bandeja_id, string $accion, ?int $usuario_id, ?int $documento_id = null ): bool {
		global $wpdb;

		return false !== $wpdb->insert(
			DB_Schema::get_bandeja_eventos_table_name(),
			array(
				'bandeja_id'   => $bandeja_id,
				'accion'       => $accion,
				'usuario_id'   => null !== $usuario_id && $usuario_id > 0 ? $usuario_id : null,
				'documento_id' => $documento_id,
				'fecha'        => gmdate( 'Y-m-d H:i:s' ),
			)
		);
	}
}
