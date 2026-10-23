<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lista fija de tipos/etiqueta de documento (contrato, factura, Modelo 347,
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
		'modelo_347'  => 'Modelo 347',
		'presupuesto' => 'Presupuesto',
		'entregable'  => 'Entregable',
		'otro'        => 'Otro',
	);

	/**
	 * Orden fijo de los grupos del listado de Documentacion y su etiqueta en
	 * plural. Facturas primero (lo mas voluminoso y lo que mas se consulta),
	 * despues el Modelo 347 (#307, por ejercicio); "otro" cierra y recoge tambien los documentos sin tipo o con uno
	 * desconocido (ver clave_para_recuento()).
	 */
	private const GRUPOS_LISTADO = array(
		'factura'     => 'Facturas',
		'modelo_347'  => 'Modelo 347',
		'contrato'    => 'Contratos',
		'presupuesto' => 'Presupuestos',
		'entregable'  => 'Entregables',
		'otro'        => 'Otros',
	);

	/**
	 * Singular y plural, para el desglose del aviso por email. "Otro" no
	 * se lee bien solo ("2 otros"): va como "documento(s) de otro tipo".
	 */
	private const RECUENTO = array(
		'contrato'    => array( 'contrato', 'contratos' ),
		'factura'     => array( 'factura', 'facturas' ),
		'modelo_347'  => array( 'Modelo 347', 'Modelos 347' ),
		'presupuesto' => array( 'presupuesto', 'presupuestos' ),
		'entregable'  => array( 'entregable', 'entregables' ),
		'otro'        => array( 'documento de otro tipo', 'documentos de otro tipo' ),
	);

	/**
	 * Clave con la que se agrupa un documento en el desglose del aviso:
	 * sin tipo (documentos anteriores a los tipos) o con uno desconocido
	 * cuenta como "otro".
	 */
	public static function clave_para_recuento( ?string $tipo ): string {
		return null !== $tipo && isset( self::TIPOS[ $tipo ] ) ? $tipo : 'otro';
	}

	/** "1 factura", "3 facturas", "2 documentos de otro tipo". */
	public static function texto_recuento( string $clave, int $cantidad ): string {
		$formas = self::RECUENTO[ $clave ] ?? self::RECUENTO['otro'];

		return $cantidad . ' ' . ( 1 === $cantidad ? $formas[0] : $formas[1] );
	}

	/**
	 * @return array<string, string> clave => etiqueta en plural, en el orden del listado
	 */
	public static function get_grupos_listado(): array {
		return self::GRUPOS_LISTADO;
	}

	/**
	 * Tipos que se pueden subir a mano en Documentos MDF (v0.36.0): todos
	 * menos los que tienen su propia via (Documento_Service::
	 * TIPOS_CON_VIA_PROPIA: facturas por "Subir facturas", Modelo 347 por su
	 * generador).
	 *
	 * @return array<string, string> clave => etiqueta
	 */
	public static function get_opciones_subida_manual(): array {
		return array_diff_key( self::TIPOS, array_flip( Documento_Service::TIPOS_CON_VIA_PROPIA ) );
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
