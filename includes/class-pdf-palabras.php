<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Palabras de una pagina con su caja, como `page.get_text("words")` de
 * PyMuPDF (#333; validado en la prueba de concepto #332 contra PyMuPDF con
 * PDF de PyMuPDF, de Chrome/Skia, de dompdf y con hipotesis de cm, Tm, Tz,
 * Tc, Tw, ', ", T*, Form XObject, MediaBox, CropBox y Rotate).
 *
 * Ninguna libreria PHP pura avanza el cursor de texto glifo a glifo, asi que
 * el estado grafico y de texto se interpreta aqui: q/Q, cm, BT, Tf, Tc, Tw,
 * Tz, TL, Ts, Td, TD, Tm, T*, Tj, TJ, ', " y Do (Form XObject, con su
 * /Matrix y sus recursos). Cada glifo avanza con su ancho (Pdf_Fuente) y su
 * caja vertical sale del ascendente/descendente, como en MuPDF. Una palabra
 * se corta en los blancos (tambien el espacio duro), en un cambio de linea
 * base, en un hueco de mas de 0,15 em o al volver atras mas de 0,5 em.
 *
 * Coordenadas como PyMuPDF: origen arriba a la izquierda de la caja visible
 * (CropBox recortada a la MediaBox), sin aplicar /Rotate.
 *
 * Una instancia por documento: guarda las fuentes ya preparadas. Un Form
 * XObject que se llama a si mismo (directa o indirectamente) no se vuelve a
 * ejecutar, como hace MuPDF, y el anidamiento, las operaciones y las
 * palabras por pagina estan acotados por Pdf_Limites.
 */
class Pdf_Palabras {

	/** Hueco horizontal (en em del tamano de letra) a partir del cual se separan palabras, como el espacio que inserta MuPDF. */
	private const HUECO_PALABRA_EM = 0.15;

	private const IDENTIDAD = array( 1.0, 0.0, 0.0, 1.0, 0.0, 0.0 );

	private Pdf_Limites $limites;

	/** Con true, cada palabra lleva un sexto elemento con datos de diagnostico (matrices, fuente, tamano, origen; nunca texto). */
	private bool $diagnostico;

	/** @var array<string, Pdf_Fuente> */
	private array $fuentes = array();

	/** @var array<int, true> Form XObjects en ejecucion (spl_object_id). */
	private array $forms_en_curso = array();

	/** @var array{x_min: float, y_max: float, palabras: list<array>, actual: ?array} */
	private array $ctx = array();

	public function __construct( Pdf_Limites $limites, bool $diagnostico = false ) {
		$this->limites     = $limites;
		$this->diagnostico = $diagnostico;
	}

	/**
	 * @return list<array> Como PyMuPDF: x0, y0, x1, y1, texto; con diagnostico,
	 *                     un sexto elemento con datos de diagnostico.
	 */
	public function de_pagina( \Smalot\PdfParser\Page $pagina ): array {
		$caja      = self::caja( $pagina );
		$this->ctx = array(
			'x_min'    => $caja[0],
			'y_max'    => $caja[3],
			'palabras' => array(),
			'actual'   => null,
		);

		$estado = array(
			'fuente' => '',
			'tam'    => 0.0,
			'tc'     => 0.0,
			'tw'     => 0.0,
			'th'     => 1.0,
			'tl'     => 0.0,
			'rise'   => 0.0,
		);

		$this->ejecutar( self::contenido( $pagina ), self::IDENTIDAD, $estado, $pagina, $pagina, 0, 'pagina' );
		$this->cerrar();

		$palabras  = $this->ctx['palabras'];
		$this->ctx = array();

		return array_map(
			fn( array $p ): array => $this->diagnostico ? array( $p[0], $p[1], $p[2], $p[3], $p[4], $p[6] ) : array( $p[0], $p[1], $p[2], $p[3], $p[4] ),
			$palabras
		);
	}

	/**
	 * Caja visible de la pagina como la usa MuPDF: CropBox (recortada a la
	 * MediaBox) o, si no hay, MediaBox; heredables de los nodos padre.
	 *
	 * @return array{0: float, 1: float, 2: float, 3: float}
	 */
	public static function caja( \Smalot\PdfParser\Page $pagina, string $clave = 'CropBox' ): array {
		$media = self::rectangulo( self::heredado( $pagina, 'MediaBox' ) ) ?? array( 0.0, 0.0, 612.0, 792.0 );

		if ( 'MediaBox' === $clave ) {
			return $media;
		}

		$crop = self::rectangulo( self::heredado( $pagina, 'CropBox' ) );

		if ( null === $crop ) {
			return $media;
		}

		return array( max( $crop[0], $media[0] ), max( $crop[1], $media[1] ), min( $crop[2], $media[2] ), min( $crop[3], $media[3] ) );
	}

