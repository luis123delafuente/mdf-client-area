<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lector de bajo nivel de smalot/pdfparser con topes (#333). smalot lee todo
 * el PDF de una vez (objetos y flujos ya descomprimidos) sin limites propios
 * mas alla de un tope opcional por flujo; aqui se acota:
 *
 * - cada flujo: antes de descomprimirlo se fija el tope de smalot con lo que
 *   queda del presupuesto total (Pdf_Limites::bytes_para_flujo()) y al volver
 *   se descuenta lo que ocupa. Los filtros (Pdf_Filtros_Acotados) cortan en
 *   cuanto se pasa;
 * - los flujos de referencias cruzadas (/Type /XRef): smalot los convierte
 *   en un array de PHP de un elemento por byte, rellena con array_fill() una
 *   fila de /Columns elementos y recorre /W byte a byte. Con valores
 *   manipulados eso agota la memoria o no acaba, asi que se exige /W en
 *   linea con anchos pequenos, /Columns pequeno y un flujo pequeno;
 * - cada valor (getRawObject() se llama una vez por numero, nombre, cadena,
 *   array...): cuenta para el tope de objetos y para el reloj. Un array de
 *   millones de numeros en un solo objeto no llega a crearse.
 *
 * Las excepciones de un filtro las silencia smalot; por eso, al volver de
 * cada flujo, se relanza lo que Pdf_Limites tenga anotado.
 */
class Pdf_Datos_Acotados extends \Smalot\PdfParser\RawData\RawDataParser {

	private Pdf_Limites $limites;
	private \Smalot\PdfParser\Config $configuracion;

	public function __construct( \Smalot\PdfParser\Config $config, Pdf_Limites $limites ) {
		parent::__construct( array(), $config );

		$this->configuracion = $config;
		$this->limites       = $limites;
		$this->filterHelper  = new Pdf_Filtros_Acotados( $limites );
	}

	protected function decodeStream( string $pdfData, array $xref, array $sdic, string $stream ): array {
		$this->limites->comprobar();

		$es_xref = 'XRef' === self::nombre_de( $sdic, 'Type' );

		if ( $es_xref ) {
			self::comprobar_xref( $sdic, $this->limites );
		}

		$tope = $this->limites->bytes_para_flujo( $es_xref );
		$this->configuracion->setDecodeMemoryLimit( $tope );

		$resultado = parent::decodeStream( $pdfData, $xref, $sdic, $stream );

		$this->limites->relanzar();

		// Un flujo que no se ha podido descomprimir se queda como estaba: para
		// un xref, eso tambien tiene que caber en su tope.
		if ( strlen( $resultado[0] ) > $tope ) {
			$this->limites->exceder( 'un flujo del PDF descomprimido supera el tamaño máximo' );
		}

		$this->limites->consumir_bytes( strlen( $resultado[0] ) );

		return $resultado;
	}

	protected function getRawObject( string $pdfData, int $offset = 0, ?array $headerDic = null, int $depth = 0 ): array {
		$this->limites->contar_valor();

		return parent::getRawObject( $pdfData, $offset, $headerDic, $depth );
	}

	/** /W en linea, cada ancho entre 0 y el tope, y /Columns del predictor acotado. */
	private static function comprobar_xref( array $sdic, Pdf_Limites $limites ): void {
		$w = self::valor_de( $sdic, 'W' );

		if ( null === $w || '[' !== $w[0] || ! is_array( $w[1] ) ) {
			$limites->exceder( 'las referencias cruzadas del PDF no tienen un formato admitido' );
		}

		foreach ( $w[1] as $ancho ) {
			if ( 'numeric' !== ( $ancho[0] ?? '' ) || (int) $ancho[1] < 0 || (int) $ancho[1] > Pdf_Limites::MAX_ANCHO_CAMPO_XREF ) {
				$limites->exceder( 'las referencias cruzadas del PDF no tienen un formato admitido' );
			}
		}

		$parametros = self::valor_de( $sdic, 'DecodeParms' );

		if ( null !== $parametros && '<<' === $parametros[0] && is_array( $parametros[1] ) ) {
			$columnas = self::valor_de( $parametros[1], 'Columns' );

			if ( null !== $columnas && ( 'numeric' !== $columnas[0] || (int) $columnas[1] < 0 || (int) $columnas[1] > Pdf_Limites::MAX_COLUMNAS_XREF ) ) {
				$limites->exceder( 'las referencias cruzadas del PDF no tienen un formato admitido' );
			}
		}
	}

	/** Valor de /$clave en un diccionario en bruto de smalot (lista de pares [tipo, valor]). */
	private static function valor_de( array $diccionario, string $clave ): ?array {
		foreach ( $diccionario as $k => $v ) {
			if ( is_array( $v ) && '/' === ( $v[0] ?? null ) && $clave === ( $v[1] ?? null ) && isset( $diccionario[ $k + 1 ] ) && is_array( $diccionario[ $k + 1 ] ) ) {
				return $diccionario[ $k + 1 ];
			}
		}

		return null;
	}

	private static function nombre_de( array $diccionario, string $clave ): ?string {
		$valor = self::valor_de( $diccionario, $clave );

		return null !== $valor && '/' === $valor[0] && is_string( $valor[1] ) ? $valor[1] : null;
	}
}
