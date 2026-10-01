<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Convierte un CSV subido en filas para Farmacia_Import_Service. Solo
 * valida el FICHERO (subida real, extension, tipo, tamano, codificacion,
 * cabeceras, numero de filas); las reglas de negocio de cada fila (CIF,
 * nombre, plan, duplicados) son del servicio, no de aqui.
 *
 * El fichero se lee directamente de la ruta temporal de la subida de PHP,
 * que PHP borra al terminar la peticion: no se copia a ninguna carpeta, ni
 * publica ni privada. Lo que se conserva entre simulacion y confirmacion
 * son las filas ya leidas, no el fichero (ver Admin_Importador_Farmacias).
 *
 * Pensado para lo que guarda Excel en espanol: separador ";" (tambien se
 * acepta ","), BOM UTF-8 opcional, y Windows-1252 si el contenido no es
 * UTF-8 valido (tildes y enes en "CSV (delimitado por comas)").
 */
class Farmacia_Csv_Parser {

	public const TAMANO_MAXIMO_BYTES = 1024 * 1024;
	public const MAX_FILAS           = 1000;

	/** Tipos que wp_check_filetype_and_ext() puede detectar en un CSV real. */
	private const MIMES_PERMITIDOS = array( 'csv' => 'text/csv' );

	/** Cabecera normalizada => campo. Columnas no listadas se ignoran. */
	private const CABECERAS = array(
		'nombre'  => 'nombre',
		'cif'     => 'cif',
		'nif'     => 'cif',
		'cif/nif' => 'cif',
		'nif/cif' => 'cif',
		'cif_nif' => 'cif',
		'plan'    => 'plan',
	);

