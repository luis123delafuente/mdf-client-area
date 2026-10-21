<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Roles y capacidades del plugin. Unico sitio que las define: las pantallas
 * y acciones usan estas constantes (o Admin_Documentos::CAPACIDAD y
 * Admin_Farmacias::CAPACIDAD, que apuntan aqui), nunca un literal.
 *
 * - mdf_cliente: la farmacia. Ninguna capacidad: ni siquiera entra en
 *   wp-admin (Role_Restrictions).
 * - mdf_gestor, "Gestion MDF" (#337): el personal de MDF. Solo 'read' de
 *   WordPress (wp-admin y su Perfil) y las capacidades propias del plugin
 *   de abajo. Sin manage_options, upload_files, edit_posts, list_users,
 *   create_users ni promote_users: WordPress le niega cualquier otra
 *   pantalla por capacidad, no solo por ocultar el menu (Admin_Inicio).
 * - administrator: todas las capacidades del plugin, ademas de las suyas.
 */
class Roles {

	public const ROLE_CLIENTE = 'mdf_cliente';
	public const ROLE_GESTOR  = 'mdf_gestor';

	/** Portada "Inicio" del plugin y la vista simplificada del backoffice (Admin_Inicio). */
	public const CAP_BACKOFFICE = 'mdf_ca_backoffice';

	/**
	 * Documentos MDF: subida manual, Subir facturas, Bandeja de facturas,
	 * Pendientes de publicar (publicar, despublicar, deshacer lote), eliminar,
	 * Documentos publicados e historial, y la vista previa de administracion.
	 */
	public const CAP_GESTIONAR_DOCUMENTOS = 'mdf_ca_gestionar_documentos';

	/**
	 * Generador del Modelo 347 (#307): subir el Excel de SAGE, generar,
	 * publicar y enviar las cartas, y sus ajustes.
	 */
	public const CAP_GESTIONAR_347 = 'mdf_ca_gestionar_347';

	/** Farmacias MDF: listado y alta manual, importador CSV e invitaciones. */
	public const CAP_GESTIONAR_FARMACIAS = 'mdf_ca_gestionar_farmacias';

	/**
	 * "Catalogo y planes": Planes MDF y Herramientas MDF (crear, editar, borrar y
	 * asignar planes a los elementos del catalogo). Lo que ve cada farmacia lo
	 * sigue decidiendo Permissions con el plan de la farmacia.
	 */
	public const CAP_GESTIONAR_CATALOGO = 'mdf_ca_gestionar_catalogo';

	/**
	 * Lo que sigue siendo solo del administrador de WordPress: Avisos por
	 * email, proteccion de la carpeta privada, requisitos del servidor y el
	 * aviso del robot retirado.
	 */
	public const CAP_ADMINISTRAR = 'manage_options';

	/** Capacidades del plugin que reciben el gestor y el administrador. */
	public const CAPACIDADES_PLUGIN = array(
		self::CAP_BACKOFFICE,
		self::CAP_GESTIONAR_DOCUMENTOS,
		self::CAP_GESTIONAR_347,
		self::CAP_GESTIONAR_FARMACIAS,
		self::CAP_GESTIONAR_CATALOGO,
	);

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

		self::registrar_gestor();
	}

	/**
	 * Rol "Gestion MDF" con 'read' y las capacidades del plugin, nada mas.
	 * Idempotente como register(): los usuarios que ya lo tienen lo
	 * conservan (su usermeta guarda el nombre del rol, no las capacidades).
	 */
	public static function registrar_gestor(): void {
		remove_role( self::ROLE_GESTOR );
		add_role( self::ROLE_GESTOR, 'Gestión MDF', array_fill_keys( array_merge( array( 'read' ), self::CAPACIDADES_PLUGIN ), true ) );
	}

	/** Idempotente: add_cap() sobre un rol que ya la tiene no cambia nada. */
	public static function conceder_capacidades_administrador(): void {
		$administrador = get_role( 'administrator' );

		if ( $administrador ) {
			foreach ( self::CAPACIDADES_PLUGIN as $capacidad ) {
				$administrador->add_cap( $capacidad );
			}
		}
	}

	/** Solo la del 347 (#307); la migracion mdf_ca_cap_347_v1 sigue llamandola. */
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
