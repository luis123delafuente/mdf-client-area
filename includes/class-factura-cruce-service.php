<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cruce de una factura SAGE ya parseada contra las farmacias (Fase 4).
 *
 * El plugin nunca lee PDFs: el parser Python (repositorio hermano
 * Parser_facturas_SAGE) extrae CIF/NIF del cliente, numero, fecha e importe y
 * devuelve un resultado estructurado. Esta clase solo decide a que farmacia
 * pertenece, contra la BD de WordPress (unica fuente de verdad de farmacias):
 *
 * - Si el parser devolvio una excepcion (sin_texto, formato_inesperado,
 *   numero_discrepante, sin_cif, cif_invalido), pasa tal cual.
 * - Si devolvio "ok", el CIF/NIF se normaliza y se REVALIDA aqui con
 *   Identificador_Fiscal_Validator (nunca se fia del dato recibido) y se busca
 *   con Farmacia_Repository::find_by_cif(): asignada o cif_sin_farmacia.
 * - El cruce es solo por CIF/NIF, nunca por nombre.
 *
 * Sin hooks ni endpoint: lo llamara el endpoint de subida (otra tarea). No
 * decide visibilidad: eso sigue siendo Permissions.
 */
class Factura_Cruce_Service {

	public const ASIGNADA           = 'asignada';
	public const SIN_TEXTO          = 'sin_texto';
	public const FORMATO_INESPERADO = 'formato_inesperado';
	public const NUMERO_DISCREPANTE = 'numero_discrepante';
	public const SIN_CIF            = 'sin_cif';
	public const CIF_INVALIDO       = 'cif_invalido';
	public const CIF_SIN_FARMACIA   = 'cif_sin_farmacia';

	/** Excepciones que el parser puede devolver y que se pasan sin tocar. */
	private const EXCEPCIONES_PARSER = array(
		self::SIN_TEXTO,
		self::FORMATO_INESPERADO,
		self::NUMERO_DISCREPANTE,
		self::SIN_CIF,
		self::CIF_INVALIDO,
	);

	private Farmacia_Repository $repository;

	public function __construct( ?Farmacia_Repository $repository = null ) {
		$this->repository = $repository ?? new Farmacia_Repository();
	}

	/**
	 * @param array<string, mixed> $resultado Salida del parser (una factura).
	 * @return array{
	 *     estado: string, motivo: string, farmacia_id: ?int, cif: ?string,
	 *     serie: ?string, numero: ?string, fecha: ?string,
	 *     importe_total_centimos: ?int, es_abono: bool
	 * }
	 */
	public function cruzar( array $resultado ): array {
		$cruce = array(
			'estado'                 => '',
			'motivo'                 => '',
			'farmacia_id'            => null,
			'cif'                    => isset( $resultado['cif'] ) && is_string( $resultado['cif'] )
				? Identificador_Fiscal_Validator::normalizar( $resultado['cif'] )
				: null,
			'serie'                  => isset( $resultado['serie'] ) ? (string) $resultado['serie'] : null,
			'numero'                 => isset( $resultado['numero'] ) ? (string) $resultado['numero'] : null,
			'fecha'                  => isset( $resultado['fecha'] ) ? (string) $resultado['fecha'] : null,
			'importe_total_centimos' => isset( $resultado['importe_total_centimos'] ) && is_int( $resultado['importe_total_centimos'] )
				? $resultado['importe_total_centimos']
				: null,
			'es_abono'               => ! empty( $resultado['es_abono'] ),
		);

		$estado = isset( $resultado['estado'] ) ? (string) $resultado['estado'] : '';
		$motivo = isset( $resultado['motivo'] ) ? (string) $resultado['motivo'] : '';

		if ( in_array( $estado, self::EXCEPCIONES_PARSER, true ) ) {
			return array_merge( $cruce, array( 'estado' => $estado, 'motivo' => $motivo ) );
		}

		if ( 'ok' !== $estado ) {
			return array_merge(
				$cruce,
				array(
					'estado' => self::FORMATO_INESPERADO,
					'motivo' => 'Resultado del parser no reconocido.',
				)
			);
		}

		// Una factura "ok" trae siempre numero, fecha e importe: si falta alguno,
		// o no tiene la forma esperada, no se asigna.
		$fecha = null === $cruce['fecha'] ? false : \DateTimeImmutable::createFromFormat( '!Y-m-d', $cruce['fecha'] );

		if ( null === $cruce['serie'] || ! preg_match( '/^[A-Z]$/', $cruce['serie'] )
			|| null === $cruce['numero'] || ! preg_match( '/^\d+$/', $cruce['numero'] )
			|| ! $fecha || $fecha->format( 'Y-m-d' ) !== $cruce['fecha'] || ! Documento_Service::fecha_documento_valida( $cruce['fecha'] )
			|| null === $cruce['importe_total_centimos'] ) {
			return array_merge(
				$cruce,
				array(
					'estado' => self::FORMATO_INESPERADO,
					'motivo' => 'Faltan el numero, la fecha o el importe de la factura, o no tienen el formato o el rango esperado.',
				)
			);
		}

		if ( null === $cruce['cif'] || '' === $cruce['cif'] ) {
			return array_merge(
				$cruce,
				array(
					'estado' => self::SIN_CIF,
					'motivo' => 'El parser no devolvio CIF/NIF del cliente.',
				)
			);
		}

		if ( ! Identificador_Fiscal_Validator::is_valid( $cruce['cif'] ) ) {
			return array_merge(
				$cruce,
				array(
					'estado' => self::CIF_INVALIDO,
					'motivo' => 'El CIF/NIF del cliente no es valido (formato o digito/letra de control).',
				)
			);
		}

		$farmacia = $this->repository->find_by_cif( $cruce['cif'] );

		if ( ! $farmacia ) {
			return array_merge(
				$cruce,
				array(
					'estado' => self::CIF_SIN_FARMACIA,
					'motivo' => 'Ninguna farmacia dada de alta tiene este CIF/NIF.',
				)
			);
		}

		return array_merge(
			$cruce,
			array(
				'estado'      => self::ASIGNADA,
				'farmacia_id' => $farmacia->get_id(),
			)
		);
	}
}
