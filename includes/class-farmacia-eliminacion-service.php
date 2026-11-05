<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ciclo de vida de una farmacia frente a su cuenta y su borrado (v0.46.0),
 * para limpiar los datos de prueba antes del alta masiva.
 *
 * 1. Al borrar un usuario de WordPress (desde Usuarios, WP-CLI o donde
 *    sea), la farmacia que lo tenia vinculado vuelve a "Sin cuenta" y se
 *    puede invitar de nuevo (hook deleted_user). Antes quedaba en
 *    "usuario_roto" e Invitacion_Service::invitar() se negaba.
 *
 * 2. Eliminar una farmacia, solo el administrador (Roles::CAP_ADMINISTRAR,
 *    desde Farmacias MDF), y solo si:
 *    - no tiene ningun documento, en ningun estado (tambien despublicados y
 *      347 retirados): no se dejan documentos huerfanos, que nadie podria
 *      ver y que no se pueden reasignar;
 *    - no tiene un usuario vinculado que exista: primero se borra la cuenta
 *      en Usuarios (y el punto 1 la desvincula).
 *    La regla vive en bloqueo() y el DELETE la repite en SQL
 *    (Farmacia_Repository::eliminar_sin_dependencias()). En una
 *    transaccion con la fila bloqueada: DELETE de la farmacia y de su
 *    presencia en "farmacias concretas" de Herramientas. El plan no se
 *    toca. No hay historial de farmacias: si falla, el registro de errores
 *    guarda id y paso, nunca nombre ni CIF.
 */
class Farmacia_Eliminacion_Service {

	private Farmacia_Repository $farmacias;
	private Documento_Repository $documentos;
	private Catalogo_Repository $catalogo;

	public function __construct( ?Farmacia_Repository $farmacias = null, ?Documento_Repository $documentos = null, ?Catalogo_Repository $catalogo = null ) {
		$this->farmacias  = $farmacias ?? new Farmacia_Repository();
		$this->documentos = $documentos ?? new Documento_Repository();
		$this->catalogo   = $catalogo ?? new Catalogo_Repository();
	}

	public static function register_hooks(): void {
		add_action( 'deleted_user', array( __CLASS__, 'desvincular_usuario_borrado' ) );
	}

	/** @param int|string $user_id */
	public static function desvincular_usuario_borrado( $user_id ): void {
		( new Farmacia_Repository() )->desvincular_usuario( (int) $user_id );
	}

	/**
	 * Por que no se puede eliminar la farmacia, o null si se puede.
	 * "corto" para la tabla de Farmacias MDF y "largo" para la confirmacion y
	 * los errores.
	 *
	 * @return array{corto: string, largo: string}|null
	 */
	public static function bloqueo( ?Farmacia $farmacia, int $documentos ): ?array {
		if ( ! $farmacia ) {
			return array(
				'corto' => 'No existe',
				'largo' => 'La farmacia no existe (puede que ya se haya eliminado).',
			);
		}

		if ( $documentos > 0 ) {
			return array(
				'corto' => sprintf( 1 === $documentos ? 'Tiene %d documento' : 'Tiene %d documentos', $documentos ),
				'largo' => sprintf(
					'La farmacia tiene %s. Elimínalos antes desde Documentos MDF (los publicados hay que despublicarlos primero; los Modelo 347 no se pueden eliminar).',
					1 === $documentos ? '1 documento' : $documentos . ' documentos'
				),
			);
		}

		$usuario = $farmacia->get_wp_user_id() ? get_userdata( $farmacia->get_wp_user_id() ) : false;

		if ( $usuario ) {
			return array(
				'corto' => 'Tiene cuenta de acceso',
				'largo' => sprintf(
					'La farmacia tiene una cuenta de acceso (usuario «%s»). Bórrala antes en Usuarios; la farmacia quedará «Sin cuenta» y entonces se podrá eliminar.',
					$usuario->user_login
				),
			);
		}

		return null;
	}

	/**
	 * @return array{nombre: string, cif: string}|\WP_Error
	 */
	public function eliminar( int $farmacia_id ) {
		global $wpdb;

		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_farmacias_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_catalogo_farmacias_table_name() ) ) {
			return new \WP_Error( 'mdf_ca_farmacia_sin_transacciones', 'Las tablas de farmacias no admiten transacciones (no son InnoDB). No se ha eliminado nada.' );
		}

		$wpdb->query( 'START TRANSACTION' );

		$farmacia = $this->farmacias->find_by_id_para_actualizar( $farmacia_id );
		$bloqueo  = self::bloqueo( $farmacia, $farmacia ? $this->documentos->contar_de_farmacia( $farmacia_id ) : 0 );

		if ( null !== $bloqueo ) {
			$wpdb->query( 'ROLLBACK' );

			return new \WP_Error( 'mdf_ca_farmacia_no_eliminable', $bloqueo['largo'] . ' No se ha eliminado nada.' );
		}

		$fallo    = null;
		$borradas =$this->farmacias->eliminar_sin_dependencias( $farmacia_id );

		if ( false === $borradas ) {
			$fallo = array( 'DELETE con error de la BD', 'La base de datos ha rechazado el borrado de la farmacia. Avisa a desarrollo: el detalle está en el registro de errores.' );
		} elseif ( 1 !== $borradas ) {
			$fallo = array( sprintf( 'DELETE afecto a %d filas', $borradas ), 'La farmacia ha cambiado mientras se eliminaba (puede que haya recibido un documento o una cuenta a la vez). Recarga la página y vuelve a intentarlo.' );
		} elseif ( ! $this->catalogo->quitar_farmacia( $farmacia_id ) ) {
			$fallo = array( 'DELETE de catalogo_farmacias fallido', 'No se ha podido quitar la farmacia de las herramientas asignadas a farmacias concretas. Avisa a desarrollo: el detalle está en el registro de errores.' );
		} elseif ( false === $wpdb->query( 'COMMIT' ) ) {
			$fallo = array( 'commit', 'La base de datos no ha confirmado el borrado.' );
		}

		if ( null !== $fallo ) {
			$error_bd = (string) $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );

			error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- solo en un fallo, sin nombre ni CIF.
				sprintf( '[mdf-client-area] Eliminar farmacia %d: %s', $farmacia_id, substr( $fallo[0] . ( '' !== $error_bd ? ' | BD: ' . $error_bd : '' ), 0, 300 ) )
			);

			return new \WP_Error( 'mdf_ca_farmacia_error_bd', $fallo[1] . ' No se ha eliminado nada.' );
		}

		return array(
			'nombre' => $farmacia->get_nombre(),
			'cif'    => $farmacia->get_cif(),
		);
	}
}
