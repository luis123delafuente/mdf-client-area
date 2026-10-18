<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Escritor ZIP minimo que escribe directamente en la salida (php://output),
 * sin ningun fichero temporal: ni el ZIP ni su contenido tocan el disco,
 * tampoco si PHP muere a mitad (#307, cartas del Modelo 347 que no se pueden
 * enviar). ZipArchive no sirve aqui porque solo escribe en un fichero.
 *
 * Metodo STORE (sin compresion): los PDF ya van comprimidos por dentro.
 * Nombres en UTF-8 (bit 11). Sin ZIP64: cada entrada y el total por debajo de
 * 4 GB (aqui son unos pocos MB). Formato: PKWARE APPNOTE, cabecera local +
 * datos por entrada, directorio central y registro de fin.
 */
class Zip_Stream {

	/** @var array<int, array{nombre: string, crc: int, tamano: int, offset: int}> */
	private array $entradas = array();
	private int $offset     = 0;

	public function anadir( string $nombre, string $datos ): void {
		$nombre = ltrim( str_replace( array( '\\', '..' ), array( '/', '' ), $nombre ), '/' );
		$crc    = (int) hexdec( hash( 'crc32b', $datos ) );
		$tamano = strlen( $datos );
		list( $hora, $fecha ) = self::fecha_dos();

		$cabecera = pack( 'VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $hora, $fecha, $crc, $tamano, $tamano, strlen( $nombre ), 0 ) . $nombre;

		echo $cabecera; // phpcs:ignore WordPress.Security.EscapeOutput -- binario del ZIP.
		echo $datos; // phpcs:ignore WordPress.Security.EscapeOutput -- binario del ZIP.

		$this->entradas[] = array(
			'nombre' => $nombre,
			'crc'    => $crc,
			'tamano' => $tamano,
			'offset' => $this->offset,
		);
		$this->offset    += strlen( $cabecera ) + $tamano;
	}

	public function cerrar(): void {
		list( $hora, $fecha ) = self::fecha_dos();
		$directorio           = '';

		foreach ( $this->entradas as $e ) {
			$directorio .= pack( 'VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $hora, $fecha, $e['crc'], $e['tamano'], $e['tamano'], strlen( $e['nombre'] ), 0, 0, 0, 0, 0, $e['offset'] ) . $e['nombre'];
		}

		echo $directorio; // phpcs:ignore WordPress.Security.EscapeOutput -- binario del ZIP.
		echo pack( 'VvvvvVVv', 0x06054b50, 0, 0, count( $this->entradas ), count( $this->entradas ), strlen( $directorio ), $this->offset, 0 ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/** @return array{0: int, 1: int} Hora y fecha en formato MS-DOS (hora local de WordPress). */
	private static function fecha_dos(): array {
		$d = new \DateTimeImmutable( 'now', wp_timezone() );

		return array(
			( (int) $d->format( 'G' ) << 11 ) | ( (int) $d->format( 'i' ) << 5 ) | intdiv( (int) $d->format( 's' ), 2 ),
			( ( (int) $d->format( 'Y' ) - 1980 ) << 9 ) | ( (int) $d->format( 'n' ) << 5 ) | (int) $d->format( 'j' ),
		);
	}
}
