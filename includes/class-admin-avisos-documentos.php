<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pantalla "Documentos MDF > Avisos por email" (Fase 4). Mismo patron que el
 * resto del backoffice: capacidad Roles::CAP_ADMINISTRAR (solo el
 * administrador: es un interruptor de todo el sitio), admin-post.php con
 * nonce en cada accion, avisos por transient de usuario.
 *
 * Muestra el estado del interruptor, los pendientes y la proxima
 * ejecucion, y permite encender/apagar los avisos y lanzar un envio
 * ahora (que respeta el interruptor, el bloqueo y el limite diario por
 * farmacia: es la misma Aviso_Documentos_Service::ejecutar() que el cron).
 *
 * Fallos: tras "Enviar ahora", la lista de farmacias que fallaron con la
 * categoria y el detalle tecnico; y, entre los pendientes, las farmacias
 * cuyo ultimo aviso fallo (usermeta, tambien los del cron). La pantalla es
 * solo del administrador, asi que el detalle se muestra siempre.
 */
class Admin_Avisos_Documentos {

	private const MENU_SLUG         = 'mdf-ca-avisos-documentos';
	private const PARENT_SLUG       = 'mdf-ca-documentos';
	private const NONCE_INTERRUPTOR = 'mdf_ca_avisos_interruptor';
	private const NONCE_ENVIAR      = 'mdf_ca_avisos_enviar_ahora';
	private const NOTICE_KEY_PREFIX = 'mdf_ca_avisos_notice_';

	public static function register_hooks(): void {
		// Prioridad 11: el menu padre lo registra Admin_Documentos en la 10.
		add_action( 'admin_menu', array( __CLASS__, 'registrar_menu' ), 11 );
		add_action( 'admin_post_mdf_ca_avisos_interruptor', array( __CLASS__, 'gestionar_interruptor' ) );
		add_action( 'admin_post_mdf_ca_avisos_enviar_ahora', array( __CLASS__, 'gestionar_enviar_ahora' ) );
	}

