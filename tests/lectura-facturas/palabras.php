<?php
/**
 * Regresion de la lectura de facturas (#333; lo usa comparar_palabras.py):
 * palabras de cada pagina en JSON, como page.get_text("words"), con las
 * clases del plugin (Pdf_Parser_Acotado y Pdf_Palabras, con los topes
 * normales). Lleva texto: comparar_palabras.py nunca lo imprime.
 *
 * Uso: php palabras.php FACTURA.pdf
 */

require __DIR__ . '/bootstrap.php';

if ( 2 !== $argc ) {
	exit( 2 );
}

MdfClientArea\Librerias::cargar();

$limites   = new MdfClientArea\Pdf_Limites();
$documento = ( new MdfClientArea\Pdf_Parser_Acotado( $limites ) )->parseFile( $argv[1] );
$extractor = new MdfClientArea\Pdf_Palabras( $limites );
$salida    = array();

foreach ( $documento->getPages() as $pagina ) {
	$salida[] = $extractor->de_pagina( $pagina );
}

echo json_encode( $salida, JSON_UNESCAPED_UNICODE );
