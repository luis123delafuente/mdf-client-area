<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aviso diario por email a cada farmacia con documentos nuevos (Fase 4).
 * Solo avisa: no concede ni muestra nada, Permissions no interviene.
 *
 * - "Nuevo" = documento con notificado_en NULL. Al subirlo queda pendiente
 *   (salvo Documento_Service::subir( ..., $notificar = false ), pensado
 *   para la ingesta del historico); el envio lo marca.
 * - Un unico resumen por farmacia y dia natural (zona de wp_timezone()),
 *   con el numero de documentos y su desglose por tipo. Nunca nombres de
 *   documento, importes, CIF ni enlaces al fichero o al visor: un unico
 *   enlace a la pagina Documentacion.
 * - Solo a farmacias con cuenta "activa" (Invitacion_Service::estado_cuenta()).
 *   Sin cuenta activa, sus documentos se quedan pendientes y entran en el
 *   primer aviso tras activarla.
 * - Interruptor: option apagada por defecto, y la constante
 *   MDF_CA_AVISOS_DESACTIVADOS (wp-config.php) fuerza el apagado. Apagado,
 *   no se envia nada y los documentos siguen pendientes.
 * - Programado con WP-Cron como evento unico a las 09:00 de wp_timezone(),
 *   reprogramado cada vez (no un evento recurrente: con "daily" la hora
 *   real se desplazaria una hora con el cambio horario).
 * - Si wp_mail() falla, no se marca nada: se reintenta en la siguiente
 *   ejecucion diaria, sin reintentos inmediatos.
 * - Dos ejecuciones simultaneas: bloqueo propio en wp_options (ver
 *   tomar_bloqueo()). Ademas solo se marcan los ids enviados, con
 *   "AND notificado_en IS NULL".
 * - Riesgo asumido: si PHP muere entre un wp_mail() correcto y el marcado,
 *   ese aviso se repite al dia siguiente (preferible a perderlo).
 * - Logs: solo recuentos, nunca emails, CIF ni nombres.
 */
class Aviso_Documentos_Service {

	public const HOOK_CRON            = 'mdf_ca_enviar_avisos_documentos';
	public const OPTION_ACTIVO        = 'mdf_ca_avisos_documentos_activos';
	public const META_ULTIMO_AVISO    = 'mdf_ca_ultimo_aviso_documentos';
	private const OPTION_BLOQUEO      = 'mdf_ca_avisos_documentos_bloqueo';
	private const BLOQUEO_TTL         = 15 * MINUTE_IN_SECONDS;
	private const HORA_ENVIO          = 9;
	private const ASUNTO              = 'Tienes documentación nueva en el Área de Clientes de Mediformplus';

	private Documento_Repository $documento_repository;
	private Farmacia_Repository $farmacia_repository;

	public function __construct( ?Documento_Repository $documento_repository = null, ?Farmacia_Repository $farmacia_repository = null ) {
		$this->documento_repository = $documento_repository ?? new Documento_Repository();
		$this->farmacia_repository  = $farmacia_repository ?? new Farmacia_Repository();
	}

	public static function register_hooks(): void {
		add_action( 'init', array( __CLASS__, 'programar' ) );
		add_action( self::HOOK_CRON, array( __CLASS__, 'ejecutar_programado' ) );
	}

	// ------------------------------------------------------------------
	// Interruptor
	// ------------------------------------------------------------------

	public static function forzado_apagado_por_constante(): bool {
		return defined( 'MDF_CA_AVISOS_DESACTIVADOS' ) && MDF_CA_AVISOS_DESACTIVADOS;
	}

	public static function esta_activo(): bool {
		return ! self::forzado_apagado_por_constante() && '1' === get_option( self::OPTION_ACTIVO, '0' );
	}

	public static function set_activo( bool $activo ): void {
		update_option( self::OPTION_ACTIVO, $activo ? '1' : '0', false );
	}

	// ------------------------------------------------------------------
	// Programacion (WP-Cron)
	// ------------------------------------------------------------------

	/**
	 * En 'init': si no hay evento pendiente, programa el siguiente. Barato
	 * (wp_next_scheduled() lee la option "cron", ya en memoria). Se
	 * programa aunque el interruptor este apagado: la ejecucion sale al
	 * principio, y asi encenderlo no depende de nada mas.
	 */
	public static function programar(): void {
		if ( ! wp_next_scheduled( self::HOOK_CRON ) ) {
			wp_schedule_single_event( self::siguiente_hora_envio(), self::HOOK_CRON );
		}
	}

