<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rol de cliente sin ninguna capacidad de backend. Sin el filtro de
 * asignacion (fase 3, invitacion por token) esta clase solo define y
 * consulta el rol; no decide quien lo recibe.
 */
class Roles {

	public const ROLE_CLIENTE = 'mdf_cliente';

	/**
	 * Generador del Modelo 347 (#307): subir el Excel de SAGE, generar,
	 * publicar y enviar las cartas, y sus ajustes. Capacidad propia (no
	 * manage_options) para poder darla mas adelante a una cuenta individual de
	 * Administracion sin darle todo el backoffice. Hoy solo la tiene el rol
	 * administrator (conceder_gestion_347()).
	 */
	public const CAP_GESTIONAR_347 = 'mdf_ca_gestionar_347';

	/**
	 * Registra el rol con las capacidades exactas definidas aqui.
	 *
	 * add_role() no hace nada si el rol ya existe (no lo actualiza ni lo
	 * duplica), asi que primero se elimina y se vuelve a crear: garantiza
	 * que activar/desactivar/reactivar el plugin deja siempre las mismas
	 * capacidades, incluso si una version anterior del codigo dejo otras.
	 */
	public static function register(): void {
		remove_role( self::ROLE_CLIENTE );
		add_role( self::ROLE_CLIENTE, 'Cliente MDF', array() );
	}

	/** Idempotente: add_cap() sobre un rol que ya la tiene no cambia nada. */
	public static function conceder_gestion_347(): void {
		$administrador = get_role( 'administrator' );

		if ( $administrador ) {
			$administrador->add_cap( self::CAP_GESTIONAR_347 );
		}
	}

	public static function user_is_cliente( \WP_User $user ): bool {
		return in_array( self::ROLE_CLIENTE, (array) $user->roles, true );
	}

	public static function current_user_is_cliente(): bool {
		return self::user_is_cliente( wp_get_current_user() );
	}
}
