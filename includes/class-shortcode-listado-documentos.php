<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode de front para el listado de documentos de la farmacia del
 * usuario logueado (Fase 2, tarea #241). Se implementa como shortcode y no
 * como widget nativo de Elementor: Elementor ni siquiera esta activo en
 * este entorno, y su propio widget "Shortcode" puede envolver esto sin
 * acoplar esta pieza de logica a que Elementor este instalado.
 *
 * No decide visibilidad por si mismo: se apoya en
 * Farmacia_Repository::find_by_wp_user_id() para resolver la farmacia del
 * usuario y en Documento_Repository::find_by_farmacia_id() para acotar la
 * consulta a esa farmacia, igual que hace Documento_Endpoint con
 * Permissions::puede_ver_documento() para la descarga individual. Sin
 * sesion o sin farmacia vinculada, el estado vacio es el mismo que con
 * cero documentos: nunca un error visible ni un indicio de por que no hay
 * nada que ver.
 */
class Shortcode_Listado_Documentos {

	private const TAG = 'mdf_ca_listado_documentos';

	public static function register_hooks(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	public static function render(): string {
		$farmacia = self::farmacia_actual();

		if ( ! $farmacia ) {
			return self::render_estado_vacio();
		}

		$documentos = ( new Documento_Repository() )->find_by_farmacia_id( $farmacia->get_id() );

		if ( ! $documentos ) {
			return self::render_estado_vacio();
		}

		return self::render_lista( $documentos );
	}

	private static function farmacia_actual(): ?Farmacia {
		if ( ! is_user_logged_in() ) {
			return null;
		}

		return ( new Farmacia_Repository() )->find_by_wp_user_id( get_current_user_id() );
	}

	private static function render_estado_vacio(): string {
		return '<p class="mdf-ca-listado-documentos mdf-ca-listado-documentos--vacio">' .
			esc_html__( 'No hay documentos disponibles.', 'mdf-client-area' ) .
			'</p>';
	}

	/**
	 * @param Documento[] $documentos
	 */
	private static function render_lista( array $documentos ): string {
		$items = '';

		foreach ( $documentos as $documento ) {
			$items .= self::render_item( $documento );
		}

		return '<ul class="mdf-ca-listado-documentos">' . $items . '</ul>';
	}

	/**
	 * El enlace apunta siempre al endpoint de servido (#232), nunca a una
	 * ruta de fichero directa (CLAUDE.md, regla no negociable 1).
	 */
	private static function render_item( Documento $documento ): string {
		$url   = home_url( 'mdf-ca-documento/' . $documento->get_id() . '/' );
		$fecha = mysql2date( get_option( 'date_format' ), $documento->get_fecha_subida() );

		return '<li class="mdf-ca-listado-documentos__item">'
			. '<a href="' . esc_url( $url ) . '">' . esc_html( $documento->get_nombre() ) . '</a>'
			. ' <span class="mdf-ca-listado-documentos__fecha">' . esc_html( $fecha ) . '</span>'
			. '</li>';
	}
}
