<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * El PDF esta cifrado (#333): Pdf_Parser_Acotado no lo lee. Mensaje fijo.
 */
class Pdf_Cifrado extends \RuntimeException {
}
