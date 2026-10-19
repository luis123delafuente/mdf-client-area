<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Topes de la lectura de un PDF (#333): una instancia por factura, que la
 * cuenta mientras se lee. El PDF lo sube un administrador, pero se trata
 * como entrada no fiable: un fichero manipulado no puede colgar PHP ni
 * agotar su memoria (eso seria un error fatal, que no se puede capturar y
 * tumbaria la peticion entera, con todas las facturas de la subida).
 *
 * Valores: una factura de SAGE tiene de 1 a unas 6 paginas, unos cientos de
 * objetos y de palabras por pagina, flujos de pocos KB y se lee en menos de
 * 30 ms con unos 3 MB. Los topes dejan mucho margen sobre eso y quedan muy
 * por debajo de lo que tumbaria PHP con un memory_limit de 128 MB.
 *
 * Cuando se supera un tope se lanza Pdf_Limite_Excedido y, ademas, queda
 * anotado (excedido()): smalot captura y silencia las excepciones de sus
 * filtros de descompresion, asi que quien lee comprueba la marca al volver.
 */
class Pdf_Limites {

	private const MB = 1048576;

	/** Tamano maximo del fichero. */
	public const MAX_BYTES_FICHERO = 10 * self::MB;

	public const MAX_PAGINAS = 50;

	/** Nodos del arbol de paginas recorridos al contarlas (protege de un arbol con nodos compartidos). */
	public const MAX_NODOS_PAGINAS = 2000;

	/** Objetos del PDF, incluidos los que van dentro de un object stream. */
	public const MAX_OBJETOS = 20000;

	/** Bytes descomprimidos de un solo flujo. */
	public const MAX_BYTES_FLUJO = 16 * self::MB;

	/** Bytes descomprimidos sumando todos los flujos del PDF. */
	public const MAX_BYTES_DESCOMPRIMIDOS = 48 * self::MB;

	/**
	 * Un flujo de referencias cruzadas (xref stream): smalot lo convierte en
	 * un array de PHP de un elemento por byte (unos 40 bytes de memoria por
	 * cada uno), asi que su tope es mucho menor. 256 KB son unos 50.000 objetos.
	 */
	public const MAX_BYTES_XREF = 256 * 1024;

	/** /W de un xref stream: bytes por campo (en la practica, 1 a 4) y /Columns del predictor. */
	public const MAX_ANCHO_CAMPO_XREF = 8;
	public const MAX_COLUMNAS_XREF    = 64;

	/**
	 * Valores sueltos (numeros, nombres, cadenas...) de los diccionarios y
	 * arrays del PDF, fuera de los flujos. smalot crea un objeto de PHP por
	 * cada uno. Una factura tiene unos pocos miles (sobre todo los anchos de
	 * las fuentes; el maximo medido en las pruebas, 3.258). smalot gasta
	 * unos 240 bytes por valor: 100.000 son unos 24 MB.
	 */
	public const MAX_VALORES = 100000;

	/**
	 * Codigos de las tablas ToUnicode, sumando todas las fuentes. dompdf (y
	 * otros) declaran un rango de identidad de 16 bits entero por fuente
	 * (<0000> <FFFF>): 65.536 codigos, unos 4 MB en la tabla de smalot. Una
	 * carta del Modelo 347 (seis variantes de Liberation) llega a seis
	 * planos; el tope son ocho, unos 32 MB. Un PDF con mas fuentes asi sale
	 * como formato_inesperado y se sube a mano.
	 */
	public const MAX_CODIGOS_TO_UNICODE = 8 * 65536;

	/** Operadores del contenido sumando todas las paginas y Form XObjects. */
	public const MAX_OPERACIONES = 1000000;

	public const MAX_PALABRAS_PAGINA = 20000;

	/** Form XObjects anidados (uno dentro de otro). */
	public const MAX_PROFUNDIDAD_FORMS = 8;

	public const MAX_SEGUNDOS = 10.0;

	/** Se deja de leer si la memoria usada se acerca a memory_limit a menos de esto. */
	private const MARGEN_MEMORIA = 24 * self::MB;

	/** Cada cuantas operaciones u objetos se miran el reloj y la memoria. */
	private const CADA = 1024;

	private float $fin;
	private int $memoria_maxima;
	private int $bytes       = 0;
	private int $objetos     = 0;
	private int $operaciones = 0;
	private int $valores     = 0;
	private int $codigos     = 0;
	private ?string $excedido = null;

	public function __construct( float $segundos = self::MAX_SEGUNDOS ) {
		$this->fin            = microtime( true ) + $segundos;
		$limite               = self::bytes_de_ini( (string) ini_get( 'memory_limit' ) );
		$this->memoria_maxima = $limite > 0 ? max( 0, $limite - self::MARGEN_MEMORIA ) : PHP_INT_MAX;
	}

	/** Tiempo y memoria. */
	public function comprobar(): void {
		if ( null !== $this->excedido ) {
			throw new Pdf_Limite_Excedido( $this->excedido );
		}

		if ( microtime( true ) > $this->fin ) {
			$this->exceder( 'la lectura del PDF ha superado el tiempo máximo' );
		}

		if ( memory_get_usage() > $this->memoria_maxima ) {
			$this->exceder( 'la lectura del PDF ha superado la memoria máxima' );
		}
	}

	public function contar_objetos( int $cuantos = 1 ): void {
		$antes          = $this->objetos;
		$this->objetos += $cuantos;

		if ( $this->objetos > self::MAX_OBJETOS ) {
			$this->exceder( 'el PDF tiene demasiados objetos' );
		}

		if ( intdiv( $antes, self::CADA ) !== intdiv( $this->objetos, self::CADA ) ) {
			$this->comprobar();
		}
	}

	public function contar_valor(): void {
		++$this->valores;

		if ( $this->valores > self::MAX_VALORES ) {
			$this->exceder( 'el PDF tiene demasiados valores en sus objetos' );
		}

		if ( 0 === $this->valores % self::CADA ) {
			$this->comprobar();
		}
	}

	public function contar_codigos( int $cuantos ): void {
		$this->codigos += $cuantos;

		if ( $this->codigos > self::MAX_CODIGOS_TO_UNICODE ) {
			$this->exceder( 'las fuentes del PDF declaran demasiados códigos' );
		}
	}

	public function contar_operacion(): void {
		++$this->operaciones;

		if ( $this->operaciones > self::MAX_OPERACIONES ) {
			$this->exceder( 'el contenido del PDF tiene demasiadas operaciones' );
		}

		if ( 0 === $this->operaciones % self::CADA ) {
			$this->comprobar();
		}
	}

	/** Bytes que puede ocupar descomprimido el siguiente flujo (al menos 1). */
	public function bytes_para_flujo( bool $es_xref = false ): int {
		$restantes = self::MAX_BYTES_DESCOMPRIMIDOS - $this->bytes;

		if ( $restantes <= 0 ) {
			$this->exceder( 'el PDF descomprimido supera el tamaño máximo' );
		}

		return max( 1, min( $es_xref ? self::MAX_BYTES_XREF : self::MAX_BYTES_FLUJO, $restantes ) );
	}

	public function consumir_bytes( int $bytes ): void {
		$this->bytes += $bytes;

		if ( $this->bytes > self::MAX_BYTES_DESCOMPRIMIDOS ) {
			$this->exceder( 'el PDF descomprimido supera el tamaño máximo' );
		}
	}

	/** Anota el tope superado y lanza la excepcion. */
	public function exceder( string $motivo ): never {
		$this->excedido ??= $motivo;

		throw new Pdf_Limite_Excedido( $this->excedido );
	}

	/** Motivo del primer tope superado, aunque su excepcion se haya silenciado por el camino. */
	public function excedido(): ?string {
		return $this->excedido;
	}

	/** Lanza la excepcion si algun tope se supero antes (p. ej. dentro de un filtro de smalot). */
	public function relanzar(): void {
		if ( null !== $this->excedido ) {
			throw new Pdf_Limite_Excedido( $this->excedido );
		}
	}

	/** "128M", "1G", "-1"... a bytes; <= 0 = sin limite. */
	private static function bytes_de_ini( string $valor ): int {
		$valor = strtolower( trim( $valor ) );

		if ( '' === $valor || '-1' === $valor ) {
			return -1;
		}

		$numero = (int) $valor;

		switch ( substr( $valor, -1 ) ) {
			case 'g':
				return $numero * 1073741824;
			case 'm':
				return $numero * self::MB;
			case 'k':
				return $numero * 1024;
			default:
				return $numero;
		}
	}
}
