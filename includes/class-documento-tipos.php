<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lista fija de tipos/etiqueta de documento (contrato, factura,
 * presupuesto, entregable, otro), segun los requisitos de MKT para la
 * seccion Documentacion. No es una entidad de datos como Plan: a
 * diferencia de los planes, no hay ningun flujo de negocio en Fase 3 que
 * necesite dar de alta o editar tipos nuevos desde el backoffice, asi que
 * un array fijo aqui es toda la infraestructura que hace falta -- crear una
 * tabla y un CRUD para esto seria construir por adelantado algo que nadie
 * ha pedido.
 */
class Documento_Tipos {

	private const TIPOS = array(
		'contrato'    => 'Contrato',
		'factura'     => 'Factura',
		'presupuesto' => 'Presupuesto',
		'entregable'  => 'Entregable',
		'otro'        => 'Otro',
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

	public static function get_etiqueta( ?string $tipo ): string {
		if ( null === $tipo || ! isset( self::TIPOS[ $tipo ] ) ) {
			return 'Sin tipo';
		}

		return self::TIPOS[ $tipo ];
	}
}
