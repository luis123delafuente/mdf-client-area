<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Representa una farmacia ya persistida. Objeto de solo lectura: cualquier
 * cambio de estado pasa por Farmacia_Repository, no por mutar esta instancia.
 *
 * wp_user_id es nullable y no se asume relacion 1:1 con el usuario de
 * WordPress: hoy el alta siempre vincula un usuario, pero el modelo deja
 * espacio para farmacias sin usuario asociado todavia (p. ej. import CSV
 * de fase 4) sin tener que rehacer esta clase.
 *
 * plan es un simple VARCHAR nullable, no una relacion a una entidad real
 * todavia (ver CLAUDE.md: Planes es entidad de datos a partir de Fase 3).
 * Sin plan asignado, esta clase no decide nada por su cuenta: es
 * Permissions::puede_ver_item_catalogo() quien trata "sin plan" como "no
 * ve nada de catalogo", igual que "sin farmacia" en el resto del plugin.
 */
class Farmacia {

	private int $id;
	private string $cif;
	private string $nombre;
	private ?int $wp_user_id;
	private ?string $plan;
	private string $fecha_alta;

	public function __construct( int $id, string $cif, string $nombre, ?int $wp_user_id, ?string $plan, string $fecha_alta ) {
		$this->id         = $id;
		$this->cif        = $cif;
		$this->nombre     = $nombre;
		$this->wp_user_id = $wp_user_id;
		$this->plan       = $plan;
		$this->fecha_alta = $fecha_alta;
	}

	public static function from_db_row( object $row ): self {
		return new self(
			(int) $row->id,
			$row->cif,
			$row->nombre,
			null !== $row->wp_user_id ? (int) $row->wp_user_id : null,
			$row->plan ?? null,
			$row->fecha_alta
		);
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_cif(): string {
		return $this->cif;
	}

	public function get_nombre(): string {
		return $this->nombre;
	}

	public function get_wp_user_id(): ?int {
		return $this->wp_user_id;
	}

	public function get_plan(): ?string {
		return $this->plan;
	}

	public function get_fecha_alta(): string {
		return $this->fecha_alta;
	}
}
