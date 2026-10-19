<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Carga perezosa de las librerias vendorizadas en lib/ (dompdf y OpenSpout,
 * #307; el autoload es el comun de Librerias) y rutas de disco que usa dompdf. Solo se cargan cuando el generador
 * del Modelo 347 las necesita, nunca en cada peticion. lib/.htaccess deniega
 * todo: ningun fichero de lib/ se sirve ni se ejecuta por HTTP. Como se
 * reconstruye lib/ esta en lib/LEEME.md.
 *
 * Donde escribe dompdf (revisado en su codigo, v3.1.6):
 * - fontDir / fontCache (lib/fonts, dentro del plugin): SOLO LECTURA en
 *   ejecucion. installed-fonts.json solo se escribe al registrar fuentes (al
 *   vendorizar) y Cpdf solo escribe una cache .ufm.json si falta. Las seis
 *   estan pregeneradas y fuentes_completas() impide renderizar si falta
 *   alguna, asi que dompdf nunca escribe dentro del plugin (y deploy.sh
 *   --delete, que sincroniza lib/fonts con el repo, no borra nada generado).
 * - tempDir: SI escribe en cada render (subconjuntos de fuente cpdf_subset_*
 *   y los PNG del canal alfa del logo cpdf_img_*; estos ultimos solo se
 *   borran en el destructor de Cpdf, al final de la peticion). Cada render
 *   usa su propia subcarpeta r-{aleatorio} (dir_temporal()), que
 *   Modelo_347_Carta borra entera al terminar, dentro de
 *   Clientes/private-docs/.tmp-generador-347, fuera del
 *   plugin (deploy.sh no la toca), fuera de wp-content y dentro de la carpeta
 *   privada con denegacion total; nunca get_temp_dir(), que puede caer en
 *   wp-content (publica) si el temporal del sistema no es escribible.
 */
class Modelo_347_Librerias {

	/** Relativa a ABSPATH, dentro de la carpeta privada de documentos. */
	public const TEMPORAL_RELATIVA = Documento_Endpoint::CARPETA_RELATIVA . '/.tmp-generador-347';

	/** Restos de un render interrumpido (PHP muerto a mitad) mas antiguos que esto se borran. */
	private const EDAD_RESTOS = HOUR_IN_SECONDS;

	/** Las seis variantes instaladas en lib/fonts y su cache de metricas. */
	private const FUENTES = array(
		'liberation_serif_normal_73707118f740100a36e3ca13278d1768',
		'liberation_serif_bold_7712d4e2fe7899998c2cbb36b46a5929',
		'liberation_serif_italic_7400beee07b717e46a10311488a2b843',
		'liberation_serif_bold_italic_50fa49e1df88b0c3b16bd23e29e31ffe',
		'liberation_sans_normal_4842fdd78d6b86f96b37ac6af71f1780',
		'liberation_sans_bold_12a2795b985ede3fffba399ee54a255c',
	);

	/** El autoload de lib/ es comun a todas las librerias (Librerias, #333). */
	public static function cargar(): void {
		Librerias::cargar();
	}

	/** Fuentes ya instaladas para dompdf (solo lectura en ejecucion). */
	public static function dir_fuentes(): string {
		return MDF_CA_PLUGIN_DIR . 'lib/fonts';
	}

	/**
	 * Si estan todas las fuentes con su cache de metricas. Si faltara alguna
	 * cache, Cpdf la escribiria en lib/fonts (dentro del plugin): en ese caso
	 * no se renderiza.
	 */
	public static function fuentes_completas(): bool {
		$dir = self::dir_fuentes();

		if ( ! is_file( $dir . '/installed-fonts.json' ) ) {
			return false;
		}

		foreach ( self::FUENTES as $fuente ) {
			if ( ! is_file( "$dir/$fuente.ttf" ) || ! is_file( "$dir/$fuente.ufm" ) || ! is_file( "$dir/$fuente.ufm.json" ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Carpeta temporal privada de dompdf PARA UN RENDER: una subcarpeta
	 * aleatoria dentro de TEMPORAL_RELATIVA (que se crea, si falta, con su
	 * .htaccess de denegacion e index.php, como la carpeta de documentos).
	 * Una por render porque dompdf solo borra sus PNG temporales en el
	 * destructor de Cpdf, que por sus referencias circulares no corre hasta
	 * el final de la peticion: con una subcarpeta propia, quien llama la borra
	 * entera al terminar (borrar_temporal()) sin tocar los ficheros de otro
	 * render simultaneo. Antes se borran las subcarpetas de mas de una hora
	 * (renders interrumpidos). null si la carpeta no existe, no es escribible
	 * o su .htaccess no deniega: entonces no se renderiza (nunca se cae a una
	 * carpeta publica).
	 */
	public static function dir_temporal(): ?string {
		$estado = Carpeta_Privada_Proteccion::asegurar( self::TEMPORAL_RELATIVA );

		if ( 'existe' !== $estado['carpeta'] || ! $estado['escribible'] || 'deniega' !== $estado['htaccess'] ) {
			return null;
		}

		$base   = ABSPATH . self::TEMPORAL_RELATIVA;
		$limite = time() - self::EDAD_RESTOS;

		foreach ( (array) glob( $base . '/r-*', GLOB_ONLYDIR | GLOB_NOSORT ) as $resto ) {
			if ( is_string( $resto ) && (int) filemtime( $resto ) < $limite ) {
				self::borrar_temporal( $resto );
			}
		}

		$dir = $base . '/r-' . bin2hex( random_bytes( 8 ) );

		return mkdir( $dir, 0700 ) ? $dir : null;
	}

	/**
	 * Borra una subcarpeta de render y lo que contenga (sin recursion: dompdf
	 * solo crea ficheros sueltos). Solo acepta rutas r-* dentro de
	 * TEMPORAL_RELATIVA.
	 */
	public static function borrar_temporal( string $dir ): void {
		$base = realpath( ABSPATH . self::TEMPORAL_RELATIVA );
		$real = realpath( $dir );

		if ( ! $base || ! $real || dirname( $real ) !== $base || ! str_starts_with( basename( $real ), 'r-' ) ) {
			return;
		}

		foreach ( (array) scandir( $real ) as $nombre ) {
			$fichero = $real . DIRECTORY_SEPARATOR . $nombre;

			if ( is_string( $nombre ) && '.' !== $nombre && '..' !== $nombre && is_file( $fichero ) ) {
				wp_delete_file( $fichero );
			}
		}

		@rmdir( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- si no esta vacia, la borrara la limpieza de restos.
	}
}
