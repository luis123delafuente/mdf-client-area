<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Envio de un fichero privado al navegador, comun a los documentos
 * (Documento_Endpoint) y a las herramientas del catalogo
 * (Herramienta_Endpoint). No decide permisos: quien llama ya ha preguntado a
 * Permissions.
 *
 * - Solo lee dentro de la carpeta que se le indica (realpath + prefijo), por
 *   defensa en profundidad aunque la ruta venga de nuestra propia BD.
 * - Como adjunto, el nombre del fichero es el visible del documento o de la
 *   herramienta, con la extension del formato: filename* en UTF-8 (las
 *   tildes llegan bien) y un filename ASCII de respaldo.
 * - Siempre nocache y nosniff.
 */
class Descarga_Fichero {

	/** Extension del fichero descargado segun el MIME guardado. */
	private const EXTENSIONES = array(
		'application/pdf'                                                         => 'pdf',
		'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
		'application/vnd.ms-excel.sheet.macroenabled.12'                          => 'xlsm',
		'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
		'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
		'image/jpeg'                                                              => 'jpg',
		'image/png'                                                               => 'png',
		'image/webp'                                                              => 'webp',
	);

	public static function extension_de_mime( ?string $mime ): string {
		return self::EXTENSIONES[ strtolower( (string) $mime ) ] ?? 'bin';
	}

	/**
	 * Sirve $ruta y termina la peticion. Si $ruta no esta dentro de $carpeta
	 * o no existe, el 404 uniforme de Documento_Endpoint.
	 *
	 * @param string|null $nombre_visible Con nombre, como adjunto; null, inline (fetch del visor).
	 */
	public static function servir( string $carpeta, string $ruta, string $mime, ?string $nombre_visible, string $extension = '' ): void {
		$carpeta_real = realpath( $carpeta );
		$ruta_real    = realpath( $ruta );

		if ( ! $carpeta_real || ! $ruta_real || 0 !== strpos( $ruta_real, $carpeta_real . DIRECTORY_SEPARATOR ) || ! is_file( $ruta_real ) ) {
			Documento_Endpoint::responder_no_encontrado();
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );

		if ( null !== $nombre_visible ) {
			$nombre = self::nombre_descarga( $nombre_visible, '' !== $extension ? $extension : self::extension_de_mime( $mime ) );
			header( 'Content-Disposition: attachment; filename="' . $nombre['ascii'] . '"; filename*=UTF-8\'\'' . rawurlencode( $nombre['utf8'] ) );
		} else {
			header( 'Content-Disposition: inline' );
		}

		header( 'Content-Length: ' . filesize( $ruta_real ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $ruta_real );
		exit;
	}

	/**
	 * Nombre del fichero descargado: el visible y la extension. Sin
	 * caracteres que los sistemas de ficheros no admiten, sin una extension
	 * repetida al final ("Libro.xlsx" no da "Libro.xlsx.xlsx") y una version
	 * ASCII para el filename= clasico.
	 *
	 * @return array{utf8: string, ascii: string}
	 */
	public static function nombre_descarga( string $nombre, string $extension ): array {
		$base = trim( (string) preg_replace( '/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', $nombre ) );
		$base = trim( (string) preg_replace( '/\s+/u', ' ', $base ), ' .' );
		$base = (string) preg_replace( '/\.' . preg_quote( $extension, '/' ) . '$/iu', '', $base );
		$base = '' !== $base ? mb_substr( $base, 0, 150 ) : 'documento';

		$ascii = trim( (string) preg_replace( '/ {2,}/', ' ', (string) preg_replace( '/[^A-Za-z0-9 ._()-]+/', '', remove_accents( $base ) ) ) );
		$ascii = '' !== $ascii ? $ascii : 'documento';

		return array(
			'utf8'  => $base . '.' . $extension,
			'ascii' => $ascii . '.' . $extension,
		);
	}
}
