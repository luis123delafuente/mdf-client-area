<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Retirada del robot de recepcion (#336). Desde "Subir facturas" (#334) y la
 * Bandeja de facturas (#335) ninguna factura entra por la ruta REST, asi que
 * el robot, su rol y su ruta se han quitado del codigo. Esta clase limpia lo
 * que quedaba en la base de datos de las instalaciones donde existio
 * (Activator::migrar(), marca mdf_ca_robot_retirado_v1):
 *
 * - revoca las contrasenas de aplicacion de todo usuario con el rol del
 *   robot y del usuario "mdf-robot" (aunque esten desactivadas en todo el
 *   sitio por Contrasenas_Aplicacion, no deben quedar guardadas);
 * - borra el usuario solo si lo unico que tiene es ese rol. Si tiene otro
 *   rol o alguna capacidad suelta, no se toca y se avisa en el backoffice
 *   (usuarios_conservados());
 * - quita el rol y borra las options del interruptor, el tope diario y la
 *   aprobacion previa de la recepcion.
 *
 * Idempotente: una segunda ejecucion no encuentra nada que hacer. Solo usa
 * la API de usuarios y de options de WordPress (la busqueda por rol es un
 * LIKE sobre la usermeta de capacidades), igual en MySQL y en MariaDB.
 *
 * Lo historico se conserva: los eventos con origen "recepcion" y las filas
 * del registro con origen "rest" se siguen mostrando ("por el robot,
 * retirado"). El usuario borrado deja sus ids en esas tablas, como cualquier
 * usuario borrado.
 */
class Robot_Retirado {

	/** El rol y el usuario que existieron (#303). Solo para encontrarlos y borrarlos. */
	public const ROL   = 'mdf_robot';
	public const LOGIN = 'mdf-robot';

	/** Options de la recepcion REST que ya no se usan. */
	private const OPTIONS_OBSOLETAS = array(
		'mdf_ca_recepcion_activa',
		'mdf_ca_recepcion_tope_diario',
		'mdf_ca_recepcion_requiere_aprobacion',
		// Marca de la migracion que daba de alta el rol (#303).
		'mdf_ca_roles_v2',
	);

	/** Ids de los usuarios que tenian el rol y no se han borrado (array de int). */
	private const OPTION_CONSERVADOS = 'mdf_ca_robot_conservados';

	private const ACCION_OCULTAR = 'mdf_ca_robot_ocultar_aviso';

	public static function register_hooks(): void {
		add_action( 'admin_notices', array( __CLASS__, 'avisar_conservados' ) );
		add_action( 'admin_post_' . self::ACCION_OCULTAR, array( __CLASS__, 'gestionar_ocultar' ) );
	}

	/**
	 * @return array{borrados: int, conservados: int[], contrasenas_revocadas: int}
	 */
	public static function ejecutar(): array {
		require_once ABSPATH . 'wp-admin/includes/user.php';

		$resultado = array(
			'borrados'              => 0,
			'conservados'           => array(),
			'contrasenas_revocadas' => 0,
		);

		foreach ( self::candidatos() as $usuario ) {
			$resultado['contrasenas_revocadas'] += self::revocar_contrasenas( $usuario->ID );

			if ( self::solo_tiene_el_rol( $usuario ) ) {
				// Sin reasignar: el robot no es autor de ninguna entrada.
				if ( wp_delete_user( $usuario->ID ) ) {
					++$resultado['borrados'];
				}
			} elseif ( isset( $usuario->caps[ self::ROL ] ) ) {
				$resultado['conservados'][] = $usuario->ID;
			}
		}

		if ( $resultado['conservados'] ) {
			$previos = array_map( 'intval', (array) get_option( self::OPTION_CONSERVADOS, array() ) );
			update_option( self::OPTION_CONSERVADOS, array_values( array_unique( array_merge( $previos, $resultado['conservados'] ) ) ), false );
		}

		remove_role( self::ROL );

		foreach ( self::OPTIONS_OBSOLETAS as $option ) {
			delete_option( $option );
		}

		return $resultado;
	}

	/**
	 * Usuarios con el rol del robot y el usuario "mdf-robot". La busqueda por
	 * rol no depende de que el rol siga registrado.
	 *
	 * @return \WP_User[]
	 */
	private static function candidatos(): array {
		$usuarios = array();

		foreach ( get_users( array( 'role' => self::ROL ) ) as $usuario ) {
			$usuarios[ $usuario->ID ] = $usuario;
		}

		$por_login = get_user_by( 'login', self::LOGIN );

		if ( $por_login ) {
			$usuarios[ $por_login->ID ] = $por_login;
		}

		return array_values( $usuarios );
	}

	/**
	 * Lo unico que tiene es el rol del robot. Se mira la usermeta de
	 * capacidades tal cual ($usuario->caps), no $usuario->roles, que deja
	 * fuera los roles que ya no estan registrados.
	 */
	private static function solo_tiene_el_rol( \WP_User $usuario ): bool {
		return array( self::ROL ) === array_keys( array_filter( (array) $usuario->caps ) );
	}

	/** Borra todas sus contrasenas de aplicacion; devuelve cuantas habia. */
	private static function revocar_contrasenas( int $user_id ): int {
		$guardadas = get_user_meta( $user_id, \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, true );
		$cuantas   = is_array( $guardadas ) ? count( $guardadas ) : 0;

		delete_user_meta( $user_id, \WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS );

		return $cuantas;
	}

	/**
	 * Usuarios conservados que siguen existiendo con el rol en su usermeta.
	 * Si ya no queda ninguno (se borraron a mano), la option desaparece.
	 *
	 * @return \WP_User[]
	 */
	public static function usuarios_conservados(): array {
		$ids = (array) get_option( self::OPTION_CONSERVADOS, array() );

		if ( ! $ids ) {
			return array();
		}

		$usuarios = array();

		foreach ( $ids as $id ) {
			$usuario = get_userdata( (int) $id );

			if ( $usuario && isset( $usuario->caps[ self::ROL ] ) ) {
				$usuarios[] = $usuario;
			}
		}

		if ( ! $usuarios ) {
			delete_option( self::OPTION_CONSERVADOS );
		}

		return $usuarios;
	}

	/** Aviso en todo el backoffice, solo para quien puede gestionar usuarios. */
	public static function avisar_conservados(): void {
		if ( ! current_user_can( Roles::CAP_ADMINISTRAR ) || ! current_user_can( 'delete_users' ) ) {
			return;
		}

		$usuarios = self::usuarios_conservados();

		if ( ! $usuarios ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p><strong>Robot de recepción retirado: hay usuarios que no se han borrado.</strong></p>
			<p>Estos usuarios tenían el rol del robot (<code><?php echo esc_html( self::ROL ); ?></code>) junto con otros permisos, así que no se han borrado. Se han revocado sus contraseñas de aplicación y el rol del robot ya no da ningún permiso. Revisa si deben seguir existiendo:</p>
			<ul style="list-style:disc;margin-left:2em">
				<?php foreach ( $usuarios as $usuario ) : ?>
					<li><a href="<?php echo esc_url( get_edit_user_link( $usuario->ID ) ); ?>"><?php echo esc_html( $usuario->user_login ); ?></a></li>
				<?php endforeach; ?>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACCION_OCULTAR ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACCION_OCULTAR ); ?>" />
				<p><button type="submit" class="button">Ya lo he revisado: ocultar este aviso</button></p>
			</form>
		</div>
		<?php
	}

	public static function gestionar_ocultar(): void {
		if ( ! current_user_can( Roles::CAP_ADMINISTRAR ) || ! current_user_can( 'delete_users' ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::ACCION_OCULTAR );
		delete_option( self::OPTION_CONSERVADOS );

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
