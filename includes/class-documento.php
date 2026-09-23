<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Representa un documento ya persistido. Objeto de solo lectura, igual que
 * Farmacia: cualquier cambio de estado pasa por Documento_Repository.
 *
 * ruta_fichero es la ruta interna del fichero dentro de la carpeta privada
 * (fuera del docroot publico, ver CLAUDE.md), nunca una URL servible
 * directamente: eso lo decide en su momento el script de servido (#232).
 *
 * tipo_documento es la etiqueta de Documento_Tipos (contrato, factura,
 * presupuesto, entregable, otro), nullable: los documentos de prueba de
 * fases anteriores a la subida desde backoffice (#246) no la tienen, y no
 * se les exige backfill (mismo criterio que plan_id en Farmacia).
 */
class Documento {

	private int $id;
	private int $farmacia_id;
	private string $nombre;
	private ?string $tipo_documento;
	private string $ruta_fichero;
	private ?string $tipo_mime;
	private ?int $tamano_bytes;
	private string $fecha_subida;

	public function __construct(
		int $id,
		int $farmacia_id,
		string $nombre,
		?string $tipo_documento,
		string $ruta_fichero,
		?string $tipo_mime,
		?int $tamano_bytes,
		string $fecha_subida
	) {
		$this->id             = $id;
		$this->farmacia_id    = $farmacia_id;
		$this->nombre         = $nombre;
		$this->tipo_documento = $tipo_documento;
		$this->ruta_fichero   = $ruta_fichero;
		$this->tipo_mime      = $tipo_mime;
		$this->tamano_bytes   = $tamano_bytes;
		$this->fecha_subida   = $fecha_subida;
	}

	public static function from_db_row( object $row ): self {
		return new self(
			(int) $row->id,
			(int) $row->farmacia_id,
			$row->nombre,
			$row->tipo_documento ?? null,
			$row->ruta_fichero,
			$row->tipo_mime,
			null !== $row->tamano_bytes ? (int) $row->tamano_bytes : null,
			$row->fecha_subida
		);
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_farmacia_id(): int {
		return $this->farmacia_id;
	}

	public function get_nombre(): string {
		return $this->nombre;
	}

	public function get_tipo_documento(): ?string {
		return $this->tipo_documento;
	}

	public function get_ruta_fichero(): string {
		return $this->ruta_fichero;
	}

	public function get_tipo_mime(): ?string {
		return $this->tipo_mime;
	}

	public function get_tamano_bytes(): ?int {
		return $this->tamano_bytes;
	}

	public function get_fecha_subida(): string {
		return $this->fecha_subida;
	}
}
