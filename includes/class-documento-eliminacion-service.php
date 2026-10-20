<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Eliminar desde el backoffice un documento subido por error (v0.28.0).
 *
 * Solo documentos NO publicados (pendientes o despublicados): un publicado
 * hay que despublicarlo antes, para que nunca desaparezca de golpe algo que
 * una farmacia puede estar viendo. Los Modelo 347 no se eliminan por aqui:
 * siguen con "Retirar para correccion".
 *
 * Todo o nada, en una transaccion con la fila bloqueada (FOR UPDATE):
 * 1. se comprueba que existe, que no esta publicado y que no es un 347;
 * 2. el fichero se aparta dentro de la carpeta privada (rename a un nombre
 *    oculto aleatorio: atomico y reversible);
 * 3. DELETE de la fila (que vuelve a exigir no publicado y no 347) e INSERT
 *    del evento "eliminado" con usuario y fecha (sin datos del documento);
 * 4. COMMIT y, despues, se borra el fichero apartado. Si algo falla antes del
 *    COMMIT, ROLLBACK y el fichero vuelve a su nombre.
 *
 * Al desaparecer la fila, su hash y su emisor + serie + numero dejan de
 * bloquear: la misma factura se puede volver a subir. Si el fichero ya no
 * estaba en disco, se borra igual la fila y se avisa.
 *
 * Documentos antiguos (Fase 1, anteriores a varias migraciones): tipo, MIME,
 * tamano, hash y fecha en NULL. Se eliminan igual (v0.32.1: el DELETE trata
 * tipo NULL como "no es un 347"). Si algo falla, el mensaje dice en que paso
 * y el registro de errores guarda el motivo tecnico (id, paso, publicado,
 * tipo y error de la BD), nunca el nombre ni datos de la factura.
 */
class Documento_Eliminacion_Service {

	private Documento_Repository $repository;
	private Publicacion_Evento_Repository $eventos;

	public function __construct( ?Documento_Repository $repository = null, ?Publicacion_Evento_Repository $eventos = null ) {
		$this->repository = $repository ?? new Documento_Repository();
		$this->eventos    = $eventos ?? new Publicacion_Evento_Repository();
	}

	/**
	 * Si el documento se puede eliminar; si no, por que (texto para el administrador).
	 */
	public static function motivo_no_eliminable( ?Documento $documento ): ?string {
		if ( ! $documento ) {
			return 'El documento no existe (puede que ya se haya eliminado).';
		}

		if ( 'modelo_347' === $documento->get_tipo_documento() ) {
			return 'Los Modelo 347 no se eliminan: usa «Retirar para corrección» en Documentos MDF.';
		}

		if ( $documento->is_publicado() ) {
			return 'El documento está publicado y su farmacia puede estar viéndolo. Despublícalo antes (en Documentos publicados) y después elimínalo.';
		}

		return null;
	}

