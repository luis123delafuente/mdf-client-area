<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * La factura no encaja en la plantilla SAGE (#333): uso interno de
 * Factura_Sage_Parser, que la convierte en el estado formato_inesperado. El
 * mensaje es el motivo, sin valores de la factura.
 */
class Factura_Formato_Inesperado extends \RuntimeException {
}
