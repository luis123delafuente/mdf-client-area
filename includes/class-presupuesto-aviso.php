<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Correos de la respuesta de una farmacia a un presupuesto (v0.44.0), con
 * Correo::enviar() (el SMTP del area, como los demas avisos):
 *
 * - Al responsable de MDF (Presupuesto_Ajustes): farmacia, CIF, presupuesto,
 *   quien y cuando, y el motivo si es un rechazo. Reply-To: el usuario que
 *   respondio. Es el importante: mientras no sale, la fila queda con
 *   presupuesto_aviso_pendiente = 1 y se reintenta cada hora (evento unico
 *   de WP-Cron que se reprograma mientras quede alguno). Sin ajuste, tambien
 *   queda pendiente: la pantalla Presupuestos lo avisa.
 * - Al usuario de la farmacia: confirmacion, una sola vez y sin reintento.
 *   Sin el nombre del documento ni el motivo (mismo criterio que el aviso
 *   de documentos nuevos: el correo de la farmacia no lleva datos del
 *   documento); se consulta en el area.
 *
 * Sin logs con datos: si falla, solo queda el aviso pendiente.
 */
class Presupuesto_Aviso {

	public const HOOK_REINTENTO = 'mdf_ca_presupuestos_reintentar_aviso';

	private const ESPERA_REINTENTO = HOUR_IN_SECONDS;

	private Documento_Repository $repository;

	public function __construct( ?Documento_Repository $repository = null ) {
		$this->repository = $repository ?? new Documento_Repository();
	}

	public static function register_hooks(): void {
		add_action( self::HOOK_REINTENTO, array( __CLASS__, 'ejecutar_reintento' ) );
	}

	public static function ejecutar_reintento(): void {
		( new self() )->reintentar_pendientes();
	}

	/** Recien aceptado o rechazado por la farmacia: los dos correos. */
	public function tras_respuesta( Documento $documento, \WP_User $usuario ): void {
		$this->enviar_a_responsable( $documento );
		$this->enviar_a_farmacia( $documento, $usuario );
	}

	/**
	 * Los avisos al responsable que no salieron. Devuelve cuantos siguen
	 * pendientes y, si queda alguno, vuelve a programarse.
	 */
	public function reintentar_pendientes(): int {
		$quedan = 0;

		foreach ( $this->repository->find_presupuestos_aviso_pendiente() as $documento ) {
			if ( ! $this->enviar_a_responsable( $documento, false ) ) {
				++$quedan;
			}
		}

		if ( $quedan ) {
			self::programar_reintento();
		}

		return $quedan;
	}

	/** true si ha salido (y la fila queda sin aviso pendiente). */
	private function enviar_a_responsable( Documento $documento, bool $programar_si_falla = true ): bool {
		$presupuesto = $documento->get_presupuesto();
		$para        = Presupuesto_Ajustes::email_responsable();

		if ( ! $presupuesto || $presupuesto->es_pendiente() ) {
			// Ya no hay nada que avisar (MDF lo devolvio a pendiente).
			$this->repository->marcar_aviso_presupuesto_enviado( $documento->get_id() );

			return true;
		}

		$usuario  = $presupuesto->get_cambiado_por() ? get_userdata( $presupuesto->get_cambiado_por() ) : false;
		$farmacia = ( new Farmacia_Repository() )->find_by_id( $documento->get_farmacia_id() );

		$cabeceras = array();

		if ( $usuario && is_email( $usuario->user_email ) ) {
			$cabeceras[] = 'Reply-To: ' . $usuario->user_email;
		}

		$fallo = '' === $para
			? Correo::fallo( Correo::EMAIL_INVALIDO, 'Falta el correo del responsable de presupuestos en Ajustes.' )
			: Correo::enviar( $para, self::asunto_responsable( $documento, $farmacia ), self::cuerpo_responsable( $documento, $farmacia, $usuario ?: null ), $cabeceras );

		if ( null === $fallo ) {
			$this->repository->marcar_aviso_presupuesto_enviado( $documento->get_id() );

			return true;
		}

		if ( $programar_si_falla ) {
			self::programar_reintento();
		}

		return false;
	}

	private function enviar_a_farmacia( Documento $documento, \WP_User $usuario ): void {
		$presupuesto = $documento->get_presupuesto();

		if ( ! $presupuesto || $presupuesto->es_pendiente() ) {
			return;
		}

		Correo::enviar( (string) $usuario->user_email, self::asunto_farmacia( $presupuesto ), self::cuerpo_farmacia( $presupuesto ) );
	}

