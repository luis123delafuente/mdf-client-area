<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filtros de las listas paginadas de documentos del backoffice (#319):
 * farmacia, nombre y pagina. Lo usan "Pendientes de publicar" y "Documentos
 * publicados", y los manejadores que vuelven a ellas tras una accion, para
 * que el saneado este en un solo sitio.
 *
 * - farmacia_id: (int) con minimo 0 (0 = todas). No absint(): un valor
 *   negativo no debe convertirse en el id de otra farmacia.
 * - q: sanitize_text_field() recortado a MAX_NOMBRE. En SQL se busca como
 *   texto literal (esc_like en Documento_Repository).
 * - paged: (int) con minimo 1; quien pagina lo limita a la ultima pagina.
 *
 * Solo lectura de la peticion: no decide nada ni toca la base de datos.
 */
final class Filtro_Listado_Documentos {

	public const MAX_NOMBRE      = 100;
	public const PREFIJO_RETORNO = 'volver_';

	public int $farmacia_id;
	public string $q;
	public int $pagina;

	private function __construct( int $farmacia_id, string $q, int $pagina ) {
		$this->farmacia_id = $farmacia_id;
		$this->q           = $q;
		$this->pagina      = $pagina;
	}

	/**
	 * @param array<string, mixed> $fuente $_GET o $_POST tal cual (con barras).
	 */
	public static function desde( array $fuente ): self {
		$farmacia = isset( $fuente['farmacia_id'] ) && is_scalar( $fuente['farmacia_id'] ) ? max( 0, (int) wp_unslash( $fuente['farmacia_id'] ) ) : 0;
		$pagina   = isset( $fuente['paged'] ) && is_scalar( $fuente['paged'] ) ? max( 1, (int) wp_unslash( $fuente['paged'] ) ) : 1;
		$q        = isset( $fuente['q'] ) && is_scalar( $fuente['q'] ) ? sanitize_text_field( wp_unslash( (string) $fuente['q'] ) ) : '';

		return new self( $farmacia, mb_substr( trim( $q ), 0, self::MAX_NOMBRE ), $pagina );
	}

	/**
	 * Los filtros con los que volver a la lista tras una accion (POST), en
	 * campos "volver_*": asi no chocan con los campos de la propia accion
	 * (p. ej. el farmacia_id de "Publicar todos los de esta farmacia").
	 *
	 * @param array<string, mixed> $fuente $_POST tal cual.
	 */
	public static function desde_retorno( array $fuente ): self {
		$campos = array();

		foreach ( array( 'farmacia_id', 'q', 'paged' ) as $clave ) {
			if ( isset( $fuente[ self::PREFIJO_RETORNO . $clave ] ) ) {
				$campos[ $clave ] = $fuente[ self::PREFIJO_RETORNO . $clave ];
			}
		}

		return self::desde( $campos );
	}

	/**
	 * Campos ocultos para los formularios de accion de la lista (ver
	 * desde_retorno()).
	 *
	 * @return array<string, string>
	 */
	public function campos_retorno(): array {
		$campos = array();

		foreach ( $this->args() as $clave => $valor ) {
			$campos[ self::PREFIJO_RETORNO . $clave ] = (string) $valor;
		}

		return $campos;
	}

	/** La farmacia elegida, o null si no se filtra por farmacia. */
	public function farmacia(): ?int {
		return $this->farmacia_id > 0 ? $this->farmacia_id : null;
	}

	public function hay_filtros(): bool {
		return null !== $this->farmacia() || '' !== $this->q;
	}

	/** Una copia en otra pagina. */
	public function en_pagina( int $pagina ): self {
		return new self( $this->farmacia_id, $this->q, max( 1, $pagina ) );
	}

	/**
	 * Argumentos de URL, sin los vacios. La pagina 1 no se escribe.
	 *
	 * @return array<string, int|string>
	 */
	public function args( bool $con_pagina = true ): array {
		$args = array();

		if ( null !== $this->farmacia() ) {
			$args['farmacia_id'] = $this->farmacia_id;
		}

		if ( '' !== $this->q ) {
			$args['q'] = $this->q;
		}

		if ( $con_pagina && $this->pagina > 1 ) {
			$args['paged'] = $this->pagina;
		}

		return $args;
	}
}
