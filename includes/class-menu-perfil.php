<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu de perfil fijo arriba a la derecha en las paginas del area de
 * clientes (get_ids_protegidos()): un boton «Mi cuenta» que despliega el
 * nombre de la farmacia, su usuario (el CIF) y el boton de cerrar sesion. Hasta ahora solo el administrador podia cerrar sesion (desde la
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

		$ids = Area_Privada_Pages::get_ids_protegidos();

		return $ids && is_page( $ids );
	}

	public static function enqueue_assets(): void {
		if ( ! self::debe_mostrarse() ) {
			return;
		}

		wp_enqueue_style( self::HANDLE, Recursos::url( 'assets/css/menu-perfil.css' ), array(), Recursos::version( 'assets/css/menu-perfil.css' ) );
		wp_enqueue_script( self::HANDLE, Recursos::url( 'assets/js/menu-perfil.js' ), array(), Recursos::version( 'assets/js/menu-perfil.js' ), true );
	}

	public static function render(): void {
		if ( ! self::debe_mostrarse() ) {
			return;
		}

		$usuario    = wp_get_current_user();
		$logout_url = wp_logout_url( wp_login_url() );

		// v0.40.0: el panel nombra a la farmacia (su propia, resuelta por el
		// usuario en sesion; nunca otra) y debajo su usuario, que es su CIF/NIF.
		// Sin farmacia vinculada, solo el usuario.
		$farmacia = ( new Farmacia_Repository() )->find_by_wp_user_id( $usuario->ID );
		$nombre   = $farmacia ? trim( $farmacia->get_nombre() ) : '';
		?>
		<div class="mdf-ca-menu-perfil" data-mdf-ca-menu-perfil>
			<button type="button" class="mdf-ca-menu-perfil__toggle" aria-haspopup="true" aria-expanded="false" aria-controls="mdf-ca-menu-perfil-panel">
				<span class="mdf-ca-menu-perfil__avatar" aria-hidden="true"><?php self::icono( 'usuario' ); ?></span>
				<span class="mdf-ca-menu-perfil__texto"><?php esc_html_e( 'Mi cuenta', 'mdf-client-area' ); ?></span>
				<span class="mdf-ca-menu-perfil__flecha" aria-hidden="true"><?php self::icono( 'flecha' ); ?></span>
			</button>
			<div class="mdf-ca-menu-perfil__panel" id="mdf-ca-menu-perfil-panel" hidden>
				<div class="mdf-ca-menu-perfil__cabecera">
					<p class="mdf-ca-menu-perfil__etiqueta"><?php esc_html_e( 'Has accedido como', 'mdf-client-area' ); ?></p>
					<?php if ( '' !== $nombre ) : ?>
						<p class="mdf-ca-menu-perfil__nombre"><?php echo esc_html( $nombre ); ?></p>
					<?php endif; ?>
					<?php if ( '' === $nombre || 0 !== strcasecmp( $nombre, $usuario->user_login ) ) : ?>
						<p class="mdf-ca-menu-perfil__usuario"><?php echo esc_html( $usuario->user_login ); ?></p>
					<?php endif; ?>
				</div>
				<a class="mdf-ca-menu-perfil__logout" href="<?php echo esc_url( $logout_url ); ?>">
					<?php self::icono( 'salir' ); ?>
					<span><?php esc_html_e( 'Cerrar sesión', 'mdf-client-area' ); ?></span>
				</a>
			</div>
		</div>
		<?php
	}

	/** Iconos de Tabler Icons 3.19.0 (MIT), los mismos de las paginas del area. */
	private static function icono( string $nombre ): void {
		$trazos = array(
			'usuario' => '<path d="M8 7a4 4 0 1 0 8 0a4 4 0 0 0 -8 0"/><path d="M6 21v-2a4 4 0 0 1 4 -4h4a4 4 0 0 1 4 4v2"/>',
			'flecha'  => '<path d="M6 9l6 6l6 -6"/>',
			'salir'   => '<path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"/><path d="M9 12h12l-3 -3"/><path d="M18 15l3 -3"/>',
		);

		if ( ! isset( $trazos[ $nombre ] ) ) {
			return;
		}

		echo '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
			. $trazos[ $nombre ] // Constante de esta clase, sin datos externos.
			. '</svg>';
	}
}
