<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convierte los datos de una o varias cartas del Modelo 347 en un PDF (#307),
 * con la plantilla de la sociedad emisora (includes/plantillas-347/{CIF}.php).
 * Una sociedad esta habilitada para el generador si y solo si existe su
 * plantilla: Formacion sera anadir B88471149.php.
 *
 * Seguridad de dompdf (el HTML lo construye esta clase, pero los textos
 * vienen de un Excel):
 * - isRemoteEnabled = false: ningun recurso remoto (ni imagenes, ni CSS, ni
 *   fuentes); isPhpEnabled = false; isJavascriptEnabled = false.
 * - chroot: solo la carpeta de fuentes vendorizada y la de imagenes de las
 *   plantillas. Cualquier otra ruta local se ignora.
 * - Todo valor que llega del Excel se escapa aqui con esc_html() antes de
 *   pasarlo a la plantilla, que lo imprime tal cual.
 * - Fuentes: Liberation, ya instaladas en lib/fonts (solo lectura); si
 *   faltara alguna cache de metricas no se renderiza, para que dompdf nunca
 *   escriba dentro del plugin. tempDir: la carpeta temporal privada
 *   (Modelo_347_Librerias::dir_temporal()), nunca get_temp_dir().
 * - Sin contrasena en el PDF: descartada (dompdf solo cifra con RC4 de 40
 *   bits; ver CLAUDE.md).
 *
 * Nunca escribe el PDF en disco: devuelve los bytes. Quien llama decide si
 * se guarda (Documento_Service) o se sirve al vuelo (vista previa, ZIP).
 */
class Modelo_347_Carta {

	private const DIR_PLANTILLAS = 'includes/plantillas-347';

	/** @var array<string, array{razon_social: string, logo: string, render: callable}> */
	private static array $plantillas = array();

	public static function emisor_habilitado( string $emisor_cif ): bool {
		return 1 === preg_match( '/^[A-Z0-9]{9}$/D', $emisor_cif )
			&& Factura_Emisores::es_emisor( $emisor_cif )
			&& is_file( self::ruta_plantilla( $emisor_cif ) );
	}

	/**
	 * Sociedades con plantilla, para el selector del generador.
	 *
	 * @return array<string, string> CIF => nombre corto
	 */
	public static function emisores_habilitados(): array {
		return array_filter(
			Factura_Emisores::get_opciones(),
			static fn( string $cif ): bool => self::emisor_habilitado( $cif ),
			ARRAY_FILTER_USE_KEY
		);
	}

	/** Razon social de la sociedad tal como sale en la carta ("MEDIFORMPLUS, S.L."). */
	public static function razon_social_emisor( string $emisor_cif ): string {
		return self::plantilla( $emisor_cif )['razon_social'];
	}

	/** "1.234,56" a partir de centimos, con signo si es negativo. */
	public static function formatear_importe( int $centimos ): string {
		$negativo = $centimos < 0;
		$abs      = abs( $centimos );

		return ( $negativo ? '-' : '' ) . number_format( intdiv( $abs, 100 ), 0, ',', '.' ) . ',' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
	}

	/**
	 * Domicilio en una linea, como la carta: "Direccion, CP Poblacion",
	 * omitiendo lo que venga vacio.
	 *
	 * @param array{direccion?: string, cp?: string, poblacion?: string} $fila
	 */
	public static function domicilio( array $fila ): string {
		$localidad = trim( ( $fila['cp'] ?? '' ) . ' ' . ( $fila['poblacion'] ?? '' ) );

		return implode( ', ', array_filter( array( trim( $fila['direccion'] ?? '' ), $localidad ), static fn( string $p ): bool => '' !== $p ) );
	}

	/**
	 * PDF con una o varias cartas (cada una en su pagina).
	 *
	 * @param array<int, array{nombre: string, nif: string, direccion: string, cp: string, poblacion: string, t1: int, t2: int, t3: int, t4: int, dev: int}> $filas
	 *        Filas ya validadas por la simulacion (importes en centimos, nif normalizado).
	 * @return string|\WP_Error Bytes del PDF.
	 */
	public static function pdf( string $emisor_cif, int $ejercicio, array $filas ) {
		if ( ! self::emisor_habilitado( $emisor_cif ) ) {
			return new \WP_Error( 'mdf_ca_347_emisor', 'Sociedad no habilitada para el generador.' );
		}

		$ajustes = Modelo_347_Ajustes::get( $emisor_cif );

		if ( '' === $ajustes['correo_admin'] ) {
			return new \WP_Error( 'mdf_ca_347_sin_correo_admin', 'Configura el correo de Administración (Modelo 347 › Ajustes) antes de generar cartas: aparece en el texto de la carta.' );
		}

		$plantilla = self::plantilla( $emisor_cif );
		$comun     = array(
			'emisor_razon_social' => esc_html( $plantilla['razon_social'] ),
			'emisor_cif'          => esc_html( $emisor_cif ),
			'correo_admin'        => esc_html( $ajustes['correo_admin'] ),
			'logo'                => esc_attr( $plantilla['logo'] ),
		);
		$cartas    = array();

		foreach ( $filas as $fila ) {
			$cartas[] = array(
				'razon_social' => esc_html( $fila['nombre'] ),
				'nif'          => esc_html( $fila['nif'] ),
				'domicilio'    => esc_html( self::domicilio( $fila ) ),
				'ejercicio'    => esc_html( (string) $ejercicio ),
				't1'           => esc_html( self::formatear_importe( (int) $fila['t1'] ) ),
				't2'           => esc_html( self::formatear_importe( (int) $fila['t2'] ) ),
				't3'           => esc_html( self::formatear_importe( (int) $fila['t3'] ) ),
				't4'           => esc_html( self::formatear_importe( (int) $fila['t4'] ) ),
				'total'        => esc_html( self::formatear_importe( (int) $fila['dev'] ) ),
			);
		}

		$html = ( $plantilla['render'] )( $cartas, $comun );

		if ( ! Modelo_347_Librerias::fuentes_completas() ) {
			return new \WP_Error( 'mdf_ca_347_fuentes', 'Faltan las fuentes o sus métricas en lib/fonts. Contacta con desarrollo (lib/LEEME.md).' );
		}

		$temporal = Modelo_347_Librerias::dir_temporal();

		if ( null === $temporal ) {
			return new \WP_Error( 'mdf_ca_347_temporal', 'La carpeta temporal privada del generador no está disponible o no está protegida. Contacta con desarrollo.' );
		}

		Modelo_347_Librerias::cargar();

		try {
			$opciones = new \Dompdf\Options(
				array(
					'isRemoteEnabled'      => false,
					'isPhpEnabled'         => false,
					'isJavascriptEnabled'  => false,
					'isHtml5ParserEnabled' => true,
					'fontDir'              => Modelo_347_Librerias::dir_fuentes(),
					'fontCache'            => Modelo_347_Librerias::dir_fuentes(),
					'tempDir'              => $temporal,
					'chroot'               => array( Modelo_347_Librerias::dir_fuentes(), dirname( $plantilla['logo'] ) ),
					'defaultFont'          => 'liberation serif',
					'defaultPaperSize'     => 'a4',
					'logOutputFile'        => '',
				)
			);
			$dompdf   = new \Dompdf\Dompdf( $opciones );
			$dompdf->loadHtml( $html, 'UTF-8' );
			$dompdf->setPaper( 'A4' );
			$dompdf->render();

			$dompdf->addInfo( 'Title', 'Modelo 347 ' . $ejercicio );
			$dompdf->addInfo( 'Creator', 'Area de Clientes Mediformplus' );

			$bytes = $dompdf->output();
		} catch ( \Throwable $e ) {
			// El mensaje de dompdf puede citar contenido de la carta: no se muestra.
			return new \WP_Error( 'mdf_ca_347_pdf', 'No se pudo generar el PDF de la carta.' );
		} finally {
			Modelo_347_Librerias::borrar_temporal( $temporal );
		}

		return is_string( $bytes ) && str_starts_with( $bytes, '%PDF-' ) ? $bytes : new \WP_Error( 'mdf_ca_347_pdf', 'No se pudo generar el PDF de la carta.' );
	}

	/**
	 * @return array{razon_social: string, logo: string, render: callable}
	 */
	private static function plantilla( string $emisor_cif ): array {
		if ( ! isset( self::$plantillas[ $emisor_cif ] ) ) {
			// emisor_habilitado() ya ha validado el formato del CIF: no hay recorrido de rutas.
			self::$plantillas[ $emisor_cif ] = require self::ruta_plantilla( $emisor_cif );
		}

		return self::$plantillas[ $emisor_cif ];
	}

	private static function ruta_plantilla( string $emisor_cif ): string {
		return MDF_CA_PLUGIN_DIR . self::DIR_PLANTILLAS . '/' . $emisor_cif . '.php';
	}
}
