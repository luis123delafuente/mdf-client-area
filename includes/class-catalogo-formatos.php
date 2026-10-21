<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Formatos de archivo admitidos en una herramienta del catalogo y lo que se
 * puede hacer con cada uno. Lista fija en codigo (mismo criterio que
 * Documento_Tipos): es lo que decide si un archivo va al visor o solo se
 * descarga, y eso no lo cambia nadie desde el backoffice.
 *
 * - visor: PDF, Excel (.xlsx) e imagenes se ven en el visor (canvas, con la
 *   marca de agua si la herramienta la lleva).
 * - Word, PowerPoint y Excel con macros no se pueden pintar en el visor:
 *   siempre se descargan, y la marca de agua no aplica.
 *
 * El .xlsm solo se admite si el administrador lo permite (Catalogo_Ajustes);
 * la regla vive en Permissions y en Catalogo_Archivo_Validator, no aqui.
 */
class Catalogo_Formatos {

	public const PDF  = 'pdf';
	public const XLSX = 'xlsx';
	public const XLSM = 'xlsm';
	public const DOCX = 'docx';
	public const PPTX = 'pptx';
	public const JPG  = 'jpg';
	public const PNG  = 'png';

	private const FORMATOS = array(
		self::PDF  => array(
			'etiqueta' => 'PDF',
			'mime'     => 'application/pdf',
			'visor'    => true,
		),
		self::XLSX => array(
			'etiqueta' => 'Excel',
			'mime'     => Xlsx_Validator::MIME,
			'visor'    => true,
		),
		self::XLSM => array(
			'etiqueta' => 'Excel con macros',
			'mime'     => 'application/vnd.ms-excel.sheet.macroEnabled.12',
			'visor'    => false,
		),
		self::DOCX => array(
			'etiqueta' => 'Word',
			'mime'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'visor'    => false,
		),
		self::PPTX => array(
			'etiqueta' => 'PowerPoint',
			'mime'     => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
			'visor'    => false,
		),
		self::JPG  => array(
			'etiqueta' => 'Imagen JPG',
			'mime'     => 'image/jpeg',
			'visor'    => true,
		),
		self::PNG  => array(
			'etiqueta' => 'Imagen PNG',
			'mime'     => 'image/png',
			'visor'    => true,
		),
	);

	/** Extensiones que nunca se admiten porque llevan (o pueden llevar) macros, con su propio mensaje. */
	public const EXTENSIONES_CON_MACROS = array( 'docm', 'dotm', 'pptm', 'potm', 'ppsm', 'xlsb', 'xltm', 'xlam' );

	public static function es_valido( ?string $formato ): bool {
		return null !== $formato && isset( self::FORMATOS[ $formato ] );
	}

	/** Se puede pintar en el visor. */
	public static function es_visualizable( ?string $formato ): bool {
		return self::es_valido( $formato ) && self::FORMATOS[ $formato ]['visor'];
	}

	/** Se descarga siempre, marque lo que se marque: no hay visor para el. */
	public static function es_siempre_descargable( ?string $formato ): bool {
		return self::es_valido( $formato ) && ! self::FORMATOS[ $formato ]['visor'];
	}

	public static function get_mime( string $formato ): string {
		return self::FORMATOS[ $formato ]['mime'] ?? 'application/octet-stream';
	}

	public static function get_etiqueta( ?string $formato ): string {
		return self::es_valido( $formato ) ? self::FORMATOS[ $formato ]['etiqueta'] : 'Archivo';
	}

	/** Extension del fichero descargado (jpg, no jpeg). */
	public static function get_extension( string $formato ): string {
		return $formato;
	}

	/**
	 * Formato por la extension del nombre original, o null. Es solo la
	 * primera criba: Catalogo_Archivo_Validator comprueba despues que el
	 * contenido real es de ese formato.
	 */
	public static function desde_extension( string $extension ): ?string {
		$extension = strtolower( $extension );

		if ( 'jpeg' === $extension ) {
			return self::JPG;
		}

		return isset( self::FORMATOS[ $extension ] ) ? $extension : null;
	}

	/**
	 * Valor del atributo accept del input de archivo. El .xlsm solo si el
	 * ajuste lo permite.
	 */
	public static function accept( bool $con_xlsm ): string {
		$accept = '.pdf,.xlsx,.docx,.pptx,.jpg,.jpeg,.png';

		return $con_xlsm ? $accept . ',.xlsm' : $accept;
	}

	/** Lo mismo en texto, para los mensajes de la zona de arrastrar. */
	public static function texto_admitidos( bool $con_xlsm ): string {
		return $con_xlsm
			? 'PDF, Excel (.xlsx o .xlsm), Word (.docx), PowerPoint (.pptx) o imagen (JPG o PNG)'
			: 'PDF, Excel (.xlsx), Word (.docx), PowerPoint (.pptx) o imagen (JPG o PNG)';
	}
}
