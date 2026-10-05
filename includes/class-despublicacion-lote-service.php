<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Deshacer un lote de "Publicar todos los pendientes" (#317). Mismo patron
 * de dos pasos que Publicacion_Bloque_Service:
 *
 * 1. calcular(): los documentos que SIGUEN publicados por el lote
 *    (publicacion_lote_id = lote y publicado = 1) y su huella. Uno del lote
 *    despublicado a mano, o despublicado y vuelto a publicar por otra via,
 *    ya no tiene el lote (lo quita cualquier cambio fuera del lote) y no
 *    cuenta: deshacer nunca contradice una decision posterior.
 * 2. aplicar(): recalcula y solo escribe si la huella coincide. En una
 *    transaccion despublica por la lista de ids (solo los que sigan
 *    publicados por el lote), les quita el lote y marca el lote como
 *    deshecho si seguia aplicado; si otro deshacer gano la carrera, no
 *    escribe nada.
 *
 * Los eventos de publicacion (#318, origen "deshacer_lote", un evento
 * "despublicado" por documento, con el lote) se escriben en esa misma
 * transaccion.
 *
 * Solo cambia publicado de 1 a 0 (Permissions sigue decidiendo quien ve
 * que) y no toca notificado_en: un documento ya avisado que se vuelve a
 * publicar no se avisa dos veces. Deshacer no retira lo que la farmacia ya
 * haya visto o descargado.
 */
class Despublicacion_Lote_Service {

	public const ERROR_CAMBIADO = 'mdf_ca_despublicacion_lote_cambiada';

	private Documento_Repository $repository;
	private Publicacion_Lote_Repository $lotes;

	public function __construct( ?Documento_Repository $repository = null, ?Publicacion_Lote_Repository $lotes = null ) {
		$this->repository = $repository ?? new Documento_Repository();
		$this->lotes      = $lotes ?? new Publicacion_Lote_Repository();
	}

	/**
	 * @return array{
	 *     lote_id: int, documentos_lote: int, ids: int[], documentos: int,
	 *     farmacias: int, huella: string
	 * }|\WP_Error
	 */
	public function calcular( int $lote_id ) {
		$lote = $this->lotes->find_by_id( $lote_id );

		if ( ! $lote ) {
			return new \WP_Error( 'mdf_ca_despublicacion_lote_no_existe', 'El lote no existe.' );
		}

		if ( Publicacion_Lote_Repository::ESTADO_APLICADO !== $lote->estado ) {
			return new \WP_Error( 'mdf_ca_despublicacion_lote_deshecho', 'Este lote ya se deshizo. No se ha cambiado nada.' );
		}

		$filas = $this->repository->find_publicados_de_lote( $lote_id );

		if ( null === $filas ) {
			return new \WP_Error( 'mdf_ca_despublicacion_lote_error_bd', 'No se pudieron leer los documentos del lote. No se ha cambiado nada.' );
		}

		$ids = array_column( $filas, 'id' );
		sort( $ids );

		return array(
			'lote_id'         => $lote_id,
			'documentos_lote' => (int) $lote->documentos,
			'ids'             => $ids,
			'documentos'      => count( $ids ),
			'farmacias'       => count( array_unique( array_column( $filas, 'farmacia_id' ) ) ),
			'huella'          => hash( 'sha256', $lote_id . ':' . implode( ',', $ids ) ),
		);
	}

	/**
	 * Despublica exactamente lo confirmado. Si cambio, no escribe nada
	 * (ERROR_CAMBIADO).
	 *
	 * @return array{despublicados: int, farmacias: int, documentos_lote: int}|\WP_Error
	 */
	public function aplicar( int $lote_id, string $huella_confirmada, int $usuario_id ) {
		global $wpdb;

		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_lotes_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) {
			return new \WP_Error(
				'mdf_ca_despublicacion_lote_sin_transacciones',
				'Las tablas de documentos, de lotes o de eventos no admiten transacciones (no son InnoDB). No se ha cambiado nada.'
			);
		}

		$actual = $this->calcular( $lote_id );

		if ( is_wp_error( $actual ) ) {
			return $actual;
		}

		if ( ! hash_equals( $actual['huella'], $huella_confirmada ) ) {
			return new \WP_Error(
				self::ERROR_CAMBIADO,
				'Los documentos del lote han cambiado desde que preparaste la despublicación (alguno se ha despublicado o publicado por otra vía). No se ha cambiado nada: revisa la confirmación actualizada.'
			);
		}

		$wpdb->query( 'START TRANSACTION' );

		$despublicados = $this->repository->despublicar_ids_de_lote( $actual['ids'], $lote_id, $usuario_id );
		$marcado       = false === $despublicados ? false : $this->lotes->marcar_deshecho( $lote_id, $usuario_id, $despublicados );

		if ( false === $marcado ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_despublicacion_lote_error_bd', 'No se pudo deshacer el lote. No se ha cambiado nada.' );
		}

		// Otro deshacer del mismo lote termino antes: este no escribe nada.
		if ( 0 === (int) $marcado ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_despublicacion_lote_deshecho', 'Este lote ya se deshizo. No se ha cambiado nada.' );
		}

		$wpdb->query( 'COMMIT' );

		Recepcion_Registro::registrar( Recepcion_Registro::DESPUBLICADO_LOTE, $despublicados );

		return array(
			'despublicados'   => $despublicados,
			'farmacias'       => $actual['farmacias'],
			'documentos_lote' => $actual['documentos_lote'],
		);
	}
}
