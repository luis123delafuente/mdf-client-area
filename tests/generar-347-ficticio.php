<?php
/**
 * Genera un Excel FICTICIO con la estructura del Modelo 347 de SAGE (#307)
 * para probar el generador. Nunca datos reales. No se despliega (tests/).
 *
 * Uso (PHP 8.4, desde la raiz del plugin):
 *   php tests/generar-347-ficticio.php <salida.xlsx> <CIF con cuenta> <CIF sin cuenta> <CIF libre 1> <CIF libre 2> <CIF libre 3> [<CIF con cuenta 2>]
 *
 * Los CIF de las farmacias de prueba locales se pasan por argumento para no
 * fijarlos aqui. Los "libres" son CIF validos que no estan en el portal.
 *
 * Casos (linea del Excel entre parentesis; la 1 es la cabecera):
 *  (2) con cuenta, nombre con tildes, ene y "&", T2 negativo, T3 a 0       -> lista + aviso
 *  (3) farmacia sin cuenta                                                  -> sin_cuenta
 *  (4) CIF valido que no esta en el portal                                  -> no_portal
 *  (5) identificador mal formado                                            -> invalido
 *  (6) CIF con el digito de control erroneo                                 -> invalido
 *  (7) suma de trimestres que no cuadra con DEV                             -> no_cuadra
 *  (8,9) mismo CIF dos veces                                                -> repetido (las dos)
 *  (10) [si hay CIF con cuenta 2] RECC activo, T4 vacio, DEV como texto     -> lista + avisos
 * Textos rellenos con espacios, como los exporta SAGE.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

require dirname( __DIR__ ) . '/lib/vendor/autoload.php';

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

if ( $argc < 7 ) {
	fwrite( STDERR, "Uso: php tests/generar-347-ficticio.php <salida.xlsx> <CIF con cuenta> <CIF sin cuenta> <libre 1> <libre 2> <libre 3> [<CIF con cuenta 2>]\n" );
	exit( 1 );
}

list( , $salida, $con_cuenta, $sin_cuenta, $libre1, $libre2, $libre3 ) = $argv;
$con_cuenta2 = $argv[7] ?? null;

$p = static fn( string $t ): string => '  ' . $t . '   '; // relleno como SAGE

$filas = array(
	array( 'Código', 'Nombre', 'NIF', 'Dirección', 'Población', 'Código postal', 'Trimestre 1', 'Trimestre 2', 'Trimestre 3', 'Trimestre 4', 'Importe DEV', 'Importe RECC', 'RECC', 'Email' ),
	array( $p( '430001' ), $p( 'Farmacia Peña & Muñoz Ibáñez, C.B.' ), $p( $con_cuenta ), $p( 'Calle Ficticia Ñandú, 12' ), $p( 'Alcalá de Prueba' ), $p( '28001' ), 1500.25, -200.10, 0, 300.00, 1600.15, 0, false, 'prueba1@example.com' ),
	array( $p( '430002' ), $p( 'Farmacia Prueba Sin Cuenta' ), $p( $sin_cuenta ), $p( 'Avenida Inventada, 3' ), $p( 'Villaprueba' ), $p( '28002' ), 100, 200, 300, 400, 1000, 0, false, '' ),
	array( $p( '430003' ), $p( 'Farmacia Fuera Del Portal' ), $p( $libre1 ), $p( 'Plaza Mayor, 1' ), $p( 'Pueblo Ficticio' ), $p( '28003' ), 50, 50, 50, 50, 200, 0, false, '' ),
	array( $p( '430004' ), $p( 'Identificador Mal Formado' ), $p( 'XX-12' ), '', '', '', 10, 10, 10, 10, 40, 0, false, '' ),
	array( $p( '430005' ), $p( 'Control Erróneo' ), $p( 'B12345675' ), '', '', '', 10, 10, 10, 10, 40, 0, false, '' ),
	array( $p( '430006' ), $p( 'Suma Que No Cuadra' ), $p( $libre2 ), '', '', '', 25, 25, 25, 25, 150, 0, false, '' ),
	array( $p( '430007' ), $p( 'Repetida Uno' ), $p( $libre3 ), '', '', '', 1, 1, 1, 1, 4, 0, false, '' ),
	array( $p( '430008' ), $p( 'Repetida Dos' ), $p( $libre3 ), '', '', '', 2, 2, 2, 2, 8, 0, false, '' ),
);

if ( null !== $con_cuenta2 ) {
	$filas[] = array( $p( '430009' ), $p( 'Farmacia Con RECC' ), $p( $con_cuenta2 ), $p( 'Calle Segunda, 2' ), $p( 'Otro Pueblo' ), $p( '08001' ), 1000, 234.56, 0, null, '1.234,56', 12.5, 'True', '' );
}

$writer = new Writer();
$writer->openToFile( $salida );
$writer->getCurrentSheet()->setName( '347' );

foreach ( $filas as $fila ) {
	$writer->addRow( Row::fromValues( $fila ) );
}

// Una segunda hoja que el lector debe ignorar.
$writer->addNewSheetAndMakeItCurrent()->setName( 'Notas' );
$writer->addRow( Row::fromValues( array( 'Hoja ajena: debe ignorarse' ) ) );
$writer->close();

echo "Generado: {$salida} (" . ( count( $filas ) - 1 ) . " filas)\n";