	public static function registrar_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			'Avisos por email de documentos nuevos',
			'Avisos por email',
			Roles::CAP_ADMINISTRAR,
			self::MENU_SLUG,
			array( __CLASS__, 'render_pagina' )
		);
	}

	public static function render_pagina(): void {
		if ( ! current_user_can( Roles::CAP_ADMINISTRAR ) ) {
			wp_die( 'No tienes permiso para acceder a esta pagina.' );
		}

		$activo          = Aviso_Documentos_Service::esta_activo();
		$encendido       = '1' === get_option( Aviso_Documentos_Service::OPTION_ACTIVO, '0' );
		$forzado_apagado = Aviso_Documentos_Service::forzado_apagado_por_constante();
		$proxima         = wp_next_scheduled( Aviso_Documentos_Service::HOOK_CRON );
		$aviso           = self::consumir_aviso();

		// Pendientes agrupados por si la farmacia recibira aviso o no
		// (solo recuentos, sin nombres).
		$farmacia_repository = new Farmacia_Repository();
		$pendientes          = array(
			'documentos'          => 0,
			'farmacias_activas'   => 0,
			'farmacias_sin_cuenta' => 0,
		);
		$con_fallo           = array();

		foreach ( ( new Aviso_Documentos_Service() )->pendientes_por_farmacia() as $farmacia_id => $documentos ) {
			$pendientes['documentos'] += count( $documentos );
			$farmacia                  = $farmacia_repository->find_by_id( $farmacia_id );

			if ( $farmacia && 'activa' === Invitacion_Service::estado_cuenta( $farmacia )['estado'] ) {
				++$pendientes['farmacias_activas'];

				$fallo = Aviso_Documentos_Service::ultimo_fallo( $farmacia );

				if ( null !== $fallo ) {
					$con_fallo[] = array(
						'farmacia'   => $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')',
						'documentos' => count( $documentos ),
						'fecha'      => $fallo['fecha'],
						'categoria'  => Correo::texto_categoria( $fallo['categoria'] ),
						'detalle'    => $fallo['detalle'],
					);
				}
			} else {
				++$pendientes['farmacias_sin_cuenta'];
			}
		}

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-avisos-documentos.php';
	}

	public static function gestionar_interruptor(): void {
		if ( ! current_user_can( Roles::CAP_ADMINISTRAR ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_INTERRUPTOR );

		$activar = isset( $_POST['activar'] ) && '1' === $_POST['activar'];
		Aviso_Documentos_Service::set_activo( $activar );

		if ( $activar && Aviso_Documentos_Service::forzado_apagado_por_constante() ) {
			self::guardar_aviso( 'warning', 'Avisos activados en la pantalla, pero siguen apagados por la constante MDF_CA_AVISOS_DESACTIVADOS de wp-config.php.' );
		} else {
			self::guardar_aviso( 'success', $activar ? 'Avisos por email activados.' : 'Avisos por email desactivados. Los documentos nuevos quedan pendientes.' );
		}

		self::redirigir();
	}

	public static function gestionar_enviar_ahora(): void {
		if ( ! current_user_can( Roles::CAP_ADMINISTRAR ) ) {
			wp_die( 'No tienes permiso para realizar esta accion.' );
		}

		check_admin_referer( self::NONCE_ENVIAR );

		$resultado = ( new Aviso_Documentos_Service() )->ejecutar();

		if ( is_wp_error( $resultado ) ) {
			self::guardar_aviso( 'error', $resultado->get_error_message() );
		} else {
			self::guardar_aviso(
				$resultado['fallidos'] > 0 ? 'warning' : 'success',
				sprintf(
					'Envio terminado: %d avisos enviados (%d documentos), %d fallidos (quedan pendientes), %d farmacias sin cuenta activa, %d ya avisadas hoy.',
					$resultado['enviados'],
					$resultado['documentos'],
					$resultado['fallidos'],
					$resultado['sin_cuenta'],
					$resultado['ya_avisadas_hoy']
				),
				self::fallos_para_pantalla( $resultado['fallos'] )
			);
		}

		self::redirigir();
	}

	private static function redirigir(): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
		exit;
	}

	/**
	 * @param array<int, array{farmacia_id: int, categoria: string, detalle: string}> $fallos
	 * @return array<int, array{farmacia: string, categoria: string, detalle: string}>
	 */
	private static function fallos_para_pantalla( array $fallos ): array {
		$farmacia_repository = new Farmacia_Repository();
		$lista               = array();

		foreach ( $fallos as $fallo ) {
			$farmacia = $farmacia_repository->find_by_id( $fallo['farmacia_id'] );
			$lista[]  = array(
				'farmacia'  => $farmacia ? $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')' : 'Farmacia ID ' . $fallo['farmacia_id'],
				'categoria' => Correo::texto_categoria( $fallo['categoria'] ),
				'detalle'   => $fallo['detalle'],
			);
		}

		return $lista;
	}

	/**
	 * @param array<int, array{farmacia: string, categoria: string, detalle: string}> $fallos
	 */
	private static function guardar_aviso( string $tipo, string $mensaje, array $fallos = array() ): void {
		set_transient(
			self::NOTICE_KEY_PREFIX . get_current_user_id(),
			array(
				'tipo'    => $tipo,
				'mensaje' => $mensaje,
				'fallos'  => $fallos,
			),
			30
		);
	}

	/**
	 * @return array{tipo: string, mensaje: string, fallos?: array<int, array{farmacia: string, categoria: string, detalle: string}>}|null
	 */
	private static function consumir_aviso(): ?array {
		$key   = self::NOTICE_KEY_PREFIX . get_current_user_id();
		$aviso = get_transient( $key );

		if ( ! $aviso ) {
			return null;
		}

		delete_transient( $key );

		return $aviso;
	}
}
