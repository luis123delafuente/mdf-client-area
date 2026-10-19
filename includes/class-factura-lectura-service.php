<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lectura de una factura SAGE en PDF en el propio servidor (#333), sin
 * Python ni binarios: el mismo resultado que el parser de Python
 * (Parser_facturas_SAGE), con los mismos campos y estados, listo para
 * Factura_Cruce_Service::cruzar() igual que el que envia el robot.
 *
 *     $resultado = ( new Factura_Lectura_Service() )->analizar( $ruta, $nombre_original );
 *     $cruce     = ( new Factura_Cruce_Service() )->cruzar( $resultado );
 *
 * Por partes: Pdf_Parser_Acotado (smalot/pdfparser, vendorizado en lib/)
 * abre el PDF y resuelve objetos y fuentes; Pdf_Palabras interpreta el
 * contenido de cada pagina y saca sus palabras con caja, como PyMuPDF; y
 * Factura_Sage_Parser aplica las reglas de la plantilla.
 *
 * El PDF lo sube un administrador, pero se trata como entrada no fiable:
 * - topes de tamano, paginas, objetos, bytes descomprimidos (por flujo y en
 *   total), operaciones, palabras y tiempo (Pdf_Limites), comprobados
 *   mientras se lee, antes de que la memoria o el tiempo se disparen;
 * - nunca lanza: cualquier fallo del lector o tope superado es
 *   formato_inesperado (o sin_texto si no hay texto), con un motivo fijo;
 * - nada del PDF se ejecuta ni se incluye, y nada se escribe en disco;
 * - no registra nada, y los avisos de PHP de las librerias se silencian
 *   mientras dura la lectura: un aviso podria citar contenido del PDF y
 *   acabar en el registro de errores. Los motivos no llevan valores.
 *
 * Si al servidor le faltan extensiones (Requisitos_Servidor), devuelve
 * formato_inesperado con un motivo que lo dice; las pantallas deben
 * comprobarlo antes y no ofrecer la subida.
 */
class Factura_Lectura_Service {

	/** Motivos fijos que "Subir facturas" (#334) explica a su manera. */
	public const NO_SE_ABRE = 'el fichero no se puede abrir como PDF';
	public const CIFRADO    = 'el PDF está cifrado o protegido con contraseña';

	private bool $leido = false;

	/**
	 * @param string           $ruta           PDF en disco (p. ej. el temporal de la subida).
	 * @param string           $nombre_fichero Nombre original del fichero: se contrasta con
	 *                                         serie y numero (numero_discrepante), nunca identifica nada.
	 * @param Pdf_Limites|null $limites        Solo para pruebas; por defecto, los topes normales.
	 * @return array{fichero: string, estado: string, motivo: string, emisor: ?string, cif: ?string, serie: ?string, numero: ?string, fecha: ?string, importe_total_centimos: ?int, es_abono: bool, campos_no_extraidos: list<string>, paginas: ?int}
	 */
	public function analizar( string $ruta, string $nombre_fichero, ?Pdf_Limites $limites = null ): array {
		$this->leido = false;
		$fichero     = basename( $nombre_fichero );
		$resultado = Factura_Sage_Parser::resultado( $fichero );
		$falta     = Requisitos_Servidor::mensaje( Requisitos_Servidor::LECTURA_FACTURAS );

		if ( null !== $falta ) {
			return Factura_Sage_Parser::con_estado( $resultado, Factura_Sage_Parser::FORMATO_INESPERADO, $falta );
		}

		$tamano = is_file( $ruta ) && is_readable( $ruta ) ? filesize( $ruta ) : false;

		if ( false === $tamano || 0 === $tamano ) {
			return Factura_Sage_Parser::con_estado( $resultado, Factura_Sage_Parser::FORMATO_INESPERADO, self::NO_SE_ABRE );
		}

		if ( $tamano > Pdf_Limites::MAX_BYTES_FICHERO ) {
			return Factura_Sage_Parser::con_estado(
				$resultado,
				Factura_Sage_Parser::FORMATO_INESPERADO,
				sprintf( 'el PDF supera el tamaño máximo (%d MB)', Pdf_Limites::MAX_BYTES_FICHERO / 1048576 )
			);
		}

		if ( ! self::empieza_como_pdf( $ruta ) ) {
			return Factura_Sage_Parser::con_estado( $resultado, Factura_Sage_Parser::FORMATO_INESPERADO, self::NO_SE_ABRE );
		}

		$limites ??= new Pdf_Limites();

		// Solo avisos y notices (los errores fatales no pasan por aqui): ver la
		// cabecera. Se restaura siempre en el finally.
		set_error_handler( static fn(): bool => true, E_WARNING | E_NOTICE | E_USER_WARNING | E_USER_NOTICE | E_DEPRECATED | E_USER_DEPRECATED );

		try {
			return $this->leer( $ruta, $fichero, $resultado, $limites );
		} finally {
			restore_error_handler();
			// smalot deja referencias circulares entre documento y objetos: en
			// una subida de muchas facturas, la memoria de cada una se libera aqui.
			gc_collect_cycles();
		}
	}

	private function leer( string $ruta, string $fichero, array $resultado, Pdf_Limites $limites ): array {
		Librerias::cargar();

		try {
			$documento = ( new Pdf_Parser_Acotado( $limites ) )->parseFile( $ruta );
		} catch ( Pdf_Cifrado $e ) {
			return Factura_Sage_Parser::con_estado( $resultado, Factura_Sage_Parser::FORMATO_INESPERADO, $e->getMessage() );
		} catch ( \Throwable $e ) {
			return Factura_Sage_Parser::con_estado( $resultado, Factura_Sage_Parser::FORMATO_INESPERADO, $limites->excedido() ?? self::NO_SE_ABRE );
		}

		try {
			$total = self::contar_paginas( $documento );

			if ( $total > Pdf_Limites::MAX_PAGINAS ) {
				return Factura_Sage_Parser::con_estado(
					$resultado,
					Factura_Sage_Parser::FORMATO_INESPERADO,
					sprintf( 'el PDF tiene más de %d páginas', Pdf_Limites::MAX_PAGINAS )
				);
			}

			$paginas   = $documento->getPages();
			$extractor = new Pdf_Palabras( $limites );
			$palabras  = array();

			foreach ( $paginas as $pagina ) {
				$palabras[] = $extractor->de_pagina( $pagina );
			}
		} catch ( \Throwable $e ) {
			$resultado['paginas'] = isset( $paginas ) ? count( $paginas ) : null;
			$motivo               = $e instanceof Pdf_Limite_Excedido ? $e->getMessage() : $limites->excedido();

			return Factura_Sage_Parser::con_estado(
				$resultado,
				Factura_Sage_Parser::FORMATO_INESPERADO,
				$motivo ?? 'no se ha podido leer el contenido de las páginas del PDF'
			);
		}

		$this->leido = true;

		return Factura_Sage_Parser::parsear( $palabras, $fichero );
	}

	/**
	 * Si en la ultima llamada a analizar() el PDF se leyo entero y el
	 * resultado sale de las reglas de la factura (sea ok o no). false si se
	 * corto antes: topes de Pdf_Limites, tamano, cifrado, no se puede abrir o
	 * faltan extensiones. La bandeja de facturas (#335) solo guarda lo leido.
	 */
	public function se_pudo_leer(): bool {
		return $this->leido;
	}

	/** La cabecera %PDF- en el primer KB, como exigen los lectores (MuPDF tambien la busca ahi). */
	private static function empieza_como_pdf( string $ruta ): bool {
		$f = fopen( $ruta, 'rb' );

		if ( false === $f ) {
			return false;
		}

		$inicio = (string) fread( $f, 1024 );
		fclose( $f );

		return false !== strpos( $inicio, '%PDF-' );
	}

	/**
	 * Paginas que devolvera Document::getPages(), contadas antes de pedirlas
	 * (Pdf_Documento_Acotado evita que smalot las pida al leer):
	 * smalot recorre el arbol de paginas sin limite y, aunque evita los
	 * ciclos, un arbol con nodos compartidos (cada nodo con dos hijos que son
	 * el mismo) se multiplica por dos en cada nivel. Mismo orden de busqueda
	 * que getPages(): catalogo, nodos /Pages sueltos o paginas sueltas. Se
	 * para en cuanto pasa del tope de paginas o de nodos.
	 */
	private static function contar_paginas( \Smalot\PdfParser\Document $documento ): int {
		$visitas = 0;

		if ( $documento->hasObjectsByType( 'Catalog' ) ) {
			$catalogos = $documento->getObjectsByType( 'Catalog' );
			$raiz      = reset( $catalogos )->get( 'Pages' );

			if ( method_exists( $raiz, 'getPages' ) ) {
				return self::contar_nodo( $raiz, array(), $visitas );
			}
		}

		if ( $documento->hasObjectsByType( 'Pages' ) ) {
			$total = 0;

			foreach ( $documento->getObjectsByType( 'Pages' ) as $nodo ) {
				$total += self::contar_nodo( $nodo, array(), $visitas );

				if ( $total > Pdf_Limites::MAX_PAGINAS ) {
					break;
				}
			}

			return $total;
		}

		return count( $documento->getObjectsByType( 'Page' ) );
	}

	/** Igual que Pages::getPages(true): no baja a un nodo que ya esta en el camino desde la raiz. */
	private static function contar_nodo( \Smalot\PdfParser\PDFObject $nodo, array $camino, int &$visitas ): int {
		if ( ++$visitas > Pdf_Limites::MAX_NODOS_PAGINAS ) {
			return Pdf_Limites::MAX_PAGINAS + 1;
		}

		$id = spl_object_id( $nodo );

		if ( isset( $camino[ $id ] ) || ! $nodo->has( 'Kids' ) ) {
			return 0;
		}

		$camino[ $id ] = true;
		$kids          = $nodo->get( 'Kids' );
		$total         = 0;

		foreach ( $kids instanceof \Smalot\PdfParser\Element\ElementArray ? $kids->getContent() : array() as $hijo ) {
			if ( $hijo instanceof \Smalot\PdfParser\Pages ) {
				$total += self::contar_nodo( $hijo, $camino, $visitas );
			} elseif ( $hijo instanceof \Smalot\PdfParser\Page ) {
				++$total;
			}

			if ( $total > Pdf_Limites::MAX_PAGINAS ) {
				break;
			}
		}

		return $total;
	}
}
