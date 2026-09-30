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
 * plan_id es una referencia (sin FK real, ver DB_Schema) a wp_mdf_ca_planes,
 * nullable: una farmacia puede no tener plan asignado. Esta clase no
 * resuelve el plan en si (ni su slug ni su nombre): es
 * Permissions::puede_ver_item_catalogo() quien trata "sin plan" o "plan_id
 * que ya no resuelve a ningun plan" como "no ve nada de catalogo", igual que
 * "sin farmacia" en el resto del plugin. Resolver plan_id contra
 * Plan_Repository fuera de Permissions violaria la regla de que las
 * comparaciones de plan viven en un unico sitio.
 */
class Farmacia {

	private int $id;
	private string $cif;
	private string $nombre;
	private ?int $wp_user_id;
	private ?int $plan_id;
	private string $fecha_alta;

	public function __construct( int $id, string $cif, string $nombre, ?int $wp_user_id, ?int $plan_id, string $fecha_alta ) {
		$this->id         = $id;
		$this->cif        = $cif;
		$this->nombre     = $nombre;
		$this->wp_user_id = $wp_user_id;
		$this->plan_id    = $plan_id;
		$this->fecha_alta = $fecha_alta;
	}

	public static function from_db_row( object $row ): self {
		return new self(
			(int) $row->id,
			$row->cif,
			$row->nombre,
			null !== $row->wp_user_id ? (int) $row->wp_user_id : null,
			null !== $row->plan_id ? (int) $row->plan_id : null,
			$row->fecha_alta
		);
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_cif(): string {
		return $this->cif;
	}

	/** Clave => etiqueta de los tipos de entidad que distingue el backoffice. */
	public const TIPOS_ENTIDAD = array(
		'persona_fisica' => 'Persona fisica',
		'sl'             => 'SL',
		'sa'             => 'SA',
		'cb'             => 'Comunidad de bienes',
		'otra'           => 'Otras entidades',
	);

	/**
	 * Tipo de entidad derivado de la forma del identificador fiscal (mismo
	 * criterio de "mirar el primer caracter" que Identificador_Fiscal_
	 * Validator): digito, o K/L/M (NIF especiales de persona fisica) =>
	 * persona fisica; A => SA; B => SL; E => comunidad de bienes; cualquier
	 * otra letra de sociedad (asociaciones, cooperativas, UTE...) => otra.
	 * No se persiste: es un dato derivable del CIF, asi que no hay esquema
	 * que migrar ni puede quedar desincronizado con el CIF.
	 */
	public function get_tipo_entidad(): string {
		$primera = '' !== $this->cif ? strtoupper( $this->cif[0] ) : '';

		if ( '' !== $primera && ( ctype_digit( $primera ) || in_array( $primera, array( 'K', 'L', 'M' ), true ) ) ) {
			return 'persona_fisica';
		}

		switch ( $primera ) {
			case 'A':
				return 'sa';
			case 'B':
				return 'sl';
			case 'E':
				return 'cb';
			default:
				return 'otra';
		}
	}

	public function get_tipo_entidad_etiqueta(): string {
		return self::TIPOS_ENTIDAD[ $this->get_tipo_entidad() ];
	}

	public function get_nombre(): string {
		return $this->nombre;
	}

	public function get_wp_user_id(): ?int {
		return $this->wp_user_id;
	}

	public function get_plan_id(): ?int {
		return $this->plan_id;
	}

	public function get_fecha_alta(): string {
		return $this->fecha_alta;
	}
}
