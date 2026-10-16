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
	 * Retira para correccion un Modelo 347 vigente (#307), publicado o
	 * pendiente, para que se pueda subir el corregido. En una transaccion:
	 * bloquea la fila; si esta publicado lo despublica con cambiar_estado()
	 * (evento "despublicado"); libera su hueco en el UNIQUE y lo marca
	 * retirado (Documento_Repository::marcar_retirado()), y deja el evento
	 * "retirado". Si algo falla, no cambia nada. Retirado es terminal: no se
	 * vuelve a publicar por ninguna via. Lo que la farmacia ya haya visto o
	 * descargado no se puede retirar.
	 *
	 * @return true|\WP_Error
	 */
	public function retirar_modelo_347( int $documento_id, int $usuario_id ) {
		global $wpdb;

		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) {
			return new \WP_Error(
				'mdf_ca_publicacion_sin_transacciones',
				'Las tablas de documentos o de eventos no admiten transacciones (no son InnoDB). No se ha cambiado nada.'
			);
		}

		$wpdb->query( 'START TRANSACTION' );

		$documento = $this->repository->find_by_id_para_actualizar( $documento_id );

		if ( ! $documento || ! $documento->es_modelo_347_vigente() ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_retirar_no_aplica', 'El documento no existe, no es un Modelo 347 o ya estaba retirado. No se ha cambiado nada.' );
		}

		$ok = true;

		if ( $documento->is_publicado() ) {
			$ok = 1 === $this->repository->cambiar_estado( array( $documento_id ), false, Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL, $usuario_id );
		}

		$ok = $ok
			&& $this->repository->marcar_retirado( $documento_id )
			&& 1 === ( new Publicacion_Evento_Repository() )->insertar_para(
				array( $documento_id => null ),
				Publicacion_Evento_Repository::ACCION_RETIRADO,
				Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL,
				$usuario_id
			);

		if ( ! $ok ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_publicacion_error_bd', 'No se pudo retirar el documento. No se ha cambiado nada.' );
		}

		$wpdb->query( 'COMMIT' );

		return true;
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
