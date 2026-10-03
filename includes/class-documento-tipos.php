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

	/** Singular y plural en minusculas, para el desglose del aviso por email. */
	private const RECUENTO = array(
		'contrato'    => array( 'contrato', 'contratos' ),
		'factura'     => array( 'factura', 'facturas' ),
		'presupuesto' => array( 'presupuesto', 'presupuestos' ),
		'entregable'  => array( 'entregable', 'entregables' ),
		'otro'        => array( 'otro', 'otros' ),
	);

	/**
	 * Clave con la que se agrupa un documento en el desglose del aviso:
	 * sin tipo (documentos anteriores a los tipos) o con uno desconocido
	 * cuenta como "otro".
	 */
	public static function clave_para_recuento( ?string $tipo ): string {
		return null !== $tipo && isset( self::TIPOS[ $tipo ] ) ? $tipo : 'otro';
	}

	/** "1 factura", "3 facturas", "2 otros". */
	public static function texto_recuento( string $clave, int $cantidad ): string {
		$formas = self::RECUENTO[ $clave ] ?? self::RECUENTO['otro'];

		return $cantidad . ' ' . ( 1 === $cantidad ? $formas[0] : $formas[1] );
	}

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
