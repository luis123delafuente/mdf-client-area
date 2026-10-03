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
	 * Usuario tecnico del robot que envia facturas (Fase 4, #303). Una unica
	 * capacidad propia, sin 'read' ni nada de WordPress: solo puede usar la
	 * ruta REST de recepcion (Recepcion_Autenticacion). No tiene acceso
	 * interactivo: ni wp-admin ni login con contrasena.
	 */
	public const ROLE_ROBOT          = 'mdf_robot';
	public const CAP_SUBIR_FACTURAS  = 'mdf_ca_subir_facturas';

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

		self::register_robot();
	}

	/**
	 * Aparte de register() para que maybe_upgrade() pueda dar de alta solo
	 * el rol nuevo en una instalacion ya activa, sin tocar mdf_cliente.
	 * Idempotente igual que register().
	 */
	public static function register_robot(): void {
		remove_role( self::ROLE_ROBOT );
		add_role( self::ROLE_ROBOT, 'Robot MDF', array( self::CAP_SUBIR_FACTURAS => true ) );
	}

	public static function user_is_robot( \WP_User $user ): bool {
		return in_array( self::ROLE_ROBOT, (array) $user->roles, true );
	}

	public static function user_is_cliente( \WP_User $user ): bool {
		return in_array( self::ROLE_CLIENTE, (array) $user->roles, true );
	}

	public static function current_user_is_cliente(): bool {
		return self::user_is_cliente( wp_get_current_user() );
	}
}