	public static function rotacion( \Smalot\PdfParser\Page $pagina ): int {
		return ( (int) ( Pdf_Fuente::numero( self::heredado( $pagina, 'Rotate' ) ) ?? 0 ) % 360 + 360 ) % 360;
	}

	/** Bytes del contenido de la pagina (uno o varios flujos, ya descomprimidos por smalot). */
	public static function contenido( \Smalot\PdfParser\PDFObject $objeto ): string {
		$contents = $objeto instanceof \Smalot\PdfParser\Page ? $objeto->get( 'Contents' ) : $objeto;

		if ( $contents instanceof \Smalot\PdfParser\Element\ElementArray ) {
			$texto = '';

			foreach ( $contents->getContent() as $flujo ) {
				$texto .= ( is_object( $flujo ) && method_exists( $flujo, 'getContent' ) ? (string) $flujo->getContent() : '' ) . "\n";
			}

			return $texto;
		}

		return is_object( $contents ) && method_exists( $contents, 'getContent' ) ? (string) $contents->getContent() : '';
	}

	/** a x b para matrices PDF [a b c d e f]. */
	public static function multiplicar( array $a, array $b ): array {
		return array(
			$a[0] * $b[0] + $a[1] * $b[2],
			$a[0] * $b[1] + $a[1] * $b[3],
			$a[2] * $b[0] + $a[3] * $b[2],
			$a[2] * $b[1] + $a[3] * $b[3],
			$a[4] * $b[0] + $a[5] * $b[2] + $b[4],
			$a[4] * $b[1] + $a[5] * $b[3] + $b[5],
		);
	}

	private static function heredado( \Smalot\PdfParser\PDFObject $nodo, string $clave ) {
		for ( $k = 0; $k < 32 && $nodo instanceof \Smalot\PdfParser\PDFObject; $k++ ) {
			if ( $nodo->has( $clave ) ) {
				return $nodo->get( $clave );
			}
			$nodo = $nodo->get( 'Parent' );
		}

		return null;
	}

	/** @return array{0: float, 1: float, 2: float, 3: float}|null normalizado (x0 < x1, y0 < y1) */
	private static function rectangulo( $valor ): ?array {
		$v = array_map( static fn( $e ): float => Pdf_Fuente::numero( $e ) ?? 0.0, Pdf_Fuente::array_de( $valor ) );

		if ( 4 !== count( $v ) ) {
			return null;
		}

		return array( min( $v[0], $v[2] ), min( $v[1], $v[3] ), max( $v[0], $v[2] ), max( $v[1], $v[3] ) );
	}

	private function cerrar(): void {
		if ( null !== $this->ctx['actual'] && '' !== $this->ctx['actual'][4] ) {
			if ( count( $this->ctx['palabras'] ) >= Pdf_Limites::MAX_PALABRAS_PAGINA ) {
				$this->limites->exceder( 'una página del PDF tiene demasiadas palabras' );
			}

			$this->ctx['palabras'][] = $this->ctx['actual'];
		}

		$this->ctx['actual'] = null;
	}

	/** Fuente por nombre de recurso: primero en los recursos del Form XObject, luego en la pagina. */
	private function fuente( string $nombre, \Smalot\PdfParser\PDFObject $recursos_de, \Smalot\PdfParser\Page $pagina ): Pdf_Fuente {
		$clave = spl_object_id( $recursos_de ) . ':' . $nombre;

		if ( isset( $this->fuentes[ $clave ] ) ) {
			return $this->fuentes[ $clave ];
		}

		$font = null;

		if ( ! $recursos_de instanceof \Smalot\PdfParser\Page ) {
			$res   = $recursos_de->get( 'Resources' );
			$fonts = is_object( $res ) && method_exists( $res, 'get' ) ? $res->get( 'Font' ) : null;
			$f     = is_object( $fonts ) && method_exists( $fonts, 'get' ) ? $fonts->get( $nombre ) : null;
			$font  = $f instanceof \Smalot\PdfParser\Font ? $f : null;
		}

		$font ??= $pagina->getFont( $nombre );

		return $this->fuentes[ $clave ] = new Pdf_Fuente( $font );
	}

	private static function xobject( string $nombre, \Smalot\PdfParser\PDFObject $recursos_de, \Smalot\PdfParser\Page $pagina ): ?\Smalot\PdfParser\PDFObject {
		if ( $recursos_de instanceof \Smalot\PdfParser\Page ) {
			return $pagina->getXObject( $nombre );
		}

		$res = $recursos_de->get( 'Resources' );
		$xos = is_object( $res ) && method_exists( $res, 'get' ) ? $res->get( 'XObject' ) : null;
		$xo  = is_object( $xos ) && method_exists( $xos, 'get' ) ? $xos->get( $nombre ) : null;

		return $xo instanceof \Smalot\PdfParser\PDFObject ? $xo : $pagina->getXObject( $nombre );
	}

