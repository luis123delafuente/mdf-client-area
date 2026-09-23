<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lista fija de secciones del catalogo (Herramientas, Formacion), segun la
 * estructura de cuatro paginas ya definida en Fase 2 (ver
 * Area_Privada_Pages). Mismo criterio que Documento_Tipos: no hay ningun
 * flujo de negocio que necesite dar de alta secciones nuevas, asi que un
 * array fijo es toda la infraestructura que hace falta.
 */
class Catalogo_Secciones {

	public const HERRAMIENTAS = 'herramientas';
	public const FORMACION    = 'formacion';

	private const SECCIONES = array(
		self::HERRAMIENTAS => 'Herramientas',
		self::FORMACION    => 'Formacion',
	);

	/**
	 * @return array<string, string> clave => etiqueta
	 */
	public static function get_opciones(): array {
		return self::SECCIONES;
	}

	public static function es_valida( string $seccion ): bool {
		return isset( self::SECCIONES[ $seccion ] );
	}

	public static function get_etiqueta( string $seccion ): string {
		return self::SECCIONES[ $seccion ] ?? $seccion;
	}
}