	/**
	 * @return array{nombre: string, fichero_estaba: bool, fichero_borrado: bool}|\WP_Error
	 *         fichero_estaba false: el fichero ya no estaba en disco; la fila y
	 *         el evento si se hicieron. fichero_borrado false con fichero_estaba
	 *         true: el apartado (.eliminando-*) no se pudo borrar tras el COMMIT
	 *         y queda en la carpeta privada.
	 */
	public function eliminar( int $documento_id, int $usuario_id ) {
		global $wpdb;

		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) {
			return new \WP_Error( 'mdf_ca_publicacion_sin_transacciones', 'Las tablas de documentos o de eventos no admiten transacciones (no son InnoDB). No se ha eliminado nada.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		$documento = $this->repository->find_by_id_para_actualizar( $documento_id );
		$motivo    = self::motivo_no_eliminable( $documento );

		if ( null !== $motivo ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_eliminar_no_aplica', $motivo . ' No se ha eliminado nada.' );
		}

		$fichero  = self::ruta_fichero( $documento );
		$apartado = null;

		if ( null !== $fichero ) {
			$apartado = dirname( $fichero ) . DIRECTORY_SEPARATOR . '.eliminando-' . bin2hex( random_bytes( 8 ) );

			if ( ! @rename( $fichero, $apartado ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- el aviso lo da el WP_Error.
				$wpdb->query( 'ROLLBACK' );
				self::registrar_fallo( $documento_id, 'rename del fichero a .eliminando-* fallido', $documento );

				return new \WP_Error( 'mdf_ca_eliminar_fichero', 'No se ha podido borrar el fichero en el servidor (permisos de la carpeta privada). No se ha eliminado nada.' );
			}
		}

		$fallo = $this->borrar_fila_y_registrar( $documento, $usuario_id );

		if ( null === $fallo && false === $wpdb->query( 'COMMIT' ) ) {
			$fallo = array( 'commit', 'La base de datos no ha confirmado el borrado.' );
		}

		if ( null !== $fallo ) {
			$error_bd = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );

			if ( null !== $apartado ) {
				@rename( $apartado, $fichero ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			}

			self::registrar_fallo( $documento_id, $fallo[0] . ( '' !== $error_bd ? ' | BD: ' . $error_bd : '' ), $documento );

			return new \WP_Error( 'mdf_ca_publicacion_error_bd', $fallo[1] . ' No se ha eliminado nada.' );
		}

		if ( null !== $apartado ) {
			wp_delete_file( $apartado );
		}

		return array(
			'nombre'          => $documento->get_nombre(),
			'fichero_estaba'  => null !== $apartado,
			'fichero_borrado' => null !== $apartado && ! file_exists( $apartado ),
		);
	}

	/**
	 * DELETE de la fila e INSERT del evento, dentro de la transaccion de
	 * eliminar(). Devuelve null si los dos han ido bien, o el paso que fallo
	 * (para el registro) y el mensaje para el administrador.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function borrar_fila_y_registrar( Documento $documento, int $usuario_id ): ?array {
		$borradas = $this->repository->eliminar_no_publicado( $documento->get_id() );

		if ( false === $borradas ) {
			return array( 'DELETE con error de la BD', 'La base de datos ha rechazado el borrado del documento. Avisa a desarrollo: el detalle está en el registro de errores.' );
		}

		if ( 1 !== $borradas ) {
			return array( sprintf( 'DELETE afecto a %d filas', $borradas ), 'El documento ha cambiado mientras se eliminaba (puede que alguien lo haya publicado o eliminado a la vez). Recarga la página y vuelve a intentarlo.' );
		}

		$evento = $this->eventos->insertar_para(
			array( $documento->get_id() => null ),
			Publicacion_Evento_Repository::ACCION_ELIMINADO,
			Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL,
			$usuario_id
		);

		if ( 1 !== $evento ) {
			return array( 'INSERT del evento eliminado fallido', 'No se ha podido apuntar la eliminación en el historial del documento, así que no se ha borrado. Avisa a desarrollo: el detalle está en el registro de errores.' );
		}

		return null;
	}

	/**
	 * Motivo tecnico al registro de errores: id, paso, estado del documento y
	 * error de la BD. Nunca el nombre del documento ni datos de la factura
	 * (serie, numero, CIF, importes).
	 */
	private static function registrar_fallo( int $documento_id, string $paso, Documento $documento ): void {
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- solo en un fallo, sin datos personales ni fiscales.
			sprintf(
				'[mdf-client-area] Eliminar documento %d: %s (publicado=%d, tipo=%s)',
				$documento_id,
				substr( $paso, 0, 300 ),
				$documento->is_publicado() ? 1 : 0,
				$documento->get_tipo_documento() ?? 'NULL'
			)
		);
	}

	/**
	 * Ruta real del fichero del documento dentro de la carpeta privada, o null
	 * si no esta en disco. Como el endpoint: realpath y prefijo, nunca una
	 * ruta fuera de la carpeta.
	 */
	private static function ruta_fichero( Documento $documento ): ?string {
		$carpeta = realpath( Documento_Endpoint::get_carpeta_documentos() );
		$ruta    = $carpeta ? realpath( $carpeta . DIRECTORY_SEPARATOR . basename( $documento->get_ruta_fichero() ) ) : false;

		return $ruta && str_starts_with( $ruta, $carpeta . DIRECTORY_SEPARATOR ) && is_file( $ruta ) ? $ruta : null;
	}
}
