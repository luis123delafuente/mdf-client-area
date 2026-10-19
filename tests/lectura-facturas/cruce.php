<?php
/**
 * Regresion de la lectura de facturas (#333): Factura_Cruce_Service::cruzar()
 * tiene que dar lo mismo con el resultado del servicio del plugin que con el
 * del parser de Python (el que hoy envia el robot), contra la base de datos
 * real del sitio. Lo lanza comparar_cruce.py con wp-cli:
 *
 *   wp eval-file cruce.php <fichero JSON>
 *
 * El JSON es una lista de {"pdf": ruta, "python": resultado de Python}. Solo
 * imprime "coincide" o los nombres de los campos del cruce que difieren,
 * nunca valores.
 *
 * Con un segundo argumento "crear-farmacias" (SOLO en local, con sinteticas):
 * da de alta una farmacia temporal por cada CIF/NIF valido que aun no exista,
 * para probar tambien el camino "asignada", y las borra al terminar (finally).
 * Sin el, no escribe nada en la base de datos.
 */

if ( ! defined( 'ABSPATH' ) || ! class_exists( 'MdfClientArea\\Factura_Cruce_Service' ) ) {
	exit( 2 );
}

$casos    = json_decode( (string) file_get_contents( $args[0] ), true );
$creadas  = array();
$lectura  = new MdfClientArea\Factura_Lectura_Service();
$cruce    = new MdfClientArea\Factura_Cruce_Service();
$distintos = 0;
$estados   = array();

if ( 'crear-farmacias' === ( $args[1] ?? '' ) ) {
	$repositorio = new MdfClientArea\Farmacia_Repository();

	foreach ( $casos as $caso ) {
		$cif = (string) ( $caso['python']['cif'] ?? '' );

		if ( MdfClientArea\Identificador_Fiscal_Validator::is_valid( $cif ) && ! $repositorio->find_by_cif( $cif ) ) {
			$farmacia  = $repositorio->insert( $cif, 'Farmacia temporal de la regresion #333' );
			$creadas[] = $farmacia ? $farmacia->get_id() : null;
		}
	}

	$creadas = array_values( array_filter( $creadas ) );
}

try {
foreach ( $casos as $i => $caso ) {
	$php       = $cruce->cruzar( $lectura->analizar( $caso['pdf'], basename( $caso['pdf'] ) ) );
	$py        = $cruce->cruzar( $caso['python'] );
	$campos    = array_keys( array_filter( $php, static fn( $v, $k ): bool => 'motivo' !== $k && $v !== ( $py[ $k ] ?? null ), ARRAY_FILTER_USE_BOTH ) );
	$distintos += $campos ? 1 : 0;
	$estados[ $php['estado'] ] = ( $estados[ $php['estado'] ] ?? 0 ) + 1;

	printf( "factura %03d: %s\n", $i + 1, $campos ? 'NO coincide en: ' . implode( ', ', $campos ) : 'coincide' );
}
} finally {
	global $wpdb;

	foreach ( $creadas as $id ) {
		$wpdb->delete( MdfClientArea\DB_Schema::get_farmacias_table_name(), array( 'id' => $id ), array( '%d' ) );
	}

	if ( $creadas ) {
		printf( "Farmacias temporales: %d creadas y borradas.\n", count( $creadas ) );
	}
}

printf( "\nCruce: %d facturas, %d coinciden, %d no coinciden. Estados del cruce: %s\n", count( $casos ), count( $casos ) - $distintos, $distintos, wp_json_encode( $estados ) );
