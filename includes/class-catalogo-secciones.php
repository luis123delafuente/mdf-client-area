<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Secciones del catalogo anteriores al esquema 1.21.0. Desde entonces el
 * catalogo es solo Herramientas (Formacion es una pagina de Elementor con un
 * enlace a la Academia) y la columna seccion ya no decide nada. Se conserva
 * para la migracion (Activator::migrar_catalogo_a_herramientas(): los
 * elementos de Formacion pasan, ocultos, a "Formacion (antigua)"), para
 * rellenar la columna, que es NOT NULL, y para que
 * [mdf_ca_catalogo_herramientas seccion="formacion"] no pinte nada.
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
