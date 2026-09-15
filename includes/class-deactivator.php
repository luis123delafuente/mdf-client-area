<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	/**
	 * De momento la desactivacion no realiza ninguna accion: ni las tablas
	 * ni el rol mdf_cliente se eliminan aqui, para que reactivar el plugin
	 * los deje exactamente como estaban (Activator::activate() los vuelve a
	 * dejar en su estado correcto de todos modos). Borrarlos, si procede,
	 * es responsabilidad exclusiva de uninstall.php.
	 */
	public static function deactivate() {
	}
}
