<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validacion del NIF (Numero de Identificacion Fiscal) de persona fisica
 * espanola mediante su letra de control. Sin dependencias de WordPress,
 * igual que CIF_Validator: logica de formato pura, reutilizable desde el
 * importador CSV de fase 4.
 *
 * Algoritmo estandar: los 8 digitos del NIF, en modulo 23, dan un indice
 * (0-22) en TABLA_LETRAS que fija la letra de control esperada -- tabla
 * cerrada del algoritmo oficial, no derivable de ninguna formula.
 *
 * NIE (extranjeros, formato X/Y/Z + 7 digitos + letra) queda fuera de
 * alcance a proposito (#251): MDF no lo ha pedido para las farmacias
 * piloto, y anadir soporte sin que lo pidan seria construir por
 * adelantado sobre una necesidad que no existe todavia.
 */
class NIF_Validator {

	private const TABLA_LETRAS = 'TRWAGMYFPDXBNJZSQVHLCKE';

	public static function is_valid( string $nif ): bool {
		$nif = strtoupper( trim( $nif ) );

		if ( ! preg_match( '/^\d{8}[A-Z]$/', $nif ) ) {
			return false;
		}

		$numero         = (int) substr( $nif, 0, 8 );
		$letra_control  = $nif[8];

		return $letra_control === self::TABLA_LETRAS[ $numero % 23 ];
	}
}