	/** Ultimo operando de tipo nombre (p. ej. el de Tf o Do). */
	private static function nombre_operando( array $args ): ?string {
		$nombre = null;

		foreach ( $args as $x ) {
			if ( is_array( $x ) && 'n' === ( $x[0] ?? '' ) ) {
				$nombre = (string) $x[1];
			}
		}

		return $nombre;
	}

	private function ejecutar( string $contenido, array $ctm, array $estado, \Smalot\PdfParser\PDFObject $recursos_de, \Smalot\PdfParser\Page $pagina, int $profundidad, string $origen ): void {
		$pila           = array();
		$tm             = self::IDENTIDAD;
		$tlm            = self::IDENTIDAD;
		$tj_con_numeros = false;

		foreach ( Pdf_Contenido::operaciones( $contenido, $this->limites ) as $o ) {
			$op = $o['op'];
			$a  = $o['args'];
			$n  = array_values( array_filter( $a, 'is_float' ) );

			switch ( $op ) {
				case 'q':
					$pila[] = array( $ctm, $estado );
					break;
				case 'Q':
					if ( $pila ) {
						list( $ctm, $estado ) = array_pop( $pila );
					}
					break;
				case 'cm':
					if ( 6 === count( $n ) ) {
						$ctm = self::multiplicar( $n, $ctm );
					}
					break;
				case 'BT':
					$tm  = self::IDENTIDAD;
					$tlm = self::IDENTIDAD;
					break;
				case 'Tf':
					$estado['fuente'] = (string) self::nombre_operando( $a );
					$estado['tam']    = $n[0] ?? 0.0;
					break;
				case 'Tc':
					$estado['tc'] = $n[0] ?? 0.0;
					break;
				case 'Tw':
					$estado['tw'] = $n[0] ?? 0.0;
					break;
				case 'Tz':
					$estado['th'] = ( $n[0] ?? 100.0 ) / 100;
					break;
				case 'TL':
					$estado['tl'] = $n[0] ?? 0.0;
					break;
				case 'Ts':
					$estado['rise'] = $n[0] ?? 0.0;
					break;
				case 'Td':
				case 'TD':
					if ( 'TD' === $op ) {
						$estado['tl'] = -( $n[1] ?? 0.0 );
					}
					$tlm = self::multiplicar( array( 1, 0, 0, 1, $n[0] ?? 0.0, $n[1] ?? 0.0 ), $tlm );
					$tm  = $tlm;
					break;
				case 'Tm':
					if ( 6 === count( $n ) ) {
						$tm  = $n;
						$tlm = $n;
					}
					break;
				case 'T*':
					$tlm = self::multiplicar( array( 1, 0, 0, 1, 0, -$estado['tl'] ), $tlm );
					$tm  = $tlm;
					break;
				case 'Do':
					$this->ejecutar_form( self::nombre_operando( $a ), $ctm, $estado, $recursos_de, $pagina, $profundidad );
					break;
				case "'":
				case '"':
				case 'Tj':
				case 'TJ':
					if ( "'" === $op || '"' === $op ) {
						if ( '"' === $op && count( $n ) >= 2 ) {
							$estado['tw'] = $n[0];
							$estado['tc'] = $n[1];
						}
						$tlm = self::multiplicar( array( 1, 0, 0, 1, 0, -$estado['tl'] ), $tlm );
						$tm  = $tlm;
					}

					$fuente = $this->fuente( $estado['fuente'], $recursos_de, $pagina );
					$trozos = array();

					foreach ( $a as $x ) {
						if ( 'TJ' === $op && is_array( $x ) && array_is_list( $x ) && ( ! isset( $x[0] ) || 's' !== $x[0] || ! is_string( $x[1] ?? null ) ) ) {
							$trozos = $x; // el array del TJ
						} elseif ( is_array( $x ) && 's' === ( $x[0] ?? '' ) ) {
							$trozos = array( $x );
						}
					}

					$tj_con_numeros = (bool) array_filter( $trozos, 'is_float' );
					$tm_inicio      = $tm;

					foreach ( $trozos as $trozo ) {
						if ( is_float( $trozo ) ) {
							$tx = -$trozo / 1000 * $estado['tam'] * $estado['th'];
							$tm = self::multiplicar( array( 1, 0, 0, 1, $tx, 0 ), $tm );
							continue;
						}

						if ( ! is_array( $trozo ) || 's' !== ( $trozo[0] ?? '' ) ) {
							continue;
						}

						foreach ( $fuente->codigos( (string) $trozo[1] ) as $glifo ) {
							$w0  = $fuente->ancho( $glifo['codigo'], $glifo['texto'] );
							$trm = self::multiplicar( array( $estado['tam'] * $estado['th'], 0, 0, $estado['tam'], 0, $estado['rise'] ), self::multiplicar( $tm, $ctm ) );

							$x       = $trm[4] - $this->ctx['x_min'];
							$y_base  = $trm[5];
							$tam_v   = abs( $trm[3] );
							$ancho   = $w0 * $trm[0];
							$espacio = 32 === $glifo['codigo'] && 1 === $fuente->bytes;
							$texto   = $glifo['texto'];

							// Separador: cualquier blanco Unicode, tambien el espacio duro
							// (U+00A0), que MuPDF tampoco mete en las palabras.
							if ( $espacio || 1 === preg_match( '/^[\s\x{00A0}\x{2000}-\x{200B}\x{3000}]*$/u', $texto ) ) {
								$this->cerrar();
							} else {
								$y0 = $this->ctx['y_max'] - ( $y_base + $fuente->ascent * $tam_v );
								$y1 = $this->ctx['y_max'] - ( $y_base + $fuente->descent * $tam_v );

								$actual = $this->ctx['actual'];

								if ( null !== $actual && (
									abs( $actual[5] - $y_base ) > 0.1 * $tam_v
									|| $x - $actual[2] > self::HUECO_PALABRA_EM * $tam_v
									|| $x < $actual[2] - 0.5 * $tam_v
								) ) {
									$this->cerrar();
								}

								if ( null === $this->ctx['actual'] ) {
									$diag = $this->diagnostico ? array(
										'ctm'            => array_map( static fn( $v ) => round( $v, 3 ), $ctm ),
										'tm'             => array_map( static fn( $v ) => round( $v, 3 ), $tm_inicio ),
										'fuente'         => $estado['fuente'],
										'base_font'      => $fuente->base_font,
										'tam'            => round( $estado['tam'], 2 ),
										'tam_efectivo'   => round( $tam_v, 2 ),
										'operador'       => $op,
										'tj_con_numeros' => $tj_con_numeros,
										'tj_empieza_num' => 'TJ' === $op && isset( $trozos[0] ) && is_float( $trozos[0] ),
										'origen'         => $origen,
									) : null;

									$this->ctx['actual'] = array( $x, $y0, $x + $ancho, $y1, $texto, $y_base, $diag );
								} else {
									$this->ctx['actual'][2]  = $x + $ancho;
									$this->ctx['actual'][4] .= $texto;
									$this->ctx['actual'][1]  = min( $this->ctx['actual'][1], $y0 );
									$this->ctx['actual'][3]  = max( $this->ctx['actual'][3], $y1 );
								}
							}

							$tx = ( $w0 * $estado['tam'] + $estado['tc'] + ( $espacio ? $estado['tw'] : 0.0 ) ) * $estado['th'];
							$tm = self::multiplicar( array( 1, 0, 0, 1, $tx, 0 ), $tm );
						}
					}
					break;
			}
		}
	}

