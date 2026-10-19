<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lo que Pdf_Palabras necesita de una fuente del PDF (#333): ancho de cada
 * glifo, ascendente y descendente (la caja vertical de las palabras) y el
 * texto Unicode de cada codigo.
 *
 * - Fuente simple: /Widths desde /FirstChar; Type0: /W y /DW de su
 *   DescendantFont, codigos de 2 bytes (Identity-H). Sin anchos y sobre una
 *   de las 14 estandar: Pdf_Metricas_Base14.
 * - Ascendente/descendente del FontDescriptor, con el signo normalizado como
 *   hace MuPDF (Chrome/Skia escribe el Descent en positivo, contra la
 *   especificacion); sin FontDescriptor en una estandar, sus metricas.
 * - Unicode: ToUnicode (via smalot, cuyas tablas ya se acotaron al leer el
 *   PDF en Pdf_Parser_Acotado) o, en fuentes simples, WinAnsi o MacRoman.
 *   Encoding /Differences no se aplica (no se ha visto en facturas SAGE).
 *
 * Los rangos de /W se acotan a codigos de 16 bits: [0 4000000000 500] no
 * puede dejar a PHP rellenando un array de 4.000 millones de entradas.
 */
class Pdf_Fuente {

	private const MAX_CODIGO = 0xFFFF;

	public float $ascent    = 0.8;
	public float $descent   = -0.2;
	public int $bytes       = 1;
	public string $base_font = '';

	/** @var array<int, float> codigo => ancho en em */
	private array $anchos         = array();
	private float $ancho_defecto  = 0.0;
	private bool $tiene_to_unicode = false;
	private bool $tiene_descriptor = false;
	private string $codificacion   = 'WinAnsiEncoding';
	private ?\Smalot\PdfParser\Font $fuente;

	/** @var array{ascent: float, descent: float, anchos: list<int>}|null */
	private ?array $estandar = null;

	public function __construct( ?\Smalot\PdfParser\Font $fuente ) {
		$this->fuente = $fuente;

		if ( null === $fuente ) {
			return;
		}

		$subtipo         = self::nombre( $fuente->get( 'Subtype' ) );
		$base            = (string) preg_replace( '/^[A-Z]{6}\+/', '', self::nombre( $fuente->get( 'BaseFont' ) ) );
		$this->base_font = $base;
		$codificacion    = self::nombre( $fuente->get( 'Encoding' ) );
		$estandar        = Pdf_Metricas_Base14::de( $base );

		$this->tiene_to_unicode = $fuente->has( 'ToUnicode' );
		$this->codificacion     = '' !== $codificacion ? $codificacion : 'WinAnsiEncoding';

		if ( 'Type0' === $subtipo ) {
			$this->bytes = 2;
			$descendiente = self::array_de( $fuente->get( 'DescendantFonts' ) )[0] ?? null;

			if ( $descendiente instanceof \Smalot\PdfParser\PDFObject ) {
				$this->ancho_defecto = ( self::numero( $descendiente->get( 'DW' ) ) ?? 1000.0 ) / 1000;
				$this->leer_w( self::array_de( $descendiente->get( 'W' ) ) );
				$this->leer_descriptor( $descendiente->get( 'FontDescriptor' ) );
			}

			if ( ! $this->anchos && $estandar ) {
				// Type0 sin /W sobre una fuente estandar (p. ej. la de TextWriter de
				// PyMuPDF): anchos por Unicode con la tabla estandar.
				$this->ancho_defecto = -1.0;
			}
		} else {
			$primero = (int) ( self::numero( $fuente->get( 'FirstChar' ) ) ?? 0 );

			foreach ( self::array_de( $fuente->get( 'Widths' ) ) as $i => $w ) {
				$this->anchos[ $primero + $i ] = ( self::numero( $w ) ?? 0.0 ) / 1000;
			}

			$this->leer_descriptor( $fuente->get( 'FontDescriptor' ) );

			if ( ! $this->anchos && $estandar ) {
				foreach ( $estandar['anchos'] as $i => $w ) {
					$this->anchos[ 32 + $i ] = $w / 1000;
				}
			}
		}

		if ( $estandar && ! $fuente->has( 'FontDescriptor' ) && 'Type0' !== $subtipo ) {
			$this->ascent  = $estandar['ascent'];
			$this->descent = $estandar['descent'];
		}

		if ( 'Type0' === $subtipo && $estandar && ! $this->tiene_descriptor ) {
			$this->ascent  = $estandar['ascent'];
			$this->descent = $estandar['descent'];
		}

		$this->estandar = $estandar;
	}

	/** @return list<array{codigo: int, texto: string}> */
	public function codigos( string $bytes ): array {
		$salida = array();
		$paso   = $this->bytes;

		for ( $i = 0, $n = strlen( $bytes ); $i + $paso <= $n; $i += $paso ) {
			$trozo    = substr( $bytes, $i, $paso );
			$codigo   = 2 === $paso ? ( ord( $trozo[0] ) << 8 ) | ord( $trozo[1] ) : ord( $trozo );
			$salida[] = array(
				'codigo' => $codigo,
				'texto'  => $this->unicode( $trozo ),
			);
		}

		return $salida;
	}

	/** Ancho del glifo en em. */
	public function ancho( int $codigo, string $texto ): float {
		if ( isset( $this->anchos[ $codigo ] ) ) {
			return $this->anchos[ $codigo ];
		}

		if ( -1.0 === $this->ancho_defecto && $this->estandar && '' !== $texto ) {
			// Por Unicode -> codigo WinAnsi -> tabla estandar.
			$cp1252 = @mb_convert_encoding( $texto, 'Windows-1252', 'UTF-8' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$c      = is_string( $cp1252 ) && 1 === strlen( $cp1252 ) ? ord( $cp1252 ) : 0;

			return isset( $this->estandar['anchos'][ $c - 32 ] ) ? $this->estandar['anchos'][ $c - 32 ] / 1000 : 0.5;
		}

		return $this->ancho_defecto > 0 ? $this->ancho_defecto : 0.5;
	}

	private function unicode( string $trozo ): string {
		if ( $this->tiene_to_unicode && null !== $this->fuente ) {
			$t = $this->fuente->translateChar( $trozo, false );

			if ( null !== $t && '' !== $t ) {
				return (string) $t;
			}
		}

		if ( 1 === $this->bytes ) {
			// mbstring no tiene MacRoman (lanza ValueError): iconv si.
			$t = 'MacRomanEncoding' === $this->codificacion
				? @iconv( 'MACINTOSH', 'UTF-8', $trozo ) // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				: mb_convert_encoding( $trozo, 'UTF-8', 'Windows-1252' );

			return false === $t ? '' : $t;
		}

		return '';
	}

	private function leer_descriptor( $descriptor ): void {
		if ( ! $descriptor instanceof \Smalot\PdfParser\PDFObject ) {
			return;
		}

		$asc = self::numero( $descriptor->get( 'Ascent' ) );
		$des = self::numero( $descriptor->get( 'Descent' ) );

		if ( null !== $asc && null !== $des && 0.0 !== $asc ) {
			$this->ascent           = abs( $asc ) / 1000;
			$this->descent          = -abs( $des ) / 1000;
			$this->tiene_descriptor = true;
		}
	}

	/** /W de una CIDFont: [c [w1 w2 ...]] o [c1 c2 w], con los codigos acotados a 16 bits. */
	private function leer_w( array $w ): void {
		$i = 0;
		$n = count( $w );

		while ( $i < $n ) {
			$c = self::numero( $w[ $i ] );

			if ( null === $c || $i + 1 >= $n ) {
				break;
			}

			$siguiente = $w[ $i + 1 ];
			$lista     = self::array_de( $siguiente );

			if ( $lista ) {
				foreach ( $lista as $k => $ancho ) {
					if ( $c + $k >= 0 && $c + $k <= self::MAX_CODIGO ) {
						$this->anchos[ (int) $c + $k ] = ( self::numero( $ancho ) ?? 0.0 ) / 1000;
					}
				}
				$i += 2;
			} else {
				$hasta = self::numero( $siguiente );
				$ancho = self::numero( $w[ $i + 2 ] ?? null );

				if ( null !== $hasta && null !== $ancho ) {
					for ( $k = (int) max( 0, $c ), $fin = (int) min( $hasta, self::MAX_CODIGO ); $k <= $fin; $k++ ) {
						$this->anchos[ $k ] = $ancho / 1000;
					}
				}
				$i += 3;
			}
		}
	}

	public static function nombre( $elemento ): string {
		if ( null === $elemento ) {
			return '';
		}

		$valor = $elemento instanceof \Smalot\PdfParser\Element || $elemento instanceof \Smalot\PdfParser\PDFObject ? $elemento->getContent() : $elemento;

		return is_scalar( $valor ) ? ltrim( (string) $valor, '/' ) : '';
	}

	public static function numero( $elemento ): ?float {
		if ( null === $elemento ) {
			return null;
		}

		$valor = is_object( $elemento ) && method_exists( $elemento, 'getContent' ) ? $elemento->getContent() : $elemento;

		return is_numeric( $valor ) ? (float) $valor : null;
	}

	/** @return list<mixed> */
	public static function array_de( $elemento ): array {
		if ( $elemento instanceof \Smalot\PdfParser\Element\ElementArray ) {
			return array_values( $elemento->getContent() );
		}

		if ( is_array( $elemento ) ) {
			return array_values( $elemento );
		}

		return array();
	}
}
