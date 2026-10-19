<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lector propio del flujo de contenido de una pagina o de un Form XObject
 * (ISO 32000-1, 7.2 y 7.8.2), validado en la prueba de concepto #332. No se
 * usa el de smalot: vacia entero un TJ que empieza por un numero
 * ([-577.5 (texto)] TJ, que algunos generadores usan para colocar texto) y
 * corta mal una cadena literal con parentesis anidados o escapes.
 *
 * Genera operaciones de una en una (no las guarda todas): array( 'op' =>
 * operador, 'args' => operandos ). Operandos: float (numero), array( 's',
 * bytes ) (cadena literal ya desescapada o hexadecimal), array( 'n', nombre )
 * y list (array PDF). Los diccionarios se saltan, igual que las imagenes en
 * linea (BI ... ID datos EI). Nada del contenido se ejecuta ni se incluye:
 * solo se trocea.
 *
 * Cada operacion cuenta para Pdf_Limites (tope de operaciones, reloj y
 * memoria). Tambien se acotan los operandos pendientes, los elementos de un
 * array y su anidamiento: un flujo de millones de numeros sin operador, o un
 * array enorme, no llega a ocupar la memoria.
 */
class Pdf_Contenido {

	private const MAX_ANIDAMIENTO = 32;

	/** Ningun operador del PDF lleva mas de unos pocos operandos (sc/scn, hasta 33). */
	private const MAX_OPERANDOS = 64;

	private const MAX_ELEMENTOS_ARRAY = 65536;

	/** Delimitadores de token ademas de los blancos (7.2.2). */
	private const DELIMITADORES = '/[]()<>{}%';

	/** @return \Generator<int, array{op: string, args: list<mixed>}> */
	public static function operaciones( string $s, Pdf_Limites $limites ): \Generator {
		$pila = array();
		$i    = 0;
		$n    = strlen( $s );

		while ( null !== ( $t = self::token( $s, $i, $n, 0 ) ) ) {
			if ( 'op' === $t[0] ) {
				$limites->contar_operacion();

				if ( 'BI' === $t[1] ) {
					// Imagen en linea: saltar hasta " EI" tras "ID ".
					$id   = strpos( $s, 'ID', $i );
					$fin  = false === $id ? false : self::fin_imagen( $s, $id + 2 );
					$i    = false === $fin ? $n : $fin;
					$pila = array();
					continue;
				}

				yield array(
					'op'   => $t[1],
					'args' => $pila,
				);
				$pila = array();
			} elseif ( 'fin' !== $t[0] ) {
				if ( count( $pila ) >= self::MAX_OPERANDOS ) {
					throw new Pdf_Limite_Excedido( 'el contenido del PDF tiene demasiados operandos seguidos' );
				}

				$pila[] = $t[1];
			}
		}
	}

	private static function fin_imagen( string $s, int $desde ): int|false {
		$p = $desde;

		while ( false !== ( $p = strpos( $s, 'EI', $p ) ) ) {
			$antes   = $s[ $p - 1 ] ?? ' ';
			$despues = $s[ $p + 2 ] ?? ' ';

			if ( ctype_space( $antes ) && ( ctype_space( $despues ) || '' === trim( $despues ) ) ) {
				return $p + 2;
			}

			$p += 2;
		}

		return false;
	}

