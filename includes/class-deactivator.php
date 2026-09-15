<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	/**
	 * Ni las tablas ni el rol mdf_cliente se eliminan aqui, para que
	 * reactivar el plugin los deje exactamente como estaban
	 * (Activator::activate() los vuelve a dejar en su estado correcto de
	 * todos modos). Borrarlos, si procede, es responsabilidad exclusiva de
	 * uninstall.php.
	 *
	 * El flush si se hace aqui (limpieza estandar de rewrite rules al
	 * desactivar), aunque por como WordPress carga los plugins en esta
	 * misma peticion la regla de Documento_Endpoint puede seguir
	 * registrada en memoria en este flush concreto y no desaparecer hasta
	 * el siguiente. Es inofensivo: sin el plugin activo no hay
	 * template_redirect que la atienda, así que como mucho queda una URL
	 * que no sirve ningun fichero.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