	public static function desprogramar(): void {
		wp_clear_scheduled_hook( self::HOOK_CRON );
	}

	public static function siguiente_hora_envio(): int {
		$ahora    = new \DateTimeImmutable( 'now', wp_timezone() );
		$objetivo = $ahora->setTime( self::HORA_ENVIO, 0 );

		if ( $objetivo <= $ahora ) {
			$objetivo = $objetivo->modify( '+1 day' );
		}

		return $objetivo->getTimestamp();
	}

	public static function ejecutar_programado(): void {
		( new self() )->ejecutar();

		// WP-Cron ya quito este evento antes de ejecutarlo: se programa el
		// siguiente aqui mismo (y si esto fallara, programar() lo repone en
		// la siguiente peticion).
		self::programar();
	}

	// ------------------------------------------------------------------
	// Envio
	// ------------------------------------------------------------------

	/**
	 * @return array{enviados: int, fallidos: int, sin_cuenta: int, ya_avisadas_hoy: int, documentos: int}|\WP_Error
	 */
	public function ejecutar() {
		if ( ! self::esta_activo() ) {
			return new \WP_Error( 'mdf_ca_avisos_apagados', 'Los avisos por email estan desactivados. No se ha enviado nada.' );
		}

		$bloqueo = self::tomar_bloqueo();

		if ( null === $bloqueo ) {
			return new \WP_Error( 'mdf_ca_avisos_en_curso', 'Ya hay un envio de avisos en curso. No se ha enviado nada.' );
		}

		$resumen = array(
			'enviados'        => 0,
			'fallidos'        => 0,
			'sin_cuenta'      => 0,
			'ya_avisadas_hoy' => 0,
			'documentos'      => 0,
		);

		try {
			foreach ( $this->pendientes_por_farmacia() as $farmacia_id => $documentos ) {
				// Renovar el bloqueo antes de cada farmacia: si otro proceso
				// lo tomo por haber caducado, este se para aqui.
				$bloqueo = self::renovar_bloqueo( $bloqueo );

				if ( null === $bloqueo ) {
					break;
				}

				$this->procesar_farmacia( $farmacia_id, $documentos, $resumen );
			}
		} finally {
			if ( null !== $bloqueo ) {
				self::liberar_bloqueo( $bloqueo );
			}
		}

		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions -- solo recuentos, sin datos personales.
			sprintf(
				'[mdf-client-area] avisos de documentos: %d enviados, %d fallidos, %d farmacias sin cuenta activa, %d ya avisadas hoy, %d documentos notificados',
				$resumen['enviados'],
				$resumen['fallidos'],
				$resumen['sin_cuenta'],
				$resumen['ya_avisadas_hoy'],
				$resumen['documentos']
			)
		);

