<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes de los presupuestos (v0.44.0). Hoy solo uno: el correo de quien
 * se encarga de los presupuestos en MDF, que recibe el aviso cuando una
 * farmacia acepta o rechaza uno. Sin direccion en el codigo (mismo criterio
 * que el correo de Administracion del 347): sin ajuste, el aviso queda
 * pendiente y la pantalla Presupuestos lo dice. Solo lo cambia el
 * administrador (Roles::CAP_ADMINISTRAR): decide a donde van datos de las
 * farmacias.
 */
class Presupuesto_Ajustes {

	private const OPTION_EMAIL_RESPONSABLE = 'mdf_ca_presupuestos_email_responsable';

	public static function email_responsable(): string {
		$email = (string) get_option( self::OPTION_EMAIL_RESPONSABLE, '' );

		return is_email( $email ) ? $email : '';
	}

	/** Vacio lo quita. false si no es un email valido (no se guarda nada). */
	public static function set_email_responsable( string $email ): bool {
		$email = sanitize_email( $email );

		if ( '' !== $email && ! is_email( $email ) ) {
			return false;
		}

		update_option( self::OPTION_EMAIL_RESPONSABLE, $email, false );

		return true;
	}
}
