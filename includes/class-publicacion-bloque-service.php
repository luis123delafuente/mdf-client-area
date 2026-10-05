<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Publicar todos los pendientes" (#316): publicar de una vez los documentos
 * pendientes de publicar, opcionalmente acotados por tipo y por fecha de
 * recepcion. Lo usa Admin_Publicacion_Documentos en dos pasos:
 *
 * 1. calcular(): el conjunto EXACTO de ids publicables (pendientes, dentro
 *    del filtro, con su farmacia existente) y su huella. Es lo que se ensena
 *    en la pantalla de confirmacion.
 * 2. aplicar(): recalcula el conjunto con los mismos filtros (guardados en
 *    servidor, nunca leidos del formulario) y solo escribe si la huella
 *    coincide; publica por la lista de ids, no por el filtro. Un documento
 *    que llegue o cambie entre los dos pasos y entre en el filtro cambia la
 *    huella: no se escribe nada y hay que volver a confirmar. Uno que llegue
 *    despues del recalculo no esta en la lista y no se publica.
 *
 * Los eventos de publicacion (#318, origen "bloque", un evento por documento
 * publicado) se escriben en esa misma transaccion.
 *
 * Cada publicacion en bloque crea un LOTE (#317, Publicacion_Lote_Repository)
 * con quien, cuando, cuantos y con que filtros, y deja su id en los
 * documentos que publica, en la MISMA transaccion: o se crea el lote y se
 * publican los documentos, o no ocurre nada. Un lote se puede deshacer
 * despues (Despublicacion_Lote_Service).
 *
 * Solo cambia publicado de 0 a 1. No decide visibilidad (eso sigue siendo
 * Permissions) ni toca los avisos: lo publicado pasa a ser candidato al
 * aviso diario como cualquier otro documento publicado.
 *
 * Una farmacia sin cuenta activa se publica igual (lo vera al activarla).
 * Un documento cuya farmacia ya no existe nunca se publica: se cuenta aparte.
 */
class Publicacion_Bloque_Service {

	public const ERROR_CAMBIADO = 'mdf_ca_publicacion_bloque_cambiada';

	private Documento_Repository $repository;
	private Publicacion_Lote_Repository $lotes;

	public function __construct( ?Documento_Repository $repository = null, ?Publicacion_Lote_Repository $lotes = null ) {
		$this->repository = $repository ?? new Documento_Repository();
		$this->lotes      = $lotes ?? new Publicacion_Lote_Repository();
	}

	/**
	 * Filtros saneados, o error si alguno no es valido. Un campo vacio es
	 * "sin acotar".
	 *
	 * @param mixed $tipo  Clave de Documento_Tipos o ''.
	 * @param mixed $desde 'Y-m-d' o ''.
	 * @param mixed $hasta 'Y-m-d' o ''.
	 * @return array{tipo: ?string, desde: ?string, hasta: ?string}|\WP_Error
	 */
	public static function normalizar_filtros( $tipo, $desde, $hasta ) {
		$tipo  = is_string( $tipo ) ? trim( $tipo ) : '';
		$desde = is_string( $desde ) ? trim( $desde ) : '';
		$hasta = is_string( $hasta ) ? trim( $hasta ) : '';

		if ( '' !== $tipo && ! Documento_Tipos::es_valido( $tipo ) ) {
			return new \WP_Error( 'mdf_ca_publicacion_bloque_filtro', 'El tipo de documento no es válido.' );
		}

		foreach ( array( $desde, $hasta ) as $fecha ) {
			if ( '' !== $fecha && ! self::fecha_valida( $fecha ) ) {
				return new \WP_Error( 'mdf_ca_publicacion_bloque_filtro', 'Alguna de las fechas no es válida.' );
			}
		}

		if ( '' !== $desde && '' !== $hasta && $desde > $hasta ) {
			return new \WP_Error( 'mdf_ca_publicacion_bloque_filtro', 'La fecha «desde» no puede ser posterior a la fecha «hasta».' );
		}

		return array(
			'tipo'  => '' !== $tipo ? $tipo : null,
			'desde' => '' !== $desde ? $desde : null,
			'hasta' => '' !== $hasta ? $hasta : null,
		);
	}

	/**
	 * El conjunto que se publicaria con estos filtros (ya normalizados).
	 *
	 * @param array{tipo: ?string, desde: ?string, hasta: ?string} $filtros
	 * @return array{
	 *     ids: int[], documentos: int, farmacias: int, excluidos: int,
	 *     recibido_desde: ?string, recibido_hasta: ?string, huella: string
	 * }|\WP_Error
	 */
	public function calcular( array $filtros ) {
		$filas = $this->repository->find_pendientes_para_bloque( $filtros['tipo'], $filtros['desde'], $filtros['hasta'] );

		if ( null === $filas ) {
			return new \WP_Error( 'mdf_ca_publicacion_bloque_error_bd', 'No se pudieron leer los documentos pendientes. No se ha publicado nada.' );
		}

		$ids       = array();
		$farmacias = array();
		$excluidos = 0;
		$fechas    = array();

		foreach ( $filas as $fila ) {
			if ( ! $fila['existe'] ) {
				++$excluidos;
				continue;
			}

			$ids[]                            = $fila['id'];
			$farmacias[ $fila['farmacia_id'] ] = true;
			$fechas[]                         = $fila['fecha_subida'];
		}

		// Los ids llegan en orden ascendente (ORDER BY id): la huella no
		// depende del orden de la consulta.
		sort( $ids );

		return array(
			'ids'            => $ids,
			'documentos'     => count( $ids ),
			'farmacias'      => count( $farmacias ),
			'excluidos'      => $excluidos,
			'recibido_desde' => $fechas ? min( $fechas ) : null,
			'recibido_hasta' => $fechas ? max( $fechas ) : null,
			'huella'         => self::huella( $ids ),
		);
	}

	/**
	 * Publica exactamente el conjunto confirmado. Si el conjunto actual no es
	 * el de la huella, no escribe nada (ERROR_CAMBIADO).
	 *
	 * @param array{tipo: ?string, desde: ?string, hasta: ?string} $filtros
	 * @param int $usuario_id Quien publica (queda en el lote).
	 * @return array{publicados: int, farmacias: int, excluidos: int, ya_publicados: int, lote_id: ?int}|\WP_Error
	 */
	public function aplicar( array $filtros, string $huella_confirmada, int $usuario_id ) {
		global $wpdb;

		if ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_lotes_table_name() )
			|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) {
			return new \WP_Error(
				'mdf_ca_publicacion_bloque_sin_transacciones',
				'Las tablas de documentos, de lotes o de eventos no admiten transacciones (no son InnoDB). No se ha publicado nada.'
			);
		}

		$actual = $this->calcular( $filtros );

		if ( is_wp_error( $actual ) ) {
			return $actual;
		}

		if ( ! hash_equals( $actual['huella'], $huella_confirmada ) ) {
			return new \WP_Error(
				self::ERROR_CAMBIADO,
				'Los documentos pendientes han cambiado desde que preparaste la publicación (ha llegado, se ha publicado o se ha despublicado alguno). No se ha publicado nada: revisa la confirmación actualizada.'
			);
		}

		$resumen = array(
			'publicados'    => 0,
			'farmacias'     => $actual['farmacias'],
			'excluidos'     => $actual['excluidos'],
			'ya_publicados' => 0,
			'lote_id'       => null,
		);

		if ( ! $actual['ids'] ) {
			return $resumen;
		}

		// Lote y documentos, juntos o nada: sin lote huerfano ni documentos
		// publicados sin lote.
		$error = new \WP_Error( 'mdf_ca_publicacion_bloque_error_bd', 'No se pudieron publicar los documentos. No se ha publicado ninguno.' );

		$wpdb->query( 'START TRANSACTION' );

		$lote_id    = $this->lotes->crear( $usuario_id, $filtros );
		$publicados = false === $lote_id ? false : $this->repository->publicar_ids( $actual['ids'], $lote_id, $usuario_id );

		if ( false === $publicados || ! $this->lotes->actualizar_documentos( (int) $lote_id, $publicados ) ) {
			$wpdb->query( 'ROLLBACK' );

			return $error;
		}

		// Todos se publicaron por otra via en el ultimo instante: ningun lote vacio.
		if ( 0 === $publicados ) {
			$wpdb->query( 'ROLLBACK' );
			$resumen['ya_publicados'] = $actual['documentos'];

			return $resumen;
		}

		$wpdb->query( 'COMMIT' );

		Recepcion_Registro::registrar( Recepcion_Registro::PUBLICADO_BLOQUE, $publicados );

		$resumen['publicados'] = $publicados;
		$resumen['lote_id']    = (int) $lote_id;
		// Publicados uno a uno en el instante entre el recalculo y el UPDATE.
		$resumen['ya_publicados'] = $actual['documentos'] - $publicados;

		return $resumen;
	}

	/** @param int[] $ids Ordenados. */
	private static function huella( array $ids ): string {
		return hash( 'sha256', implode( ',', $ids ) );
	}

	private static function fecha_valida( string $fecha ): bool {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fecha );

		return $d && $d->format( 'Y-m-d' ) === $fecha;
	}
}
