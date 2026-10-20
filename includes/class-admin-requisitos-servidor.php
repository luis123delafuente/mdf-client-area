<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aviso en el backoffice si al PHP del servidor le faltan extensiones que
 * necesitan las funciones del plugin (#333; la lista, en
 * Requisitos_Servidor). Solo para Roles::CAP_ADMINISTRAR y solo en las pantallas
 * del plugin y en Plugins, que es donde WordPress vuelve tras activar el
 * plugin: asi se ve al activarlo y cada vez que se entra en una pantalla que
 * lo necesita. No se guarda nada: se comprueba en cada carga (es una llamada
 * a extension_loaded() por extension).
 */
class Admin_Requisitos_Servidor {

	private const PREFIJO_PANTALLA = 'page_mdf-ca-';
	private const PANTALLA_PLUGINS = 'plugins';

	public static function register_hooks(): void {
		add_action( 'admin_notices', array( __CLASS__, 'mostrar_aviso' ) );
	}

	public static function mostrar_aviso(): void {
		if ( ! current_user_can( Roles::CAP_ADMINISTRAR ) || ! self::es_pantalla_del_plugin() ) {
			return;
		}

		$mensajes = array_values( array_filter( array_map( array( Requisitos_Servidor::class, 'mensaje' ), Requisitos_Servidor::funciones() ) ) );

		if ( ! $mensajes ) {
			return;
		}

		require MDF_CA_PLUGIN_DIR . 'includes/views/admin-requisitos-servidor.php';
	}

	private static function es_pantalla_del_plugin(): bool {
		$pantalla = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $pantalla && ( self::PANTALLA_PLUGINS === $pantalla->id || false !== strpos( $pantalla->id, self::PREFIJO_PANTALLA ) );
	}
}
