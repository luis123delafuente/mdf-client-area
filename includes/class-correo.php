<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Envio de un correo con wp_mail() que, si falla, dice por que: una
 * categoria en lenguaje claro (para cualquiera que gestione el envio) y el
 * detalle tecnico de PHPMailer (solo para el administrador). Lo usan el
 * aviso generico de documentos y el envio del Modelo 347.
 *
 * - El destinatario se comprueba con is_email() antes de llamar a
 *   wp_mail(): si no vale, no se intenta enviar.
 * - El motivo sale de wp_mail_failed (el mensaje de la excepcion de
 *   PHPMailer). La categoria se deduce de sus textos, que WordPress
 *   traduce al idioma del sitio (WP_PHPMailer::setLanguage()): se compara
 *   con el original en ingles y con la traduccion. Lo que no encaja es
 *   "Error desconocido".
 * - El detalle nunca lleva el usuario ni la contrasena del SMTP: PHPMailer
 *   no los pone en sus mensajes, y ademas se tachan si aparecieran. Va
 *   saneado y cortado a 255 caracteres (la columna error de los envios del
 *   347). Puede llevar el email del propio destinatario y un trozo de la
 *   respuesta del servidor.
 * - Guardado como texto ("clave|detalle", ver serializar()), para la
 *   columna error del 347 sin cambio de esquema.
 */
class Correo {

	public const EMAIL_INVALIDO = 'email_invalido';
	public const RECHAZADO      = 'rechazado';
	public const SIN_CONEXION   = 'sin_conexion';
	public const ADJUNTO        = 'adjunto';
	public const DESCONOCIDO    = 'desconocido';

	private const CATEGORIAS = array(
		self::EMAIL_INVALIDO => 'Email no válido',
		self::RECHAZADO      => 'El servidor de correo rechazó el envío',
		self::SIN_CONEXION   => 'No se pudo conectar con el servidor de correo',
		self::ADJUNTO        => 'No se pudo generar el adjunto',
		self::DESCONOCIDO    => 'Error desconocido',
	);

	private const MAX_DETALLE = 255;

	/**
	 * Mensajes de PHPMailer por categoria, en el orden en que se comprueban:
	 * los msgid de WP_PHPMailer::setLanguage() (se buscan en ingles y
	 * traducidos) y, en la conexion, fragmentos que PHP o el servidor anaden
	 * sin traducir.
	 */
	private const MENSAJES = array(
		self::EMAIL_INVALIDO => array( 'Invalid address: ', 'You must provide at least one recipient email address.' ),
		self::ADJUNTO        => array( 'Could not access file: ', 'File Error: Could not open file: ', 'Unknown encoding: ' ),
		self::SIN_CONEXION   => array( 'SMTP Error: Could not connect to SMTP host.', 'SMTP connect() failed.', 'Could not instantiate mail function.', 'Could not execute: ', 'Invalid host: ', 'Invalid host entry: ' ),
		self::RECHAZADO      => array( 'SMTP Error: Could not authenticate.', 'SMTP Error: Data not accepted.', 'The following From address failed: ', 'SMTP Error: The following recipients failed: ', 'SMTP server error: ' ),
	);

	private const FRAGMENTOS_CONEXION = array( 'failed to connect', 'connection refused', 'timed out', 'timeout' );

