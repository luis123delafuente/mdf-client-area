<?php
/**
 * Se ejecuta cuando el plugin se borra desde el listado de plugins de
 * WordPress (no al desactivar). WordPress solo carga este fichero si esta
 * constante esta definida, lo que impide su ejecucion directa.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// TODO: decidir en una tarea futura si al desinstalar se deben borrar las
// tablas `mdf_ca_farmacias` y `mdf_ca_documentos` (y sus datos), o
// conservarlas para no perder documentos/farmacias por un borrado accidental
// del plugin. De momento la desinstalacion no borra nada.

// TODO: decidir tambien si al desinstalar se debe eliminar el rol
// mdf_cliente (remove_role()). Mientras no se decida, los usuarios que lo
// tengan simplemente se quedan sin ninguna capacidad si el rol desaparece,
// sin que eso corrompa sus datos.
