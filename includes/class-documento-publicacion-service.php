<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Publicar o despublicar de uno en uno, y publicar los pendientes de una
 * farmacia (#318). Cada operacion abre su transaccion, llama a
 * Documento_Repository::cambiar_estado() (que deja el evento en ella) y
 * confirma: o cambia el estado y queda su evento, o no pasa nada.
 *
 * Solo cambia publicado (y publicacion_lote_id, con las reglas de la #317).
 * No decide visibilidad (eso sigue siendo Permissions) y no toca los avisos
 * por email (notificado_en). Exige InnoDB en documentos y eventos: la
 * comprobacion es la misma de la publicacion en bloque.
 */
class Documento_Publicacion_Service {

	private Documento_Repository $repository;

	public function __construct( ?Documento_Repository $repository = null ) {
		$this->repository = $repository ?? new Documento_Repository();
	}

	/**
	 * Cambia varios documentos en una transaccion.
	 *
	 * @param int[] $ids
	 * @return int|\WP_Error Filas cambiadas (0 si ya estaban en ese estado o no existen).
	 */
	private function cambiar( array $ids, bool $publicar, string $origen, int $usuario_id ) {
		global $wpdb;

		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) {
			return new \WP_Error(
				'mdf_ca_publicacion_sin_transacciones',
				'Las tablas de documentos o de eventos no admiten transacciones (no son InnoDB). No se ha cambiado nada.'
			);
		}

		$wpdb->query( 'START TRANSACTION' );

		$cambiados = $this->repository->cambiar_estado( $ids, $publicar, $origen, $usuario_id );

		if ( false === $cambiados ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_publicacion_error_bd', 'No se pudo completar el cambio. No se ha cambiado nada.' );
		}

		$wpdb->query( 'COMMIT' );

		return $cambiados;
	}

	/** @return int|\WP_Error */
	public function publicar_uno( int $documento_id, int $usuario_id ) {
		return $this->cambiar( array( $documento_id ), true, Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL, $usuario_id );
	}

	/** @return int|\WP_Error */
	public function despublicar_uno( int $documento_id, int $usuario_id ) {
		return $this->cambiar( array( $documento_id ), false, Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL, $usuario_id );
	}

	/**
	 * Publica los pendientes de UNA farmacia; ninguna otra se toca.
	 *
	 * @return int|\WP_Error Documentos publicados.
	 */
	public function publicar_farmacia( int $farmacia_id, int $usuario_id ) {
		$ids = $this->repository->find_ids_pendientes_de_farmacia( $farmacia_id );

		if ( null === $ids ) {
			return new \WP_Error( 'mdf_ca_publicacion_error_bd', 'No se pudieron leer los documentos pendientes. No se ha cambiado nada.' );
		}

		return $this->cambiar( $ids, true, Publicacion_Evento_Repository::ORIGEN_POR_FARMACIA, $usuario_id );
	}
}
