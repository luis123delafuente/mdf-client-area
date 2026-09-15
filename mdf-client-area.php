<?php
/**
 * Plugin Name:       MDF Client Area
 * Description:       Logica de negocio del Area de Clientes de Mediformplus SL (permisos, documentos, catalogo). Elementor solo maqueta, este plugin decide.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Mediformplus SL
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       mdf-client-area
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MDF_CA_VERSION', '0.1.0' );
define( 'MDF_CA_DB_VERSION', '1.0.0' );
define( 'MDF_CA_PLUGIN_FILE', __FILE__ );
define( 'MDF_CA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MDF_CA_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoload propio para las clases del namespace MdfClientArea, evita tener
 * que mantener una lista de require_once a mano segun crezca el plugin.
 * Convierte MdfClientArea\Nombre_Clase en includes/class-nombre-clase.php,
 * siguiendo la convencion de nombres de fichero de WordPress.
 */
spl_autoload_register(
	function ( $class_name ) {
		$prefix = 'MdfClientArea\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative_class = substr( $class_name, strlen( $prefix ) );
		$file_name      = 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
		$file_path      = MDF_CA_PLUGIN_DIR . 'includes/' . $file_name;

		if ( file_exists( $file_path ) ) {
			require_once $file_path;
		}
	}
);

register_activation_hook( __FILE__, array( 'MdfClientArea\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MdfClientArea\\Deactivator', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MdfClientArea\\Role_Restrictions', 'register_hooks' ) );
add_action( 'plugins_loaded', array( 'MdfClientArea\\Documento_Endpoint', 'register_hooks' ) );

// TEMPORAL -- tarea #233 (primer despliegue en produccion). Borrar esta
// linea junto con temp-233-seed-produccion.php en cuanto se confirme que
// los datos de prueba quedaron creados en produccion.
require_once MDF_CA_PLUGIN_DIR . 'temp-233-seed-produccion.php';