	/**
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo Un elemento de $_FILES.
	 * @return array<int, array{linea: int, cif: string, nombre: string, plan: string}>|\WP_Error
	 */
	public function parsear( ?array $archivo ) {
		$validacion = $this->validar_archivo( $archivo );

		if ( is_wp_error( $validacion ) ) {
			return $validacion;
		}

		$contenido = file_get_contents( $archivo['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- fichero local de subida, ya validado.

		if ( false === $contenido ) {
			return new \WP_Error( 'mdf_ca_csv_ilegible', 'No se pudo leer el fichero subido.' );
		}

		if ( false !== strpos( $contenido, "\0" ) ) {
			return new \WP_Error( 'mdf_ca_csv_binario', 'El fichero no es un CSV de texto. Guardalo desde Excel como "CSV (delimitado por comas)".' );
		}

		return $this->parsear_contenido( $contenido );
	}

	/**
	 * Separado de parsear() para poder probarlo sin una subida HTTP real.
	 *
	 * @return array<int, array{linea: int, cif: string, nombre: string, plan: string}>|\WP_Error
	 */
	public function parsear_contenido( string $contenido ) {
		if ( 0 === strncmp( $contenido, "\xEF\xBB\xBF", 3 ) ) {
			$contenido = substr( $contenido, 3 );
		}

		if ( ! mb_check_encoding( $contenido, 'UTF-8' ) ) {
			$contenido = mb_convert_encoding( $contenido, 'UTF-8', 'Windows-1252' );
		}

		$contenido = str_replace( array( "\r\n", "\r" ), "\n", $contenido );

		$primera_linea = strtok( $contenido, "\n" );

		if ( false === $primera_linea || '' === trim( $primera_linea ) ) {
			return new \WP_Error( 'mdf_ca_csv_vacio', 'El fichero esta vacio.' );
		}

		$separador = substr_count( $primera_linea, ';' ) >= substr_count( $primera_linea, ',' ) ? ';' : ',';

		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- buffer en memoria, no toca disco publico.
		fwrite( $stream, $contenido ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $stream );

		$cabecera = fgetcsv( $stream, 0, $separador, '"', '' );
		$columnas = $this->mapear_cabecera( is_array( $cabecera ) ? $cabecera : array() );

		if ( is_wp_error( $columnas ) ) {
			fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $columnas;
		}

		$filas = array();
		$linea = 1;

		while ( false !== ( $celdas = fgetcsv( $stream, 0, $separador, '"', '' ) ) ) {
			++$linea;

			$valores = array_map( static fn( $celda ): string => trim( (string) $celda ), $celdas );

			// Excel deja filas de solo separadores al final (";;"): se ignoran.
			if ( '' === implode( '', $valores ) ) {
				continue;
			}

			if ( count( $filas ) >= self::MAX_FILAS ) {
				fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new \WP_Error(
					'mdf_ca_csv_demasiadas_filas',
					sprintf( 'El fichero tiene mas de %d filas de datos. Dividelo en varios ficheros.', self::MAX_FILAS )
				);
			}

			$filas[] = array(
				'linea'  => $linea,
				'cif'    => sanitize_text_field( $valores[ $columnas['cif'] ] ?? '' ),
				'nombre' => sanitize_text_field( $valores[ $columnas['nombre'] ] ?? '' ),
				'plan'   => sanitize_text_field( $valores[ $columnas['plan'] ] ?? '' ),
			);
		}

		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( ! $filas ) {
			return new \WP_Error( 'mdf_ca_csv_sin_filas', 'El fichero solo tiene cabecera, sin ninguna fila de datos.' );
		}

		return $filas;
	}

	/**
	 * @param array<int, string|null> $cabecera
	 * @return array{cif: int, nombre: int, plan: int}|\WP_Error Campo => indice de columna.
	 */
	private function mapear_cabecera( array $cabecera ) {
		$columnas = array();

		foreach ( $cabecera as $indice => $titulo ) {
			$clave = strtolower( str_replace( ' ', '', remove_accents( trim( (string) $titulo ) ) ) );

			if ( isset( self::CABECERAS[ $clave ] ) && ! isset( $columnas[ self::CABECERAS[ $clave ] ] ) ) {
				$columnas[ self::CABECERAS[ $clave ] ] = $indice;
			}
		}

		$faltan = array_diff( array( 'nombre', 'cif', 'plan' ), array_keys( $columnas ) );

		if ( $faltan ) {
			return new \WP_Error(
				'mdf_ca_csv_cabecera',
				sprintf( 'Faltan columnas obligatorias en la cabecera: %s. La primera fila debe ser: nombre;cif;plan', implode( ', ', $faltan ) )
			);
		}

		return $columnas;
	}

	/**
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo
	 * @return true|\WP_Error
	 */
	private function validar_archivo( ?array $archivo ) {
		if ( ! $archivo || ! isset( $archivo['error'], $archivo['tmp_name'], $archivo['name'], $archivo['size'] ) ) {
			return new \WP_Error( 'mdf_ca_csv_sin_fichero', 'No se ha recibido ningun fichero.' );
		}

		if ( UPLOAD_ERR_INI_SIZE === $archivo['error'] || UPLOAD_ERR_FORM_SIZE === $archivo['error'] ) {
			return new \WP_Error( 'mdf_ca_csv_tamano', 'El fichero supera el tamano maximo permitido (1 MB).' );
		}

		if ( UPLOAD_ERR_OK !== $archivo['error'] ) {
			return new \WP_Error( 'mdf_ca_csv_sin_fichero', 'No se ha recibido ningun fichero, o la subida ha fallado.' );
		}

		if ( ! is_uploaded_file( $archivo['tmp_name'] ) ) {
			return new \WP_Error( 'mdf_ca_csv_sin_fichero', 'El fichero recibido no es una subida valida.' );
		}

		$tamano = filesize( $archivo['tmp_name'] );

		if ( false === $tamano || $tamano > self::TAMANO_MAXIMO_BYTES || (int) $archivo['size'] > self::TAMANO_MAXIMO_BYTES ) {
			return new \WP_Error( 'mdf_ca_csv_tamano', 'El fichero supera el tamano maximo permitido (1 MB).' );
		}

		if ( 0 === $tamano ) {
			return new \WP_Error( 'mdf_ca_csv_vacio', 'El fichero esta vacio.' );
		}

		$filetype = wp_check_filetype_and_ext( $archivo['tmp_name'], $archivo['name'], self::MIMES_PERMITIDOS );

		if ( 'csv' !== $filetype['ext'] ) {
			// Tambien cae aqui un .csv con etiquetas HTML: finfo lo detecta como
			// text/html y WordPress no lo acepta como CSV. Se deja asi a proposito.
			return new \WP_Error( 'mdf_ca_csv_tipo', 'El fichero debe ser un .csv de texto (en Excel: Guardar como > CSV). Se rechazan tambien los ficheros con contenido HTML.' );
		}

		return true;
	}
}
