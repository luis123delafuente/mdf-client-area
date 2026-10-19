<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carga perezosa de las librerias vendorizadas en lib/ (dompdf y OpenSpout
 * para el Modelo 347, #307; smalot/pdfparser para la lectura de facturas,
 * #333). Un unico autoload de Composer para todas, resuelto en un Mac y
 * copiado tal cual (en el servidor no hay Composer). Solo se carga cuando
 * una funcion que las usa lo pide, nunca en cada peticion.
 *
 * lib/.htaccess deniega todo: ningun fichero de lib/ se sirve ni se ejecuta
 * por HTTP. Como se reconstruye lib/ esta en lib/LEEME.md.
 *
 * Quien carga debe comprobar antes Requisitos_Servidor para su funcion: sin
 * las extensiones de PHP que piden las librerias, fallarian a medias.
 */
class Librerias {

	public static function cargar(): void {
		static $cargado = false;

		if ( ! $cargado ) {
			require_once MDF_CA_PLUGIN_DIR . 'lib/vendor/autoload.php';
			$cargado = true;
		}
	}
}
