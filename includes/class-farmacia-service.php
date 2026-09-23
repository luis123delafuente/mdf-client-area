<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Casos de uso de negocio sobre farmacias. Punto de entrada unico para dar
 * de alta una farmacia: valida antes de escribir, nunca deja un registro a
 * medias. Consumido por Admin_Farmacias (#250, alta minima para el piloto
 * de Fase 3) y reutilizable igual desde WP-CLI o un script -- el alta
 * manual COMPLETA como formulario que alimenta esta misma logica (el que
 * ademas alimentaria el futuro importador CSV) sigue siendo Fase 4, ver
 * CLAUDE.md: Admin_Farmacias no la sustituye, solo desbloquea el piloto.
 */
class Farmacia_Service {

	private Farmacia_Repository $repository;
	private Plan_Repository $plan_repository;

	public function __construct( ?Farmacia_Repository $repository = null, ?Plan_Repository $plan_repository = null ) {
		$this->repository      = $repository ?? new Farmacia_Repository();
		$this->plan_repository = $plan_repository ?? new Plan_Repository();
	}

	/**
	 * Da de alta una farmacia. wp_user_id es opcional desde #249: antes
	 * era obligatorio pasar un usuario de WordPress ya existente, pero el
	 * flujo de invitacion por token (Invitacion_Service) es ahora quien
	 * crea ese usuario y lo vincula a posteriori via
	 * Farmacia_Repository::update_wp_user_id() -- exactamente el escenario
	 * que el modelo Farmacia ya preveia en su propio comentario ("el
	 * modelo deja espacio para farmacias sin usuario asociado todavia").
	 * Si se pasa un wp_user_id, se sigue validando que exista de verdad,
	 * igual que antes.
	 *
	 * plan_id es opcional desde #250 (alta minima de farmacias piloto,
	 * Admin_Farmacias): si se indica, se valida que el plan exista ANTES
	 * de insertar nada -- "nunca deja un registro a medias" tambien cubre
	 * no crear una farmacia con un plan_id que luego resulte invalido.
	 *
	 * $cif acepta tanto CIF de sociedad como NIF de persona fisica desde
	 * #251 (Identificador_Fiscal_Validator detecta cual de los dos es):
	 * el parametro y la columna de BD conservan el nombre "cif" a
	 * proposito -- sigue siendo el identificador fiscal unico de la
	 * farmacia, cambiar el nombre solo por admitir un formato mas habria
	 * exigido una migracion de esquema que esta tarea no necesita.
	 *
	 * @return Farmacia|\WP_Error
	 */
	public function crear( string $cif, string $nombre, ?int $wp_user_id = null, ?int $plan_id = null ) {
		$cif    = strtoupper( trim( $cif ) );
		$nombre = trim( $nombre );

		if ( ! Identificador_Fiscal_Validator::is_valid( $cif ) ) {
			return new \WP_Error(
				'mdf_ca_identificador_invalido',
				sprintf( 'El CIF/NIF "%s" no supera la validacion del digito de control.', $cif )
			);
		}

		if ( '' === $nombre ) {
			return new \WP_Error(
				'mdf_ca_nombre_vacio',
				'El nombre de la farmacia no puede estar vacio.'
			);
		}

		if ( null !== $wp_user_id && ! get_userdata( $wp_user_id ) ) {
			return new \WP_Error(
				'mdf_ca_usuario_no_existe',
				sprintf( 'No existe ningun usuario de WordPress con ID %d.', $wp_user_id )
			);
		}

		if ( null !== $plan_id && ! $this->plan_repository->find_by_id( $plan_id ) ) {
			return new \WP_Error(
				'mdf_ca_plan_no_existe',
				'El plan seleccionado no existe.'
			);
		}

		if ( $this->repository->find_by_cif( $cif ) ) {
			return new \WP_Error(
				'mdf_ca_identificador_duplicado',
				sprintf( 'Ya existe una farmacia registrada con el CIF/NIF "%s".', $cif )
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

		if ( null !== $plan_id ) {
			if ( ! $this->repository->update_plan( $farmacia->get_id(), $plan_id ) ) {
				return new \WP_Error(
					'mdf_ca_error_bd',
					'La farmacia se creo correctamente, pero no se pudo asignar el plan. Asignalo despues desde Planes MDF.'
				);
			}

			$farmacia = $this->repository->find_by_id( $farmacia->get_id() );
		}

		return $farmacia;
	}
}
