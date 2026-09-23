<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Casos de uso de negocio sobre planes: valida antes de escribir, nunca deja
 * un registro a medias ni un estado inconsistente con farmacias. Punto de
 * entrada unico para el CRUD de backoffice (Admin_Planes), mismo patron que
 * Farmacia_Service.
 */
class Plan_Service {

	private Plan_Repository $repository;
	private Farmacia_Repository $farmacia_repository;
	private Catalogo_Repository $catalogo_repository;

	public function __construct(
		?Plan_Repository $repository = null,
		?Farmacia_Repository $farmacia_repository = null,
		?Catalogo_Repository $catalogo_repository = null
	) {
		$this->repository          = $repository ?? new Plan_Repository();
		$this->farmacia_repository = $farmacia_repository ?? new Farmacia_Repository();
		$this->catalogo_repository = $catalogo_repository ?? new Catalogo_Repository();
	}

	/**
	 * @return Plan|\WP_Error
	 */
	public function crear( string $nombre ) {
		$nombre = trim( $nombre );

		if ( '' === $nombre ) {
			return new \WP_Error( 'mdf_ca_plan_nombre_vacio', 'El nombre del plan no puede estar vacio.' );
		}

		$slug = sanitize_title( $nombre );

		if ( '' === $slug ) {
			return new \WP_Error( 'mdf_ca_plan_nombre_invalido', 'El nombre del plan debe contener al menos un caracter alfanumerico.' );
		}

		if ( $this->repository->find_by_nombre( $nombre ) ) {
			return new \WP_Error( 'mdf_ca_plan_nombre_duplicado', sprintf( 'Ya existe un plan llamado "%s".', $nombre ) );
		}

		// Colision de slug con nombre distinto (p. ej. "Basico" y "Básico!"
		// generan el mismo slug): el nombre ya paso la comprobacion de
		// arriba, asi que esto es un caso aparte, no el mismo error.
		if ( $this->repository->find_by_slug( $slug ) ) {
			return new \WP_Error( 'mdf_ca_plan_slug_duplicado', 'Ya existe un plan con un nombre demasiado parecido. Usa un nombre mas distintivo.' );
		}

		$plan = $this->repository->insert( $nombre, $slug );

		if ( null === $plan ) {
			global $wpdb;
			return new \WP_Error( 'mdf_ca_error_bd', sprintf( 'No se pudo crear el plan: %s', $wpdb->last_error ) );
		}

		return $plan;
	}

	/**
	 * Solo renombra: el slug no cambia (ver Plan). El nombre nuevo pasa por
	 * la misma comprobacion de duplicados que crear(), excluyendo el propio
	 * plan que se esta editando.
	 *
	 * @return Plan|\WP_Error
	 */
	public function actualizar( int $id, string $nombre ) {
		$actual = $this->repository->find_by_id( $id );

		if ( ! $actual ) {
			return new \WP_Error( 'mdf_ca_plan_no_existe', 'El plan que intentas editar ya no existe.' );
		}

		$nombre = trim( $nombre );

		if ( '' === $nombre ) {
			return new \WP_Error( 'mdf_ca_plan_nombre_vacio', 'El nombre del plan no puede estar vacio.' );
		}

		$duplicado = $this->repository->find_by_nombre( $nombre );

		if ( $duplicado && $duplicado->get_id() !== $id ) {
			return new \WP_Error( 'mdf_ca_plan_nombre_duplicado', sprintf( 'Ya existe un plan llamado "%s".', $nombre ) );
		}

		if ( ! $this->repository->update( $id, $nombre ) ) {
			return new \WP_Error( 'mdf_ca_error_bd', 'No se pudo actualizar el plan.' );
		}

		return $this->repository->find_by_id( $id );
	}

	/**
	 * Borrar un plan todavia en uso -- por una farmacia o por un item de
	 * catalogo -- se rechaza en vez de permitirse: sin FK real que lo
	 * impida a nivel de esquema (ver DB_Schema), dejarlo pasar dejaria
	 * farmacias con un plan_id huerfano o items de catalogo con una fila
	 * puente colgando, y Permissions ya trata "sin plan resoluble" igual
	 * que "sin plan asignado" -- esas farmacias perderian en silencio el
	 * acceso al catalogo que su plan les daba, sin que nadie lo decidiera a
	 * proposito. El administrador tiene que reasignar esas farmacias (o
	 * desmarcar el plan de esos items) de forma explicita antes de poder
	 * eliminarlo.
	 *
	 * Nota que esto es asimetrico con Catalogo_Service::eliminar(), que no
	 * bloquea nada al borrar un item: un item es el lado "muchos" de la
	 * relacion, borrarlo solo afecta a sus propias filas puente, nunca deja
	 * huerfano a un plan (los planes no dependen de que exista ningun item
	 * en concreto).
	 *
	 * @return true|\WP_Error
	 */
	public function eliminar( int $id ) {
		$plan = $this->repository->find_by_id( $id );

		if ( ! $plan ) {
			return new \WP_Error( 'mdf_ca_plan_no_existe', 'El plan que intentas eliminar ya no existe.' );
		}

		$farmacias_con_plan = $this->farmacia_repository->count_by_plan_id( $id );

		if ( $farmacias_con_plan > 0 ) {
			return new \WP_Error(
				'mdf_ca_plan_en_uso',
				sprintf(
					'No se puede eliminar el plan "%s": %d farmacia(s) lo tienen asignado. Reasigna esas farmacias a otro plan antes de eliminarlo.',
					$plan->get_nombre(),
					$farmacias_con_plan
				)
			);
		}

		$items_con_plan = $this->catalogo_repository->count_items_con_plan( $id );

		if ( $items_con_plan > 0 ) {
			return new \WP_Error(
				'mdf_ca_plan_en_uso',
				sprintf(
					'No se puede eliminar el plan "%s": %d item(s) de catalogo lo tienen asignado. Desmarca ese plan en esos items antes de eliminarlo.',
					$plan->get_nombre(),
					$items_con_plan
				)
			);
		}

		if ( ! $this->repository->delete( $id ) ) {
			return new \WP_Error( 'mdf_ca_error_bd', 'No se pudo eliminar el plan.' );
		}

		return true;
	}
}
