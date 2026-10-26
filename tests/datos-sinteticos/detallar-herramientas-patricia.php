<?php
use MdfClientArea as M;
$textos = array(
	29 => 'Un panel que se actualiza cada mañana con los datos de tu programa de gestión. Ves de un vistazo las ventas del día y del mes, el margen por familia y las referencias con poco stock, y puedes compararlo con el mismo periodo del año anterior para detectar a tiempo qué está subiendo y qué se está quedando parado.',
	30 => 'Una hoja preparada para el recuento de existencias, sección por sección. Anotas las unidades contadas y la plantilla calcula las diferencias con el stock teórico y el valor del inventario a precio de coste, listo para pasárselo a tu gestoría.',
	31 => 'Introduce el precio de coste y el margen que quieres obtener y te devuelve el PVP recomendado, con y sin IVA. También funciona al revés: a partir de un PVP te dice qué margen real estás sacando, para revisar rápido tus referencias de parafarmacia.',
	32 => 'El plan de acciones de marketing del año, mes a mes: campañas, escaparates, mensajes en redes y promociones en el mostrador. Cada acción indica qué materiales necesitas y con cuánta antelación conviene prepararla.',
	33 => 'Las fechas clave del año para tu farmacia (alergias, verano, gripe, Navidad…) con las campañas que recomendamos en cada una y las categorías de producto que conviene destacar.',
	34 => 'Acceso directo a los cursos de venta consejo de la Academia MDF. Son módulos cortos, pensados para hacerlos en equipo, sobre cómo recomendar un producto complementario sin forzar la venta.',
	36 => 'La lista de tareas para abrir y cerrar la farmacia cada día: caja, cámara frigorífica, alarmas, pedidos pendientes y limpieza. Imprímela y deja que el equipo la marque, así nada depende de la memoria de nadie.',
	38 => 'Todo lo que necesita una persona que se incorpora al equipo en su primera semana: cómo funciona la farmacia, a quién preguntar, los programas que usamos y las normas básicas de atención al paciente.',
);
$repo = new M\Catalogo_Repository();
$svc  = new M\Catalogo_Service();
function entrada( M\Catalogo_Item $i, string $det ): array {
	return array( 'nombre' => $i->get_nombre(), 'descripcion' => (string) $i->get_descripcion(), 'descripcion_detallada' => $det,
		'categoria_id' => (int) $i->get_categoria_id(), 'etiqueta_ids' => $i->get_etiqueta_ids(), 'recurso' => $i->get_recurso(),
		'enlace_url' => (string) $i->get_enlace_url(), 'descargable' => $i->is_descargable(), 'marca_agua' => $i->con_marca_de_agua(),
		'plan_ids' => $repo_plan = ( new M\Catalogo_Repository() )->find_plan_ids( $i->get_id() ), 'solo_farmacias' => (bool) $i->get_farmacia_ids(),
		'farmacia_ids' => $i->get_farmacia_ids(), 'visible' => $i->is_visible() );
}
foreach ( $textos as $id => $t ) {
	$r = $svc->guardar( $id, entrada( $repo->find_by_id( $id ), $t ), null );
	echo $id, ': ', is_wp_error( $r ) ? 'ERROR ' . $r->get_error_message() : ( $repo->find_by_id( $id )->get_descripcion_detallada() === $t ? 'ok' : 'NO GUARDADO' ), "\n";
}
// Demasiado larga: error y no cambia nada.
$antes = $repo->find_by_id( 29 )->get_descripcion_detallada();
$r = $svc->guardar( 29, entrada( $repo->find_by_id( 29 ), str_repeat( 'a', 601 ) ), null );
echo 'larga: ', is_wp_error( $r ) ? $r->get_error_message() : 'SIN ERROR', ' / intacta: ', $repo->find_by_id( 29 )->get_descripcion_detallada() === $antes ? 'sí' : 'NO', "\n";
// Vacia: se guarda NULL. Y alta nueva con descripcion detallada.
$base = $repo->find_by_id( 34 );
$e = entrada( $base, 'Texto de prueba de alta.' ); $e['nombre'] = 'Prueba alta temporal';
$r = $svc->guardar( 0, $e, null );
if ( is_wp_error( $r ) ) { echo 'alta: ERROR ', $r->get_error_message(), "\n"; } else {
	$nuevo = $r['item']->get_id();
	echo 'alta: ', $repo->find_by_id( $nuevo )->get_descripcion_detallada(), "\n";
	$e2 = entrada( $repo->find_by_id( $nuevo ), '' ); $svc->guardar( $nuevo, $e2, null );
	echo 'vaciada: ', var_export( $repo->find_by_id( $nuevo )->get_descripcion_detallada(), true ), "\n";
	$d = $svc->eliminar( $nuevo ); echo 'borrada temporal: ', is_wp_error( $d ) ? $d->get_error_message() : 'sí', ' / existe: ', $repo->find_by_id( $nuevo ) ? 'SÍ' : 'no', "\n";
}
