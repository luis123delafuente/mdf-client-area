<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode de front para el listado de documentos de la farmacia del
 * usuario logueado (Fase 2, tarea #241). Se implementa como shortcode y no
 * como widget nativo de Elementor: Elementor ni siquiera esta activo en
 * este entorno, y su propio widget "Shortcode" puede envolver esto sin
 * acoplar esta pieza de logica a que Elementor este instalado.
 *
 * No decide visibilidad por si mismo: se apoya en
 * Farmacia_Repository::find_by_wp_user_id() para resolver la farmacia del
 * usuario y en Documento_Repository::find_by_farmacia_id() para acotar la
 * consulta a esa farmacia, igual que hace Documento_Endpoint con
 * Permissions::puede_ver_documento() para la descarga individual. Sin
 * sesion o sin farmacia vinculada, el estado vacio es el mismo que con
 * cero documentos: nunca un error visible ni un indicio de por que no hay
 * nada que ver.
 *
 * Atributo opcional "vista":
 * - "agrupada" (por defecto): documentos por tipo, en el orden de
 *   Documento_Tipos::get_grupos_listado(), y las facturas ademas por ano y mes
 *   de la FECHA DE LA FACTURA (nunca la de subida) con el mes en espanol; las
 *   facturas sin fecha, en un grupo "Sin fecha". El Modelo 347 (#307), justo
 *   despues, por EJERCICIO (nunca por fecha de subida), sin meses, y los que
 *   no tienen ejercicio en "Sin ejercicio". Secciones colapsables con
 *   <details> nativo, sin JavaScript.
 * - "plana": el listado de siempre, byte a byte (ul/li sin agrupar).
 * El agrupado es solo presentacion: una unica consulta, el filtrado de
 * visibilidad sigue siendo solo de Permissions, y todo se agrupa en memoria.
 *
 * Atributo opcional "tipo" (v0.39.0, una pagina por tipo de documento): una
 * clave de Documento_Tipos::get_grupos_listado() (factura, modelo_347,
 * contrato, presupuesto, entregable, otro). Se aplica DESPUES de
 * Permissions::filtrar_documentos_visibles(): no decide visibilidad, solo
 * elige cuales de los ya visibles se pintan; "otro" recoge tambien los
 * documentos sin tipo, igual que el grupo del listado. En la vista agrupada
 * se omite el <details> del tipo (la pagina ya lleva su titulo) y su
 * contenido va directamente en el contenedor, con la clase
 * mdf-ca-listado-documentos--tipo-{clave}. Un tipo desconocido no lista
 * nada (estado vacio): una pagina "Contratos" mal configurada no debe
 * mostrar todos los documentos. Sin documentos del tipo, el vacio dice
 * cuales faltan ("No hay facturas disponibles.").
 */
class Shortcode_Listado_Documentos {

	private const TAG = 'mdf_ca_listado_documentos';

	/**
	 * Ids de los documentos que el usuario puede descargar en este render
	 * (Permissions::filtrar_documentos_descargables(), una sola consulta de
	 * farmacia para todo el listado).
	 *
	 * @var array<int, true>
	 */
	private static array $descargables = array();

	public static function register_hooks(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function render( $atts = array() ): string {
		$atts     = shortcode_atts(
			array(
				'vista' => 'agrupada',
				'tipo'  => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::TAG
		);
		$tipo     = strtolower( trim( (string) $atts['tipo'] ) );
		$farmacia = self::farmacia_actual();

		if ( '' !== $tipo && ! isset( Documento_Tipos::get_grupos_listado()[ $tipo ] ) ) {
			return self::render_estado_vacio();
		}

		if ( ! $farmacia ) {
			return self::render_estado_vacio( $tipo );
		}

		// La consulta ya viene acotada a la farmacia, pero quien decide que
		// documentos se ven es Permissions (#304: un documento pendiente de
		// publicar no se lista). No se filtra "publicado" en SQL para no
		// tener un segundo sitio que decida visibilidad.
		$documentos = Permissions::filtrar_documentos_visibles(
			wp_get_current_user(),
			( new Documento_Repository() )->find_by_farmacia_id( $farmacia->get_id() )
		);

		// El tipo solo elige entre los ya visibles (presentacion).
		if ( '' !== $tipo ) {
			$documentos = array_values(
				array_filter(
					$documentos,
					static fn( Documento $documento ): bool => Documento_Tipos::clave_para_recuento( $documento->get_tipo_documento() ) === $tipo
				)
			);
		}

		if ( ! $documentos ) {
			return self::render_estado_vacio( $tipo );
		}

		self::$descargables = Permissions::filtrar_documentos_descargables( wp_get_current_user(), $documentos );

		// Estado de los presupuestos (v0.44.0): su CSS, solo si hay alguno.
		foreach ( $documentos as $documento ) {
			if ( $documento->get_presupuesto() ) {
				wp_enqueue_style( 'mdf-ca-listado-documentos', Recursos::url( 'assets/css/listado-documentos.css' ), array(), Recursos::version( 'assets/css/listado-documentos.css' ) );
				break;
			}
		}

		// Cualquier valor que no sea "plana" es la vista agrupada.
		if ( 'plana' === strtolower( trim( (string) $atts['vista'] ) ) ) {
			return self::render_lista( $documentos );
		}

		return '' !== $tipo ? self::render_tipo( $tipo, $documentos ) : self::render_agrupado( $documentos );
	}

	private static function farmacia_actual(): ?Farmacia {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		return ( new Farmacia_Repository() )->find_by_wp_user_id( get_current_user_id() );
	}

	/** Vacio de una pagina por tipo: dice que falta, nunca por que. */
	private const VACIO_POR_TIPO = array(
		'factura'     => 'No hay facturas disponibles.',
		'modelo_347'  => 'No hay cartas del Modelo 347 disponibles.',
		'contrato'    => 'No hay contratos disponibles.',
		'presupuesto' => 'No hay presupuestos disponibles.',
		'entregable'  => 'No hay entregables disponibles.',
		'otro'        => 'No hay otros documentos disponibles.',
	);

	private static function render_estado_vacio( string $tipo = '' ): string {
		return '<p class="mdf-ca-listado-documentos mdf-ca-listado-documentos--vacio">' .
			esc_html( self::VACIO_POR_TIPO[ $tipo ] ?? __( 'No hay documentos disponibles.', 'mdf-client-area' ) ) .
			'</p>';
	}

	/**
	 * Un solo tipo, sin el <details> del grupo: la pagina ya lleva el titulo.
	 * Mismo cuerpo que ese tipo tiene en render_agrupado().
	 *
	 * @param Documento[] $documentos Ya filtrados por Permissions y por tipo.
	 */
	private static function render_tipo( string $tipo, array $documentos ): string {
		return '<div class="mdf-ca-listado-documentos mdf-ca-listado-documentos--agrupado mdf-ca-listado-documentos--tipo-' . esc_attr( $tipo ) . '">'
			. self::cuerpo_grupo( $tipo, $documentos )
			. '</div>';
	}

	/**
	 * @param Documento[] $documentos
	 */
	private static function cuerpo_grupo( string $clave, array $documentos ): string {
		if ( 'factura' === $clave ) {
			return self::render_facturas( $documentos );
		}

		if ( 'modelo_347' === $clave ) {
			return self::render_modelo_347( $documentos );
		}

		return self::render_items( self::ordenar_por_subida( $documentos ), false );
	}

	/**
	 * @param Documento[] $documentos
	 */
	private static function render_lista( array $documentos ): string {
		$items = '';

		foreach ( $documentos as $documento ) {
			$items .= self::render_item( $documento );
		}

		return '<ul class="mdf-ca-listado-documentos">' . $items . '</ul>';
	}

	/**
	 * El enlace apunta al visor (#248), nunca al endpoint de servido (#232)
	 * ni a una ruta de fichero directa (CLAUDE.md, regla no negociable 1):
	 * el visor es quien hace el fetch autenticado al endpoint desde su
	 * propio JS, pero una navegacion normal de <a href> directa al endpoint
	 * abriria el fichero crudo en el visor nativo del navegador (con su
	 * propia barra de imprimir/guardar), justo lo que el visor propio
	 * existe para evitar.
	 */
	private static function render_item( Documento $documento ): string {
		$url   = home_url( 'mdf-ca-visor/' . $documento->get_id() . '/' );
		$fecha = mysql2date( get_option( 'date_format' ), $documento->get_fecha_subida() );

		return '<li class="mdf-ca-listado-documentos__item">'
			. '<a href="' . esc_url( $url ) . '">' . esc_html( $documento->get_nombre() ) . '</a>'
			. ' <span class="mdf-ca-listado-documentos__fecha">' . esc_html( $fecha ) . '</span>'
			. self::estado_presupuesto( $documento )
			. self::enlace_descargar( $documento )
			. '</li>';
	}

	/**
	 * Estado de un presupuesto (v0.44.0): "Pendiente", "Aceptado el
	 * 26/10/2026", "Rechazado el 26/10/2026" o "Aceptado" (puesto por MDF).
	 * Vacio en cualquier otro documento.
	 */
	private static function estado_presupuesto( Documento $documento ): string {
		$presupuesto = $documento->get_presupuesto();

		if ( ! $presupuesto ) {
			return '';
		}

		return ' <span class="mdf-ca-listado-documentos__estado mdf-ca-listado-documentos__estado--' . esc_attr( $presupuesto->get_estado() ) . '">'
			. esc_html( $presupuesto->texto_para_farmacia() ) . '</span>';
	}

	/**
	 * "Descargar" (v0.36.0), solo si Permissions lo permite: va a la ruta de
	 * descarga del endpoint, que vuelve a comprobarlo al servir.
	 */
	private static function enlace_descargar( Documento $documento ): string {
		if ( ! isset( self::$descargables[ $documento->get_id() ] ) ) {
			return '';
		}

		return ' <a class="mdf-ca-listado-documentos__descargar" href="' . esc_url( Documento_Endpoint::url_descarga( $documento->get_id() ) ) . '"'
			. ' aria-label="' . esc_attr( 'Descargar ' . $documento->get_nombre() ) . '">'
			. esc_html__( 'Descargar', 'mdf-client-area' ) . '</a>';
	}

	// ------------------------------------------------------------------
	// Vista agrupada
	// ------------------------------------------------------------------

	/** Nombres de mes en espanol, propios: no dependen del locale del servidor ni del idioma de WordPress. */
	private const MESES = array(
		1  => 'enero',
		2  => 'febrero',
		3  => 'marzo',
		4  => 'abril',
		5  => 'mayo',
		6  => 'junio',
		7  => 'julio',
		8  => 'agosto',
		9  => 'septiembre',
		10 => 'octubre',
		11 => 'noviembre',
		12 => 'diciembre',
	);

	/**
	 * @param Documento[] $documentos Ya filtrados por Permissions.
	 */
	private static function render_agrupado( array $documentos ): string {
		$grupos = array_fill_keys( array_keys( Documento_Tipos::get_grupos_listado() ), array() );

		foreach ( $documentos as $documento ) {
			// Sin tipo o con uno desconocido: "otro".
			$grupos[ Documento_Tipos::clave_para_recuento( $documento->get_tipo_documento() ) ][] = $documento;
		}

		$html = '';

		foreach ( Documento_Tipos::get_grupos_listado() as $clave => $etiqueta ) {
			if ( ! $grupos[ $clave ] ) {
				continue;
			}

			$cuerpo = self::cuerpo_grupo( $clave, $grupos[ $clave ] );

			$html .= self::seccion( 'mdf-ca-listado-documentos__grupo mdf-ca-listado-documentos__grupo--' . $clave, $etiqueta, count( $grupos[ $clave ] ), $cuerpo, true );
		}

		return '<div class="mdf-ca-listado-documentos mdf-ca-listado-documentos--agrupado">' . $html . '</div>';
	}

	/**
	 * Facturas por ano y mes de la fecha de la factura, mas recientes primero;
	 * las que no tienen fecha, en "Sin fecha" al final. La fecha de subida
	 * NUNCA sustituye a la de factura. Solo el ano y el mes mas recientes
	 * salen abiertos.
	 *
	 * @param Documento[] $facturas
	 */
	private static function render_facturas( array $facturas ): string {
		$por_mes   = array(); // 'AAAA-MM' => Documento[]
		$sin_fecha = array();

		foreach ( $facturas as $factura ) {
			$fecha = $factura->get_fecha_documento();

			if ( null === $fecha ) {
				$sin_fecha[] = $factura;
			} else {
				$por_mes[ substr( $fecha, 0, 7 ) ][] = $factura;
			}
		}

		krsort( $por_mes, SORT_STRING );

		$por_anio = array(); // 'AAAA' => array( 'AAAA-MM' => Documento[] )

		foreach ( $por_mes as $anio_mes => $docs ) {
			$por_anio[ substr( (string) $anio_mes, 0, 4 ) ][ $anio_mes ] = $docs;
		}

		$html        = '';
		$primer_anio = true;

		foreach ( $por_anio as $anio => $meses ) {
			$html_meses  = '';
			$total_anio  = 0;
			$primer_mes  = true;

			foreach ( $meses as $anio_mes => $docs ) {
				usort(
					$docs,
					static fn( Documento $a, Documento $b ): int => array( $b->get_fecha_documento(), $b->get_fecha_subida(), $b->get_id() ) <=> array( $a->get_fecha_documento(), $a->get_fecha_subida(), $a->get_id() )
				);

				$nombre_mes  = ucfirst( self::MESES[ (int) substr( (string) $anio_mes, 5, 2 ) ] );
				$html_meses .= self::seccion(
					'mdf-ca-listado-documentos__mes',
					$nombre_mes,
					count( $docs ),
					self::render_items( $docs, true ),
					$primer_anio && $primer_mes,
					array( 'data-mes' => (string) $anio_mes )
				);
				$total_anio += count( $docs );
				$primer_mes  = false;
			}

			$html       .= self::seccion( 'mdf-ca-listado-documentos__anio', (string) $anio, $total_anio, $html_meses, $primer_anio, array( 'data-anio' => (string) $anio ) );
			$primer_anio = false;
		}

		if ( $sin_fecha ) {
			$html .= self::seccion(
				'mdf-ca-listado-documentos__mes mdf-ca-listado-documentos__mes--sin-fecha',
				'Sin fecha',
				count( $sin_fecha ),
				self::render_items( self::ordenar_por_subida( $sin_fecha ), false, true ),
				false
			);
		}

		return $html;
	}

	/**
	 * Modelo 347 por ejercicio, el mas reciente primero, sin meses; dentro de
	 * cada ejercicio, por nombre (los de las dos sociedades juntos). Sin
	 * ejercicio: "Sin ejercicio" al final. La fecha de subida NUNCA sustituye
	 * al ejercicio, y en los ejercicios no se muestra (no es la fecha del
	 * documento). Solo el ejercicio mas reciente sale abierto.
	 *
	 * @param Documento[] $documentos
	 */
	private static function render_modelo_347( array $documentos ): string {
		$por_ejercicio = array(); // AAAA => Documento[]
		$sin_ejercicio = array();

		foreach ( $documentos as $documento ) {
			if ( null === $documento->get_ejercicio() ) {
				$sin_ejercicio[] = $documento;
			} else {
				$por_ejercicio[ $documento->get_ejercicio() ][] = $documento;
			}
		}

		krsort( $por_ejercicio, SORT_NUMERIC );

		$html    = '';
		$primero = true;

		foreach ( $por_ejercicio as $ejercicio => $docs ) {
			usort(
				$docs,
				static fn( Documento $a, Documento $b ): int => array( $a->get_nombre(), $a->get_id() ) <=> array( $b->get_nombre(), $b->get_id() )
			);

			$html   .= self::seccion( 'mdf-ca-listado-documentos__anio', (string) $ejercicio, count( $docs ), self::render_items( $docs, false, false, false ), $primero, array( 'data-anio' => (string) $ejercicio ) );
			$primero = false;
		}

		if ( $sin_ejercicio ) {
			$html .= self::seccion(
				'mdf-ca-listado-documentos__anio mdf-ca-listado-documentos__anio--sin-ejercicio',
				'Sin ejercicio',
				count( $sin_ejercicio ),
				self::render_items( self::ordenar_por_subida( $sin_ejercicio ), false, true ),
				false
			);
		}

		return $html;
	}

	/**
	 * Un bloque colapsable. $cuerpo ya viene escapado; aqui se escapan titulo,
	 * contador y atributos.
	 *
	 * @param array<string, string> $atributos
	 */
	private static function seccion( string $clases, string $titulo, int $total, string $cuerpo, bool $abierta, array $atributos = array() ): string {
		$extra = '';

		foreach ( $atributos as $nombre => $valor ) {
			$extra .= ' ' . esc_attr( $nombre ) . '="' . esc_attr( $valor ) . '"';
		}

		return '<details class="' . esc_attr( $clases ) . '"' . $extra . ( $abierta ? ' open' : '' ) . '>'
			. '<summary class="mdf-ca-listado-documentos__titulo">' . esc_html( $titulo )
			. ' <span class="mdf-ca-listado-documentos__contador">(' . esc_html( (string) $total ) . ')</span></summary>'
			. $cuerpo
			. '</details>';
	}

	/**
	 * @param Documento[] $documentos
	 * @param bool        $con_fecha_factura true: la fecha que se muestra es la de la factura.
	 * @param bool        $es_sin_fecha      true: factura sin fecha (o 347 sin ejercicio); se indica "Subido el", nunca como si fuera la fecha del documento.
	 * @param bool        $con_fecha         false: sin fecha visible (Modelo 347 dentro de su ejercicio).
	 */
	private static function render_items( array $documentos, bool $con_fecha_factura, bool $es_sin_fecha = false, bool $con_fecha = true ): string {
		$items = '';

		foreach ( $documentos as $documento ) {
			$url = home_url( 'mdf-ca-visor/' . $documento->get_id() . '/' );

			if ( ! $con_fecha ) {
				$fecha = '';
			} elseif ( $con_fecha_factura && null !== $documento->get_fecha_documento() ) {
				$fecha = '<span class="mdf-ca-listado-documentos__fecha"><time datetime="' . esc_attr( $documento->get_fecha_documento() ) . '">'
					. esc_html( self::fecha_larga( $documento->get_fecha_documento() ) ) . '</time></span>';
			} elseif ( $es_sin_fecha ) {
				$fecha = '<span class="mdf-ca-listado-documentos__fecha mdf-ca-listado-documentos__fecha--subida">'
					. esc_html( 'Subido el ' . self::fecha_larga( $documento->get_fecha_subida() ) ) . '</span>';
			} else {
				$fecha = '<span class="mdf-ca-listado-documentos__fecha">' . esc_html( self::fecha_larga( $documento->get_fecha_subida() ) ) . '</span>';
			}

			$items .= '<li class="mdf-ca-listado-documentos__item">'
				. '<a href="' . esc_url( $url ) . '">' . esc_html( $documento->get_nombre() ) . '</a>' . ( '' !== $fecha ? ' ' . $fecha : '' )
				. self::estado_presupuesto( $documento )
				. self::enlace_descargar( $documento )
				. '</li>';
		}

		return '<ul class="mdf-ca-listado-documentos__lista">' . $items . '</ul>';
	}

	/**
	 * Por fecha de subida descendente (y por id, para un orden estable).
	 *
	 * @param Documento[] $documentos
	 * @return Documento[]
	 */
	private static function ordenar_por_subida( array $documentos ): array {
		usort(
			$documentos,
			static fn( Documento $a, Documento $b ): int => array( $b->get_fecha_subida(), $b->get_id() ) <=> array( $a->get_fecha_subida(), $a->get_id() )
		);

		return $documentos;
	}

	/** "1 de febrero de 2026" a partir de 'AAAA-MM-DD' (o 'AAAA-MM-DD hh:mm:ss'), con el mes en espanol propio. */
	private static function fecha_larga( string $fecha ): string {
		$partes = explode( '-', substr( $fecha, 0, 10 ) );

		if ( 3 !== count( $partes ) || ! isset( self::MESES[ (int) $partes[1] ] ) ) {
			return '';
		}

		return (int) $partes[2] . ' de ' . self::MESES[ (int) $partes[1] ] . ' de ' . $partes[0];
	}
}
