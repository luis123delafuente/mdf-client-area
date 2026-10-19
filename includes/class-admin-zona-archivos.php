<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Zona para arrastrar y soltar archivos en las subidas del backoffice (#339).
 * Componente reutilizable: assets/js/admin-zona-archivos.js mejora cualquier
 * <input type="file"> que lleve los atributos de atributos(), para uno o
 * varios archivos segun tenga o no el atributo multiple (la pantalla "Subir
 * facturas", #334, lo usara con varios).
 *
 * Solo presentacion y comodidad:
 * - el archivo soltado pasa al mismo input (DataTransfer), y el formulario se
 *   envia como siempre, con su nonce, su capacidad y su validacion: no hay
 *   ruta ni accion AJAX nueva;
 * - el rechazo en el navegador (tipo segun el accept del input, tamano,
 *   varios archivos en un input de uno, carpetas) no sustituye a la
 *   validacion del servidor, que no cambia;
 * - sin JS, el input se ve y funciona como siempre.
 *
 * Modo lote (#334, "Subir facturas"): con multiple y $carpetas, admite
 * carpetas (soltadas, con sus subcarpetas, o con "Elegir carpeta"), ignora
 * sin error los ficheros ocultos y lo que no es del tipo admitido y aplica un
 * tope de archivos por subida.
 */
class Admin_Zona_Archivos {

	public const HANDLE = 'mdf-ca-admin-zona-archivos';

	private const JS  = 'assets/js/admin-zona-archivos.js';
	private const CSS = 'assets/css/admin-zona-archivos.css';

	/** Encolar desde el admin_enqueue_scripts de cada pantalla con subida. */
	public static function encolar(): void {
		wp_enqueue_style( self::HANDLE, Recursos::url( self::CSS ), array( 'dashicons' ), Recursos::version( self::CSS ) );
		wp_enqueue_script( self::HANDLE, Recursos::url( self::JS ), array(), Recursos::version( self::JS ), true );
	}

	/**
	 * Atributos para el <input type="file">, ya escapados.
	 *
	 * @param string $tipos     Tipos admitidos en texto, para los mensajes ("PDF, JPG o Excel (.xlsx)").
	 *                          Los que se comprueban salen del accept del input.
	 * @param int    $max_bytes Tamano maximo por archivo (el mismo que exige el servidor).
	 * @param bool   $carpetas  Modo lote: solo con un input multiple.
	 * @param int    $max_archivos Tope de archivos a la vez (0, sin tope).
	 */
	public static function atributos( string $tipos, int $max_bytes, bool $carpetas = false, int $max_archivos = 0 ): string {
		return sprintf(
			'data-mdf-ca-zona-archivos data-mdf-ca-zona-tipos="%s" data-mdf-ca-zona-max-bytes="%d"%s%s',
			esc_attr( $tipos ),
			$max_bytes,
			$carpetas ? ' data-mdf-ca-zona-carpetas' : '',
			$max_archivos > 0 ? sprintf( ' data-mdf-ca-zona-max-archivos="%d"', $max_archivos ) : ''
		);
	}
}
