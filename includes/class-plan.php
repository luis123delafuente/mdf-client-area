<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Representa un plan ya persistido. Objeto de solo lectura, igual que
 * Farmacia: cualquier cambio pasa por Plan_Repository.
 *
 * slug es el identificador estable que usa Permissions para decidir
 * visibilidad; nombre es la etiqueta editable en el backoffice. Se
 * mantienen separados a proposito: Plan_Service fija el slug al crear el
 * plan y no lo vuelve a tocar en una edicion posterior, para no romper en
 * silencio un [mdf_ca_si_plan planes="..."] o un item de catalogo ya
 * publicado por MKT solo porque un administrador retoco el nombre.
 */
class Plan {

	private int $id;
	private string $nombre;
	private string $slug;
	private string $fecha_creacion;

	public function __construct( int $id, string $nombre, string $slug, string $fecha_creacion ) {
		$this->id             = $id;
		$this->nombre         = $nombre;
		$this->slug           = $slug;
		$this->fecha_creacion = $fecha_creacion;
	}

	public static function from_db_row( object $row ): self {
		return new self(
			(int) $row->id,
			$row->nombre,
			$row->slug,
			$row->fecha_creacion
		);
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_nombre(): string {
		return $this->nombre;
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_fecha_creacion(): string {
		return $this->fecha_creacion;
	}
}
