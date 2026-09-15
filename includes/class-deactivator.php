<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Deactivator {

	/**
	 * De momento la desactivacion no realiza ninguna accion: todavia no hay
	 * roles, cron ni rewrite rules registrados por el plugin. Las tablas
	 * nunca se borran aqui; eso, si procede, es responsabilidad exclusiva
	 * de uninstall.php.
	 */
	public static function deactivate() {
	}
}
