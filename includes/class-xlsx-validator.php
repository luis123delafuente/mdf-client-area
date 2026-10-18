<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validacion por contenido de un .xlsx, compartida por la subida de
 * documentos (Documento_Service, que solo la usa para aceptar o rechazar un
 * Excel) y por el generador del Modelo 347 (que despues lo lee con
 * OpenSpout). Un .xlsx es un ZIP (OOXML) y la extension la controla quien lo
 * sube: aqui se comprueba que el contenido lo es de verdad y que no lleva
 * nada ejecutable: estructura minima de libro de Excel, sin vbaProject.bin,
 * sin objetos OLE embebidos y sin tipo de contenido "macroEnabled" en el
 * libro principal (un .xlsm renombrado a .xlsx). Nada se extrae a disco:
 * solo se suman los tamanos declarados (anti zip-bomb).
 *
 * Ningun XML del libro puede declarar DOCTYPE ni ENTITY (XXE y billion
 * laughs), aunque libxml2 >= 2.9 ya no expanda entidades externas. Desde
 * #307 lo comprueban las dos entradas: la subida de documentos (validar(),
 * cuyo Excel se abre despues con SheetJS en el visor) y el generador
 * (validar_para_lectura(), que lo parsea en el servidor). Ningun Excel
 * legitimo lleva DTD.
 */
class Xlsx_Validator {

	public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	/** Unico tipo admitido para el libro principal (un xlsm seria ...sheet.macroEnabled.main+xml). */
	private const TIPO_LIBRO_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml';

	private const MAX_ENTRADAS          = 2000;
	private const MAX_DESCOMPRIMIDO_BYTES = 200 * 1024 * 1024;

	/** Bytes de cada XML que se inspeccionan buscando DOCTYPE/ENTITY: la declaracion va siempre al principio. */
	private const BYTES_CABECERA_XML = 4096;

	/**
	 * @return true|\WP_Error
	 */
	public static function validar( string $ruta ) {
		return self::comprobar( $ruta, true );
	}

	/**
	 * Lo mismo que validar(): el nombre deja claro, en el generador, que
	 * despues se parsea el contenido en el servidor.
	 *
	 * @return true|\WP_Error
	 */
	public static function validar_para_lectura( string $ruta ) {
		return self::comprobar( $ruta, true );
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function comprobar( string $ruta, bool $sin_dtd ) {
		$invalido = new \WP_Error( 'mdf_ca_documento_xlsx_invalido', 'El fichero no es un Excel (.xlsx) valido o contiene macros u objetos embebidos.' );

		if ( ! class_exists( \ZipArchive::class ) ) {
			return new \WP_Error( 'mdf_ca_documento_xlsx_sin_zip', 'El servidor no puede validar ficheros Excel. Contacta con desarrollo antes de seguir.' );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $ruta, \ZipArchive::RDONLY ) ) {
			return $invalido;
		}

		try {
			if ( $zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRADAS ) {
				return $invalido;
			}

			$nombres       = array();
			$originales    = array();
			$descomprimido = 0;

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );

				if ( false === $stat ) {
					return $invalido;
				}

				$originales[]   = $stat['name'];
				$nombres[]      = strtolower( $stat['name'] );
				$descomprimido += (int) $stat['size'];
			}

			if ( $descomprimido > self::MAX_DESCOMPRIMIDO_BYTES ) {
				return $invalido;
			}

			if ( ! in_array( '[content_types].xml', $nombres, true ) || ! in_array( 'xl/workbook.xml', $nombres, true ) ) {
				return $invalido;
			}

			foreach ( $nombres as $nombre ) {
				if ( str_ends_with( $nombre, 'vbaproject.bin' ) || str_starts_with( $nombre, 'xl/embeddings/' ) || str_contains( $nombre, '..' ) ) {
					return $invalido;
				}
			}

			if ( $sin_dtd ) {
				foreach ( $originales as $nombre ) {
					$minuscula = strtolower( $nombre );

					if ( ! str_ends_with( $minuscula, '.xml' ) && ! str_ends_with( $minuscula, '.rels' ) ) {
						continue;
					}

					if ( self::declara_dtd( $zip, $nombre ) ) {
						return $invalido;
					}
				}
			}

			$content_types = $zip->getFromName( '[Content_Types].xml' );

			if ( false === $content_types ) {
				return $invalido;
			}

			// El tipo del libro principal decide si es xlsx o xlsm. No se
			// busca "macroEnabled" en todo el fichero: hay generadores
			// legitimos (p. ej. SheetJS) que declaran un Default generico con
			// ese texto sin que exista ninguna macro.
			$xml = simplexml_load_string( $content_types, 'SimpleXMLElement', LIBXML_NONET );

			if ( false === $xml ) {
				return $invalido;
			}

			if ( self::TIPO_LIBRO_XLSX !== self::tipo_de_parte( $xml, '/xl/workbook.xml' ) ) {
				return $invalido;
			}
		} finally {
			$zip->close();
		}

		return true;
	}

	/**
	 * Tipo de contenido EFECTIVO de una parte segun la regla de OPC (ECMA-376
	 * parte 2): el de su Override si existe; si no, el Default de su
	 * extension. Nombres de parte y extensiones sin distinguir mayusculas,
	 * como manda el estandar. null si no hay ninguno.
	 *
	 * Antes solo se miraba el Override y se rechazaban xlsx validos: SAGE
	 * (libreria .NET estilo OpenXML SDK) no escribe Override para
	 * /xl/workbook.xml y declara el tipo del libro con
	 * <Default Extension="xml" ContentType="...sheet.main+xml"/>. Un xlsm
	 * sigue rechazandose: su tipo efectivo es macroEnabled.main+xml venga de
	 * donde venga, y un Override macroEnabled gana a un Default main.
	 */
	private static function tipo_de_parte( \SimpleXMLElement $tipos, string $parte ): ?string {
		$parte     = strtolower( $parte );
		$extension = strtolower( pathinfo( $parte, PATHINFO_EXTENSION ) );
		$overrides = array();
		$defaults  = array();

		foreach ( $tipos->children() as $nodo ) {
			if ( 'Override' === $nodo->getName() && strtolower( (string) $nodo['PartName'] ) === $parte ) {
				$overrides[] = (string) $nodo['ContentType'];
			}

			if ( 'Default' === $nodo->getName() && strtolower( (string) $nodo['Extension'] ) === $extension ) {
				$defaults[] = (string) $nodo['ContentType'];
			}
		}

		// Dos Override para la misma parte (o dos Default para la misma
		// extension) es un paquete invalido segun OPC y ambiguo: un
		// Override main seguido de uno macroEnabled no puede colar. null =
		// rechazo.
		if ( count( $overrides ) > 1 || count( $defaults ) > 1 ) {
			return null;
		}

		return $overrides[0] ?? $defaults[0] ?? null;
	}

	/** Si los primeros bytes de esta entrada declaran un DOCTYPE o una ENTITY. Lee por stream, nunca la entrada entera. */
	private static function declara_dtd( \ZipArchive $zip, string $nombre ): bool {
		$stream = $zip->getStream( $nombre );

		if ( false === $stream ) {
			return true;
		}

		$cabecera = (string) fread( $stream, self::BYTES_CABECERA_XML );
		fclose( $stream );

		return false !== stripos( $cabecera, '<!DOCTYPE' ) || false !== stripos( $cabecera, '<!ENTITY' );
	}
}
