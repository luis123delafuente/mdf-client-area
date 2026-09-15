<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validacion del CIF (Codigo de Identificacion Fiscal) espanol mediante su
 * algoritmo de digito de control. Sin dependencias de WordPress: es logica
 * de formato pura, reutilizable desde el importador CSV de fase 4.
 */
class CIF_Validator {

	private const LETRAS_CONTROL = array( 'J', 'A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I' );

	/** Letras iniciales cuyo caracter de control debe ser una letra. */
	private const PRIMERA_LETRA_EXIGE_LETRA = array( 'K', 'P', 'Q', 'S' );

	/** Letras iniciales cuyo caracter de control debe ser un digito. */
	private const PRIMERA_LETRA_EXIGE_DIGITO = array( 'A', 'B', 'E', 'H' );

	public static function is_valid( string $cif ): bool {
		$cif = strtoupper( trim( $cif ) );

		if ( ! preg_match( '/^[ABCDEFGHJKLMNPQRSUVW]\d{7}[0-9A-J]$/', $cif ) ) {
			return false;
		}

		$primera_letra = $cif[0];
		$digitos       = substr( $cif, 1, 7 );
		$caracter_final = $cif[8];

		$suma = 0;

		for ( $i = 0; $i < 7; $i++ ) {
			$digito = (int) $digitos[ $i ];

			if ( 0 === $i % 2 ) {
				// Posiciones impares (1a, 3a, 5a, 7a): se duplican y, si el
				// resultado tiene dos cifras, se suman entre si.
				$doble = $digito * 2;
				$suma += $doble > 9 ? $doble - 9 : $doble;
			} else {
				$suma += $digito;
			}
		}

		$digito_control = ( 10 - ( $suma % 10 ) ) % 10;
		$letra_control  = self::LETRAS_CONTROL[ $digito_control ];

		if ( in_array( $primera_letra, self::PRIMERA_LETRA_EXIGE_LETRA, true ) ) {
			return $caracter_final === $letra_control;
		}

		if ( in_array( $primera_letra, self::PRIMERA_LETRA_EXIGE_DIGITO, true ) ) {
			return $caracter_final === (string) $digito_control;
		}

		// Resto de letras: la practica habitual acepta digito o letra.
		return $caracter_final === (string) $digito_control || $caracter_final === $letra_control;
	}
}
