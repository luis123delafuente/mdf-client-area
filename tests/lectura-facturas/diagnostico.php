<?php
/**
 * Diagnostico de la lectura de facturas (#333; lo usa
 * comparar_palabras.py --diagnostico). En JSON, por pagina: cajas y
 * rotacion, Form XObjects y sus matrices, recuento de operadores del flujo
 * de contenido y las palabras de Pdf_Palabras con sus datos de diagnostico
 * (matrices, fuente, tamano, operador); ademas, estado y motivo del servicio.
 * El JSON lleva texto de las palabras, pero comparar_palabras.py NUNCA lo
 * imprime: solo lo usa para emparejar.
 *
 * Uso: php diagnostico.php FACTURA.pdf
 */

use MdfClientArea\Pdf_Contenido;
use MdfClientArea\Pdf_Fuente;
use MdfClientArea\Pdf_Limites;
use MdfClientArea\Pdf_Palabras;

require __DIR__ . '/bootstrap.php';

if ( 2 !== $argc ) {
	exit( 2 );
}

/** Recuento de operadores y de rasgos del flujo que pueden confundir a un lector. */
function rasgos( string $contenido ): array {
	$cuenta = array();
	$tj     = array(
		'total'                => 0,
		'empiezan_por_numero'  => 0,
		'numero_inicial_min'   => null,
		'numero_inicial_max'   => null,
		'con_numeros_seguidos' => 0,
		'numero_abs_max'       => 0.0,
	);

	foreach ( Pdf_Contenido::operaciones( $contenido, new Pdf_Limites() ) as $o ) {
		$cuenta[ $o['op'] ] = ( $cuenta[ $o['op'] ] ?? 0 ) + 1;

		if ( 'TJ' !== $o['op'] ) {
			continue;
		}

		foreach ( $o['args'] as $arr ) {
			if ( ! is_array( $arr ) || ! array_is_list( $arr ) || ( 's' === ( $arr[0] ?? null ) && is_string( $arr[1] ?? null ) ) ) {
				continue;
			}

			++$tj['total'];

			if ( isset( $arr[0] ) && is_float( $arr[0] ) ) {
				++$tj['empiezan_por_numero'];
				$tj['numero_inicial_min'] = null === $tj['numero_inicial_min'] ? $arr[0] : min( $tj['numero_inicial_min'], $arr[0] );
				$tj['numero_inicial_max'] = null === $tj['numero_inicial_max'] ? $arr[0] : max( $tj['numero_inicial_max'], $arr[0] );
			}

			$anterior_num = false;
			$seguidos     = false;

			foreach ( $arr as $e ) {
				if ( is_float( $e ) ) {
					$tj['numero_abs_max'] = max( $tj['numero_abs_max'], abs( $e ) );
					$seguidos             = $seguidos || $anterior_num;
					$anterior_num         = true;
				} else {
					$anterior_num = false;
				}
			}

			$tj['con_numeros_seguidos'] += $seguidos ? 1 : 0;
		}
	}

	$interes = array( 'BT', 'ET', 'Tf', 'Tm', 'Td', 'TD', 'T*', 'Tj', 'TJ', "'", '"', 'cm', 'q', 'Q', 'Do', 'Tz', 'Tc', 'Tw', 'Ts', 'TL', 'Tr', 'BDC', 'BMC', 'EMC', 'd0', 'd1', 'BI' );

	return array(
		'operadores' => array_intersect_key( $cuenta, array_flip( $interes ) ),
		'tj'         => $tj,
	);
}

MdfClientArea\Librerias::cargar();

$limites   = new Pdf_Limites();
$documento = ( new MdfClientArea\Pdf_Parser_Acotado( $limites ) )->parseFile( $argv[1] );
$extractor = new Pdf_Palabras( $limites, true );
$salida    = array( 'paginas' => array() );

foreach ( $documento->getPages() as $pagina ) {
	$xobjects = array();

	foreach ( $pagina->getXObjects() as $nombre => $xo ) {
		if ( ! $xo instanceof \Smalot\PdfParser\PDFObject ) {
			continue;
		}

		$sub        = Pdf_Fuente::nombre( $xo->get( 'Subtype' ) );
		$contenido  = 'Form' === $sub ? (string) $xo->getContent() : '';
		$xobjects[] = array(
			'nombre'    => (string) $nombre,
			'subtipo'   => $sub,
			'con_texto' => 'Form' === $sub && 1 === preg_match( '/\bBT\b/', $contenido ),
			'matrix'    => array_map( static fn( $e ) => Pdf_Fuente::numero( $e ), Pdf_Fuente::array_de( $xo->get( 'Matrix' ) ) ),
			'bbox'      => array_map( static fn( $e ) => Pdf_Fuente::numero( $e ), Pdf_Fuente::array_de( $xo->get( 'BBox' ) ) ),
		);
	}

	$salida['paginas'][] = array(
		'mediabox' => Pdf_Palabras::caja( $pagina, 'MediaBox' ),
		'cropbox'  => Pdf_Palabras::caja( $pagina ),
		'rotate'   => Pdf_Palabras::rotacion( $pagina ),
		'xobjects' => $xobjects,
		'rasgos'   => rasgos( Pdf_Palabras::contenido( $pagina ) ),
		'palabras' => $extractor->de_pagina( $pagina ),
	);
}

$r                = ( new MdfClientArea\Factura_Lectura_Service() )->analizar( $argv[1], basename( $argv[1] ) );
$salida['estado'] = $r['estado'];
$salida['motivo'] = $r['motivo'];

echo json_encode( $salida, JSON_UNESCAPED_UNICODE );
