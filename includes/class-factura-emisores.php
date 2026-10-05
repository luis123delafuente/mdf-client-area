<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sociedades del grupo MDF que emiten las facturas que llegan por la
 * recepcion automatica (#314), con su nombre corto y sus series de SAGE.
 *
 * Lista fija en codigo, no una entidad de datos ni una option: son pocas,
 * cambian rara vez y anadir una sociedad o una serie exige criterio (una
 * serie nunca se comparte entre sociedades, para que la factura quede
 * identificada por emisor + serie + numero). Es el UNICO sitio que decide
 * que emisor y que serie se aceptan; el parser tiene su propia lista
 * (Parser_facturas_SAGE, config.EMISORES) y el servidor no se fia de ella.
 */
class Factura_Emisores {

	/** CIF normalizado => nombre corto (va en el nombre visible del documento) y series admitidas. */
	private const EMISORES = array(
		'B82827635' => array(
			'nombre' => 'Mediformplus',
			'series' => array( 'A' ),
		),
		'B88471149' => array(
			'nombre' => 'Formación',
			'series' => array( 'SF' ),
		),
	);

	public static function es_emisor( string $cif ): bool {
		return isset( self::EMISORES[ $cif ] );
	}

	/** Si $serie es una serie de $cif. Nunca deduce el emisor a partir de la serie. */
	public static function admite( string $cif, string $serie ): bool {
		return self::es_emisor( $cif ) && in_array( $serie, self::EMISORES[ $cif ]['series'], true );
	}

	public static function nombre_corto( string $cif ): ?string {
		return self::EMISORES[ $cif ]['nombre'] ?? null;
	}
}
