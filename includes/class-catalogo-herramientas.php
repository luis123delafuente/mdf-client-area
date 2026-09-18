<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PLACEHOLDER de Fase 2 (tarea #242): array PHP hardcodeado solo para
 * desbloquear a MKT con datos representativos del catalogo de
 * Herramientas condicionado por plan. No es la entidad real -- Planes y
 * el catalogo por plan son entidad de datos (tabla propia) a partir de
 * Fase 3 (ver CLAUDE.md), momento en el que esta clase entera se
 * sustituye por un repositorio contra esa tabla. No anadir aqui logica de
 * negocio: la decision de visibilidad sigue viviendo en
 * Permissions::puede_ver_item_catalogo(), esto solo son datos.
 */
class Catalogo_Herramientas {

	/**
	 * @return array<int, array{id: string, nombre: string, planes: string[]}>
	 */
	public static function get_items(): array {
		return array(
			array(
				'id'     => 'gestion-stock',
				'nombre' => 'Gestion de stock basica',
				'planes' => array( 'basico', 'premium' ),
			),
			array(
				'id'     => 'informes-basicos',
				'nombre' => 'Informes mensuales',
				'planes' => array( 'basico' ),
			),
			array(
				'id'     => 'formacion-basica',
				'nombre' => 'Formacion online basica',
				'planes' => array( 'basico', 'premium' ),
			),
			array(
				'id'     => 'pedidos-automaticos',
				'nombre' => 'Pedidos automaticos a proveedor',
				'planes' => array( 'premium' ),
			),
			array(
				'id'     => 'analitica-avanzada',
				'nombre' => 'Analitica avanzada de ventas',
				'planes' => array( 'premium' ),
			),
			array(
				'id'     => 'soporte-prioritario',
				'nombre' => 'Soporte prioritario',
				'planes' => array( 'premium' ),
			),
		);
	}
}
