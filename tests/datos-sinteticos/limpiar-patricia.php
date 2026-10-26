<?php
// Borra SOLO lo que creo sembrar-patricia.php (IDs de sembrado-patricia.json),
// con los servicios de borrado del plugin. Uso: wp eval-file limpiar-patricia.php

use MdfClientArea as M;

$registro = __DIR__ . '/sembrado-patricia.json';

if ( ! file_exists( $registro ) ) {
	fwrite( STDERR, "No hay registro de sembrado.\n" );
	exit( 1 );
}

$creado = json_decode( (string) file_get_contents( $registro ), true );
$admin  = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admin[0];
wp_set_current_user( $admin );

$borrados = array( 'documentos' => 0, 'herramientas' => 0, 'etiquetas' => 0 );

$eliminacion = new M\Documento_Eliminacion_Service();
foreach ( $creado['documentos'] as $id ) {
	$r = $eliminacion->eliminar( (int) $id, $admin );
	is_wp_error( $r ) ? print( "documento $id: " . $r->get_error_message() . "\n" ) : $borrados['documentos']++;
}

$catalogo = new M\Catalogo_Service();
foreach ( $creado['herramientas'] as $id ) {
	$r = $catalogo->eliminar( (int) $id );
	is_wp_error( $r ) ? print( "herramienta $id: " . $r->get_error_message() . "\n" ) : $borrados['herramientas']++;
}

$etiquetas = new M\Catalogo_Termino_Service( M\Catalogo_Termino_Repository::etiquetas() );
foreach ( $creado['etiquetas'] as $id ) {
	$r = $etiquetas->eliminar( (int) $id );
	is_wp_error( $r ) ? print( "etiqueta $id: " . $r->get_error_message() . "\n" ) : $borrados['etiquetas']++;
}

rename( $registro, $registro . '.borrado-' . gmdate( 'YmdHis' ) );
echo wp_json_encode( $borrados ) . "\n";
