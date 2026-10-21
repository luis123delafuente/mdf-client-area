<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crear, renombrar y borrar categorias y etiquetas del catalogo. Las reglas:
 * nombre obligatorio, de 100 caracteres como mucho y sin repetir (sin
 * distinguir mayusculas ni tildes); el slug se fija al crear y no cambia;
 * una categoria en uso no se borra; una etiqueta en uso si, y se quita de
 * las herramientas que la llevan (la pantalla pide confirmarlo antes).
 */
class Catalogo_Termino_Service {

	public const MAX_NOMBRE = 100;

	private Catalogo_Termino_Repository $repository;

	public function __construct( Catalogo_Termino_Repository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * @return Catalogo_Termino|\WP_Error
	 */
	public function crear( string $nombre ) {
		$nombre     = trim( $nombre );
		$validacion = $this->validar_nombre( $nombre, 0 );

		if ( is_wp_error( $validacion ) ) {
			return $validacion;
		}

		$termino = $this->repository->insert( $nombre, $this->repository->slug_libre( $nombre ) );

		// El UNIQUE de nombre y slug tambien frena dos altas iguales a la vez.
		return $termino ?? new \WP_Error( 'mdf_ca_termino_error_bd', 'No se pudo guardar. Puede que ya exista con ese nombre.' );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function renombrar( int $id, string $nombre ) {
		if ( ! $this->repository->find_by_id( $id ) ) {
			return new \WP_Error( 'mdf_ca_termino_no_existe', 'Ya no existe.' );
		}

		$nombre     = trim( $nombre );
		$validacion = $this->validar_nombre( $nombre, $id );

		if ( is_wp_error( $validacion ) ) {
			return $validacion;
		}

		return $this->repository->renombrar( $id, $nombre )
			? true
			: new \WP_Error( 'mdf_ca_termino_error_bd', 'No se pudo guardar. Puede que ya exista con ese nombre.' );
	}

	/**
	 * @return true|\WP_Error
	 */
	public function eliminar( int $id ) {
		if ( ! $this->repository->find_by_id( $id ) ) {
			return new \WP_Error( 'mdf_ca_termino_no_existe', 'Ya no existe.' );
		}

		if ( $this->repository->es_categorias() ) {
			$en_uso = $this->repository->count_herramientas( $id );

			if ( $en_uso > 0 ) {
				return new \WP_Error(
					'mdf_ca_categoria_en_uso',
					sprintf( 'No se puede borrar: la usan %d herramienta(s). Cámbiales antes la categoría.', $en_uso )
				);
			}
		}

		return $this->repository->delete( $id )
			? true
			: new \WP_Error( 'mdf_ca_termino_error_bd', 'No se pudo borrar. Si es una categoría, puede que alguien la haya asignado a una herramienta a la vez.' );
	}

	/**
	 * @return true|\WP_Error
	 */
	private function validar_nombre( string $nombre, int $id ) {
		if ( '' === $nombre ) {
			return new \WP_Error( 'mdf_ca_termino_nombre_vacio', 'El nombre no puede estar vacío.' );
		}

		if ( mb_strlen( $nombre ) > self::MAX_NOMBRE ) {
			return new \WP_Error( 'mdf_ca_termino_nombre_largo', sprintf( 'El nombre no puede tener más de %d caracteres.', self::MAX_NOMBRE ) );
		}

		if ( $this->repository->existe_nombre( $nombre, $id ) ) {
			return new \WP_Error( 'mdf_ca_termino_nombre_repetido', 'Ya existe otra con ese nombre.' );
		}

		return true;
	}
}
