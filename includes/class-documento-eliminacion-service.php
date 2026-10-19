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

				return new \WP_Error( 'mdf_ca_eliminar_fichero', 'No se ha podido borrar el fichero en el servidor (permisos de la carpeta privada). No se ha eliminado nada.' );
			}
		}

		$ok = 1 === $this->repository->eliminar_no_publicado( $documento_id )
			&& 1 === $this->eventos->insertar_para(
				array( $documento_id => null ),
				Publicacion_Evento_Repository::ACCION_ELIMINADO,
				Publicacion_Evento_Repository::ORIGEN_INDIVIDUAL,
				$usuario_id
			);

		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );

			if ( null !== $apartado ) {
				@rename( $apartado, $fichero ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			}

			return new \WP_Error( 'mdf_ca_publicacion_error_bd', 'No se pudo eliminar el documento. No se ha eliminado nada.' );
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
