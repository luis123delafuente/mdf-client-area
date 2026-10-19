<?php
/**
 * Regresion de la lectura de facturas en PHP (#333). tests/ no se despliega.
 *
 * Carga las clases del plugin sin arrancar WordPress (sin base de datos ni
 * sitio de Local en marcha): la lectura (Factura_Lectura_Service y las
 * clases Pdf_*) no usa funciones de WordPress. El cruce con las farmacias si
 * las usa: se prueba aparte con wp-cli (cruce.php).
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

define( 'ABSPATH', dirname( __DIR__, 5 ) . '/' );
define( 'MDF_CA_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );

// El mismo autoload que mdf-client-area.php.
spl_autoload_register(
	static function ( string $clase ): void {
		$prefijo = 'MdfClientArea\\';

		if ( 0 !== strpos( $clase, $prefijo ) ) {
			return;
		}

		$fichero = MDF_CA_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', substr( $clase, strlen( $prefijo ) ) ) ) . '.php';

		if ( file_exists( $fichero ) ) {
			require_once $fichero;
		}
	}
);
