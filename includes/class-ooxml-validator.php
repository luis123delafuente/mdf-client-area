<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validacion por contenido de un Word (.docx), un PowerPoint (.pptx) o un
 * Excel con macros (.xlsm) de una herramienta del catalogo. El .xlsx sin
 * macros sigue en Xlsx_Validator (el mismo de los documentos y del 347), y
 * de alli se reutilizan la regla OPC del tipo de una parte y la busqueda de
 * DOCTYPE/ENTITY.
 *
 * Igual que con el xlsx: es un ZIP que se inspecciona sin extraer nada a
 * disco, con los mismos topes anti zip bomb, y el tipo de la parte principal
 * decide el formato (un .docm renombrado a .docx tiene el tipo
 * macroEnabled y se rechaza). Ademas, en docx y pptx:
 *
 * - ningun vbaProject.bin ni vbaData.xml (macros) en ninguna ruta;
 * - nada en activeX/ (controles ActiveX);
 * - en embeddings/ solo se admiten libros y documentos OOXML incrustados
 *   (.xlsx, .docx, .pptx: los graficos de PowerPoint y Word guardan asi sus
 *   datos); un objeto OLE (.bin) o cualquier otra cosa se rechaza;
 * - ninguna relacion externa salvo los hipervinculos: una plantilla adjunta
 *   remota (attachedTemplate) es la via habitual para colar macros en un
 *   docx sin macros, y un objeto o imagen vinculados piden algo a internet
 *   al abrir el documento.
 *
 * En el .xlsm las macros son lo esperado (solo se admite si el
 * administrador lo permite y siempre se descarga): se exige que sea un libro
 * macroEnabled de verdad y se aplican los topes, la regla de DTD y la de
 * relaciones externas.
 *
 * Limite asumido: no se abre un .xlsx incrustado para validarlo a su vez.
 */
class Ooxml_Validator {

	private const MAX_ENTRADAS            = 5000;
	private const MAX_DESCOMPRIMIDO_BYTES = 200 * 1024 * 1024;

	/** Parte principal y su unico tipo admitido, por formato. */
	private const PARTES = array(
		Catalogo_Formatos::DOCX => array( '/word/document.xml', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml' ),
		Catalogo_Formatos::PPTX => array( '/ppt/presentation.xml', 'application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml' ),
		Catalogo_Formatos::XLSM => array( '/xl/workbook.xml', 'application/vnd.ms-excel.sheet.macroEnabled.main+xml' ),
	);

	private const INCRUSTADOS_ADMITIDOS = array( 'xlsx', 'docx', 'pptx' );

	/**
	 * @return true|\WP_Error
	 */
	public static function validar( string $ruta, string $formato ) {
		if ( ! isset( self::PARTES[ $formato ] ) ) {
			return self::invalido( $formato );
		}

		if ( ! class_exists( \ZipArchive::class ) ) {
			return new \WP_Error( 'mdf_ca_herramienta_sin_zip', 'El servidor no puede validar ficheros de Office. Contacta con desarrollo antes de seguir.' );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $ruta, \ZipArchive::RDONLY ) ) {
			return self::invalido( $formato );
		}

		try {
			$resultado = self::comprobar( $zip, $formato );
		} finally {
			$zip->close();
		}

		return $resultado;
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function comprobar( \ZipArchive $zip, string $formato ) {
		$con_macros = Catalogo_Formatos::XLSM === $formato;

		if ( $zip->numFiles < 1 || $zip->numFiles > self::MAX_ENTRADAS ) {
			return self::invalido( $formato );
		}

		list( $parte_principal, $tipo_esperado ) = self::PARTES[ $formato ];

		$nombres       = array();
		$descomprimido = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );

			if ( false === $stat ) {
				return self::invalido( $formato );
			}

			$nombres[]      = $stat['name'];
			$descomprimido += (int) $stat['size'];
		}

		if ( $descomprimido > self::MAX_DESCOMPRIMIDO_BYTES ) {
			return self::invalido( $formato );
		}

		$minusculas = array_map( 'strtolower', $nombres );

