<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cambios de estado de un presupuesto (v0.44.0). Dos caminos:
 *
 * - La farmacia, desde el visor (Documento_Visor): aceptar o rechazar (con
 *   motivo), solo si puede ver el documento (Permissions), es un presupuesto
 *   y sigue pendiente. Cualquier otro caso es NO_ENCONTRADO, y quien llama
 *   responde el 404 uniforme. Guarda la huella SHA-256 del fichero: constancia
 *   de que documento exacto se acepto. Despues del COMMIT, los correos
 *   (Presupuesto_Aviso): si fallan, el estado ya esta guardado.
 * - MDF, desde la pantalla Presupuestos: pendiente o aceptado (p. ej.
 *   aceptado por otra via, o devolver a pendiente un rechazo). Sin correos.
 *
 * Mismo patron que Documento_Configuracion_Service: fila bloqueada (FOR
 * UPDATE), UPDATE condicionado al estado de partida y evento en la misma
 * transaccion. Dos aceptaciones a la vez: una gana y la otra ve "ya
 * respondido"; solo sale un correo.
 */
class Presupuesto_Service {

	public const NO_ENCONTRADO = 'mdf_ca_presupuesto_no_encontrado';
	public const YA_RESPONDIDO = 'mdf_ca_presupuesto_ya_respondido';

	private Documento_Repository $repository;
	private Publicacion_Evento_Repository $eventos;

	public function __construct( ?Documento_Repository $repository = null, ?Publicacion_Evento_Repository $eventos = null ) {
		$this->repository = $repository ?? new Documento_Repository();
		$this->eventos    = $eventos ?? new Publicacion_Evento_Repository();
	}

	/**
	 * @return array{nombre: string, estado: string}|\WP_Error
	 */
	public function aceptar( int $documento_id, \WP_User $usuario ) {
		return $this->responder( $documento_id, $usuario, Presupuesto_Estado::ACEPTADO, null );
	}

	/**
	 * @return array{nombre: string, estado: string}|\WP_Error
	 */
	public function rechazar( int $documento_id, \WP_User $usuario, string $motivo ) {
		$motivo = trim( preg_replace( '/[ \t]+/', ' ', str_replace( "\r", '', $motivo ) ) );

		if ( '' === $motivo ) {
			return new \WP_Error( 'mdf_ca_presupuesto_motivo_vacio', 'Escribe el motivo del rechazo.' );
		}

		if ( mb_strlen( $motivo ) > Presupuesto_Estado::MAX_MOTIVO ) {
			return new \WP_Error( 'mdf_ca_presupuesto_motivo_largo', sprintf( 'El motivo no puede tener más de %d caracteres.', Presupuesto_Estado::MAX_MOTIVO ) );
		}

		return $this->responder( $documento_id, $usuario, Presupuesto_Estado::RECHAZADO, $motivo );
	}