	/** @return array{0: string, 1: mixed}|null  'num' | 'str' | 'name' | 'arr' | 'dict' | 'op' | 'fin' */
	private static function token( string $s, int &$i, int $n, int $profundidad ): ?array {
		while ( $i < $n ) {
			$c = $s[ $i ];

			if ( ctype_space( $c ) || "\0" === $c ) {
				++$i;
			} elseif ( '%' === $c ) {
				while ( $i < $n && "\n" !== $s[ $i ] && "\r" !== $s[ $i ] ) {
					++$i;
				}
			} else {
				break;
			}
		}

		if ( $i >= $n ) {
			return null;
		}

		$c = $s[ $i ];

		if ( '(' === $c ) {
			return array( 'str', array( 's', self::literal( $s, $i, $n ) ) );
		}

		if ( '<' === $c && '<' === ( $s[ $i + 1 ] ?? '' ) ) {
			// Diccionario (p. ej. en BDC/DP): se salta respetando el anidamiento.
			$nivel = 0;

			while ( $i < $n ) {
				if ( '<' === $s[ $i ] && '<' === ( $s[ $i + 1 ] ?? '' ) ) {
					++$nivel;
					$i += 2;
				} elseif ( '>' === $s[ $i ] && '>' === ( $s[ $i + 1 ] ?? '' ) ) {
					--$nivel;
					$i += 2;

					if ( 0 === $nivel ) {
						break;
					}
				} elseif ( '(' === $s[ $i ] ) {
					self::literal( $s, $i, $n );
				} else {
					++$i;
				}
			}

			return array( 'dict', null );
		}

		if ( '<' === $c ) {
			$fin = strpos( $s, '>', $i );
			$fin = false === $fin ? $n : $fin;
			$hex = preg_replace( '/[^0-9a-fA-F]/', '', substr( $s, $i + 1, $fin - $i - 1 ) ) ?? '';
			$i   = $fin + 1;

			return array( 'str', array( 's', (string) hex2bin( strlen( $hex ) % 2 ? $hex . '0' : $hex ) ) );
		}

		if ( '[' === $c ) {
			++$i;

			if ( $profundidad >= self::MAX_ANIDAMIENTO ) {
				throw new Pdf_Limite_Excedido( 'el contenido del PDF tiene arrays demasiado anidados' );
			}

			$arr = array();

			while ( null !== ( $t = self::token( $s, $i, $n, $profundidad + 1 ) ) && 'fin' !== $t[0] ) {
				if ( 'op' !== $t[0] && 'dict' !== $t[0] ) {
					if ( count( $arr ) >= self::MAX_ELEMENTOS_ARRAY ) {
						throw new Pdf_Limite_Excedido( 'el contenido del PDF tiene un array demasiado grande' );
					}

					$arr[] = $t[1];
				}
			}

			return array( 'arr', $arr );
		}

		if ( ']' === $c ) {
			++$i;

			return array( 'fin', null );
		}

		if ( '/' === $c ) {
			$j = $i + 1;

			while ( $j < $n && ! ctype_space( $s[ $j ] ) && false === strpos( self::DELIMITADORES, $s[ $j ] ) ) {
				++$j;
			}

			$nombre = substr( $s, $i + 1, $j - $i - 1 );
			$i      = $j;

			return array( 'name', array( 'n', (string) preg_replace_callback( '/#([0-9a-fA-F]{2})/', static fn( array $m ): string => chr( (int) hexdec( $m[1] ) ), $nombre ) ) );
		}

		if ( '{' === $c || '}' === $c || '>' === $c || ')' === $c ) {
			++$i;

			return array( 'fin', null );
		}

		$j = $i;

		while ( $j < $n && ! ctype_space( $s[ $j ] ) && false === strpos( self::DELIMITADORES, $s[ $j ] ) ) {
			++$j;
		}

		$palabra = substr( $s, $i, $j - $i );
		$i       = $j;

		if ( 1 === preg_match( '/^[+-]?(\d+\.?\d*|\.\d+)$/', $palabra ) ) {
			return array( 'num', (float) $palabra );
		}

		return array( 'op', $palabra );
	}

	/** Cadena literal desde '(' con anidamiento y escapes; deja $i tras el ')' que la cierra. */
	private static function literal( string $s, int &$i, int $n ): string {
		++$i;
		$nivel = 1;
		$out   = '';

		while ( $i < $n ) {
			$c = $s[ $i ];

			if ( '\\' === $c ) {
				$e = $s[ $i + 1 ] ?? '';

				if ( '' !== $e && false !== strpos( '01234567', $e ) ) {
					$oct = $e;
					$k   = $i + 2;

					while ( strlen( $oct ) < 3 && $k < $n && false !== strpos( '01234567', $s[ $k ] ) ) {
						$oct .= $s[ $k ];
						++$k;
					}

					$out .= chr( (int) octdec( $oct ) & 0xFF );
					$i    = $k;
					continue;
				}

				$i += 2;

				if ( "\r" === $e && "\n" === ( $s[ $i ] ?? '' ) ) {
					++$i;
					continue;
				}

				$out .= match ( $e ) {
					'n' => "\n",
					'r' => "\r",
					't' => "\t",
					'b' => "\x08",
					'f' => "\x0C",
					"\n", "\r" => '',
					default => $e,
				};
				continue;
			}

			if ( '(' === $c ) {
				++$nivel;
			} elseif ( ')' === $c ) {
				--$nivel;

				if ( 0 === $nivel ) {
					++$i;
					break;
				}
			}

			$out .= $c;
			++$i;
		}

		return $out;
	}
}
