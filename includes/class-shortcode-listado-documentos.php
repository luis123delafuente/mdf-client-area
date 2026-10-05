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
 *   facturas sin fecha, en un grupo "Sin fecha". Secciones colapsables con
 *   <details> nativo, sin JavaScript.
 * - "plana": el listado de siempre, byte a byte (ul/li sin agrupar).
 * El agrupado es solo presentacion: una unica consulta, el filtrado de
 * visibilidad sigue siendo solo de Permissions, y todo se agrupa en memoria.
 */
class Shortcode_Listado_Documentos {

	private const TAG = 'mdf_ca_listado_documentos';

	public static function register_hooks(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	/**
	 * @param array<string, string>|string $atts
	 */
	public static function render( $atts = array() ): string {
		$atts     = shortcode_atts( array( 'vista' => 'agrupada' ), is_array( $atts ) ? $atts : array(), self::TAG );
		$farmacia = self::farmacia_actual();

		if ( ! $farmacia ) {
			return self::render_estado_vacio();
		}

		// La consulta ya viene acotada a la farmacia, pero quien decide que
		// documentos se ven es Permissions (#304: un documento pendiente de
		// publicar no se lista). No se filtra "publicado" en SQL para no
		// tener un segundo sitio que decida visibilidad.
		$documentos = Permissions::filtrar_documentos_visibles(
			wp_get_current_user(),
			( new Documento_Repository() )->find_by_farmacia_id( $farmacia->get_id() )
		);

		if ( ! $documentos ) {
			return self::render_estado_vacio();
		}

		// Cualquier valor que no sea "plana" es la vista agrupada.
		if ( 'plana' === strtolower( trim( (string) $atts['vista'] ) ) ) {
			return self::render_lista( $documentos );
		}

		return self::render_agrupado( $documentos );
	}

	private static function farmacia_actual(): ?Farmacia {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		return ( new Farmacia_Repository() )->find_by_wp_user_id( get_current_user_id() );
	}

	private static function render_estado_vacio(): string {
		return '<p class="mdf-ca-listado-documentos mdf-ca-listado-documentos--vacio">' .
			esc_html__( 'No hay documentos disponibles.', 'mdf-client-area' ) .
			'</p>';
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
			. '</li>';
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

			$cuerpo = 'factura' === $clave
				? self::render_facturas( $grupos[ $clave ] )
				: self::render_items( self::ordenar_por_subida( $grupos[ $clave ] ), false );

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
	 * @param bool        $es_sin_fecha      true: factura sin fecha; se indica "Subido el", nunca como si fuera la fecha de la factura.
	 */
	private static function render_items( array $documentos, bool $con_fecha_factura, bool $es_sin_fecha = false ): string {
		$items = '';

		foreach ( $documentos as $documento ) {
			$url = home_url( 'mdf-ca-visor/' . $documento->get_id() . '/' );

			if ( $con_fecha_factura && null !== $documento->get_fecha_documento() ) {
				$fecha = '<span class="mdf-ca-listado-documentos__fecha"><time datetime="' . esc_attr( $documento->get_fecha_documento() ) . '">'
					. esc_html( self::fecha_larga( $documento->get_fecha_documento() ) ) . '</time></span>';
			} elseif ( $es_sin_fecha ) {
				$fecha = '<span class="mdf-ca-listado-documentos__fecha mdf-ca-listado-documentos__fecha--subida">'
					. esc_html( 'Subido el ' . self::fecha_larga( $documento->get_fecha_subida() ) ) . '</span>';
			} else {
				$fecha = '<span class="mdf-ca-listado-documentos__fecha">' . esc_html( self::fecha_larga( $documento->get_fecha_subida() ) ) . '</span>';
			}

			$items .= '<li class="mdf-ca-listado-documentos__item">'
				. '<a href="' . esc_url( $url ) . '">' . esc_html( $documento->get_nombre() ) . '</a> ' . $fecha
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
