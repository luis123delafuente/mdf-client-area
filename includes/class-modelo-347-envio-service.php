<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Envio por correo de las cartas del Modelo 347 (#307), con el PDF adjunto,
 * por tandas que lanza la pantalla (AJAX) al ritmo de los ajustes.
 *
 * - Cada carta se RECLAMA antes de enviar (Modelo_347_Envio_Repository::reclamar):
 *   dos peticiones a la vez nunca mandan la misma.
 * - Justo antes de enviar se comprueba que sigue publicada y vigente y que la
 *   farmacia tiene cuenta activa con email; si no, el envio se cancela. El
 *   destinatario es el email ACTUAL de la cuenta del portal.
 * - El PDF se lee de la carpeta privada (realpath + prefijo, como el
 *   endpoint) y se adjunta desde memoria con phpmailer_init: no se copia a
 *   ningun sitio.
 * - Envio con Correo::enviar(). Bien: enviado y notificado_en marcado (el
 *   aviso generico ya excluye el 347). Mal: fallido, con "categoria|detalle"
 *   (Correo::serializar()) en la columna error; la pantalla muestra la
 *   categoria a todos y el detalle solo al administrador. Se reintenta solo
 *   con "Reintentar fallidos" o "Reenviar".
 * - From: el del SMTP actual (no se toca). Reply-To: el del ajuste.
 * - Ritmo: ademas de la espera de la pantalla, el servidor rechaza una tanda
 *   que llegue antes de segundos_tanda desde la anterior (de cualquier usuario).
 */
class Modelo_347_Envio_Service {

	private const TRANSIENT_ULTIMA_TANDA = 'mdf_ca_347_ultima_tanda';

	private Modelo_347_Envio_Repository $envios;
	private Documento_Repository $documentos;
	private Farmacia_Repository $farmacias;

	public function __construct( ?Modelo_347_Envio_Repository $envios = null, ?Documento_Repository $documentos = null, ?Farmacia_Repository $farmacias = null ) {
		$this->envios     = $envios ?? new Modelo_347_Envio_Repository();
		$this->documentos = $documentos ?? new Documento_Repository();
		$this->farmacias  = $farmacias ?? new Farmacia_Repository();
	}

	public static function asunto( int $ejercicio ): string {
		return sprintf( 'Comunicación de importes y solicitud de conformidad – Modelo 347, ejercicio %d', $ejercicio );
	}

	public static function cuerpo( string $emisor_cif, int $ejercicio ): string {
		return strtr(
			Modelo_347_Ajustes::get( $emisor_cif )['cuerpo'],
			array(
				'{SOCIEDAD}' => Modelo_347_Carta::razon_social_emisor( $emisor_cif ),
				'{AÑO}'      => (string) $ejercicio,
				'{ANO}'      => (string) $ejercicio,
			)
		);
	}

	/**
	 * Una tanda: como mucho por_tanda envios pendientes de esta sociedad y
	 * ejercicio.
	 *
	 * @return array{enviados: int, fallidos: int, cancelados: int, quedan: int, espera: int}|\WP_Error
	 *         espera > 0: aun no toca (segundos que faltan); no se ha enviado nada.
	 */
	public function enviar_tanda( string $emisor_cif, int $ejercicio ) {
		$ajustes = Modelo_347_Ajustes::get( $emisor_cif );

		if ( '' === $ajustes['reply_to'] ) {
			return new \WP_Error( 'mdf_ca_347_sin_reply_to', 'Configura el Reply-To (Modelo 347 › Ajustes) antes de enviar: las farmacias responden a esa dirección.' );
		}

		$ultima = (int) get_transient( self::TRANSIENT_ULTIMA_TANDA );
		$falta  = $ultima + $ajustes['segundos_tanda'] - time();

		if ( $ultima > 0 && $falta > 0 ) {
			return array(
				'enviados'   => 0,
				'fallidos'   => 0,
				'cancelados' => 0,
				'quedan'     => count( $this->envios->ids_pendientes( $emisor_cif, $ejercicio, 100000 ) ),
				'espera'     => $falta,
			);
		}

		set_transient( self::TRANSIENT_ULTIMA_TANDA, time(), HOUR_IN_SECONDS );

		$resumen = array(
			'enviados'   => 0,
			'fallidos'   => 0,
			'cancelados' => 0,
			'quedan'     => 0,
			'espera'     => 0,
		);

		foreach ( $this->envios->ids_pendientes( $emisor_cif, $ejercicio, $ajustes['por_tanda'] ) as $envio_id ) {
			if ( ! $this->envios->reclamar( $envio_id ) ) {
				continue; // Otra peticion se la ha llevado.
			}

			$resultado = $this->enviar_reclamado( $envio_id, $emisor_cif, $ejercicio, $ajustes['reply_to'] );
			++$resumen[ $resultado ];
		}

		$resumen['quedan'] = count( $this->envios->ids_pendientes( $emisor_cif, $ejercicio, 100000 ) );

		return $resumen;
	}

	/** @return string enviados | fallidos | cancelados */
	private function enviar_reclamado( int $envio_id, string $emisor_cif, int $ejercicio, string $reply_to ): string {
		$envio     = $this->envios->find( $envio_id );
		$documento = $envio ? $this->documentos->find_by_id( (int) $envio->documento_id ) : null;

		if ( ! $documento || ! $documento->is_publicado() || ! $documento->es_modelo_347_vigente()
			|| $documento->get_emisor_cif() !== $emisor_cif || $documento->get_ejercicio() !== $ejercicio ) {
			$this->envios->marcar_cancelado( $envio_id, 'La carta ya no está publicada o fue retirada.' );

			return 'cancelados';
		}

		$farmacia = $this->farmacias->find_by_id( $documento->get_farmacia_id() );
		$email    = $farmacia ? Modelo_347_Simulacion_Service::email_de_cuenta_activa( $farmacia ) : null;

		if ( null === $email ) {
			$this->envios->marcar_cancelado( $envio_id, 'La farmacia ya no tiene cuenta activa con email.' );

			return 'cancelados';
		}

		$pdf = self::leer_pdf( $documento );

		if ( null === $pdf ) {
			$this->envios->marcar_fallido( $envio_id, $email, Correo::serializar( Correo::fallo( Correo::ADJUNTO, 'No se pudo leer el PDF de la carta en el servidor.' ) ) );

			return 'fallidos';
		}

		$fallo = self::mandar( $email, self::asunto( $ejercicio ), self::cuerpo( $emisor_cif, $ejercicio ), $reply_to, $pdf, $documento->get_nombre() );

		if ( null !== $fallo ) {
			$this->envios->marcar_fallido( $envio_id, $email, Correo::serializar( $fallo ) );

			return 'fallidos';
		}

		$this->envios->marcar_enviado( $envio_id, $email );
		$this->documentos->marcar_notificados( array( $documento->get_id() ) );

		return 'enviados';
	}

	/**
	 * "Enviarme una prueba": esta carta (pendiente o publicada, no retirada)
	 * a la direccion de prueba del ajuste. No publica, no toca la tabla de
	 * envios ni notificado_en.
	 *
	 * @return true|\WP_Error Si el envio falla, el mensaje lleva la categoria
	 *                        y el dato 'detalle', el detalle tecnico.
	 */
	public function enviar_prueba( int $documento_id ) {
		$documento = $this->documentos->find_by_id( $documento_id );

		if ( ! $documento || ! $documento->es_modelo_347_vigente() ) {
			return new \WP_Error( 'mdf_ca_347_prueba_documento', 'La carta no existe, no es un Modelo 347 o está retirada.' );
		}

		$emisor_cif = (string) $documento->get_emisor_cif();
		$ajustes    = Modelo_347_Ajustes::get( $emisor_cif );

		if ( '' === $ajustes['correo_prueba'] ) {
			return new \WP_Error( 'mdf_ca_347_sin_correo_prueba', 'Configura la dirección de prueba en Modelo 347 › Ajustes.' );
		}

		$pdf = self::leer_pdf( $documento );

		if ( null === $pdf ) {
			return new \WP_Error( 'mdf_ca_347_prueba_pdf', 'No se pudo leer el PDF de la carta en el servidor.' );
		}

		$ejercicio = (int) $documento->get_ejercicio();
		$fallo     = self::mandar(
			$ajustes['correo_prueba'],
			'[PRUEBA] ' . self::asunto( $ejercicio ),
			self::cuerpo( $emisor_cif, $ejercicio ),
			'' !== $ajustes['reply_to'] ? $ajustes['reply_to'] : null,
			$pdf,
			$documento->get_nombre()
		);

		if ( null === $fallo ) {
			return true;
		}

		return new \WP_Error(
			'mdf_ca_347_prueba_fallo',
			'El correo de prueba no se pudo enviar: ' . Correo::texto_categoria( $fallo['categoria'] ) . '.',
			array( 'detalle' => $fallo['detalle'] )
		);
	}

	/**
	 * Correo::enviar() con el PDF adjunto desde memoria.
	 *
	 * @return array{categoria: string, detalle: string}|null null si ha ido bien.
	 */
	private static function mandar( string $para, string $asunto, string $cuerpo, ?string $reply_to, string $pdf, string $nombre_documento ): ?array {
		$adjunto   = sanitize_file_name( remove_accents( $nombre_documento ) ) . '.pdf';
		$cabeceras = array( 'Content-Type: text/plain; charset=UTF-8' );

		if ( null !== $reply_to ) {
			$cabeceras[] = 'Reply-To: ' . $reply_to;
		}

		return Correo::enviar( $para, $asunto, $cuerpo, $cabeceras, array( $adjunto => $pdf ) );
	}

	/** Bytes del PDF guardado, con la misma defensa de ruta que Documento_Endpoint. */
	private static function leer_pdf( Documento $documento ): ?string {
		$carpeta = realpath( Documento_Endpoint::get_carpeta_documentos() );
		$ruta    = $carpeta ? realpath( $carpeta . DIRECTORY_SEPARATOR . $documento->get_ruta_fichero() ) : false;

		if ( ! $carpeta || ! $ruta || ! str_starts_with( $ruta, $carpeta . DIRECTORY_SEPARATOR ) || ! is_file( $ruta ) ) {
			return null;
		}

		$bytes = file_get_contents( $ruta ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichero local validado.

		return is_string( $bytes ) && str_starts_with( $bytes, '%PDF-' ) ? $bytes : null;
	}
}
