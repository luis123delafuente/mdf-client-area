<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * URL y version de los JS y CSS del plugin. Unico sitio que decide la
 * version que lleva cada recurso: MDF_CA_VERSION mas la fecha de
 * modificacion del fichero. Asi, si un recurso cambia en un despliegue sin
 * que se suba MDF_CA_VERSION, su URL cambia y ningun navegador sigue usando
 * la copia en cache (paso en las pruebas del generador del Modelo 347).
 *
 * Todo recurso encolado (wp_enqueue_script/style) o enlazado a mano (visor
 * de documentos, PDF.js, SheetJS) pasa por aqui.
 */
class Recursos {

	/** URL publica de un recurso, por su ruta relativa a la raiz del plugin ("assets/js/x.js"). */
	public static function url( string $relativa ): string {
		return plugins_url( $relativa, MDF_CA_PLUGIN_FILE );
	}

	/**
	 * Version para el parametro "ver": "0.24.0.1792336731". Si el fichero no
	 * existe (no deberia), solo MDF_CA_VERSION.
	 */
	public static function version( string $relativa ): string {
		$ruta  = MDF_CA_PLUGIN_DIR . $relativa;
		$fecha = is_file( $ruta ) ? (int) filemtime( $ruta ) : 0;

		return $fecha > 0 ? MDF_CA_VERSION . '.' . $fecha : MDF_CA_VERSION;
	}

	/** URL con "?ver=" ya puesto, para los recursos que no pasan por wp_enqueue_*. */
	public static function url_versionada( string $relativa ): string {
		return add_query_arg( 'ver', self::version( $relativa ), self::url( $relativa ) );
	}
}
