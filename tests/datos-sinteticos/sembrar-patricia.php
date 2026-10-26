<?php
// Datos SINTETICOS para la farmacia de prueba "Patricia Sada" (id 14) en
// LOCAL: documentos de todos los tipos y herramientas del catalogo, dados de
// alta con los servicios del plugin. Uso: wp eval-file sembrar-patricia.php
// Guarda lo creado en sembrado-patricia.json para poder borrarlo despues.

use MdfClientArea as M;

const FARMACIA_ID = 14;
$dir_trabajo      = __DIR__ . '/sembrado';
$registro         = __DIR__ . '/sembrado-patricia.json';

if ( file_exists( $registro ) ) {
	fwrite( STDERR, "Ya hay un sembrado registrado en $registro; borralo antes con limpiar-patricia.php.\n" );
	exit( 1 );
}

wp_mkdir_p( $dir_trabajo );
M\Librerias::cargar();

$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin = (int) $admin[0];
wp_set_current_user( $admin );

$farmacia = ( new M\Farmacia_Repository() )->find_by_id( FARMACIA_ID );
$creado   = array( 'documentos' => array(), 'herramientas' => array(), 'etiquetas' => array() );
$errores  = array();

/** PDF sintetico con cabecera de MDF y un aviso claro de que es de prueba. */
function mdf_pdf( string $titulo, string $subtitulo, array $filas, ?array $totales, $farmacia ): string {
	$lineas = '';
	foreach ( $filas as $fila ) {
		$lineas .= '<tr><td>' . esc_html( $fila[0] ) . '</td><td class="d">' . esc_html( $fila[1] ) . '</td></tr>';
	}
	$total_html = '';
	if ( $totales ) {
		foreach ( $totales as $k => $v ) {
			$total_html .= '<tr class="t"><td>' . esc_html( $k ) . '</td><td class="d">' . esc_html( $v ) . '</td></tr>';
		}
	}
	$html = '<html><head><meta charset="utf-8"><style>
		body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222;margin:28px}
		.cab{border-bottom:4px solid #70BA65;padding-bottom:10px;margin-bottom:18px}
		.marca{font-size:26px;font-weight:bold;letter-spacing:2px;color:#5FA854}
		.emp{font-size:9px;color:#555}
		h1{font-size:18px;margin:0 0 4px} .sub{color:#555;margin:0 0 16px}
		.cli{background:#F5F5F5;padding:10px 12px;margin-bottom:16px}
		table{width:100%;border-collapse:collapse} td{padding:7px 6px;border-bottom:1px solid #D9D9D9}
		.d{text-align:right} .t td{font-weight:bold;border-bottom:none}
		.aviso{margin-top:28px;border:1px dashed #5FA854;background:#EAF5E7;padding:8px 10px;font-size:9px;text-align:center}
	</style></head><body>
		<div class="cab"><div class="marca">MDF</div><div class="emp">Mediformplus SL · Las Rozas (Madrid) · 91 636 48 18</div></div>
		<h1>' . esc_html( $titulo ) . '</h1><p class="sub">' . esc_html( $subtitulo ) . '</p>
		<div class="cli"><strong>' . esc_html( $farmacia->get_nombre() ) . '</strong><br>NIF ' . esc_html( $farmacia->get_cif() ) . '</div>
		<table>' . $lineas . $total_html . '</table>
		<div class="aviso">DOCUMENTO SINTÉTICO DE PRUEBA. Generado en el entorno local; no tiene validez.</div>
	</body></html>';

	$opciones = new \Dompdf\Options( array( 'isRemoteEnabled' => false, 'isPhpEnabled' => false ) );
	$dompdf   = new \Dompdf\Dompdf( $opciones );
	$dompdf->loadHtml( $html, 'UTF-8' );
	$dompdf->setPaper( 'A4' );
	$dompdf->render();

	return $dompdf->output();
}

function mdf_archivo( string $dir, string $nombre_fichero, string $bytes ): array {
	$ruta = $dir . '/' . $nombre_fichero;
	file_put_contents( $ruta, $bytes );

	return array( 'name' => $nombre_fichero, 'type' => 'application/pdf', 'tmp_name' => $ruta, 'error' => 0, 'size' => strlen( $bytes ), 'desde_disco' => true );
}

function mdf_euros( float $n ): string {
	return number_format( $n, 2, ',', '.' ) . ' €';
}

$publicacion = new M\Documento_Publicacion_Service();
$documentos  = new M\Documento_Service();

// ---------- Facturas (subir_factura_recibida + publicar) ----------
$facturas = array();
foreach ( range( 1, 10 ) as $mes ) {
	$facturas[] = array( 'B82827635', 'A', (string) ( 1200 + $mes ), sprintf( '2026-%02d-05', $mes ), 'Cuota mensual Plan Premium ' . wp_date( 'F Y', strtotime( sprintf( '2026-%02d-05', $mes ) ) ), 285.00 );
}
foreach ( array( 10, 11, 12 ) as $mes ) {
	$facturas[] = array( 'B82827635', 'A', (string) ( 1140 + $mes ), sprintf( '2025-%02d-05', $mes ), 'Cuota mensual Plan Premium ' . wp_date( 'F Y', strtotime( sprintf( '2025-%02d-05', $mes ) ) ), 265.00 );
}
$facturas[] = array( 'B88471149', 'SF', '301', '2025-11-20', 'Curso de dermocosmética para el equipo (3 plazas)', 390.00 );
$facturas[] = array( 'B88471149', 'SF', '318', '2026-03-12', 'Programa de especialización en nutrición', 450.00 );
$facturas[] = array( 'B88471149', 'SF', '342', '2026-06-18', 'Taller de gestión de categorías', 180.00 );

foreach ( $facturas as list( $cif, $serie, $numero, $fecha, $concepto, $base ) ) {
	$emisor = M\Factura_Emisores::nombre_corto( $cif );
	$iva    = round( $base * 0.21, 2 );
	$pdf    = mdf_pdf(
		"Factura $serie $numero",
		( 'SF' === $serie ? 'Mediformplus Formación SL' : 'Mediformplus SL' ) . ' · Fecha ' . wp_date( 'j/m/Y', strtotime( $fecha ) ),
		array( array( $concepto, mdf_euros( $base ) ) ),
		array( 'Base imponible' => mdf_euros( $base ), 'IVA 21 %' => mdf_euros( $iva ), 'Total' => mdf_euros( $base + $iva ) ),
		$farmacia
	);
	$archivo = mdf_archivo( $dir_trabajo, "factura-$serie-$numero.pdf", $pdf );
	$doc     = $documentos->subir_factura_recibida(
		FARMACIA_ID,
		"Factura $emisor $serie $numero",
		$archivo,
		hash( 'sha256', $pdf ),
		array( 'emisor_cif' => $cif, 'serie' => $serie, 'numero' => $numero ),
		$fecha,
		M\Publicacion_Evento_Repository::ORIGEN_SUBIDA_FACTURAS
	);
	if ( is_wp_error( $doc ) ) {
		$errores[] = "factura $serie $numero: " . $doc->get_error_message();
		continue;
	}
	$publicacion->publicar_uno( $doc->get_id(), $admin );
	$creado['documentos'][] = $doc->get_id();
}

// ---------- Modelo 347 (generado + publicar) ----------
foreach ( array( array( 'B82827635', 2024 ), array( 'B82827635', 2023 ), array( 'B88471149', 2025 ), array( 'B88471149', 2024 ) ) as list( $cif, $ejercicio ) ) {
	$pdf = mdf_pdf(
		"Modelo 347. Ejercicio $ejercicio",
		'Declaración anual de operaciones con terceras personas · ' . ( 'B88471149' === $cif ? 'Mediformplus Formación SL' : 'Mediformplus SL' ),
		array( array( '1.er trimestre', mdf_euros( 855 + $ejercicio % 7 ) ), array( '2.º trimestre', mdf_euros( 855 ) ), array( '3.er trimestre', mdf_euros( 870 ) ), array( '4.º trimestre', mdf_euros( 870 ) ) ),
		array( 'Importe anual' => mdf_euros( 3450 + $ejercicio % 7 ) ),
		$farmacia
	);
	$doc = $documentos->guardar_modelo_347_generado( FARMACIA_ID, $cif, $ejercicio, $pdf );
	if ( is_wp_error( $doc ) ) {
		$errores[] = "347 $cif $ejercicio: " . $doc->get_error_message();
		continue;
	}
	if ( ! $doc->is_publicado() ) {
		$publicacion->publicar_uno( $doc->get_id(), $admin );
	}
	$creado['documentos'][] = $doc->get_id();
}

// ---------- Contratos, presupuestos, entregables y otros (subir) ----------
$manuales = array(
	array( 'contrato', 'Contrato de servicios de consultoría 2026', '2026-01-08', true, array( array( 'Plan Premium: consultoría, formación y herramientas', '12 meses' ), array( 'Inicio', '1 de enero de 2026' ) ) ),
	array( 'contrato', 'Encargo de tratamiento de datos (RGPD)', '2026-01-08', true, array( array( 'Anexo al contrato de servicios', 'Firmado' ) ) ),
	array( 'contrato', 'Contrato de servicios de consultoría 2025', '2025-01-10', false, array( array( 'Plan Básico', '12 meses' ) ) ),
	array( 'presupuesto', 'Presupuesto reforma del lineal de dermocosmética', '2026-09-22', true, array( array( 'Estudio de espacio y planograma', mdf_euros( 640 ) ), array( 'Implantación en tienda', mdf_euros( 980 ) ) ) ),
	array( 'presupuesto', 'Presupuesto formación del equipo 2027', '2026-10-02', true, array( array( 'Itinerario anual para 4 personas', mdf_euros( 1560 ) ) ) ),
	array( 'presupuesto', 'Presupuesto campaña de solares 2026', '2026-03-15', false, array( array( 'Diseño y cartelería', mdf_euros( 420 ) ) ) ),
	array( 'entregable', 'Informe de rentabilidad del primer semestre 2026', '2026-07-14', true, array( array( 'Ventas', '+8,4 % sobre 2025' ), array( 'Margen bruto', '31,2 %' ) ) ),
	array( 'entregable', 'Plan de Marketing 2026', '2026-01-20', true, array( array( 'Campañas', '12' ), array( 'Acciones en redes', '24' ) ) ),
	array( 'entregable', 'Auditoría de surtido', '2025-11-05', false, array( array( 'Referencias analizadas', '3.214' ), array( 'Propuesta de bajas', '187' ) ) ),
	array( 'entregable', 'Análisis de categoría: dermofarmacia', '2026-05-28', true, array( array( 'Peso en ventas', '22,6 %' ) ) ),
	array( 'otro', 'Certificado de asistencia Meeting Feng 2025', '2025-11-14', true, array( array( 'Asistentes', '2' ) ) ),
	array( 'otro', 'Guía de protección de datos para la farmacia', '2026-02-03', true, array( array( 'Versión', '2026' ) ) ),
);
foreach ( $manuales as $i => list( $tipo, $nombre, $fecha, $descargable, $filas ) ) {
	$pdf     = mdf_pdf( $nombre, 'Fecha ' . wp_date( 'j/m/Y', strtotime( $fecha ) ), $filas, null, $farmacia );
	$archivo = mdf_archivo( $dir_trabajo, "$tipo-$i.pdf", $pdf );
	$doc     = ( new M\Documento_Service() )->descargable( $descargable )->subir( FARMACIA_ID, $nombre, $tipo, $archivo, false, $fecha );
	if ( is_wp_error( $doc ) ) {
		$errores[] = "$tipo $nombre: " . $doc->get_error_message();
		continue;
	}
	$creado['documentos'][] = $doc->get_id();
}

// ---------- Herramientas del catalogo ----------
$etiquetas = M\Catalogo_Termino_Repository::etiquetas();
$servicio_etiquetas = new M\Catalogo_Termino_Service( $etiquetas );
$ids_etiqueta = array();
foreach ( array( 'Ventas', 'Márgenes', 'Stock', 'Campañas', 'Equipo', 'Normativa' ) as $nombre ) {
	$r = $servicio_etiquetas->crear( $nombre );
	if ( is_wp_error( $r ) ) {
		$errores[] = "etiqueta $nombre: " . $r->get_error_message();
		continue;
	}
	$id                     = is_object( $r ) && method_exists( $r, 'get_id' ) ? $r->get_id() : (int) $r;
	$ids_etiqueta[ $nombre ] = $id;
	$creado['etiquetas'][]  = $id;
}

$catalogo = new M\Catalogo_Service();
$ref      = new ReflectionClass( $catalogo );
$en_bd    = $ref->getMethod( 'guardar_en_bd' );
$en_bd->setAccessible( true );

$cat = array( 'Gestión' => 7, 'Marketing' => 8, 'Calidad' => 9, 'Recursos humanos' => 10 );
$herramientas = array(
	// nombre, descripcion, categoria, etiquetas, planes, recurso, extra
	array( 'Cuadro de mando MDF', 'Ventas, márgenes y stock de tu farmacia, actualizados cada día.', 'Gestión', array( 'Ventas', 'Márgenes' ), array( 1, 2 ), 'enlace', 'https://www.mediformplus.com/' ),
	array( 'Plantilla de inventario', 'Excel para el recuento de existencias por sección.', 'Gestión', array( 'Stock' ), array( 1, 2 ), 'xlsx', array( 'Sección', 'Código nacional', 'Descripción', 'Unidades', 'Coste unitario' ) ),
	array( 'Calculadora de márgenes', 'Calcula el margen y el PVP recomendado de cada referencia.', 'Gestión', array( 'Márgenes' ), array( 2 ), 'xlsx', array( 'Referencia', 'Coste', 'IVA', 'PVP', 'Margen %' ) ),
	array( 'Plan de Marketing MDF 2026', 'Las acciones del año, mes a mes, para tu farmacia.', 'Marketing', array( 'Campañas' ), array( 2 ), 'pdf', null ),
	array( 'Calendario de campañas 2026', 'Fechas clave y campañas recomendadas por temporada.', 'Marketing', array( 'Campañas', 'Ventas' ), array( 1, 2 ), 'pdf', null ),
	array( 'Academia: cursos de venta consejo', 'Acceso a la formación de venta consejo en la Academia MDF.', 'Marketing', array( 'Equipo' ), array( 2 ), 'enlace', 'https://academiamdf.com/' ),
	array( 'Protocolo de dispensación', 'Pasos y comprobaciones para una dispensación segura.', 'Calidad', array( 'Normativa' ), array( 1, 2 ), 'pdf', null ),
	array( 'Checklist de apertura y cierre', 'Lista de tareas diarias para el equipo.', 'Calidad', array( 'Equipo' ), array( 1, 2 ), 'pdf', null ),
	array( 'Cuadrante de turnos', 'Plantilla para organizar turnos y guardias.', 'Recursos humanos', array( 'Equipo' ), array( 2 ), 'xlsx', array( 'Semana', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado' ) ),
	array( 'Guía de acogida para nuevo personal', 'Todo lo que necesita saber alguien que se incorpora.', 'Recursos humanos', array( 'Equipo', 'Normativa' ), array( 1, 2 ), 'pdf', null ),
);

foreach ( $herramientas as $i => list( $nombre, $descripcion, $categoria, $etqs, $planes, $recurso, $extra ) ) {
	$etiqueta_ids = array_values( array_filter( array_map( static fn( $e ) => $ids_etiqueta[ $e ] ?? 0, $etqs ) ) );

	if ( 'enlace' === $recurso ) {
		$r = $catalogo->guardar(
			0,
			array( 'nombre' => $nombre, 'descripcion' => $descripcion, 'categoria_id' => $cat[ $categoria ], 'etiqueta_ids' => $etiqueta_ids, 'plan_ids' => $planes, 'solo_farmacias' => false, 'farmacia_ids' => array(), 'recurso' => M\Catalogo_Item::RECURSO_ENLACE, 'enlace_url' => $extra, 'visible' => true, 'descargable' => false, 'marca_agua' => true ),
			null
		);
		if ( is_wp_error( $r ) ) {
			$errores[] = "herramienta $nombre: " . $r->get_error_message();
			continue;
		}
		$creado['herramientas'][] = $r['item']->get_id();
		continue;
	}

	// Archivo: el servicio exige una subida HTTP (is_uploaded_file); aqui se
	// escribe el fichero en la carpeta de herramientas con su mismo esquema de
	// nombre y se guarda con su metodo interno de BD.
	if ( 'pdf' === $recurso ) {
		$bytes = mdf_pdf( $nombre, $descripcion, array( array( 'Contenido', 'Herramienta de ejemplo' ) ), null, $farmacia );
		$mime  = 'application/pdf';
	} else {
		$tmp    = $dir_trabajo . "/herramienta-$i.xlsx";
		$writer = new \OpenSpout\Writer\XLSX\Writer();
		$writer->openToFile( $tmp );
		$writer->addRow( \OpenSpout\Common\Entity\Row::fromValues( $extra ) );
		foreach ( range( 1, 12 ) as $n ) {
			$writer->addRow( \OpenSpout\Common\Entity\Row::fromValues( array_map( static fn( $c ) => '', $extra ) ) );
		}
		$writer->close();
		$bytes = file_get_contents( $tmp );
		$mime  = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
	}

	$estado = M\Carpeta_Privada_Proteccion::asegurar( M\Catalogo_Service::CARPETA_RELATIVA );
	$fichero = 'h-' . bin2hex( random_bytes( 16 ) ) . '.' . $recurso;
	file_put_contents( M\Catalogo_Service::get_carpeta() . DIRECTORY_SEPARATOR . $fichero, $bytes );

	$id = $en_bd->invoke(
		$catalogo,
		0,
		array(
			'nombre' => $nombre, 'descripcion' => $descripcion, 'categoria_id' => $cat[ $categoria ], 'recurso' => M\Catalogo_Item::RECURSO_ARCHIVO,
			'visible' => true, 'fecha_modificacion' => current_time( 'mysql', true ), 'formato' => $recurso, 'ruta_fichero' => $fichero,
			'tipo_mime' => $mime, 'tamano' => strlen( $bytes ), 'enlace_url' => null, 'descargable' => true, 'marca_agua' => 'pdf' === $recurso,
		),
		$planes,
		array(),
		$etiqueta_ids
	);
	if ( is_wp_error( $id ) ) {
		$errores[] = "herramienta $nombre: " . $id->get_error_message();
		continue;
	}
	$creado['herramientas'][] = (int) $id;
}

file_put_contents( $registro, wp_json_encode( $creado, JSON_PRETTY_PRINT ) );

printf( "Documentos: %d · Herramientas: %d · Etiquetas: %d\n", count( $creado['documentos'] ), count( $creado['herramientas'] ), count( $creado['etiquetas'] ) );
foreach ( $errores as $e ) {
	echo "ERROR $e\n";
}
