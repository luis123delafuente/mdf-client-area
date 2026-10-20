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
// tablas `mdf_ca_farmacias`, `mdf_ca_documentos` y `mdf_ca_planes` (y sus
// datos), o conservarlas para no perder documentos/farmacias/planes por un
// borrado accidental del plugin. De momento la desinstalacion no borra nada.

// Rol Gestion MDF (#337) y capacidades del plugin en el administrador: se
// quitan al desinstalar. Los usuarios que tuvieran el rol se quedan sin
// ninguna capacidad, sin perder sus datos. Nombres literales: este fichero
// se carga sin el plugin.
remove_role( 'mdf_gestor' );

$mdf_ca_administrador = get_role( 'administrator' );

if ( $mdf_ca_administrador ) {
	foreach ( array( 'mdf_ca_backoffice', 'mdf_ca_gestionar_documentos', 'mdf_ca_gestionar_347', 'mdf_ca_gestionar_farmacias', 'mdf_ca_gestionar_catalogo' ) as $mdf_ca_capacidad ) {
		$mdf_ca_administrador->remove_cap( $mdf_ca_capacidad );
	}
}

// TODO: decidir tambien si al desinstalar se debe eliminar el rol
// mdf_cliente (remove_role()). Mientras no se decida, los usuarios que lo
// tengan simplemente se quedan sin ninguna capacidad si el rol desaparece,
// sin que eso corrompa sus datos.
