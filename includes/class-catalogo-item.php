<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Representa un item de catalogo (Herramientas o Formacion) ya persistido,
 * con los slugs de plan que lo hacen visible ya resueltos. Objeto de solo
 * lectura, igual que Farmacia y Plan: cualquier cambio pasa por
 * Catalogo_Repository / Catalogo_Service.
 *
 * planes_slugs viaja ya resuelto (no un plan_id[] crudo) porque es
 * exactamente la forma que Permissions::puede_ver_item_catalogo() necesita
 * pasar a puede_ver_bloque_por_plan() -- mismo contrato que ya tenia el
 * array placeholder de Catalogo_Herramientas en Fase 2 ('planes' =>
 * string[]), solo que ahora Catalogo_Repository lo rellena desde la tabla
 * puente wp_mdf_ca_catalogo_planes en vez de venir hardcodeado.
 */
class Catalogo_Item {

	private int $id;
	private string $seccion;
	private string $nombre;
	private string $tipo;
	private ?string $enlace_url;
	private string $fecha_creacion;
	/** @var string[] */
	private array $planes_slugs;

	/**
	 * @param string[] $planes_slugs
	 */
	public function __construct(
		int $id,
		string $seccion,
		string $nombre,
		string $tipo,
		?string $enlace_url,
		string $fecha_creacion,
		array $planes_slugs
	) {
		$this->id             = $id;
		$this->seccion        = $seccion;
		$this->nombre         = $nombre;
		$this->tipo           = $tipo;
		$this->enlace_url     = $enlace_url;
		$this->fecha_creacion = $fecha_creacion;
		$this->planes_slugs   = $planes_slugs;
	}

	/**
	 * @param string[] $planes_slugs
	 */
	public static function from_db_row( object $row, array $planes_slugs ): self {
		return new self(
			(int) $row->id,
			$row->seccion,
			$row->nombre,
			$row->tipo,
			$row->enlace_url ?: null,
			$row->fecha_creacion,
			$planes_slugs
		);
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_seccion(): string {
		return $this->seccion;
	}

	public function get_nombre(): string {
		return $this->nombre;
	}

	public function get_tipo(): string {
		return $this->tipo;
	}

	public function get_enlace_url(): ?string {
		return $this->enlace_url;
	}

	public function get_fecha_creacion(): string {
		return $this->fecha_creacion;
	}

	/**
	 * @return string[]
	 */
	public function get_planes_slugs(): array {
		return $this->planes_slugs;
	}
}
