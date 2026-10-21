<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Valida el archivo subido para una herramienta y dice de que formato es.
 * Nunca se fia de la extension ni del tipo que declara el navegador: la
 * extension solo elige que comprobacion se hace, y el contenido real tiene
 * que ser de ese formato.
 *
 * - PDF: fileinfo dice application/pdf.
 * - JPG y PNG: fileinfo dice image/jpeg o image/png y getimagesize() lo lee.
 * - .xlsx: Xlsx_Validator (sin macros ni objetos incrustados).
 * - .docx, .pptx y .xlsm: Ooxml_Validator.
 * - .xlsm solo si Catalogo_Ajustes lo permite; .docm, .pptm y el resto de
 *   formatos con macros, nunca. Cualquier otra extension se rechaza.
 *
 * 20 MB como mucho, el mismo tope que los documentos.
 */
class Catalogo_Archivo_Validator {

	public const TAMANO_MAXIMO_BYTES = Documento_Service::TAMANO_MAXIMO_BYTES;

	/**
	 * @param array{name?: string, tmp_name?: string, error?: int, size?: int}|null $archivo De $_FILES.
	 * @return array{formato: string, mime: string, tamano: int}|\WP_Error
	 */
	public static function validar( ?array $archivo, bool $permitir_xlsm ) {
		$error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new \WP_Error( 'mdf_ca_herramienta_sin_fichero', 'Selecciona un archivo.' );
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new \WP_Error( 'mdf_ca_herramienta_limite_servidor', 'El archivo supera el límite de subida configurado en el servidor.' );
		}

		if ( UPLOAD_ERR_OK !== $error || empty( $archivo['tmp_name'] ) || ! is_uploaded_file( $archivo['tmp_name'] ) ) {
			return new \WP_Error( 'mdf_ca_herramienta_error_subida', 'Ha ocurrido un error al subir el archivo. Inténtalo de nuevo.' );
		}

		$tamano = (int) filesize( $archivo['tmp_name'] );

		if ( $tamano < 1 ) {
			return new \WP_Error( 'mdf_ca_herramienta_vacio', 'El archivo está vacío.' );
		}

		if ( $tamano > self::TAMANO_MAXIMO_BYTES ) {
			return new \WP_Error(
				'mdf_ca_herramienta_demasiado_grande',
				sprintf( 'El archivo supera el tamaño máximo permitido (%d MB).', self::TAMANO_MAXIMO_BYTES / 1024 / 1024 )
			);
		}

		$extension = strtolower( pathinfo( (string) ( $archivo['name'] ?? '' ), PATHINFO_EXTENSION ) );

		if ( in_array( $extension, Catalogo_Formatos::EXTENSIONES_CON_MACROS, true ) ) {
			return new \WP_Error( 'mdf_ca_herramienta_con_macros', 'Los documentos con macros (.docm, .pptm y similares) no se admiten. Guárdalo como .docx o .pptx y vuelve a subirlo.' );
		}

		$formato = Catalogo_Formatos::desde_extension( $extension );

		if ( null === $formato ) {
			return new \WP_Error( 'mdf_ca_herramienta_tipo_no_permitido', 'Tipo de archivo no permitido. Solo se aceptan ' . Catalogo_Formatos::texto_admitidos( $permitir_xlsm ) . '.' );
		}

		if ( Catalogo_Formatos::XLSM === $formato && ! $permitir_xlsm ) {
			return new \WP_Error( 'mdf_ca_herramienta_xlsm_no_permitido', 'Los Excel con macros (.xlsm) no están permitidos. Solo el administrador puede activarlos, en Herramientas MDF > Ajustes.' );
		}

		$validacion = self::validar_contenido( $archivo['tmp_name'], $formato );

		if ( is_wp_error( $validacion ) ) {
			return $validacion;
		}

		return array(
			'formato' => $formato,
			'mime'    => Catalogo_Formatos::get_mime( $formato ),
			'tamano'  => $tamano,
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	private static function validar_contenido( string $ruta, string $formato ) {
		switch ( $formato ) {
			case Catalogo_Formatos::PDF:
				return 'application/pdf' === self::mime_real( $ruta )
					? true
					: new \WP_Error( 'mdf_ca_herramienta_pdf_invalido', 'El archivo no es un PDF válido.' );

			case Catalogo_Formatos::JPG:
			case Catalogo_Formatos::PNG:
				$esperado = Catalogo_Formatos::get_mime( $formato );
				$imagen   = @getimagesize( $ruta ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- una imagen rota da false, sin aviso.

				return $esperado === self::mime_real( $ruta ) && is_array( $imagen ) && $esperado === ( $imagen['mime'] ?? '' )
					? true
					: new \WP_Error( 'mdf_ca_herramienta_imagen_invalida', 'El archivo no es una imagen ' . strtoupper( $formato ) . ' válida.' );

			case Catalogo_Formatos::XLSX:
				return Xlsx_Validator::validar( $ruta );

			default:
				return Ooxml_Validator::validar( $ruta, $formato );
		}
	}

	/** MIME real del contenido (fileinfo), o null si no se puede inspeccionar: entonces se rechaza. */
	private static function mime_real( string $ruta ): ?string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return null;
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = false === $finfo ? false : finfo_file( $finfo, $ruta );

		return is_string( $mime ) ? $mime : null;
	}
}
