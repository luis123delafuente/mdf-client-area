<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * smalot/pdfparser con los topes de Pdf_Limites (#333). Solo se usa para
 * abrir el PDF, resolver sus objetos y leer las fuentes; el contenido de las
 * paginas lo interpreta Pdf_Palabras.
 *
 * - Lector de bajo nivel acotado (Pdf_Datos_Acotados) y sin imagenes en
 *   memoria (retainImageContent: las imagenes no se descomprimen siquiera).
 * - Anidamiento de arrays y diccionarios hasta 64 niveles (smalot admite
 *   5000; ningun PDF real pasa de unos pocos).
 * - Cada objeto cuenta para el tope de objetos, y un object stream cuenta
 *   por todos los que contiene ANTES de que smalot los cree: smalot los
 *   construye en un bucle propio, sin pasar por aqui.
 * - smalot carga la tabla ToUnicode de todas las fuentes al terminar de leer
 *   (Document::init()) y recorre cada rango bfrange codigo a codigo:
 *   <00000000> <FFFFFFFF> serian 4.000 millones de entradas. Por eso, al
 *   leer cada flujo, se cuentan los codigos que declara su CMap (si lo es) y
 *   se para antes de que smalot llegue a cargarlo.
 * - parseContent() es una copia del de smalot 2.13.1 con dos cambios:
 *   el documento es un Pdf_Documento_Acotado (sin el recuento de paginas sin
 *   limite de Document::buildDetails()) y un PDF cifrado se rechaza con
 *   Pdf_Cifrado. smalot solo lo detecta si /Encrypt es una referencia a
 *   otro objeto; con el diccionario en linea (como lo escribe PyMuPDF) lo
 *   leia como si no estuviera cifrado y sacaba basura. El diccionario del
 *   trailer o del xref stream nunca va comprimido, asi que basta con
 *   buscarlo en los bytes del fichero. Si se actualiza smalot, hay que
 *   revisar esta copia.
 */
class Pdf_Parser_Acotado extends \Smalot\PdfParser\Parser {

	private const MAX_ANIDAMIENTO = 64;

	/** /Encrypt como referencia (12 0 R) o como diccionario en linea. */
	private const CLAVE_CIFRADO = '/\/Encrypt\s*(?:<<|\d+\s+\d+\s+R)/';

	/** El mismo patron que usa smalot para el indice de un object stream (Parser::getObjectStreamIndex()). */
	private const PAR_INDICE = '/\G[\s\0]*(\d+)[\s\0]+(\d+)[\s\0]*/';

	/** Los mismos patrones que usa smalot para bfchar y bfrange (Font::loadTranslateTable()). */
	private const ENTRADA_BFCHAR  = '/<(?P<from>[0-9A-F]+)> *<(?P<to>[0-9A-F]+)>[ \r\n]+/is';
	private const ENTRADA_BFRANGE = '/<(?P<from>[0-9A-F]+)> *<(?P<to>[0-9A-F]+)> *(?P<dest><[0-9A-F]+>|\[[\r\n<>0-9A-F ]+\])[ \r\n]+/is';

	private Pdf_Limites $limites;

	public function __construct( Pdf_Limites $limites ) {
		$config = new \Smalot\PdfParser\Config();
		$config->setRetainImageContent( false );
		$config->setMaxNestingDepth( self::MAX_ANIDAMIENTO );

		parent::__construct( array(), $config );

		$this->limites       = $limites;
		$this->rawDataParser = new Pdf_Datos_Acotados( $config, $limites );
	}

	public function parseContent( string $content ): \Smalot\PdfParser\Document {
		list( $xref, $pdfData ) = $this->rawDataParser->parseHeaderAndXref( $content );
		unset( $content );

		if ( isset( $xref['trailer']['encrypt'] ) || 1 === preg_match( self::CLAVE_CIFRADO, $pdfData ) ) {
			throw new Pdf_Cifrado( Factura_Lectura_Service::CIFRADO );
		}

		$document      = new Pdf_Documento_Acotado();
		$this->objects = array();

		foreach ( $this->rawDataParser->iterateIndirectObjects( $pdfData, $xref ) as $id => $structure ) {
			$this->parseObject( $id, $structure, $document );
		}

		if ( empty( $this->objects ) ) {
			throw new \Exception( 'Object list not found. Possible secured file.' );
		}

		$document->setTrailer( $this->parseTrailer( $xref['trailer'], $document ) );
		$document->setObjects( $this->objects );

		return $document;
	}

	protected function parseObject( string $id, array $structure, ?\Smalot\PdfParser\Document $document ) {
		$this->limites->contar_objetos();

		foreach ( $structure as $parte ) {
			if ( ! is_array( $parte ) || 'stream' !== ( $parte[0] ?? null ) ) {
				continue;
			}

			$contenido = isset( $parte[3][0] ) && is_string( $parte[3][0] ) ? $parte[3][0] : (string) ( $parte[1] ?? '' );

			if ( self::es_object_stream( $structure ) ) {
				$this->limites->contar_objetos( $this->pares_de_indice( $contenido ) );
			}

			if ( false !== strpos( $contenido, 'beginbf' ) ) {
				$this->limites->contar_codigos( $this->codigos_de_cmap( $contenido ) );
			}
		}

		return parent::parseObject( $id, $structure, $document );
	}

	private static function es_object_stream( array $structure ): bool {
		foreach ( $structure as $parte ) {
			if ( is_array( $parte ) && '<<' === ( $parte[0] ?? null ) && is_array( $parte[1] ?? null ) ) {
				foreach ( $parte[1] as $k => $v ) {
					if ( array( '/', 'Type' ) === array_slice( (array) $v, 0, 2 ) && array( '/', 'ObjStm' ) === array_slice( (array) ( $parte[1][ $k + 1 ] ?? array() ), 0, 2 ) ) {
						return true;
					}
				}
			}
		}

		return false;
	}

	/** Pares "numero desplazamiento" al principio del flujo: cota superior de los objetos que creara smalot. */
	private function pares_de_indice( string $contenido ): int {
		$pares  = 0;
		$offset = 0;
		$tope   = Pdf_Limites::MAX_OBJETOS + 1;

		while ( $pares < $tope && 1 === preg_match( self::PAR_INDICE, $contenido, $m, 0, $offset ) && '' !== $m[0] ) {
			++$pares;
			$offset += strlen( $m[0] );
		}

		return $pares;
	}

	/**
	 * Entradas que smalot metera en la tabla ToUnicode por este flujo: una
	 * por bfchar y (hasta - desde + 1) por bfrange. Se recorre sin crear
	 * arrays de coincidencias y se para en cuanto pasa del tope.
	 */
	private function codigos_de_cmap( string $contenido ): int {
		$tope  = Pdf_Limites::MAX_CODIGOS_TO_UNICODE + 1;
		$total = 0;

		foreach ( array( 'bfchar' => self::ENTRADA_BFCHAR, 'bfrange' => self::ENTRADA_BFRANGE ) as $seccion => $patron ) {
			$desde = 0;

			while ( $total < $tope && false !== ( $inicio = strpos( $contenido, 'begin' . $seccion, $desde ) ) ) {
				$fin    = strpos( $contenido, 'end' . $seccion, $inicio );
				$fin    = false === $fin ? strlen( $contenido ) : $fin;
				$trozo  = substr( $contenido, $inicio, $fin - $inicio );
				$offset = 0;

				// Como preg_match_all(): cada busqueda empieza donde acabo la anterior.
				while ( $total < $tope && 1 === preg_match( $patron, $trozo, $m, PREG_OFFSET_CAPTURE, $offset ) ) {
					$offset = $m[0][1] + strlen( $m[0][0] );

					if ( 'bfchar' === $seccion ) {
						++$total;
						continue;
					}

					$de    = hexdec( $m['from'][0] );
					$hasta = hexdec( $m['to'][0] );
					$total += $hasta >= $de ? (int) min( $tope, $hasta - $de + 1 ) : 0;
				}

				$desde = $fin + 1;
				$this->limites->comprobar();
			}
		}

		return min( $total, $tope );
	}
}
