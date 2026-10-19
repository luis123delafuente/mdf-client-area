<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extensiones de PHP que necesita cada funcion que usa las librerias de
 * lib/ (#333). Unico sitio que lo decide: el aviso del backoffice
 * (Admin_Requisitos_Servidor) y las propias funciones, que se niegan con un
 * mensaje entendible en vez de romperse a medias (un error fatal a mitad de
 * un render o de una lectura).
 *
 * Sacado de los composer.json de lib/vendor y del codigo propio:
 * - Lectura de facturas en PDF: smalot/pdfparser pide zlib e iconv (y
 *   mbstring, que su polyfill solo emula a medias); el extractor propio usa
 *   zlib (inflate_*), mbstring, iconv (MacRoman) y ctype.
 * - Modelo 347: dompdf pide dom y mbstring; php-css-parser, iconv; Cpdf
 *   necesita gd para el logo PNG; OpenSpout pide dom, filter, libxml,
 *   xmlreader y zip; Xlsx_Validator, zip; el PDF generado se valida con
 *   fileinfo; los identificadores fiscales usan ctype.
 */
class Requisitos_Servidor {

	public const LECTURA_FACTURAS = 'lectura_facturas';
	public const MODELO_347       = 'modelo_347';

	private const EXTENSIONES = array(
		self::LECTURA_FACTURAS => array( 'zlib', 'mbstring', 'iconv', 'ctype' ),
		self::MODELO_347       => array( 'dom', 'mbstring', 'iconv', 'gd', 'zip', 'xmlreader', 'libxml', 'filter', 'fileinfo', 'ctype' ),
	);

	private const NOMBRES = array(
		self::LECTURA_FACTURAS => 'Leer facturas en PDF',
		self::MODELO_347       => 'Generar el Modelo 347',
	);

	/** @return list<string> funciones conocidas */
	public static function funciones(): array {
		return array_keys( self::EXTENSIONES );
	}

	public static function nombre( string $funcion ): string {
		return self::NOMBRES[ $funcion ] ?? $funcion;
	}

	/**
	 * Extensiones que faltan para $funcion (vacio si no falta ninguna o si la
	 * funcion no existe). El filtro solo sirve para probar el aviso en local.
	 *
	 * @return list<string>
	 */
	public static function faltan( string $funcion ): array {
		$faltan = array_values(
			array_filter(
				self::EXTENSIONES[ $funcion ] ?? array(),
				static fn( string $extension ): bool => ! extension_loaded( $extension )
			)
		);

		if ( function_exists( 'apply_filters' ) ) {
			$faltan = array_values( array_map( 'strval', (array) apply_filters( 'mdf_ca_requisitos_faltan', $faltan, $funcion ) ) );
		}

		return $faltan;
	}

	/** Mensaje para el administrador si falta alguna extension; null si esta todo. */
	public static function mensaje( string $funcion ): ?string {
		$faltan = self::faltan( $funcion );

		if ( ! $faltan ) {
			return null;
		}

		return sprintf(
			'%s no está disponible: al PHP del servidor le %s %s. Pide al hosting (IONOS) que %s y vuelve a cargar esta pantalla.',
			self::nombre( $funcion ),
			1 === count( $faltan ) ? 'falta la extensión' : 'faltan las extensiones',
			self::lista( $faltan ),
			1 === count( $faltan ) ? 'la active' : 'las active'
		);
	}

	public static function error( string $funcion ): ?\WP_Error {
		$mensaje = self::mensaje( $funcion );

		return null === $mensaje ? null : new \WP_Error( 'mdf_ca_requisitos_' . $funcion, $mensaje );
	}

	/** @param list<string> $extensiones */
	private static function lista( array $extensiones ): string {
		if ( 1 === count( $extensiones ) ) {
			return $extensiones[0];
		}

		$ultima = array_pop( $extensiones );

		return implode( ', ', $extensiones ) . ' y ' . $ultima;
	}
}