		if ( ! in_array( '[content_types].xml', $minusculas, true ) || ! in_array( ltrim( $parte_principal, '/' ), $minusculas, true ) ) {
			return self::invalido( $formato );
		}

		foreach ( $minusculas as $nombre ) {
			if ( str_contains( $nombre, '..' ) || str_starts_with( $nombre, '/' ) || str_contains( $nombre, '\\' ) ) {
				return self::invalido( $formato );
			}

			if ( ! $con_macros && self::es_contenido_activo( $nombre ) ) {
				return self::con_macros( $formato );
			}
		}

		foreach ( $nombres as $nombre ) {
			$minuscula = strtolower( $nombre );

			if ( ! str_ends_with( $minuscula, '.xml' ) && ! str_ends_with( $minuscula, '.rels' ) ) {
				continue;
			}

			if ( Xlsx_Validator::declara_dtd( $zip, $nombre ) ) {
				return self::invalido( $formato );
			}

			if ( str_ends_with( $minuscula, '.rels' ) && self::tiene_relacion_externa_no_admitida( $zip, $nombre ) ) {
				return new \WP_Error( 'mdf_ca_herramienta_relacion_externa', 'El documento enlaza contenido externo (una plantilla, un objeto o una imagen vinculados). Guárdalo sin vínculos y vuelve a subirlo.' );
			}
		}

		$content_types = $zip->getFromName( '[Content_Types].xml' );
		$xml           = false === $content_types ? false : simplexml_load_string( $content_types, 'SimpleXMLElement', LIBXML_NONET );

		if ( false === $xml ) {
			return self::invalido( $formato );
		}

		$tipo = Xlsx_Validator::tipo_de_parte( $xml, $parte_principal );

		if ( $tipo_esperado !== $tipo ) {
			// Un docx o pptx cuyo tipo real es el de macros se explica como tal.
			return null !== $tipo && false !== stripos( $tipo, 'macroEnabled' ) ? self::con_macros( $formato ) : self::invalido( $formato );
		}

		return true;
	}

	/** Macros, ActiveX u objetos incrustados que no son OOXML. */
	private static function es_contenido_activo( string $nombre ): bool {
		$base = basename( $nombre );

		if ( 'vbaproject.bin' === $base || 'vbadata.xml' === $base || str_contains( $nombre, '/activex/' ) ) {
			return true;
		}

		if ( str_contains( $nombre, '/embeddings/' ) && ! str_ends_with( $nombre, '/' ) ) {
			return ! in_array( pathinfo( $base, PATHINFO_EXTENSION ), self::INCRUSTADOS_ADMITIDOS, true );
		}

		return false;
	}

	/**
	 * Alguna relacion con TargetMode="External" que no sea un hipervinculo.
	 * Un .rels que no se puede leer cuenta como rechazo.
	 */
	private static function tiene_relacion_externa_no_admitida( \ZipArchive $zip, string $nombre ): bool {
		$contenido = $zip->getFromName( $nombre );

		if ( false === $contenido ) {
			return true;
		}

		if ( false === stripos( $contenido, 'External' ) ) {
			return false;
		}

		$xml = simplexml_load_string( $contenido, 'SimpleXMLElement', LIBXML_NONET );

		if ( false === $xml ) {
			return true;
		}

		foreach ( $xml->children() as $relacion ) {
			if ( 'External' !== (string) $relacion['TargetMode'] ) {
				continue;
			}

			if ( ! str_ends_with( (string) $relacion['Type'], '/hyperlink' ) ) {
				return true;
			}
		}

		return false;
	}

	private static function invalido( string $formato ): \WP_Error {
		return new \WP_Error(
			'mdf_ca_herramienta_ooxml_invalido',
			sprintf( 'El fichero no es un %s válido.', Catalogo_Formatos::get_etiqueta( $formato ) )
		);
	}

	private static function con_macros( string $formato ): \WP_Error {
		return new \WP_Error(
			'mdf_ca_herramienta_con_macros',
			sprintf( 'El %s contiene macros, controles ActiveX u objetos incrustados y no se admite. Guárdalo sin ellos y vuelve a subirlo.', Catalogo_Formatos::get_etiqueta( $formato ) )
		);
	}
}
