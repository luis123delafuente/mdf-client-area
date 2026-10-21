<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Una herramienta del catalogo ya persistida, con sus relaciones resueltas:
 * slugs de los planes que la ven, ids de las farmacias concretas que la ven
 * y de sus etiquetas. Objeto de solo lectura, igual que Farmacia y Plan:
 * cualquier cambio pasa por Catalogo_Repository / Catalogo_Service.
 *
 * planes_slugs viaja ya resuelto porque es la forma que necesita
 * Permissions::puede_ver_item_catalogo(). farmacia_ids solo lo lee
 * Permissions y el backoffice: nunca se pinta en el area de cliente.
 *
 * Desde el esquema 1.21.0 el catalogo es solo Herramientas: get_seccion()
 * queda para la migracion de los elementos de Formacion.
 */
class Catalogo_Item {

	public const RECURSO_ENLACE  = 'enlace';
	public const RECURSO_ARCHIVO = 'archivo';

	private int $id;
	private string $seccion;
	private string $nombre;
	private ?string $descripcion;
	private ?int $categoria_id;
	private string $recurso;
	private ?string $enlace_url;
	private ?string $formato;
	private ?string $ruta_fichero;
	private ?string $tipo_mime;
	private ?int $tamano;
	private bool $descargable;
	private bool $marca_agua;
	private bool $visible;
	private string $fecha_creacion;
	/** @var string[] */
	private array $planes_slugs;
	/** @var int[] */
	private array $farmacia_ids;
	/** @var int[] */
	private array $etiqueta_ids;

	/**
	 * @param string[] $planes_slugs
	 * @param int[]    $farmacia_ids
	 * @param int[]    $etiqueta_ids
	 */
	public static function from_db_row( object $row, array $planes_slugs, array $farmacia_ids = array(), array $etiqueta_ids = array() ): self {
		$item = new self();

		$item->id             = (int) $row->id;
		$item->seccion        = (string) $row->seccion;
		$item->nombre         = (string) $row->nombre;
		$item->descripcion    = isset( $row->descripcion ) && '' !== $row->descripcion ? (string) $row->descripcion : null;
		$item->categoria_id   = isset( $row->categoria_id ) ? (int) $row->categoria_id : null;
		$item->recurso        = self::RECURSO_ARCHIVO === ( $row->recurso ?? '' ) ? self::RECURSO_ARCHIVO : self::RECURSO_ENLACE;
		$item->enlace_url     = $row->enlace_url ?: null;
		$item->formato        = isset( $row->formato ) && '' !== $row->formato ? (string) $row->formato : null;
		$item->ruta_fichero   = isset( $row->ruta_fichero ) && '' !== $row->ruta_fichero ? (string) $row->ruta_fichero : null;
		$item->tipo_mime      = isset( $row->tipo_mime ) && '' !== $row->tipo_mime ? (string) $row->tipo_mime : null;
		$item->tamano         = isset( $row->tamano ) ? (int) $row->tamano : null;
		$item->descargable    = ! empty( $row->descargable );
		$item->marca_agua     = ! isset( $row->marca_agua ) || ! empty( $row->marca_agua );
		$item->visible        = ! isset( $row->visible ) || ! empty( $row->visible );
		$item->fecha_creacion = (string) $row->fecha_creacion;
		$item->planes_slugs   = $planes_slugs;
		$item->farmacia_ids   = array_map( 'intval', $farmacia_ids );
		$item->etiqueta_ids   = array_map( 'intval', $etiqueta_ids );

		return $item;
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

	public function get_descripcion(): ?string {
		return $this->descripcion;
	}

	public function get_categoria_id(): ?int {
		return $this->categoria_id;
	}

	public function get_recurso(): string {
		return $this->recurso;
	}

	public function es_archivo(): bool {
		return self::RECURSO_ARCHIVO === $this->recurso;
	}

	public function get_enlace_url(): ?string {
		return $this->enlace_url;
	}

	/** Catalogo_Formatos::*, solo si es un archivo. */
	public function get_formato(): ?string {
		return $this->formato;
	}

	public function get_ruta_fichero(): ?string {
		return $this->ruta_fichero;
	}

	public function get_tipo_mime(): ?string {
		return $this->tipo_mime;
	}

	public function get_tamano(): ?int {
		return $this->tamano;
	}

	/** Lo guardado: Permissions decide ademas por el formato (Word, PowerPoint y xlsm siempre se descargan). */
	public function is_descargable(): bool {
		return $this->descargable;
	}

	/** Lo guardado: el visor pinta la marca solo si esto es true. */
	public function con_marca_de_agua(): bool {
		return $this->marca_agua;
	}

	/** Estado: una oculta no la ve ninguna farmacia. */
	public function is_visible(): bool {
		return $this->visible;
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

	/**
	 * @return int[]
	 */
	public function get_farmacia_ids(): array {
		return $this->farmacia_ids;
	}

	/**
	 * @return int[]
	 */
	public function get_etiqueta_ids(): array {
		return $this->etiqueta_ids;
	}

	/**
	 * El recurso esta completo: un enlace con URL o un archivo con su
	 * fichero y un formato conocido. Una herramienta incompleta (p. ej. un
	 * elemento antiguo sin enlace) no ofrece ningun boton y el backoffice lo
	 * avisa.
	 */
	public function recurso_completo(): bool {
		return $this->es_archivo()
			? null !== $this->ruta_fichero && Catalogo_Formatos::es_valido( $this->formato )
			: null !== $this->enlace_url;
	}
}
