<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Un PDF ha superado uno de los topes de Pdf_Limites (#333). El mensaje es
 * siempre un texto fijo, sin nada del contenido del PDF: puede acabar en el
 * motivo del resultado.
 */
class Pdf_Limite_Excedido extends \RuntimeException {
}
