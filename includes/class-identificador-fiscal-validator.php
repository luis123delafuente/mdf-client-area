<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Punto unico de validacion para el campo cif de una farmacia (#251):
 * acepta tanto CIF (sociedades) como NIF de persona fisica, cada uno con
 * su propio algoritmo real de digito/letra de control -- CIF_Validator y
 * NIF_Validator no se tocan ni se relajan, cada uno sigue validando solo
 * su propio formato. Quien usa esta clase (Farmacia_Service, el futuro
 * importador CSV de Fase 4) no tiene que indicar el tipo a mano: la
 * deteccion es automatica por la FORMA del identificador:
 *
 * - CIF: el primer caracter es una letra de sociedad (formato ya validado
 *   en CIF_Validator), seguida de 7 digitos y un caracter de control.
 * - NIF de persona fisica: empieza siempre por un digito (8 digitos +
 *   letra de control), nunca por una letra.
 *
 * Los dos formatos son mutuamente excluyentes por construccion (uno exige
 * que el primer caracter sea letra, el otro que sea digito), asi que
 * detectar el tipo es tan simple como mirar ese primer caracter y delegar
 * en el validador que corresponde -- no hace falta decidir el tipo con
 * logica aparte ni probar los dos algoritmos a ciegas.
 *
 * NIE queda fuera de alcance a proposito, ver NIF_Validator.
 */
class Identificador_Fiscal_Validator {

	/**
	 * Forma canonica del identificador, la que se guarda en BD y sirve de
	 * clave del upsert del importador CSV: mayusculas, sin espacios
	 * (incluido el espacio duro que a veces pega Excel) ni guiones. "b-1234567 4"
	 * y "B12345674" son la misma farmacia. Unico sitio donde se normaliza:
	 * Farmacia_Service::crear() y Farmacia_Import_Service lo usan los dos.
	 */
	public static function normalizar( string $identificador ): string {
		return strtoupper( (string) preg_replace( '/[\s\x{00A0}\-]+/u', '', $identificador ) );
	}

	public static function is_valid( string $identificador ): bool {
		$identificador = strtoupper( trim( $identificador ) );

		if ( '' === $identificador ) {
			return false;
		}

		return ctype_digit( $identificador[0] )
			? NIF_Validator::is_valid( $identificador )
			: CIF_Validator::is_valid( $identificador );
	}
}
