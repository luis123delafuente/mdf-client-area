<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Poner o quitar la marca de agua de un documento ya guardado (#321),
 * pendiente o publicado, desde Pendientes de publicar o Documentos MDF.
 *
 * El ajuste vive en documentos.sin_marca y solo lo lee el servidor al pintar
 * el visor (Documento_Visor y la vista previa del administrador): ningun
 * parametro de la peticion lo cambia ni lo fuerza. Cambiarlo deja un evento
 * "marca_quitada" o "marca_puesta" con usuario y fecha, en la misma
 * transaccion que el UPDATE (fila bloqueada con FOR UPDATE). Si el documento
 * ya estaba asi, no cambia nada ni deja evento.
 *
 * Lo que la farmacia ya vio con o sin marca no cambia: es el visor de ahora
 * en adelante.
 */
class Documento_Marca_Service {

	private Documento_Repository $repository;
	private Publicacion_Evento_Repository $eventos;

	public function __construct( ?Documento_Repository $repository = null, ?Publicacion_Evento_Repository $eventos = null ) {
		$this->repository = $repository ?? new Documento_Repository();
		$this->eventos    = $eventos ?? new Publicacion_Evento_Repository();
	}

	/**
	 * @return array{nombre: string, cambiado: bool}|\WP_Error
	 */
	public function cambiar( int $documento_id, bool $sin_marca, int $usuario_id ) {
		global $wpdb;

		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) {
			return new \WP_Error( 'mdf_ca_publicacion_sin_transacciones', 'Las tablas de documentos o de eventos no admiten transacciones (no son InnoDB). No se ha cambiado nada.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		$documento = $this->repository->find_by_id_para_actualizar( $documento_id );

		if ( ! $documento ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_marca_no_existe', 'El documento no existe (puede que se haya eliminado). No se ha cambiado nada.' );
		}

		if ( $documento->is_sin_marca_de_agua() === $sin_marca ) {
			$wpdb->query( 'ROLLBACK' );

			return array(
				'nombre'   => $documento->get_nombre(),
				'cambiado' => false,
			);
		}

		$ok = 1 === $this->repository->cambiar_marca( $documento_id, $sin_marca )
			&& 1 === $this->eventos->insertar_para(
				array( $documento_id => null ),
				$sin_marca ? Publicacion_Evento_Repository::ACCION_MARCA_QUITADA : Publicacion_Evento_Repository::ACCION_MARCA_PUESTA,
				Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL,
				$usuario_id
			);

		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_publicacion_error_bd', 'No se pudo cambiar la marca de agua del documento. No se ha cambiado nada.' );
		}

		return array(
			'nombre'   => $documento->get_nombre(),
			'cambiado' => true,
		);
	}
}
