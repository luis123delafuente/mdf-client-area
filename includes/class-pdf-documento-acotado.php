<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Documento de smalot/pdfparser sin el recuento de paginas de
 * Document::buildDetails() (#333). smalot lo hace al terminar de leer
 * (setObjects() -> init() -> buildDetails() -> getPages()), antes de
 * devolver el control, y getPages() recorre el arbol sin limite: con nodos
 * compartidos (cada nodo con dos hijos que son el mismo) se multiplica por
 * dos en cada nivel y agota la memoria. Aqui los detalles (metadatos /Info y
 * numero de paginas) no se calculan: la lectura de facturas no los usa, y
 * las paginas las cuenta Factura_Lectura_Service con tope antes de pedirlas.
 */
class Pdf_Documento_Acotado extends \Smalot\PdfParser\Document {

	protected function buildDetails() {
		$this->details = array();
	}
}