	private static function programar_reintento(): void {
		if ( ! wp_next_scheduled( self::HOOK_REINTENTO ) ) {
			wp_schedule_single_event( time() + self::ESPERA_REINTENTO, self::HOOK_REINTENTO );
		}
	}

	private static function asunto_responsable( Documento $documento, ?Farmacia $farmacia ): string {
		$accion = Presupuesto_Estado::ACEPTADO === $documento->get_presupuesto()->get_estado() ? 'aceptado' : 'rechazado';

		return sprintf( 'Presupuesto %s: %s · %s', $accion, $documento->get_nombre(), $farmacia ? $farmacia->get_nombre() : 'farmacia' );
	}

	/** Texto plano. Para MDF: lleva farmacia, CIF, presupuesto y motivo. */
	public static function cuerpo_responsable( Documento $documento, ?Farmacia $farmacia, ?\WP_User $usuario ): string {
		$presupuesto = $documento->get_presupuesto();
		$aceptado    = Presupuesto_Estado::ACEPTADO === $presupuesto->get_estado();
		$cuando      = $presupuesto->get_cambiado_en() ? get_date_from_gmt( $presupuesto->get_cambiado_en(), 'd/m/Y \a \l\a\s H:i' ) : '';
		$quien       = $usuario ? trim( $usuario->display_name . ' <' . $usuario->user_email . '>' ) : 'Usuario ya no existe';

		$lineas = array(
			'Hola:',
			'',
			sprintf(
				'La farmacia %s ha %s un presupuesto desde el Área de Clientes.',
				$farmacia ? $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')' : '(farmacia ya no existe)',
				$aceptado ? 'aceptado' : 'rechazado'
			),
			'',
			'Presupuesto: ' . $documento->get_nombre(),
			'Fecha: ' . $cuando,
			'Usuario: ' . $quien,
		);

		if ( ! $aceptado ) {
			$lineas[] = '';
			$lineas[] = 'Motivo del rechazo:';
			$lineas[] = (string) $presupuesto->get_motivo();
		}

		$lineas[] = '';
		$lineas[] = 'Puedes verlo en Documentos MDF > Presupuestos:';
		$lineas[] = Admin_Presupuestos::url_listado();
		$lineas[] = '';
		$lineas[] = 'Si respondes a este correo, la respuesta le llega a quien lo ha ' . ( $aceptado ? 'aceptado' : 'rechazado' ) . '.';
		$lineas[] = '';
		$lineas[] = 'Este es un aviso automático del Área de Clientes de Mediformplus.';

		return implode( "\r\n", $lineas ) . "\r\n";
	}

	private static function asunto_farmacia( Presupuesto_Estado $presupuesto ): string {
		return Presupuesto_Estado::ACEPTADO === $presupuesto->get_estado()
			? 'Hemos recibido la aceptación de tu presupuesto'
			: 'Hemos recibido el rechazo de tu presupuesto';
	}

	/** Texto plano, sin datos del documento (ver la cabecera). */
	public static function cuerpo_farmacia( Presupuesto_Estado $presupuesto ): string {
		$aceptado = Presupuesto_Estado::ACEPTADO === $presupuesto->get_estado();
		$id       = Area_Privada_Pages::get_paginas_adicionales_ids()[ Area_Privada_Pages::slug_de_tipo( Presupuesto_Estado::TIPO_DOCUMENTO ) ] ?? 0;
		$url      = $id ? get_permalink( $id ) : false;

		return "Hola:\r\n\r\n"
			. ( $aceptado
				? "Hemos recibido la aceptación de un presupuesto desde tu Área de Clientes de Mediformplus. Nuestro equipo se pondrá en contacto contigo para los siguientes pasos.\r\n\r\n"
				: "Hemos recibido el rechazo de un presupuesto desde tu Área de Clientes de Mediformplus, con el motivo que nos has indicado. Nuestro equipo lo revisará.\r\n\r\n" )
			. "Puedes consultarlo en la sección Presupuestos, después de iniciar sesión:\r\n"
			. ( $url ? $url : home_url( '/' ) ) . "\r\n\r\n"
			. "Este es un aviso automático de Mediformplus.\r\n";
	}
}
