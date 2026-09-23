<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Casos de uso de negocio sobre items de catalogo: valida antes de
 * escribir, nunca deja un item a medias ni con un plan_id que ya no existe
 * en su lista de planes. Punto de entrada unico para el CRUD de backoffice
 * (Admin_Catalogo), mismo patron que Plan_Service y Farmacia_Service.
 */
class Catalogo_Service {

	private Catalogo_Repository $repository;
	private Plan_Repository $plan_repository;

	public function __construct( ?Catalogo_Repository $repository = null, ?Plan_Repository $plan_repository = null ) {
		$this->repository      = $repository ?? new Catalogo_Repository();
		$this->plan_repository = $plan_repository ?? new Plan_Repository();
	}

	/**
	 * @param int[] $plan_ids
	 * @return Catalogo_Item|\WP_Error
	 */
	public function crear( string $seccion, string $nombre, string $tipo, string $enlace_url, array $plan_ids ) {
		$validacion = $this->validar_campos( $seccion, $nombre, $tipo, $enlace_url, $plan_ids );

		if ( is_wp_error( $validacion ) ) {
			return $validacion;
		}

		$item = $this->repository->insert( $seccion, trim( $nombre ), $tipo, '' !== $enlace_url ? $enlace_url : null );

		if ( null === $item ) {
			global $wpdb;
			return new \WP_Error( 'mdf_ca_error_bd', sprintf( 'No se pudo crear el item de catalogo: %s', $wpdb->last_error ) );
		}

		$this->repository->set_planes( $item->get_id(), $plan_ids );

		return $this->repository->find_by_id( $item->get_id() );
	}

	/**
	 * @param int[] $plan_ids
	 * @return Catalogo_Item|\WP_Error
	 */
	public function actualizar( int $id, string $seccion, string $nombre, string $tipo, string $enlace_url, array $plan_ids ) {
		if ( ! $this->repository->find_by_id( $id ) ) {
			return new \WP_Error( 'mdf_ca_catalogo_no_existe', 'El item de catalogo que intentas editar ya no existe.' );
		}

		$validacion = $this->validar_campos( $seccion, $nombre, $tipo, $enlace_url, $plan_ids );

		if ( is_wp_error( $validacion ) ) {
			return $validacion;
		}

		if ( ! $this->repository->update( $id, $seccion, trim( $nombre ), $tipo, '' !== $enlace_url ? $enlace_url : null ) ) {
			return new \WP_Error( 'mdf_ca_error_bd', 'No se pudo actualizar el item de catalogo.' );
		}

		$this->repository->set_planes( $id, $plan_ids );

		return $this->repository->find_by_id( $id );
	}

	/**
	 * A diferencia de Plan_Service::eliminar(), borrar un item de catalogo
	 * no tiene ninguna condicion que lo bloquee: el item es el lado "muchos"
	 * de la relacion con planes (borrar un plan puede dejar huerfanas
	 * muchas farmacias/items; borrar un item solo afecta a sus propias
	 * filas de la tabla puente, que se limpian aqui mismo, ver
	 * Catalogo_Repository::delete()). No hay ninguna otra entidad que
	 * referencie un catalogo_id.
	 *
	 * @return true|\WP_Error
	 */
	public function eliminar( int $id ) {
		if ( ! $this->repository->find_by_id( $id ) ) {
			return new \WP_Error( 'mdf_ca_catalogo_no_existe', 'El item de catalogo que intentas eliminar ya no existe.' );
		}

		if ( ! $this->repository->delete( $id ) ) {
			return new \WP_Error( 'mdf_ca_error_bd', 'No se pudo eliminar el item de catalogo.' );
		}

		return true;
	}

	/**
	 * @param int[] $plan_ids
	 * @return true|\WP_Error
	 */
	private function validar_campos( string $seccion, string $nombre, string $tipo, string $enlace_url, array $plan_ids ) {
		if ( ! Catalogo_Secciones::es_valida( $seccion ) ) {
			return new \WP_Error( 'mdf_ca_catalogo_seccion_invalida', 'Selecciona una seccion valida.' );
		}

		if ( '' === trim( $nombre ) ) {
			return new \WP_Error( 'mdf_ca_catalogo_nombre_vacio', 'El nombre del item no puede estar vacio.' );
		}

		if ( ! Catalogo_Tipos::es_valido( $tipo ) ) {
			return new \WP_Error( 'mdf_ca_catalogo_tipo_invalido', 'Selecciona un tipo valido.' );
		}

		if ( '' !== $enlace_url && ! self::es_url_valida( $enlace_url ) ) {
			return new \WP_Error( 'mdf_ca_catalogo_enlace_invalido', 'El enlace no es una URL valida (debe empezar por http:// o https://).' );
		}

		foreach ( $plan_ids as $plan_id ) {
			if ( ! $this->plan_repository->find_by_id( (int) $plan_id ) ) {
				return new \WP_Error( 'mdf_ca_catalogo_plan_invalido', 'Uno de los planes seleccionados ya no existe.' );
			}
		}

		return true;
	}

	/**
	 * Validacion de formato, no de alcanzabilidad: wp_http_validate_url()
	 * (descartado aqui a proposito) hace una resolucion DNS real del host y
	 * rechaza rangos de IP privados -- correcto para el caso que protege
	 * (WordPress a punto de hacer una peticion HTTP saliente el mismo,
	 * riesgo de SSRF), pero no aplica aqui: este enlace nunca lo pide el
	 * servidor, solo se guarda para pintarlo como <a href> en el front. Con
	 * wp_http_validate_url() cualquier URL real cuyo DNS no resuelva en ese
	 * instante desde el servidor (mantenimiento, entorno sin salida a
	 * internet, etc.) se rechazaria sin motivo -- se detecto probando en
	 * local con una URL de ejemplo perfectamente valida.
	 */
	private static function es_url_valida( string $url ): bool {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		return in_array( wp_parse_url( $url, PHP_URL_SCHEME ), array( 'http', 'https' ), true );
	}
}
