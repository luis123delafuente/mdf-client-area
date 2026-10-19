<?php
/**
 * Regresion de la lectura de facturas (#333): pasa cada PDF por el servicio
 * del plugin, Factura_Lectura_Service::analizar(), con su nombre de fichero
 * como nombre original. Lo usan comparar.py y probar_maliciosos.py.
 *
 * Uso (PHP 8.4, CLI):
 *   php analizar.php FACTURA.pdf [...]
 *
 * Imprime una linea JSON por factura con el resultado del servicio (el mismo
 * esquema que `python -m parser_facturas`, mas "paginas") y "ms" y
 * "memoria_pico_bytes". Lleva valores de la factura: quien lo llama no los
 * imprime. No escribe ficheros ni hace red.
 */

require __DIR__ . '/bootstrap.php';

if ( $argc < 2 ) {
	fwrite( STDERR, "Uso: php analizar.php FACTURA.pdf [...]\n" );
	exit( 2 );
}

$servicio = new MdfClientArea\Factura_Lectura_Service();

foreach ( array_slice( $argv, 1 ) as $ruta ) {
	memory_reset_peak_usage();
	$inicio                    = hrtime( true );
	$res                       = $servicio->analizar( $ruta, basename( $ruta ) );
	$res['ms']                 = round( ( hrtime( true ) - $inicio ) / 1e6, 2 );
	$res['memoria_pico_bytes'] = memory_get_peak_usage( false );
	echo json_encode( $res, JSON_UNESCAPED_UNICODE ), "\n";
}
