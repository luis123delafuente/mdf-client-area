<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Estado de un presupuesto (v0.44.0, esquema 1.23.0): solo lectura, sale de
 * la fila del documento (columnas presupuesto_*). Unico sitio de los estados,
 * las vias y sus textos.
 *
 * - pendiente: la farmacia puede aceptarlo o rechazarlo desde el visor.
 * - aceptado / rechazado: la farmacia ya no puede cambiarlo; MDF si
 *   (Presupuesto_Service::cambiar_por_mdf()), a pendiente o aceptado.
 * - via "area": lo hizo la farmacia en el visor; "mdf": lo puso MDF (al
 *   subirlo o despues), p. ej. un presupuesto aceptado por otra via.
 */
class Presupuesto_Estado {

	public const TIPO_DOCUMENTO = 'presupuesto';

	public const PENDIENTE = 'pendiente';
	public const ACEPTADO  = 'aceptado';
	public const RECHAZADO = 'rechazado';

	public const VIA_AREA = 'area';
	public const VIA_MDF  = 'mdf';

	/** Motivo del rechazo, obligatorio y de como mucho estos caracteres. */
	public const MAX_MOTIVO = 1000;

	private const ETIQUETAS = array(
		self::PENDIENTE => 'Pendiente',
		self::ACEPTADO  => 'Aceptado',
		self::RECHAZADO => 'Rechazado',
	);

	private string $estado;
	private ?string $cambiado_en;
	private ?int $cambiado_por;
	private ?string $via;
	private ?string $motivo;
	private ?string $hash;
	private bool $aviso_pendiente;

	private function __construct() {}

	/** Null si la fila no es un presupuesto. Sin estado guardado, pendiente. */
	public static function from_db_row( object $row ): ?self {
		if ( self::TIPO_DOCUMENTO !== ( $row->tipo_documento ?? null ) ) {
			return null;
		}

		$estado = new self();

		$estado->estado          = self::es_valido( (string) ( $row->presupuesto_estado ?? '' ) ) ? (string) $row->presupuesto_estado : self::PENDIENTE;
		$estado->cambiado_en     = ! empty( $row->presupuesto_cambiado_en ) ? (string) $row->presupuesto_cambiado_en : null;
		$estado->cambiado_por    = ! empty( $row->presupuesto_cambiado_por ) ? (int) $row->presupuesto_cambiado_por : null;
		$estado->via             = in_array( $row->presupuesto_via ?? null, array( self::VIA_AREA, self::VIA_MDF ), true ) ? (string) $row->presupuesto_via : null;
		$estado->motivo          = isset( $row->presupuesto_motivo ) && '' !== $row->presupuesto_motivo ? (string) $row->presupuesto_motivo : null;
		$estado->hash            = ! empty( $row->presupuesto_hash ) ? (string) $row->presupuesto_hash : null;
		$estado->aviso_pendiente = ! empty( $row->presupuesto_aviso_pendiente );

		return $estado;
	}

	public static function es_valido( string $estado ): bool {
		return isset( self::ETIQUETAS[ $estado ] );
	}

	/** Lo que MDF puede elegir al subir o cambiar un presupuesto (no "rechazado"). */
	public static function opciones_mdf(): array {
		return array(
			self::PENDIENTE => self::ETIQUETAS[ self::PENDIENTE ],
			self::ACEPTADO  => self::ETIQUETAS[ self::ACEPTADO ],
		);
	}

	public static function etiqueta_de( string $estado ): string {
		return self::ETIQUETAS[ $estado ] ?? $estado;
	}

	public function get_estado(): string {
		return $this->estado;
	}

	public function get_etiqueta(): string {
		return self::etiqueta_de( $this->estado );
	}

	public function es_pendiente(): bool {
		return self::PENDIENTE === $this->estado;
	}

	/** UTC, 'Y-m-d H:i:s'. */
	public function get_cambiado_en(): ?string {
		return $this->cambiado_en;
	}

	public function get_cambiado_por(): ?int {
		return $this->cambiado_por;
	}

	public function get_via(): ?string {
		return $this->via;
	}

	public function es_de_la_farmacia(): bool {
		return self::VIA_AREA === $this->via;
	}

	public function get_motivo(): ?string {
		return $this->motivo;
	}

	public function get_hash(): ?string {
		return $this->hash;
	}

	public function tiene_aviso_pendiente(): bool {
		return $this->aviso_pendiente;
	}

	/** Fecha del cambio en la zona de WordPress, "26/10/2026", o null. */
	public function fecha_cambio(): ?string {
		return $this->cambiado_en ? get_date_from_gmt( $this->cambiado_en, 'd/m/Y' ) : null;
	}

	/**
	 * Texto para la farmacia: "Aceptado el 26/10/2026" si lo hizo ella en el
	 * area; "Aceptado" si lo puso MDF (la fecha seria la de MDF, no la de la
	 * aceptacion real).
	 */
	public function texto_para_farmacia(): string {
		if ( ! $this->es_pendiente() && $this->es_de_la_farmacia() && $this->fecha_cambio() ) {
			return $this->get_etiqueta() . ' el ' . $this->fecha_cambio();
		}

		return $this->get_etiqueta();
	}
}