	/**
	 * @param array<int, string>    $cabeceras Cabeceras de wp_mail().
	 * @param array<string, string> $adjuntos  PDF adjuntados desde memoria: nombre => bytes.
	 * @return array{categoria: string, detalle: string}|null null si ha salido.
	 */
	public static function enviar( string $para, string $asunto, string $cuerpo, array $cabeceras = array(), array $adjuntos = array() ): ?array {
		if ( ! is_email( $para ) ) {
			return self::fallo( self::EMAIL_INVALIDO, 'La dirección del destinatario no es un email válido.' );
		}

		$error    = null;
		$secretos = array();

		$preparar = static function ( $phpmailer ) use ( $adjuntos, &$secretos ): void {
			$secretos = array( (string) $phpmailer->Username, (string) $phpmailer->Password );

			foreach ( $adjuntos as $nombre => $bytes ) {
				$phpmailer->addStringAttachment( $bytes, $nombre, 'base64', 'application/pdf' );
			}
		};
		$capturar = static function ( \WP_Error $fallo ) use ( &$error, &$secretos ): void {
			$error = $fallo->get_error_message();

			// Otro plugin (WP Mail SMTP) puede poner las credenciales despues
			// de phpmailer_init: se leen tambien en el momento del fallo.
			global $phpmailer;

			if ( is_object( $phpmailer ) && isset( $phpmailer->Username, $phpmailer->Password ) ) {
				$secretos[] = (string) $phpmailer->Username;
				$secretos[] = (string) $phpmailer->Password;
			}
		};

		add_action( 'phpmailer_init', $preparar );
		add_action( 'wp_mail_failed', $capturar );

		try {
			$ok = wp_mail( $para, $asunto, $cuerpo, $cabeceras );
		} catch ( \Throwable $e ) {
			// addStringAttachment() y otros pasos de phpmailer_init lanzan
			// fuera del try de wp_mail().
			$ok    = false;
			$error = $e->getMessage();
		} finally {
			remove_action( 'phpmailer_init', $preparar );
			remove_action( 'wp_mail_failed', $capturar );
		}

		if ( $ok ) {
			return null;
		}

		if ( ! is_string( $error ) || '' === $error ) {
			return self::fallo( self::DESCONOCIDO, 'wp_mail() devolvió false sin indicar el motivo.' );
		}

		$detalle = self::tachar( $error, $secretos );

		return self::fallo( self::categoria_de( $detalle ), $detalle );
	}

	/** @return array{categoria: string, detalle: string} */
	public static function fallo( string $categoria, string $detalle ): array {
		return array(
			'categoria' => isset( self::CATEGORIAS[ $categoria ] ) ? $categoria : self::DESCONOCIDO,
			'detalle'   => mb_substr( sanitize_text_field( $detalle ), 0, self::MAX_DETALLE ),
		);
	}

	public static function texto_categoria( string $categoria ): string {
		return self::CATEGORIAS[ $categoria ] ?? self::CATEGORIAS[ self::DESCONOCIDO ];
	}

	/**
	 * "clave|detalle", cortado a 255 caracteres en total (columna error de
	 * los envios del 347).
	 *
	 * @param array{categoria: string, detalle: string} $fallo
	 */
	public static function serializar( array $fallo ): string {
		return mb_substr( $fallo['categoria'] . '|' . $fallo['detalle'], 0, self::MAX_DETALLE );
	}

	/**
	 * Inversa de serializar(), ya con la categoria en texto. Un texto que no
	 * empieza por una clave conocida (motivos de cancelacion, que son textos
	 * fijos del plugin, o errores anteriores a la 0.38.0) se devuelve entero
	 * como texto, sin detalle.
	 *
	 * @return array{texto: string, detalle: string}
	 */
	public static function leer( string $guardado ): array {
		$partes = explode( '|', $guardado, 2 );

		if ( 2 === count( $partes ) && isset( self::CATEGORIAS[ $partes[0] ] ) ) {
			return array(
				'texto'   => self::CATEGORIAS[ $partes[0] ],
				'detalle' => $partes[1],
			);
		}

		return array(
			'texto'   => $guardado,
			'detalle' => '',
		);
	}

	private static function categoria_de( string $mensaje ): string {
		$minusculas = mb_strtolower( $mensaje );

		foreach ( self::MENSAJES as $categoria => $msgids ) {
			$fragmentos = array();

			foreach ( $msgids as $msgid ) {
				$fragmentos[] = $msgid;
				// Mismo msgid y dominio (el del nucleo) que WP_PHPMailer.
				$fragmentos[] = __( $msgid ); // phpcs:ignore WordPress.WP.I18n -- se busca la traduccion del nucleo, no se traduce un texto propio.
			}

			if ( self::SIN_CONEXION === $categoria ) {
				$fragmentos = array_merge( $fragmentos, self::FRAGMENTOS_CONEXION );
			}

			foreach ( $fragmentos as $fragmento ) {
				$fragmento = mb_strtolower( trim( $fragmento, ' :.' ) );

				if ( '' !== $fragmento && str_contains( $minusculas, $fragmento ) ) {
					return $categoria;
				}
			}
		}

		return self::DESCONOCIDO;
	}

	/** @param array<int, string> $secretos */
	private static function tachar( string $mensaje, array $secretos ): string {
		foreach ( $secretos as $secreto ) {
			if ( strlen( $secreto ) >= 3 ) {
				$mensaje = str_replace( $secreto, '[oculto]', $mensaje );
			}
		}

		return $mensaje;
	}
}
