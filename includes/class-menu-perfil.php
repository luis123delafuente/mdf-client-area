<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu de perfil fijo arriba a la derecha en las cuatro paginas del area
 * de clientes: un boton con icono de perfil que despliega el nombre de
 * usuario (el CIF) y el boton de cerrar sesion. Hasta ahora solo el administrador podia cerrar sesion (desde la
 * barra de administracion o wp-admin); mdf_cliente tiene ambos ocultos o
 * bloqueados, asi que no tenia ninguna via.
 *
 * Solo se pinta para mdf_cliente y solo en las paginas de Area_Privada_Pages:
 * un administrador ya tiene la barra de administracion (con su propio
 * "Cerrar sesion") en esa misma esquina. Se engancha en wp_footer con
 * position: fixed, en vez de un shortcode, para no depender de que MKT lo
 * coloque en cada maqueta de Elementor. No es una comprobacion de
 * seguridad: la URL de logout la firma wp_logout_url() con nonce y la
 * proteccion de las paginas sigue en Role_Restrictions.
 */
class Menu_Perfil {

	private const HANDLE = 'mdf-ca-menu-perfil';

	public static function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render' ) );
	}

	private static function debe_mostrarse(): bool {
		if ( ! is_user_logged_in() || ! Roles::current_user_is_cliente() ) {
			return false;
		}

		$ids = array_values( Area_Privada_Pages::get_page_ids() );

		return $ids && is_page( $ids );
	}

	public static function enqueue_assets(): void {
		if ( ! self::debe_mostrarse() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, plugins_url( 'assets/css/menu-perfil.css', MDF_CA_PLUGIN_FILE ), array(), MDF_CA_VERSION );
		wp_enqueue_script( self::HANDLE, plugins_url( 'assets/js/menu-perfil.js', MDF_CA_PLUGIN_FILE ), array(), MDF_CA_VERSION, true );
	}

	public static function render(): void {
		if ( ! self::debe_mostrarse() ) {
			return;
		}

		$usuario    = wp_get_current_user();
		$logout_url = wp_logout_url( wp_login_url() );
		?>
		<div class="mdf-ca-menu-perfil" data-mdf-ca-menu-perfil>
			<button type="button" class="mdf-ca-menu-perfil__toggle" aria-haspopup="true" aria-expanded="false" aria-controls="mdf-ca-menu-perfil-panel" aria-label="<?php esc_attr_e( 'Menú de perfil', 'mdf-client-area' ); ?>">
				<svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4" fill="currentColor"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8z" fill="currentColor"/></svg>
			</button>
			<div class="mdf-ca-menu-perfil__panel" id="mdf-ca-menu-perfil-panel" hidden>
				<p class="mdf-ca-menu-perfil__etiqueta"><?php esc_html_e( 'Usuario', 'mdf-client-area' ); ?></p>
				<p class="mdf-ca-menu-perfil__usuario"><?php echo esc_html( $usuario->user_login ); ?></p>
				<a class="mdf-ca-menu-perfil__logout" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Cerrar sesión', 'mdf-client-area' ); ?></a>
			</div>
		</div>
		<?php
	}
}