	/**
	 * @return array{nombre: string, estado: string}|\WP_Error
	 */
	private function responder( int $documento_id, \WP_User $usuario, string $estado, ?string $motivo ) {
		global $wpdb;

		$error_tablas = self::comprobar_tablas();

		if ( $error_tablas ) {
			return $error_tablas;
		}

		$wpdb->query( 'START TRANSACTION' );

		$documento   = $this->repository->find_by_id_para_actualizar( $documento_id );
		$presupuesto = $documento ? $documento->get_presupuesto() : null;

		if ( ! $presupuesto || ! Permissions::puede_ver_documento( $usuario, $documento ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( self::NO_ENCONTRADO, 'Documento no encontrado.' );
		}

		if ( ! $presupuesto->es_pendiente() ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( self::YA_RESPONDIDO, sprintf( 'Este presupuesto ya está %s.', mb_strtolower( $presupuesto->get_etiqueta() ) ) );
		}

		$ruta = Documento_Endpoint::get_carpeta_documentos() . DIRECTORY_SEPARATOR . basename( $documento->get_ruta_fichero() );
		$hash = is_readable( $ruta ) ? hash_file( 'sha256', $ruta ) : false;

		if ( ! is_string( $hash ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_presupuesto_fichero', 'No se ha podido registrar tu respuesta. Inténtalo de nuevo más tarde.' );
		}

		$ok = 1 === $this->repository->cambiar_estado_presupuesto(
			$documento_id,
			array( Presupuesto_Estado::PENDIENTE ),
			array(
				'estado'          => $estado,
				'cambiado_en'     => current_time( 'mysql', true ),
				'cambiado_por'    => (int) $usuario->ID,
				'via'             => Presupuesto_Estado::VIA_AREA,
				'motivo'          => $motivo,
				'hash'            => $hash,
				'aviso_pendiente' => 1,
			)
		)
			&& 1 === $this->eventos->insertar_para(
				array( $documento_id => null ),
				Presupuesto_Estado::ACEPTADO === $estado ? Publicacion_Evento_Repository::ACCION_PRESUPUESTO_ACEPTADO : Publicacion_Evento_Repository::ACCION_PRESUPUESTO_RECHAZADO,
				Publicacion_Evento_Repository::ORIGEN_AREA_CLIENTE,
				(int) $usuario->ID
			);

		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_presupuesto_error_bd', 'No se ha podido registrar tu respuesta. Inténtalo de nuevo más tarde.' );
		}

		// Ya guardado: los correos no deshacen nada si fallan.
		$actualizado = $this->repository->find_by_id( $documento_id );

		if ( $actualizado ) {
			( new Presupuesto_Aviso( $this->repository ) )->tras_respuesta( $actualizado, $usuario );
		}

		return array(
			'nombre' => $documento->get_nombre(),
			'estado' => $estado,
		);
	}

	/**
	 * MDF pone un presupuesto en pendiente o aceptado. Si ya lo estaba, no
	 * cambia nada ni deja evento. Borra el motivo y la huella de la respuesta
	 * anterior (quedan en el historial de eventos) y cancela su aviso si no
	 * habia salido: el estado que vale es el nuevo.
	 *
	 * @return array{nombre: string, cambiado: bool}|\WP_Error
	 */
	public function cambiar_por_mdf( int $documento_id, string $estado, int $usuario_id ) {
		global $wpdb;

		if ( ! isset( Presupuesto_Estado::opciones_mdf()[ $estado ] ) ) {
			return new \WP_Error( 'mdf_ca_presupuesto_estado_invalido', 'Estado no válido: elige pendiente o aceptado.' );
		}

		$error_tablas = self::comprobar_tablas();

		if ( $error_tablas ) {
			return $error_tablas;
		}

		$wpdb->query( 'START TRANSACTION' );

		$documento   = $this->repository->find_by_id_para_actualizar( $documento_id );
		$presupuesto = $documento ? $documento->get_presupuesto() : null;

		if ( ! $presupuesto ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( self::NO_ENCONTRADO, 'El presupuesto no existe (puede que se haya eliminado). No se ha cambiado nada.' );
		}

		if ( $presupuesto->get_estado() === $estado ) {
			$wpdb->query( 'ROLLBACK' );

			return array(
				'nombre'   => $documento->get_nombre(),
				'cambiado' => false,
			);
		}

		$es_pendiente = Presupuesto_Estado::PENDIENTE === $estado;
		$ok           = 1 === $this->repository->cambiar_estado_presupuesto(
			$documento_id,
			array( $presupuesto->get_estado() ),
			array(
				'estado'          => $estado,
				'cambiado_en'     => $es_pendiente ? null : current_time( 'mysql', true ),
				'cambiado_por'    => $es_pendiente ? null : $usuario_id,
				'via'             => $es_pendiente ? null : Presupuesto_Estado::VIA_MDF,
				'motivo'          => null,
				'hash'            => null,
				'aviso_pendiente' => 0,
			)
		)
			&& 1 === $this->eventos->insertar_para(
				array( $documento_id => null ),
				$es_pendiente ? Publicacion_Evento_Repository::ACCION_PRESUPUESTO_PENDIENTE : Publicacion_Evento_Repository::ACCION_PRESUPUESTO_ACEPTADO,
				Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL,
				$usuario_id
			);

		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_presupuesto_error_bd', 'No se pudo cambiar el estado del presupuesto. No se ha cambiado nada.' );
		}

		return array(
			'nombre'   => $documento->get_nombre(),
			'cambiado' => true,
		);
	}

	private static function comprobar_tablas(): ?\WP_Error {
		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) {
			return new \WP_Error( 'mdf_ca_publicacion_sin_transacciones', 'Las tablas de documentos o de eventos no admiten transacciones (no son InnoDB). No se ha cambiado nada.' );
		}

		return null;
	}
}
