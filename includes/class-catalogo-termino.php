<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Una categoria o una etiqueta del catalogo de herramientas: las dos tienen
 * la misma forma (nombre editable y slug inmutable, fijado al crear, igual
 * que los planes). El slug es lo que el HTML del area de cliente expone en
 * data-categoria / data-etiquetas, para que los filtros que MKT anada
 * despues no se rompan al renombrar. Objeto de solo lectura.
 */
class Catalogo_Termino {

	private int $id;
	private string $nombre;
	private string $slug;

	public function __construct( int $id, string $nombre, string $slug ) {
		$this->id     = $id;
		$this->nombre = $nombre;
		$this->slug   = $slug;
	}

	public static function from_db_row( object $row ): self {
		return new self( (int) $row->id, (string) $row->nombre, (string) $row->slug );
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
}
