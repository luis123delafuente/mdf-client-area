<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes del catalogo de herramientas. Hoy solo uno: "Permitir
 * herramientas Excel con macros" (.xlsm), apagado por defecto y solo para el
 * administrador (Roles::CAP_ADMINISTRAR), no para quien tiene "Catalogo y
 * planes": es una decision de seguridad, como el interruptor de los avisos
 * por email.
 *
 * Apagado:
 * - no se puede subir un .xlsm (Catalogo_Archivo_Validator);
 * - los ya subidos no los ve ninguna farmacia y su descarga da 404
 *   (Permissions); el backoffice los marca.
 */
class Catalogo_Ajustes {

	private const OPTION_PERMITIR_XLSM = 'mdf_ca_herramientas_permitir_xlsm';

	public static function permite_xlsm(): bool {
		return '1' === (string) get_option( self::OPTION_PERMITIR_XLSM, '0' );
	}

	public static function set_permite_xlsm( bool $permitir ): void {
		update_option( self::OPTION_PERMITIR_XLSM, $permitir ? '1' : '0', false );
	}
}
