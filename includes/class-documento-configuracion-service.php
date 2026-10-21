<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Configuracion de un documento ya guardado, pendiente o publicado, desde
 * Pendientes de publicar o Documentos MDF: la marca de agua (#321) y, desde
 * la v0.36.0, si es descargable. Antes se llamaba Documento_Marca_Service.
 *
 * Los dos ajustes viven en la fila (documentos.sin_marca y
 * documentos.descargable) y solo los lee el servidor (Documento_Visor, la
 * vista previa del administrador y Permissions::puede_descargar_documento()):
 * ningun parametro de la peticion los cambia ni los fuerza. Cada cambio deja
 * su evento ("marca_quitada"/"marca_puesta", "descarga_activada"/
 * "descarga_desactivada") con usuario y fecha, en la misma transaccion que
 * el UPDATE (fila bloqueada con FOR UPDATE). Si el documento ya estaba asi,
 * no cambia nada ni deja evento.
 *
 * Lo que la farmacia ya vio o descargo no cambia: vale de ahora en adelante.
 */
class Documento_Configuracion_Service {

	private Documento_Repository $repository;
	private Publicacion_Evento_Repository $eventos;

	public function __construct( ?Documento_Repository $repository = null, ?Publicacion_Evento_Repository $eventos = null ) {
		$this->repository = $repository ?? new Documento_Repository();
		$this->eventos    = $eventos ?? new Publicacion_Evento_Repository();
	}

	/**
	 * @return array{nombre: string, cambiado: bool}|\WP_Error
	 */
	public function cambiar_marca( int $documento_id, bool $sin_marca, int $usuario_id ) {
		return $this->cambiar(
			$documento_id,
			$usuario_id,
			static fn( Documento $d ): bool => $d->is_sin_marca_de_agua() === $sin_marca,
			fn(): int|false => $this->repository->cambiar_marca( $documento_id, $sin_marca ),
			$sin_marca ? Publicacion_Evento_Repository::ACCION_MARCA_QUITADA : Publicacion_Evento_Repository::ACCION_MARCA_PUESTA,
			'No se pudo cambiar la marca de agua del documento. No se ha cambiado nada.'
		);
	}

	/**
	 * @return array{nombre: string, cambiado: bool}|\WP_Error
	 */
	public function cambiar_descarga( int $documento_id, bool $descargable, int $usuario_id ) {
		return $this->cambiar(
			$documento_id,
			$usuario_id,
			static fn( Documento $d ): bool => $d->is_descargable() === $descargable,
			fn(): int|false => $this->repository->cambiar_descargable( $documento_id, $descargable ),
			$descargable ? Publicacion_Evento_Repository::ACCION_DESCARGA_ACTIVADA : Publicacion_Evento_Repository::ACCION_DESCARGA_DESACTIVADA,
			'No se pudo cambiar la descarga del documento. No se ha cambiado nada.'
		);
	}

	/**
	 * @param callable(Documento): bool $ya_esta    El documento ya tiene el valor pedido.
	 * @param callable(): (int|false)   $actualizar UPDATE condicionado; filas afectadas.
	 * @return array{nombre: string, cambiado: bool}|\WP_Error
	 */
	private function cambiar( int $documento_id, int $usuario_id, callable $ya_esta, callable $actualizar, string $accion, string $error ) {
		global $wpdb;

		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) {
			return new \WP_Error( 'mdf_ca_publicacion_sin_transacciones', 'Las tablas de documentos o de eventos no admiten transacciones (no son InnoDB). No se ha cambiado nada.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		$documento = $this->repository->find_by_id_para_actualizar( $documento_id );

		if ( ! $documento ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_configuracion_no_existe', 'El documento no existe (puede que se haya eliminado). No se ha cambiado nada.' );
		}

		if ( $ya_esta( $documento ) ) {
			$wpdb->query( 'ROLLBACK' );

			return array(
				'nombre'   => $documento->get_nombre(),
				'cambiado' => false,
			);
		}

		$ok = 1 === $actualizar()
			&& 1 === $this->eventos->insertar_para(
				array( $documento_id => null ),
				$accion,
				Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL,
				$usuario_id
			);

		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_publicacion_error_bd', $error );
		}

		return array(
			'nombre'   => $documento->get_nombre(),
			'cambiado' => true,
		);
	}
}
