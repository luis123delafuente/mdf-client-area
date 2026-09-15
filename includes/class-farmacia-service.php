<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Casos de uso de negocio sobre farmacias. Punto de entrada unico para dar
 * de alta una farmacia: valida antes de escribir, nunca deja un registro a
 * medias. Pensado para invocarse desde WP-CLI o un script de prueba; sin UI
 * de backoffice todavia.
 */
class Farmacia_Service {

	private Farmacia_Repository $repository;

	public function __construct( ?Farmacia_Repository $repository = null ) {
		$this->repository = $repository ?? new Farmacia_Repository();
	}

	/**
	 * Da de alta una farmacia vinculada a un usuario de WordPress existente.
	 *
	 * @return Farmacia|\WP_Error
	 */
	public function crear( string $cif, string $nombre, int $wp_user_id ) {
		$cif    = strtoupper( trim( $cif ) );
		$nombre = trim( $nombre );

		if ( ! CIF_Validator::is_valid( $cif ) ) {
			return new \WP_Error(
				'mdf_ca_cif_invalido',
				sprintf( 'El CIF "%s" no supera la validacion del digito de control.', $cif )
			);
		}

		if ( '' === $nombre ) {
			return new \WP_Error(
				'mdf_ca_nombre_vacio',
				'El nombre de la farmacia no puede estar vacio.'
			);
		}

		if ( ! get_userdata( $wp_user_id ) ) {
			return new \WP_Error(
				'mdf_ca_usuario_no_existe',
				sprintf( 'No existe ningun usuario de WordPress con ID %d.', $wp_user_id )
			);
		}

		if ( $this->repository->find_by_cif( $cif ) ) {
			return new \WP_Error(
				'mdf_ca_cif_duplicado',
				sprintf( 'Ya existe una farmacia registrada con el CIF "%s".', $cif )
			);
		}

		$farmacia = $this->repository->insert( $cif, $nombre, $wp_user_id );

		if ( null === $farmacia ) {
			global $wpdb;
			return new \WP_Error(
				'mdf_ca_error_bd',
				sprintf( 'No se pudo crear la farmacia: %s', $wpdb->last_error )
			);
		}

		return $farmacia;
	}
}