	/** Do de un Form XObject: su contenido con su /Matrix sobre el CTM actual y sus propios recursos. */
	private function ejecutar_form( ?string $nombre, array $ctm, array $estado, \Smalot\PdfParser\PDFObject $recursos_de, \Smalot\PdfParser\Page $pagina, int $profundidad ): void {
		if ( null === $nombre || $profundidad >= Pdf_Limites::MAX_PROFUNDIDAD_FORMS ) {
			return;
		}

		$xo = self::xobject( $nombre, $recursos_de, $pagina );

		if ( ! $xo || 'Form' !== Pdf_Fuente::nombre( $xo->get( 'Subtype' ) ) ) {
			return;
		}

		$id = spl_object_id( $xo );

		if ( isset( $this->forms_en_curso[ $id ] ) ) {
			return;
		}

		$m = array_map( static fn( $e ): float => Pdf_Fuente::numero( $e ) ?? 0.0, Pdf_Fuente::array_de( $xo->get( 'Matrix' ) ) );
		$m = 6 === count( $m ) ? $m : self::IDENTIDAD;

		$this->forms_en_curso[ $id ] = true;

		try {
			$this->ejecutar( (string) $xo->getContent(), self::multiplicar( $m, $ctm ), $estado, $xo, $pagina, $profundidad + 1, 'xobject:' . $nombre );
		} finally {
			unset( $this->forms_en_curso[ $id ] );
		}
	}
}
