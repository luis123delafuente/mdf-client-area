<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Proteccion de la carpeta privada de documentos (#327): la crea cuando
 * falta y comprueba que de verdad no se sirve por HTTP.
 *
 * Dos comprobaciones complementarias, porque ninguna basta sola:
 * - asegurar(): crea solo lo que falta (carpeta, .htaccess de denegacion
 *   total, index.php) y mira si el .htaccess existente deniega. Nunca
 *   sobrescribe un fichero existente.
 * - sondear(): deja un fichero de prueba con nombre aleatorio, lo pide por
 *   HTTP a la URL publica de la carpeta y lo borra siempre. Detecta lo que
 *   el texto del .htaccess no dice: un hosting con AllowOverride None, o una
 *   regla de denegacion metida dentro de un <Files>.
 *
 * Sin hooks ni salida: el aviso lo pinta Admin_Proteccion_Carpeta. Nada de
 * lo que devuelve lleva rutas absolutas ni datos de farmacias.
 */
class Carpeta_Privada_Proteccion {

	/**
	 * Contenido exacto del .htaccess creado a mano en produccion (121 bytes):
	 * deniega todo en Apache 2.4 y 2.2.
	 */
	public const HTACCESS = "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>";

	public const INDEX_PHP = "<?php // Silence is golden.\n";

	public const ESTADO_PROTEGIDA      = 'protegida';
	public const ESTADO_NO_PROTEGIDA   = 'no_protegida';
	public const ESTADO_NO_COMPROBABLE = 'no_comprobable';

	private const TRANSIENT       = 'mdf_ca_proteccion_carpeta';
	private const TTL_OK          = 12 * HOUR_IN_SECONDS;
	private const TTL_OTRO        = HOUR_IN_SECONDS;
	private const PREFIJO_SONDA   = 'mdf-ca-sonda-';
	private const CONTENIDO_SONDA = 'mdf-ca-sonda-proteccion-carpeta-privada';
	private const PERMISOS        = 0644;

	/**
	 * Una sonda mas antigua que esto es huerfana (PHP murio antes del
	 * finally: error fatal o max_execution_time) y se borra.
	 */
	private const EDAD_SONDA_HUERFANA = 10 * MINUTE_IN_SECONDS;

	/**
	 * Resultado completo de la carpeta real, cacheado en un transient global
	 * (es estado del servidor, no del usuario): 12 h si todo esta bien, 1 h
	 * en cualquier otro caso, para que una alarma o un "no se pudo comprobar"
	 * se repita pronto sin que nadie lo pida.
	 *
	 * @return array{carpeta: string, escribible: bool, htaccess: string, index: string, http: string, http_codigo: ?int, http_motivo: ?string, comprobado: int}
	 */
	public static function comprobar( bool $forzar = false ): array {
		if ( ! $forzar ) {
			$cacheado = get_transient( self::TRANSIENT );

			if ( is_array( $cacheado ) && isset( $cacheado['http'] ) ) {
				return $cacheado;
			}
		}

		$resultado = array_merge(
			self::asegurar(),
			self::sondear(),
			array( 'comprobado' => time() )
		);

		set_transient( self::TRANSIENT, $resultado, self::todo_bien( $resultado ) ? self::TTL_OK : self::TTL_OTRO );

		return $resultado;
	}

	public static function invalidar_cache(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * @param array{carpeta: string, escribible: bool, htaccess: string, index: string, http: string} $resultado
	 */
	public static function todo_bien( array $resultado ): bool {
		return 'existe' === $resultado['carpeta']
			&& $resultado['escribible']
			&& 'deniega' === $resultado['htaccess']
			&& 'existe' === $resultado['index']
			&& self::ESTADO_PROTEGIDA === $resultado['http'];
	}

	/**
	 * Crea solo lo que falta. Idempotente: si todo existe, solo lee (no
	 * escribe ficheros ni deja nada en el log).
	 *
	 * El parametro existe solo para las pruebas con una carpeta distinta de
	 * la real; nunca llega de una entrada del usuario.
	 *
	 * @return array{carpeta: string, escribible: bool, htaccess: string, index: string}
	 *         carpeta: existe | no_existe. htaccess: deniega | no_deniega |
	 *         ilegible | falta. index: existe | falta.
	 */
	public static function asegurar( string $relativa = Documento_Endpoint::CARPETA_RELATIVA ): array {
		$carpeta = ABSPATH . $relativa;

		if ( ! is_dir( $carpeta ) && ! wp_mkdir_p( $carpeta ) ) {
			return array(
				'carpeta'    => 'no_existe',
				'escribible' => false,
				'htaccess'   => 'falta',
				'index'      => 'falta',
			);
		}

		$escribible = is_writable( $carpeta );
		$htaccess   = $carpeta . DIRECTORY_SEPARATOR . '.htaccess';
		$index      = $carpeta . DIRECTORY_SEPARATOR . 'index.php';

		if ( ! file_exists( $htaccess ) && $escribible ) {
			self::crear_si_no_existe( $htaccess, self::HTACCESS );
		}

		if ( ! file_exists( $index ) && $escribible ) {
			self::crear_si_no_existe( $index, self::INDEX_PHP );
		}

		return array(
			'carpeta'    => 'existe',
			'escribible' => $escribible,
			'htaccess'   => self::estado_htaccess( $htaccess ),
			'index'      => is_file( $index ) ? 'existe' : 'falta',
		);
	}

	/**
	 * Pide por HTTP un fichero de prueba de la carpeta y lo borra siempre.
	 * 403 o 404 = protegida; 200 con el contenido exacto de la sonda = NO
	 * protegida; cualquier otra cosa (red, timeout, 3xx, 5xx, 200 con otro
	 * cuerpo, no se pudo crear la sonda) = no se pudo comprobar, nunca alarma.
	 *
	 * La URL sale de site_url() (que corresponde a ABSPATH, no home_url()),
	 * la ruta relativa fija y un nombre aleatorio: nada del usuario. Extension
	 * .txt y nunca .php: en una carpeta abierta, un .php se ejecutaria.
	 *
	 * @return array{http: string, http_codigo: ?int, http_motivo: ?string}
	 *         http_motivo (solo si no_comprobable): sin_sonda | red | codigo | contenido.
	 */
	public static function sondear( string $relativa = Documento_Endpoint::CARPETA_RELATIVA ): array {
		$carpeta = ABSPATH . $relativa;

		if ( ! is_dir( $carpeta ) || ! is_writable( $carpeta ) ) {
			return self::resultado_http( self::ESTADO_NO_COMPROBABLE, null, 'sin_sonda' );
		}

		self::borrar_sondas_huerfanas( $carpeta );

		$nombre = self::PREFIJO_SONDA . bin2hex( random_bytes( 16 ) ) . '.txt';
		$ruta   = $carpeta . DIRECTORY_SEPARATOR . $nombre;

		if ( ! self::crear_si_no_existe( $ruta, self::CONTENIDO_SONDA ) ) {
			return self::resultado_http( self::ESTADO_NO_COMPROBABLE, null, 'sin_sonda' );
		}

		try {
			$respuesta = wp_remote_get(
				site_url( '/' . $relativa . '/' . $nombre ),
				array(
					'timeout'             => 5,
					'redirection'         => 0,
					'sslverify'           => true,
					'limit_response_size' => 1024,
				)
			);
		} finally {
			if ( file_exists( $ruta ) ) {
				unlink( $ruta );
			}
		}

		if ( is_wp_error( $respuesta ) ) {
			return self::resultado_http( self::ESTADO_NO_COMPROBABLE, null, 'red' );
		}

		$codigo = (int) wp_remote_retrieve_response_code( $respuesta );

		if ( 403 === $codigo || 404 === $codigo ) {
			return self::resultado_http( self::ESTADO_PROTEGIDA, $codigo, null );
		}

		if ( 200 === $codigo ) {
			return self::CONTENIDO_SONDA === wp_remote_retrieve_body( $respuesta )
				? self::resultado_http( self::ESTADO_NO_PROTEGIDA, $codigo, null )
				: self::resultado_http( self::ESTADO_NO_COMPROBABLE, $codigo, 'contenido' );
		}

		return self::resultado_http( self::ESTADO_NO_COMPROBABLE, $codigo ?: null, 'codigo' );
	}

	/**
	 * Reconoce una denegacion total por cualquiera de sus dos formas, en una
	 * linea que no sea comentario. No interpreta bloques (<Files>, <If>...):
	 * eso lo cubre la sonda HTTP.
	 */
	public static function htaccess_deniega( string $contenido ): bool {
		return 1 === preg_match( '/^\s*(Require\s+all\s+denied|Deny\s+from\s+all)\b/im', $contenido );
	}

	private static function estado_htaccess( string $ruta ): string {
		if ( ! file_exists( $ruta ) ) {
			return 'falta';
		}

		$contenido = is_readable( $ruta ) ? file_get_contents( $ruta ) : false;

		if ( false === $contenido ) {
			return 'ilegible';
		}

		return self::htaccess_deniega( $contenido ) ? 'deniega' : 'no_deniega';
	}

	/**
	 * Escribe un fichero nuevo con permisos 0644 sin pisar nunca uno
	 * existente: el modo 'x' falla si ya existe, tambien si otra peticion lo
	 * ha creado entre el file_exists() de quien llama y este fopen(). En ese
	 * caso (o sin permisos) PHP emite un warning que se silencia, para no
	 * dejar ruido en el log: el resultado se lee de nuevo despues.
	 */
	private static function crear_si_no_existe( string $ruta, string $contenido ): bool {
		$fichero = @fopen( $ruta, 'x' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( false === $fichero ) {
			return false;
		}

		$escrito = fwrite( $fichero, $contenido );
		fclose( $fichero );

		if ( strlen( $contenido ) !== $escrito ) {
			unlink( $ruta );
			return false;
		}

		chmod( $ruta, self::PERMISOS );

		return true;
	}

	/**
	 * Solo borra ficheros con el patron exacto de la sonda; nunca toca un
	 * documento.
	 */
	private static function borrar_sondas_huerfanas( string $carpeta ): void {
		$sondas = glob( $carpeta . DIRECTORY_SEPARATOR . self::PREFIJO_SONDA . '*.txt' );

		foreach ( $sondas ?: array() as $sonda ) {
			if ( 1 === preg_match( '/^' . preg_quote( self::PREFIJO_SONDA, '/' ) . '[0-9a-f]{32}\.txt$/D', basename( $sonda ) )
				&& is_file( $sonda )
				&& filemtime( $sonda ) < time() - self::EDAD_SONDA_HUERFANA ) {
				unlink( $sonda );
			}
		}
	}

	/**
	 * @return array{http: string, http_codigo: ?int, http_motivo: ?string}
	 */
	private static function resultado_http( string $estado, ?int $codigo, ?string $motivo ): array {
		return array(
			'http'        => $estado,
			'http_codigo' => $codigo,
			'http_motivo' => $motivo,
		);
	}
}
