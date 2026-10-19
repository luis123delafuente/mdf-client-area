<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reglas de lectura de una factura SAGE del grupo MDF sobre las palabras de
 * sus paginas (#333): traduccion linea a linea de parser_facturas/parser.py
 * (repositorio Parser_facturas_SAGE), con los mismos rotulos, umbrales
 * (MISMA_FILA 3, MARGEN_VALOR 40, redondeo al par), comprobaciones y orden de
 * estados:
 *
 *     sin_texto > formato_inesperado > numero_discrepante > sin_cif > cif_invalido > ok
 *
 * Si cambian las reglas de un lado, hay que cambiarlas en el otro mientras
 * convivan (la regresion de tests/lectura-facturas compara los dos campo a
 * campo).
 *
 * Diferencias deliberadas con Python:
 * - Sociedades y series salen de Factura_Emisores y el CIF/NIF se normaliza y
 *   valida con Identificador_Fiscal_Validator: el plugin no tiene una segunda
 *   lista ni un segundo algoritmo (los de Python son una copia de estos).
 * - Los motivos nunca llevan valores de la factura (Python incluye serie y
 *   numero en dos de ellos): pueden acabar en una pantalla o en un registro.
 * - Las cifras son solo ASCII (Python, con \d, admite cifras de otros
 *   alfabetos) y un importe que no cabe en un entero de PHP no se extrae.
 *   Ninguna de las dos cosas sale en una factura de SAGE.
 *
 * "ok" significa que no se ve ningun problema; la asignacion a una farmacia
 * la decide Factura_Cruce_Service, que recibe este resultado igual que el
 * del parser de Python y vuelve a validarlo todo.
 */
class Factura_Sage_Parser {

	public const OK                 = 'ok';
	public const SIN_TEXTO          = Factura_Cruce_Service::SIN_TEXTO;
	public const FORMATO_INESPERADO = Factura_Cruce_Service::FORMATO_INESPERADO;
	public const NUMERO_DISCREPANTE = Factura_Cruce_Service::NUMERO_DISCREPANTE;
	public const SIN_CIF            = Factura_Cruce_Service::SIN_CIF;
	public const CIF_INVALIDO       = Factura_Cruce_Service::CIF_INVALIDO;

	/** Fila de cabeceras del bloque de datos de la factura, de izquierda a derecha. */
	private const COLUMNAS      = array( 'FECHA', 'FACTURA', 'CLIENTE', 'N.I.F', 'TELÉFONO', 'PÁG.' );
	private const ROTULO_EMISOR = 'CIF:';
	private const ROTULO_MONEDA = '(EUR)';
	private const SIMBOLO       = '€';

	/** Tolerancia vertical (puntos) para considerar dos palabras en la misma fila. */
	private const MISMA_FILA = 3;

	/** Distancia maxima bajo un rotulo para buscar su valor. */
	private const MARGEN_VALOR = 40;

	/** Cifras de los euros que caben en centimos en un entero de 64 bits. */
	private const MAX_CIFRAS_EUROS = 16;

	/**
	 * Resultado con el mismo esquema que ResultadoFactura.a_dict() de Python,
	 * mas "paginas".
	 *
	 * @return array{fichero: string, estado: string, motivo: string, emisor: ?string, cif: ?string, serie: ?string, numero: ?string, fecha: ?string, importe_total_centimos: ?int, es_abono: bool, campos_no_extraidos: list<string>, paginas: ?int}
	 */
	public static function resultado( string $fichero, string $estado = self::OK, string $motivo = '' ): array {
		return array(
			'fichero'                => $fichero,
			'estado'                 => $estado,
			'motivo'                 => $motivo,
			'emisor'                 => null,
			'cif'                    => null,
			'serie'                  => null,
			'numero'                 => null,
			'fecha'                  => null,
			'importe_total_centimos' => null,
			'es_abono'               => false,
			'campos_no_extraidos'    => array(),
			'paginas'                => null,
		);
	}

	/**
	 * @param list<list<array>> $paginas Palabras de cada pagina (x0, y0, x1, y1, texto), en orden.
	 * @param string            $fichero Nombre del fichero (solo de contraste: nunca identifica nada).
	 */
	public static function parsear( array $paginas, string $fichero ): array {
		$r            = self::resultado( $fichero );
		$r['paginas'] = count( $paginas );

		if ( ! $paginas || ! $paginas[0] ) {
			return self::con_estado( $r, self::SIN_TEXTO, 'el PDF no tiene capa de texto (posible escaneo); no se aplica OCR' );
		}

		try {
			$leidas  = array_map( array( self::class, 'leer_pagina' ), $paginas );
			$copias  = self::agrupar_copias( $leidas );
			$totales = array_map( static fn( array $c ): string => self::leer_total( $c[ count( $c ) - 1 ]['words'] ), $copias );
		} catch ( Factura_Formato_Inesperado $e ) {
			return self::con_estado( $r, self::FORMATO_INESPERADO, $e->getMessage() );
		}

		// Todas las paginas (original y "Es copia") deben dar exactamente la misma
		// cabecera, y todas las copias el mismo total: si no, no se sabe cual es la buena.
		$datos = $leidas[0]['cabecera'];

		foreach ( array_slice( $leidas, 1 ) as $p ) {
			if ( $p['cabecera'] !== $datos ) {
				return self::con_estado( $r, self::FORMATO_INESPERADO, 'las paginas del PDF no tienen los mismos datos de factura' );
			}
		}

		if ( 1 !== count( array_unique( $totales ) ) ) {
			return self::con_estado( $r, self::FORMATO_INESPERADO, 'las paginas del PDF no tienen los mismos datos de factura' );
		}

		if ( ! Factura_Emisores::es_emisor( $datos['emisor'] ) ) {
			return self::con_estado( $r, self::FORMATO_INESPERADO, 'el emisor de la factura no es una sociedad del grupo MDF configurada' );
		}

		$r['emisor'] = $datos['emisor'];

		$numero  = 1 === preg_match( '/^([A-Z]{1,2})\s+(\d+)$/D', $datos['factura'], $mn ) ? $mn : null;
		$fecha   = self::parsear_fecha( $datos['fecha'] );
		$importe = self::parsear_importe( $totales[0] );

		if ( $numero ) {
			$r['serie']  = $numero[1];
			$r['numero'] = $numero[2];
		} else {
			$r['campos_no_extraidos'][] = 'numero';
		}

		if ( null !== $fecha ) {
			$r['fecha'] = $fecha;
		} else {
			$r['campos_no_extraidos'][] = 'fecha';
		}

		if ( null !== $importe ) {
			$r['importe_total_centimos'] = $importe;
			$r['es_abono']               = $importe < 0;
		} else {
			$r['campos_no_extraidos'][] = 'importe_total';
		}

		$cif = Identificador_Fiscal_Validator::normalizar( $datos['cif'] );

		if ( '' === $cif ) {
			$r['campos_no_extraidos'][] = 'cif';
		}

		$no_cif = array_values( array_filter( $r['campos_no_extraidos'], static fn( string $c ): bool => 'cif' !== $c ) );

		if ( $no_cif ) {
			return self::con_estado( $r, self::FORMATO_INESPERADO, 'no se pudieron extraer con formato valido: ' . implode( ', ', $no_cif ) );
		}

		if ( ! Factura_Emisores::admite( $r['emisor'], $r['serie'] ) ) {
			return self::con_estado( $r, self::FORMATO_INESPERADO, 'la serie no es una serie configurada para el emisor de la factura' );
		}

		// re.IGNORECASE en el patron, pero los grupos se comparan tal cual (como Python).
		if ( 1 !== preg_match( '/^FA([A-Z]{1,2})(\d+)\.pdf$/Di', $fichero, $mf ) || array( $mf[1], $mf[2] ) !== array( $r['serie'], $r['numero'] ) ) {
			return self::con_estado( $r, self::NUMERO_DISCREPANTE, 'el nombre del fichero no corresponde a la factura del contenido' );
		}

		if ( '' === $cif ) {
			return self::con_estado( $r, self::SIN_CIF, 'la factura no tiene CIF/NIF del cliente' );
		}

		if ( Factura_Emisores::es_emisor( $cif ) ) {
			$r['campos_no_extraidos'][] = 'cif';

			return self::con_estado( $r, self::SIN_CIF, 'el CIF/NIF del campo cliente es el de un emisor del grupo, no el de una farmacia' );
		}

		$r['cif'] = $cif;

		if ( ! Identificador_Fiscal_Validator::is_valid( $cif ) ) {
			return self::con_estado( $r, self::CIF_INVALIDO, 'el CIF/NIF del cliente no es valido (formato o digito/letra de control)' );
		}

		return $r;
	}

	public static function con_estado( array $r, string $estado, string $motivo ): array {
		$r['estado'] = $estado;
		$r['motivo'] = $motivo;

		return $r;
	}

	// --- Lectura por coordenadas ------------------------------------------------

	private static function buscar_rotulo( array $words, string $texto ): ?array {
		foreach ( $words as $w ) {
			if ( $w[4] === $texto ) {
				return $w;
			}
		}

		return null;
	}

	private static function misma_fila( array $words, float $y0 ): array {
		$fila = array_values( array_filter( $words, static fn( array $w ): bool => abs( $w[1] - $y0 ) < self::MISMA_FILA ) );
		usort( $fila, static fn( array $a, array $b ): int => $a[0] <=> $b[0] ); // estable desde PHP 8, como sorted()

		return $fila;
	}

	private static function indice( array $fila, array $w ): int {
		foreach ( $fila as $i => $x ) {
			if ( $x === $w ) {
				return $i;
			}
		}

		return -1;
	}

	/** Las palabras que siguen a $w en su fila, si son exactamente $siguientes. */
	private static function seguidas_en_fila( array $words, array $w, array $siguientes ): ?array {
		$fila = self::misma_fila( $words, $w[1] );
		$tras = array_slice( $fila, self::indice( $fila, $w ) + 1, count( $siguientes ) );

		return array_map( static fn( array $t ): string => $t[4], $tras ) === $siguientes ? $tras : null;
	}

	private static function leer_emisor( array $words ): string {
		$rotulo = self::buscar_rotulo( $words, self::ROTULO_EMISOR );

		if ( null === $rotulo ) {
			throw new Factura_Formato_Inesperado( "no aparece el rotulo 'CIF:' del emisor" );
		}

		$derecha = array_values( array_filter( self::misma_fila( $words, $rotulo[1] ), static fn( array $w ): bool => $w[0] > $rotulo[2] ) );

		if ( ! $derecha ) {
			throw new Factura_Formato_Inesperado( 'el rotulo del emisor no tiene identificador' );
		}

		return $derecha[0][4];
	}

	/**
	 * Valor bajo cada rotulo de la fila de cabeceras. Las columnas se separan
	 * por el punto medio entre rotulos vecinos, y cada palabra va a la columna
	 * donde cae su centro. Una columna sin valor queda como cadena vacia.
	 *
	 * @return array<string, string>
	 */
	private static function leer_fila_cabeceras( array $words ): array {
		$rotulos = array();

		foreach ( self::COLUMNAS as $nombre ) {
			$r = self::buscar_rotulo( $words, $nombre );

			if ( null === $r ) {
				throw new Factura_Formato_Inesperado( "no aparece el rotulo '$nombre'" );
			}

			$rotulos[ $nombre ] = $r;
		}

		$ys = array_unique( array_map( static fn( array $r ): float => round( $r[1], 0, PHP_ROUND_HALF_EVEN ), $rotulos ) );

		if ( max( $ys ) - min( $ys ) >= self::MISMA_FILA ) {
			throw new Factura_Formato_Inesperado( 'los rotulos de cabecera no estan en la misma fila' );
		}

		$centros = array();

		foreach ( $rotulos as $nombre => $r ) {
			$centros[] = array( ( $r[0] + $r[2] ) / 2, $nombre );
		}

		usort( $centros, static fn( array $a, array $b ): int => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );

		if ( array_column( $centros, 1 ) !== self::COLUMNAS ) {
			throw new Factura_Formato_Inesperado( 'los rotulos de cabecera no estan en el orden esperado' );
		}

		$limites = array();

		for ( $i = 0; $i < count( $centros ) - 1; $i++ ) {
			$limites[] = ( $centros[ $i ][0] + $centros[ $i + 1 ][0] ) / 2;
		}

		$y_rotulos = max( array_map( static fn( array $r ): float => $r[3], $rotulos ) );
		$debajo    = array_values( array_filter( $words, static fn( array $w ): bool => $y_rotulos < $w[1] && $w[1] < $y_rotulos + self::MARGEN_VALOR ) );

		if ( ! $debajo ) {
			throw new Factura_Formato_Inesperado( 'no hay fila de valores bajo las cabeceras' );
		}

		$fila    = self::misma_fila( $debajo, min( array_column( $debajo, 1 ) ) );
		$valores = array_fill_keys( self::COLUMNAS, array() );

		foreach ( $fila as $w ) {
			$centro                               = ( $w[0] + $w[2] ) / 2;
			$columna                              = count( array_filter( $limites, static fn( float $l ): bool => $centro > $l ) );
			$valores[ $centros[ $columna ][1] ][] = $w[4];
		}

		return array_map( static fn( array $p ): string => implode( ' ', $p ), $valores );
	}

	/**
	 * El "TOTAL" seguido de "FRA" en su fila. Hay otro "TOTAL" ("TOTAL IMP.")
	 * que en la plantilla de Formacion comparte fila con "TOTAL FRA": tiene que
	 * ser la palabra siguiente.
	 */
	private static function rotulo_total_fra( array $words ): array {
		foreach ( $words as $w ) {
			if ( 'TOTAL' === $w[4] ) {
				$fra = self::seguidas_en_fila( $words, $w, array( 'FRA' ) );

				if ( $fra ) {
					return array( $w, $fra[0] );
				}
			}
		}

		return array( null, null );
	}

	/** Rotulo "(EUR)" (o null) y palabras de la columna del total bajo "TOTAL FRA". */
	private static function zona_total( array $words ): array {
		list( $total, $fra ) = self::rotulo_total_fra( $words );

		if ( null === $total ) {
			throw new Factura_Formato_Inesperado( "no aparece el rotulo 'TOTAL FRA'" );
		}

		// La columna del total va desde un poco a la izquierda del rotulo hasta el
		// borde derecho: ahi solo debe haber una palabra, el importe.
		$x_min = $total[0] - 25;
		$eur   = null;

		foreach ( $words as $w ) {
			if ( self::ROTULO_MONEDA === $w[4] && $w[0] >= $x_min && $fra[1] + self::MISMA_FILA <= $w[1] && $w[1] < $fra[3] + self::MARGEN_VALOR ) {
				$eur = $w;
				break;
			}
		}

		$y_rotulo   = null !== $eur ? $eur[3] : $fra[3];
		$candidatos = array_values( array_filter( $words, static fn( array $w ): bool => $y_rotulo < $w[1] && $w[1] < $y_rotulo + self::MARGEN_VALOR && $w[0] >= $x_min ) );

		return array( $eur, $candidatos );
	}

	private static function leer_total( array $words ): string {
		list( $eur, $candidatos ) = self::zona_total( $words );

		if ( 1 !== count( $candidatos ) ) {
			throw new Factura_Formato_Inesperado( "se esperaba un unico importe bajo 'TOTAL FRA', hay " . count( $candidatos ) );
		}

		$importe     = $candidatos[0][4];
		$con_simbolo = str_ends_with( $importe, self::SIMBOLO );

		// La moneda va en un solo sitio: el rotulo "(EUR)" o el simbolo pegado al importe.
		if ( ( null !== $eur ) === $con_simbolo ) {
			throw new Factura_Formato_Inesperado( "la moneda del total no aparece una sola vez (rotulo '(EUR)' o simbolo del euro)" );
		}

		return $con_simbolo ? substr( $importe, 0, -strlen( self::SIMBOLO ) ) : $importe;
	}

	/** N de "Es copia N" en la cabecera (por encima de la fila de rotulos), o null. */
	private static function leer_marcador_copia( array $words, float $y_cabeceras ): ?int {
		$marcadores = array();

		foreach ( $words as $w ) {
			if ( 'Es' !== $w[4] || $w[1] >= $y_cabeceras ) {
				continue;
			}

			$fila = self::misma_fila( $words, $w[1] );
			$i    = self::indice( $fila, $w );
			$tras = array_map( static fn( array $t ): string => $t[4], array_slice( $fila, $i + 1, 2 ) );

			if ( array_slice( $tras, 0, 1 ) !== array( 'copia' ) ) {
				continue;
			}

			if ( count( $tras ) < 2 || 1 !== preg_match( '/^\d{1,2}$/D', $tras[1] ) ) {
				throw new Factura_Formato_Inesperado( "el marcador 'Es copia' no lleva numero de copia" );
			}

			$marcadores[] = (int) $tras[1];
		}

		if ( count( $marcadores ) > 1 ) {
			throw new Factura_Formato_Inesperado( "hay mas de un marcador 'Es copia' en la misma pagina" );
		}

		return $marcadores[0] ?? null;
	}

	private static function tiene_suma_y_sigue( array $words ): bool {
		foreach ( $words as $w ) {
			if ( 'SUMA' === $w[4] && self::seguidas_en_fila( $words, $w, array( 'Y', 'SIGUE' ) ) ) {
				return true;
			}
		}

		return false;
	}

	private static function leer_pagina( array $words ): array {
		$cab = self::leer_fila_cabeceras( $words );

		if ( 1 !== preg_match( '/^\d{1,3}$/D', $cab['PÁG.'] ) ) {
			throw new Factura_Formato_Inesperado( 'el numero de pagina (PAG.) no es un entero' );
		}

		$y_cabeceras = self::buscar_rotulo( $words, 'FECHA' )[1];

		return array(
			'cabecera'     => array(
				'emisor'  => Identificador_Fiscal_Validator::normalizar( self::leer_emisor( $words ) ),
				'cliente' => $cab['CLIENTE'],
				'cif'     => $cab['N.I.F'],
				'factura' => $cab['FACTURA'],
				'fecha'   => $cab['FECHA'],
			),
			'numero'       => (int) $cab['PÁG.'],
			'copia'        => self::leer_marcador_copia( $words, $y_cabeceras ),
			'suma_y_sigue' => self::tiene_suma_y_sigue( $words ),
			'words'        => $words,
		);
	}

	/** Separa el PDF en original y copias. Una copia empieza en cada PAG. 1. */
	private static function agrupar_copias( array $paginas ): array {
		$copias = array();

		foreach ( $paginas as $p ) {
			if ( 1 === $p['numero'] ) {
				$copias[] = array( $p );
				continue;
			}

			if ( ! $copias ) {
				throw new Factura_Formato_Inesperado( 'la primera pagina del PDF no es la PAG. 1' );
			}

			$ultima   = count( $copias ) - 1;
			$anterior = $copias[ $ultima ][ count( $copias[ $ultima ] ) - 1 ];

			if ( $p['numero'] !== $anterior['numero'] + 1 ) {
				throw new Factura_Formato_Inesperado( 'la numeracion de paginas (PAG.) salta o se repite' );
			}

			if ( $p['copia'] !== $anterior['copia'] ) {
				throw new Factura_Formato_Inesperado( "el marcador 'Es copia' cambia dentro de una misma copia" );
			}

			$copias[ $ultima ][] = $p;
		}

		if ( 1 !== count( array_unique( array_map( 'count', $copias ) ) ) ) {
			throw new Factura_Formato_Inesperado( 'el original y las copias no tienen el mismo numero de paginas' );
		}

		foreach ( $copias as $copia ) {
			foreach ( array_slice( $copia, 0, -1 ) as $intermedia ) {
				if ( ! $intermedia['suma_y_sigue'] ) {
					throw new Factura_Formato_Inesperado( "una pagina intermedia no lleva 'SUMA Y SIGUE'" );
				}

				list( , $candidatos ) = self::zona_total( $intermedia['words'] );

				if ( $candidatos ) {
					throw new Factura_Formato_Inesperado( "una pagina que no es la ultima de su copia tiene valor bajo 'TOTAL FRA'" );
				}
			}

			if ( $copia[ count( $copia ) - 1 ]['suma_y_sigue'] ) {
				throw new Factura_Formato_Inesperado( "la ultima pagina de una copia lleva 'SUMA Y SIGUE'" );
			}
		}

		return $copias;
	}

	// --- Validacion de campos ---------------------------------------------------

	/** "dd/mm/yy" a AAAA-MM-DD; ano de dos cifras, siempre 20yy. */
	private static function parsear_fecha( string $texto ): ?string {
		if ( 1 !== preg_match( '/^(\d{2})\/(\d{2})\/(\d{2})$/D', $texto, $m ) ) {
			return null;
		}

		$dia  = (int) $m[1];
		$mes  = (int) $m[2];
		$anio = 2000 + (int) $m[3];

		return checkdate( $mes, $dia, $anio ) ? sprintf( '%04d-%02d-%02d', $anio, $mes, $dia ) : null;
	}

	/** Centimos con signo, en aritmetica entera (como Decimal en Python). */
	private static function parsear_importe( string $texto ): ?int {
		if ( 1 !== preg_match( '/^-?\d{1,3}(?:\.\d{3})*,\d{2}$/D', $texto ) ) {
			return null;
		}

		list( $euros, $centimos ) = explode( ',', str_replace( array( '-', '.' ), '', $texto ) );
		$euros                    = ltrim( $euros, '0' );

		if ( strlen( $euros ) > self::MAX_CIFRAS_EUROS ) {
			return null;
		}

		$valor = (int) $euros * 100 + (int) $centimos;

		return str_starts_with( $texto, '-' ) ? -$valor : $valor;
	}
}
