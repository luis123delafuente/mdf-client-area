<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lista fija de tipo de item de catalogo: si es un recurso descargable
 * (p. ej. una plantilla, un documento de apoyo) o solo visualizable/enlace
 * (p. ej. el acceso a la Academia en Formacion, o una herramienta que se
 * usa online sin descargarse). No implementa la logica de descarga en si
 * (fuera de alcance de esta tarea, ver #247) -- solo deja el dato
 * modelado para que el front y una futura tarea de descarga puedan
 * distinguir un caso del otro sin tener que anadir una columna despues.
 */
class Catalogo_Tipos {

	public const DESCARGABLE  = 'descargable';
	public const VISUALIZABLE = 'visualizable';

	private const TIPOS = array(
		self::DESCARGABLE  => 'Descargable',
		self::VISUALIZABLE => 'Solo visualizacion',
	);

	/**
	 * @return array<string, string> clave => etiqueta
	 */
	public static function get_opciones(): array {
		return self::TIPOS;
	}

	public static function es_valido( string $tipo ): bool {
		return isset( self::TIPOS[ $tipo ] );
	}

	public static function get_etiqueta( string $tipo ): string {
		return self::TIPOS[ $tipo ] ?? $tipo;
	}
}
