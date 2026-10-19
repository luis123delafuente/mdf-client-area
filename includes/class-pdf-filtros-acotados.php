<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filtros de descompresion de smalot/pdfparser con tope (#333). Solo cambia
 * FlateDecode, el que usan los PDF de facturas:
 *
 * - smalot hace gzuncompress() del flujo entero de una vez y, si falla, lo
 *   reintenta escribiendolo en un tmpfile() del sistema. Aqui se descomprime
 *   por trozos de 4 KB de entrada con inflate_add(), mirando el tope del
 *   flujo, el reloj y la memoria entre trozo y trozo: una bomba de
 *   descompresion se corta en cuanto pasa del tope, sin haber ocupado mas de
 *   unos pocos MB de mas, y nada del PDF se escribe en disco.
 * - Formato zlib (el normal) y, si no se puede, deflate sin cabecera (el caso
 *   que smalot recupera con su tmpfile). Un flujo corrupto o cortado devuelve
 *   lo que se haya podido descomprimir hasta el error, como MuPDF.
 *
 * LZWDecode y RunLengthDecode ya respetan el tope de smalot
 * (decodeMemoryLimit, que Pdf_Datos_Acotados fija antes de cada flujo).
 * ASCIIHexDecode y ASCII85Decode solo pueden encoger.
 */
class Pdf_Filtros_Acotados extends \Smalot\PdfParser\RawData\FilterHelper {

	private const TROZO = 4096;

	private Pdf_Limites $limites;

	public function __construct( Pdf_Limites $limites ) {
		$this->limites = $limites;
	}

	protected function decodeFilterFlateDecode( string $data, int $decodeMemoryLimit ): ?string {
		foreach ( array( ZLIB_ENCODING_DEFLATE, ZLIB_ENCODING_RAW ) as $codificacion ) {
			$salida = $this->inflar( $data, $codificacion, $decodeMemoryLimit );

			if ( '' !== $salida ) {
				return $salida;
			}
		}

		throw new \Exception( 'decodeFilterFlateDecode: invalid data' );
	}

	private function inflar( string $data, int $codificacion, int $tope ): string {
		$contexto = inflate_init( $codificacion );

		if ( false === $contexto ) {
			return '';
		}

		$salida = '';

		for ( $i = 0, $n = strlen( $data ); $i < $n; $i += self::TROZO ) {
			$trozo = @inflate_add( $contexto, substr( $data, $i, self::TROZO ), ZLIB_SYNC_FLUSH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- datos corruptos: se queda lo leido.

			if ( false === $trozo ) {
				break;
			}

			$salida .= $trozo;

			if ( $tope > 0 && strlen( $salida ) > $tope ) {
				$this->limites->exceder( 'un flujo del PDF descomprimido supera el tamaño máximo' );
			}

			if ( ZLIB_STREAM_END === inflate_get_status( $contexto ) ) {
				break;
			}

			$this->limites->comprobar();
		}

		return $salida;
	}
}