		return $resumen;
	}

	/**
	 * @param array<int, array{id: int, farmacia_id: int, tipo_documento: ?string}> $documentos
	 * @param array<string, int> $resumen
	 */
	private function procesar_farmacia( int $farmacia_id, array $documentos, array &$resumen ): void {
		$farmacia = $this->farmacia_repository->find_by_id( $farmacia_id );

		if ( ! $farmacia || 'activa' !== Invitacion_Service::estado_cuenta( $farmacia )['estado'] ) {
			++$resumen['sin_cuenta'];
			return;
		}

		$usuario = get_userdata( (int) $farmacia->get_wp_user_id() );

		if ( self::avisada_hoy( $usuario->ID ) ) {
			++$resumen['ya_avisadas_hoy'];
			return;
		}

		$tipos = array_map( static fn( array $doc ): ?string => $doc['tipo_documento'], $documentos );

		if ( ! wp_mail( $usuario->user_email, self::ASUNTO, self::cuerpo_email( $tipos ) ) ) {
			++$resumen['fallidos'];
			return;
		}

		$marcados = $this->documento_repository->marcar_notificados( array_column( $documentos, 'id' ) );
		update_user_meta( $usuario->ID, self::META_ULTIMO_AVISO, time() );

		++$resumen['enviados'];
		$resumen['documentos'] += (int) $marcados;
	}

	/**
	 * @return array<int, array<int, array{id: int, farmacia_id: int, tipo_documento: ?string}>> farmacia_id => documentos
	 */
	public function pendientes_por_farmacia(): array {
		$agrupados = array();

		foreach ( $this->documento_repository->find_pendientes_aviso() as $documento ) {
			$agrupados[ $documento['farmacia_id'] ][] = $documento;
		}

		return $agrupados;
	}

	/** Como maximo un aviso por farmacia y dia natural (zona de wp_timezone()). */
	private static function avisada_hoy( int $user_id ): bool {
		$ultimo = (int) get_user_meta( $user_id, self::META_ULTIMO_AVISO, true );

		return $ultimo > 0 && wp_date( 'Y-m-d', $ultimo ) === wp_date( 'Y-m-d' );
	}

	/**
	 * Texto plano, sobrio y neutro (el primer aviso incluye todo lo
	 * acumulado). Recibe solo los tipos: no hay forma de que un nombre de
	 * documento, un importe o un CIF acaben aqui.
	 *
	 * @param array<int, ?string> $tipos Tipo de cada documento nuevo.
	 */
	public static function cuerpo_email( array $tipos ): string {
		$total     = count( $tipos );
		$recuentos = array_count_values( array_map( array( Documento_Tipos::class, 'clave_para_recuento' ), $tipos ) );
		arsort( $recuentos );

		$lineas = array();

		foreach ( $recuentos as $clave => $cantidad ) {
			$lineas[] = '- ' . Documento_Tipos::texto_recuento( (string) $clave, $cantidad );
		}

		$frase = 1 === $total
			? 'Hay 1 documento nuevo en tu Área de Clientes de Mediformplus:'
			: sprintf( 'Hay %d documentos nuevos en tu Área de Clientes de Mediformplus:', $total );

		return "Hola:\r\n\r\n"
			. $frase . "\r\n\r\n"
			. implode( "\r\n", $lineas ) . "\r\n\r\n"
			. "Puedes consultarlos en la sección Documentación, después de iniciar sesión:\r\n"
			. self::url_documentacion() . "\r\n\r\n"
			. "Este es un aviso automático de Mediformplus.\r\n";
	}

	private static function url_documentacion(): string {
		$id  = Area_Privada_Pages::get_page_ids()[ Area_Privada_Pages::SLUG_DOCUMENTACION ] ?? 0;
		$url = $id ? get_permalink( $id ) : false;

		return $url ? $url : home_url( '/' );
	}

	// ------------------------------------------------------------------
	// Bloqueo
	// ------------------------------------------------------------------
	//
	// Fila propia en wp_options con valor "caducidad|token", siempre por
	// SQL directo (sin la cache de options):
	// - Tomar: INSERT IGNORE. Atomico por la clave unica de option_name.
	//   (add_option() no sirve: hace INSERT ... ON DUPLICATE KEY UPDATE y
	//   pisaria el bloqueo de otro proceso.)
	// - Bloqueo caducado (proceso muerto): se toma con un UPDATE
	//   condicionado al valor exacto leido. Si dos procesos lo intentan a
	//   la vez, solo uno ve 1 fila afectada.
	// - Renovar y liberar: tambien condicionados al valor propio, asi que
	//   un proceso nunca toca un bloqueo que ya no es suyo.

	private static function tomar_bloqueo(): ?string {
		global $wpdb;

		$nuevo    = self::valor_bloqueo();
		$insertado = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				self::OPTION_BLOQUEO,
				$nuevo
			)
		);

		if ( 1 === $insertado ) {
			return $nuevo;
		}

		$actual = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::OPTION_BLOQUEO )
		);

		if ( null === $actual || (int) strtok( $actual, '|' ) > time() ) {
			return null;
		}

		return self::sustituir_bloqueo( $actual, $nuevo ) ? $nuevo : null;
	}

	private static function renovar_bloqueo( string $actual ): ?string {
		$nuevo = self::valor_bloqueo();

		return self::sustituir_bloqueo( $actual, $nuevo ) ? $nuevo : null;
	}

	private static function sustituir_bloqueo( string $actual, string $nuevo ): bool {
		global $wpdb;

		return 1 === $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$nuevo,
				self::OPTION_BLOQUEO,
				$actual
			)
		);
	}

	private static function liberar_bloqueo( string $actual ): void {
		global $wpdb;

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				self::OPTION_BLOQUEO,
				$actual
			)
		);
	}

	private static function valor_bloqueo(): string {
		return ( time() + self::BLOQUEO_TTL ) . '|' . wp_generate_password( 20, false, false );
	}
}
