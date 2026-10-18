<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lee el Excel del Modelo 347 que exporta SAGE (#307, generador): hoja
 * "347", columnas localizadas por el NOMBRE de su cabecera (primera fila), en
 * cualquier orden. Solo lee y sanea: no decide nada de negocio (eso es
 * Modelo_347_Simulacion_Service) ni escribe nada.
 *
 * Antes de que OpenSpout abra el fichero, Xlsx_Validator::validar_para_lectura()
 * comprueba que es un .xlsx de verdad, sin macros ni objetos embebidos, sin
 * DOCTYPE/ENTITY en ningun XML y dentro de los limites anti zip-bomb.
 * OpenSpout lee por streaming (XMLReader): la memoria no depende del tamano.
 *
 * Importes: siempre en CENTIMOS (int). Una celda numerica se redondea a
 * centimos; un texto solo se acepta en formatos inequivocos (1.234,56 /
 * 1234,56 / 1234.56 / 1234). "1.234" (punto y tres cifras) es ambiguo y se
 * trata como ilegible. Trimestre vacio = 0 (con aviso); DEV vacio o
 * cualquier importe ilegible = null, y la fila queda bloqueada en la
 * simulacion.
 */
class Modelo_347_Excel_Lector {

	public const HOJA            = '347';
	public const MAX_BYTES       = 5 * 1024 * 1024;
	public const MAX_FILAS       = 2000;
	private const MAX_TEXTO      = 255;

	/** Cabecera normalizada => clave interna. "email" es opcional (modo proveedores, futuro). */
	private const COLUMNAS = array(
		'codigo'        => 'codigo',
		'nombre'        => 'nombre',
		'nif'           => 'nif',
		'direccion'     => 'direccion',
		'poblacion'     => 'poblacion',
		'codigo postal' => 'cp',
		'trimestre 1'   => 't1',
		'trimestre 2'   => 't2',
		'trimestre 3'   => 't3',
		'trimestre 4'   => 't4',
		'importe dev'   => 'dev',
		'importe recc'  => 'recc_importe',
		'recc'          => 'recc',
	);

	private const COLUMNAS_OPCIONALES = array(
		'email' => 'email',
	);

	/**
	 * @return array{filas: array<int, array<string, mixed>>, tiene_email: bool}|\WP_Error
	 *         Cada fila: linea (numero de fila en Excel), codigo, nombre, nif
	 *         (tal cual, recortado), direccion, poblacion, cp, email,
	 *         t1..t4 y dev (centimos o null si ilegible), t_vacios (trimestres
	 *         vacios tomados como 0), recc_importe (centimos o null), recc_activo (bool).
	 */
	public function leer( string $ruta ) {
		$error = new \WP_Error( 'mdf_ca_347_excel_invalido', 'El fichero no es un Excel (.xlsx) valido o contiene macros u objetos embebidos.' );

		if ( ! is_file( $ruta ) || filesize( $ruta ) > self::MAX_BYTES ) {
			return new \WP_Error( 'mdf_ca_347_excel_tamano', sprintf( 'El Excel supera el tamano maximo (%d MB).', self::MAX_BYTES / 1024 / 1024 ) );
		}

		$validacion = Xlsx_Validator::validar_para_lectura( $ruta );

		if ( is_wp_error( $validacion ) ) {
			return $validacion;
		}

		Modelo_347_Librerias::cargar();

		// Filas vacias conservadas: asi "linea" es el numero de fila del Excel
		// que Administracion ve al abrirlo.
		$reader = new \OpenSpout\Reader\XLSX\Reader( new \OpenSpout\Reader\XLSX\Options( SHOULD_PRESERVE_EMPTY_ROWS: true ) );

		try {
			$reader->open( $ruta );
		} catch ( \Throwable $e ) {
			return $error;
		}

		try {
			$hoja = null;

			foreach ( $reader->getSheetIterator() as $sheet ) {
				if ( self::HOJA === trim( $sheet->getName() ) ) {
					$hoja = $sheet;
					break;
				}
			}

			if ( null === $hoja ) {
				return new \WP_Error( 'mdf_ca_347_excel_sin_hoja', 'El Excel no tiene ninguna hoja llamada «347».' );
			}

			$mapa  = null;
			$filas = array();
			$linea = 0;

			foreach ( $hoja->getRowIterator() as $row ) {
				++$linea;
				// $row->cells va indexado por columna (puede tener huecos).
				$valores = array_map( static fn( $cell ) => $cell->getValue(), $row->cells );

				if ( null === $mapa ) {
					$mapa = self::mapear_cabecera( $valores );

					if ( is_wp_error( $mapa ) ) {
						return $mapa;
					}

					continue;
				}

				if ( self::fila_vacia( $valores ) ) {
					continue;
				}

				if ( count( $filas ) >= self::MAX_FILAS ) {
					return new \WP_Error( 'mdf_ca_347_excel_filas', sprintf( 'El Excel tiene mas de %d filas de datos. No se ha procesado nada.', self::MAX_FILAS ) );
				}

				$filas[] = self::leer_fila( $linea, $valores, $mapa );
			}

			if ( null === $mapa ) {
				return new \WP_Error( 'mdf_ca_347_excel_vacio', 'La hoja «347» esta vacia.' );
			}

			return array(
				'filas'       => $filas,
				'tiene_email' => isset( $mapa['email'] ),
			);
		} catch ( \Throwable $e ) {
			// Nunca el mensaje de la libreria (puede llevar contenido del fichero).
			return $error;
		} finally {
			$reader->close();
		}
	}

	/**
	 * @param array<int, mixed> $valores
	 * @return array<string, int>|\WP_Error clave interna => indice de columna
	 */
	private static function mapear_cabecera( array $valores ) {
		$mapa  = array();
		$todas = self::COLUMNAS + self::COLUMNAS_OPCIONALES;

		foreach ( $valores as $indice => $valor ) {
			$normalizada = self::normalizar_cabecera( is_scalar( $valor ) ? (string) $valor : '' );

			if ( ! isset( $todas[ $normalizada ] ) ) {
				continue;
			}

			$clave = $todas[ $normalizada ];

			if ( isset( $mapa[ $clave ] ) ) {
				return new \WP_Error( 'mdf_ca_347_excel_cabecera_duplicada', 'La cabecera del Excel tiene una columna repetida. No se ha procesado nada.' );
			}

			$mapa[ $clave ] = (int) $indice;
		}

		$faltan = array_diff( self::COLUMNAS, array_keys( $mapa ) );

		if ( $faltan ) {
			$nombres = array(
				'codigo'       => 'Código',
				'nombre'       => 'Nombre',
				'nif'          => 'NIF',
				'direccion'    => 'Dirección',
				'poblacion'    => 'Población',
				'cp'           => 'Código postal',
				't1'           => 'Trimestre 1',
				't2'           => 'Trimestre 2',
				't3'           => 'Trimestre 3',
				't4'           => 'Trimestre 4',
				'dev'          => 'Importe DEV',
				'recc_importe' => 'Importe RECC',
				'recc'         => 'RECC',
			);

			return new \WP_Error(
				'mdf_ca_347_excel_columnas',
				'Faltan columnas en la hoja «347»: ' . implode( ', ', array_map( static fn( string $c ): string => $nombres[ $c ], $faltan ) ) . '. No se ha procesado nada.'
			);
		}

		return $mapa;
	}

	/** "  Código Postal " => "codigo postal". */
	private static function normalizar_cabecera( string $texto ): string {
		$texto = remove_accents( trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $texto ) ?? '' ) );

		return strtolower( $texto );
	}

	/** @param array<int, mixed> $valores */
	private static function fila_vacia( array $valores ): bool {
		foreach ( $valores as $valor ) {
			if ( null !== $valor && '' !== trim( is_scalar( $valor ) ? (string) $valor : 'x' ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array<int, mixed>  $valores
	 * @param array<string, int> $mapa
	 * @return array<string, mixed>
	 */
	private static function leer_fila( int $linea, array $valores, array $mapa ): array {
		$texto = static fn( string $clave ): string => isset( $mapa[ $clave ] ) ? self::texto( $valores[ $mapa[ $clave ] ] ?? null ) : '';

		$fila = array(
			'linea'     => $linea,
			'codigo'    => $texto( 'codigo' ),
			'nombre'    => $texto( 'nombre' ),
			'nif'       => $texto( 'nif' ),
			'direccion' => $texto( 'direccion' ),
			'poblacion' => $texto( 'poblacion' ),
			'cp'        => $texto( 'cp' ),
			'email'     => $texto( 'email' ),
			't_vacios'  => array(),
		);

		foreach ( array( 't1', 't2', 't3', 't4' ) as $t ) {
			$bruto = $valores[ $mapa[ $t ] ] ?? null;

			if ( self::es_vacio( $bruto ) ) {
				$fila[ $t ]           = 0;
				$fila['t_vacios'][] = $t;
			} else {
				$fila[ $t ] = self::centimos( $bruto );
			}
		}

		$fila['dev']          = self::es_vacio( $valores[ $mapa['dev'] ] ?? null ) ? null : self::centimos( $valores[ $mapa['dev'] ] );
		$fila['recc_importe'] = self::es_vacio( $valores[ $mapa['recc_importe'] ] ?? null ) ? 0 : self::centimos( $valores[ $mapa['recc_importe'] ] );
		$fila['recc_activo']  = self::recc_activo( $valores[ $mapa['recc'] ] ?? null );

		return $fila;
	}

	/** Texto recortado (tambien el espacio duro que pega SAGE/Excel), sin caracteres de control, como mucho 255. */
	private static function texto( $valor ): string {
		if ( $valor instanceof \DateTimeInterface ) {
			return $valor->format( 'Y-m-d' );
		}

		if ( is_float( $valor ) && floor( $valor ) === $valor ) {
			$valor = (string) (int) $valor;
		}

		$texto = is_scalar( $valor ) ? (string) $valor : '';
		$texto = (string) preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $texto );
		$texto = trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $texto ) );

		return mb_substr( $texto, 0, self::MAX_TEXTO );
	}

	private static function es_vacio( $valor ): bool {
		return null === $valor || ( is_string( $valor ) && '' === trim( str_replace( "\u{00A0}", ' ', $valor ) ) );
	}

	/** Importe en centimos, o null si no se puede leer sin ambiguedad. */
	private static function centimos( $valor ): ?int {
		if ( is_int( $valor ) ) {
			return $valor * 100;
		}

		if ( is_float( $valor ) ) {
			return is_finite( $valor ) && abs( $valor ) < 1e12 ? (int) round( $valor * 100 ) : null;
		}

		if ( ! is_string( $valor ) ) {
			return null;
		}

		$t = str_replace( array( ' ', "\u{00A0}", '€' ), '', trim( $valor ) );

		if ( 1 === preg_match( '/^-?\d{1,3}(\.\d{3})+(,\d{1,2})?$/D', $t ) ) {
			$t = str_replace( array( '.', ',' ), array( '', '.' ), $t ); // 1.234,56
		} elseif ( 1 === preg_match( '/^-?\d+,\d{1,2}$/D', $t ) ) {
			$t = str_replace( ',', '.', $t );                          // 1234,56
		} elseif ( 1 === preg_match( '/^-?\d+\.\d{3}$/D', $t ) ) {
			return null;                                               // 1.234: ambiguo
		} elseif ( 1 !== preg_match( '/^-?\d+(\.\d{1,2})?$/D', $t ) ) {
			return null;                                               // 1234.56 o 1234 pasan
		}

		$negativo = str_starts_with( $t, '-' );
		$t        = ltrim( $t, '-' );
		$partes   = explode( '.', $t );
		$centimos = (int) $partes[0] * 100 + ( isset( $partes[1] ) ? (int) str_pad( $partes[1], 2, '0' ) : 0 );

		return $negativo ? -$centimos : $centimos;
	}

	/** RECC "apagado": vacio, 0, false o "False"/"No"/"0". Cualquier otra cosa cuenta como activo (aviso). */
	private static function recc_activo( $valor ): bool {
		if ( null === $valor || false === $valor || 0 === $valor || 0.0 === $valor ) {
			return false;
		}

		if ( is_string( $valor ) ) {
			return ! in_array( strtolower( trim( $valor ) ), array( '', '0', 'false', 'falso', 'no' ), true );
		}

		return true;
	}
}
