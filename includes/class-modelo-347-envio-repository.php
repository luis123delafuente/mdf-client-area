<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Unico acceso a la tabla de envios del Modelo 347 (#307). Una fila por
 * carta (UNIQUE documento_id). Las transiciones de estado son UPDATE
 * condicionados al estado de origen: quien no consigue cambiar la fila no
 * hace nada, asi que dos pestanas (o dos administradores) nunca envian la
 * misma carta a la vez.
 *
 *   pendiente --reclamar()--> enviando --marcar_enviado()--> enviado
 *                                      --marcar_fallido()--> fallido
 *   fallido   --reintentar_fallidos()--> pendiente
 *   cualquiera (salvo "enviando" reciente) --reenviar()--> pendiente
 *   pendiente --cancelar...()--> cancelado (lote deshecho / carta ya no publicada)
 *
 * Sin importes: documento, farmacia, destinatario, estado, intentos, fechas
 * (UTC) y error saneado.
 */
class Modelo_347_Envio_Repository {

	public const PENDIENTE = 'pendiente';
	public const ENVIANDO  = 'enviando';
	public const ENVIADO   = 'enviado';
	public const FALLIDO   = 'fallido';
	public const CANCELADO = 'cancelado';

	/**
	 * Una fila "enviando" mas antigua que esto se considera interrumpida
	 * (PHP murio entre reclamar y marcar): no se reintenta sola (el correo
	 * pudo salir) y solo "Reenviar" la vuelve a poner en cola.
	 */
	public const MINUTOS_INTERRUMPIDO = 10;

	private static function tabla(): string {
		return DB_Schema::get_modelo_347_envios_table_name();
	}

	/**
	 * Encola estas cartas dentro de la transaccion de quien publica. Una carta
	 * sin fila se inserta pendiente; una "cancelada" (lote deshecho y vuelta
	 * a publicar) vuelve a pendiente con el lote nuevo. Una ya enviada,
	 * fallida o en curso NO se toca: nunca se reenvia sin "Reenviar".
	 *
	 * @param array<int, array{farmacia_id: int, destinatario: string}> $cartas documento_id => datos
	 * @return int|false Filas encoladas, o false si fallo algo (quien llama revierte).
	 */
	public function encolar( array $cartas, int $lote_id, int $usuario_id ) {
		global $wpdb;

		if ( ! $cartas ) {
			return 0;
		}

		$tabla        = self::tabla();
		$ids          = array_map( 'intval', array_keys( $cartas ) );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$existentes   = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generados arriba.
				"SELECT documento_id, estado FROM {$tabla} WHERE documento_id IN ({$placeholders}) FOR UPDATE",
				$ids
			),
			ARRAY_A
		);

		if ( null === $existentes || '' !== $wpdb->last_error ) {
			return false;
		}

		$estado_actual = array();

		foreach ( $existentes as $fila ) {
			$estado_actual[ (int) $fila['documento_id'] ] = $fila['estado'];
		}

		$ahora     = gmdate( 'Y-m-d H:i:s' );
		$encoladas = 0;

		foreach ( $cartas as $documento_id => $carta ) {
			$documento_id = (int) $documento_id;

			if ( ! isset( $estado_actual[ $documento_id ] ) ) {
				$ok = $wpdb->insert(
					$tabla,
					array(
						'documento_id' => $documento_id,
						'farmacia_id'  => (int) $carta['farmacia_id'],
						'lote_id'      => $lote_id,
						'destinatario' => $carta['destinatario'],
						'estado'       => self::PENDIENTE,
						'creado_en'    => $ahora,
						'usuario_id'   => $usuario_id,
					),
					array( '%d', '%d', '%d', '%s', '%s', '%s', '%d' )
				);
			} elseif ( self::CANCELADO === $estado_actual[ $documento_id ] ) {
				$ok = $wpdb->update(
					$tabla,
					array(
						'estado'       => self::PENDIENTE,
						'lote_id'      => $lote_id,
						'destinatario' => $carta['destinatario'],
						'error'        => null,
						'usuario_id'   => $usuario_id,
					),
					array(
						'documento_id' => $documento_id,
						'estado'       => self::CANCELADO,
					),
					array( '%s', '%d', '%s', '%s', '%d' ),
					array( '%d', '%s' )
				);
			} else {
				continue;
			}

			if ( false === $ok ) {
				return false;
			}

			$encoladas += (int) $ok;
		}

		return $encoladas;
	}

	/**
	 * Ids de envio pendientes de esta sociedad y ejercicio, en orden.
	 *
	 * @return int[]
	 */
	public function ids_pendientes( string $emisor_cif, int $ejercicio, int $limite ): array {
		global $wpdb;

		$tabla      = self::tabla();
		$documentos = DB_Schema::get_documentos_table_name();

		return array_map(
			'intval',
			$wpdb->get_col(
				$wpdb->prepare(
					"SELECT e.id FROM {$tabla} e INNER JOIN {$documentos} d ON d.id = e.documento_id
					WHERE e.estado = %s AND d.emisor_cif = %s AND d.ejercicio = %d ORDER BY e.id LIMIT %d",
					self::PENDIENTE,
					$emisor_cif,
					$ejercicio,
					max( 1, $limite )
				)
			)
		);
	}

	/** Reclama un envio pendiente para enviarlo. true solo para quien lo consigue. */
	public function reclamar( int $envio_id ): bool {
		global $wpdb;

		$tabla = self::tabla();

		return 1 === $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tabla} SET estado = %s, intentos = intentos + 1, ultimo_intento_en = %s, error = NULL WHERE id = %d AND estado = %s",
				self::ENVIANDO,
				gmdate( 'Y-m-d H:i:s' ),
				$envio_id,
				self::PENDIENTE
			)
		);
	}

	public function find( int $envio_id ): ?object {
		global $wpdb;

		$tabla = self::tabla();
		$fila  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tabla} WHERE id = %d", $envio_id ) );

		return $fila ?: null;
	}

	public function marcar_enviado( int $envio_id, string $destinatario ): bool {
		return $this->cerrar( $envio_id, self::ENVIADO, $destinatario, null );
	}

	public function marcar_fallido( int $envio_id, string $destinatario, string $error ): bool {
		return $this->cerrar( $envio_id, self::FALLIDO, $destinatario, $error );
	}

	/** Carta que ya no se puede enviar (despublicada, retirada o sin cuenta). */
	public function marcar_cancelado( int $envio_id, string $motivo ): bool {
		return $this->cerrar( $envio_id, self::CANCELADO, null, $motivo );
	}

	private function cerrar( int $envio_id, string $estado, ?string $destinatario, ?string $error ): bool {
		global $wpdb;

		$datos   = array(
			'estado' => $estado,
			'error'  => null === $error ? null : mb_substr( $error, 0, 255 ),
		);
		$formato = array( '%s', '%s' );

		if ( null !== $destinatario ) {
			$datos['destinatario'] = $destinatario;
			$formato[]             = '%s';
		}

		if ( self::ENVIADO === $estado ) {
			$datos['enviado_en'] = gmdate( 'Y-m-d H:i:s' );
			$formato[]           = '%s';
		}

		return 1 === $wpdb->update(
			self::tabla(),
			$datos,
			array(
				'id'     => $envio_id,
				'estado' => self::ENVIANDO,
			),
			$formato,
			array( '%d', '%s' )
		);
	}

	/**
	 * "Reenviar": accion explicita por carta. Vuelve a poner en cola un envio
	 * enviado, fallido o cancelado, o uno "enviando" interrumpido hace mas de
	 * MINUTOS_INTERRUMPIDO. Nunca uno en curso.
	 */
	public function reenviar( int $envio_id ): bool {
		global $wpdb;

		$tabla = self::tabla();

		return 1 === $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tabla} SET estado = %s, error = NULL
				WHERE id = %d AND ( estado IN (%s, %s, %s) OR ( estado = %s AND ultimo_intento_en < %s ) )",
				self::PENDIENTE,
				$envio_id,
				self::ENVIADO,
				self::FALLIDO,
				self::CANCELADO,
				self::ENVIANDO,
				gmdate( 'Y-m-d H:i:s', time() - self::MINUTOS_INTERRUMPIDO * MINUTE_IN_SECONDS )
			)
		);
	}

	/** Todos los fallidos de esta sociedad y ejercicio vuelven a la cola. */
	public function reintentar_fallidos( string $emisor_cif, int $ejercicio ): int {
		global $wpdb;

		$tabla      = self::tabla();
		$documentos = DB_Schema::get_documentos_table_name();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tabla} e INNER JOIN {$documentos} d ON d.id = e.documento_id
				SET e.estado = %s, e.error = NULL
				WHERE e.estado = %s AND d.emisor_cif = %s AND d.ejercicio = %d",
				self::PENDIENTE,
				self::FALLIDO,
				$emisor_cif,
				$ejercicio
			)
		);
	}

	/** Al deshacer un lote: lo que siga pendiente de ese lote ya no se envia. */
	public function cancelar_pendientes_de_lote( int $lote_id ): int {
		global $wpdb;

		$tabla = self::tabla();

		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$tabla} SET estado = %s, error = %s WHERE lote_id = %d AND estado = %s",
				self::CANCELADO,
				'Lote deshecho antes de enviar.',
				$lote_id,
				self::PENDIENTE
			)
		);
	}

	/**
	 * Recuento por estado de esta sociedad y ejercicio; "interrumpido" separa
	 * los "enviando" antiguos.
	 *
	 * @return array<string, int>
	 */
	public function recuentos( string $emisor_cif, int $ejercicio ): array {
		global $wpdb;

		$tabla      = self::tabla();
		$documentos = DB_Schema::get_documentos_table_name();
		$limite     = gmdate( 'Y-m-d H:i:s', time() - self::MINUTOS_INTERRUMPIDO * MINUTE_IN_SECONDS );
		$filas      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CASE WHEN e.estado = %s AND e.ultimo_intento_en < %s THEN 'interrumpido' ELSE e.estado END AS estado, COUNT(*) AS total
				FROM {$tabla} e INNER JOIN {$documentos} d ON d.id = e.documento_id
				WHERE d.emisor_cif = %s AND d.ejercicio = %d GROUP BY 1",
				self::ENVIANDO,
				$limite,
				$emisor_cif,
				$ejercicio
			),
			ARRAY_A
		);

		$recuentos = array_fill_keys( array( self::PENDIENTE, self::ENVIANDO, 'interrumpido', self::ENVIADO, self::FALLIDO, self::CANCELADO ), 0 );

		foreach ( $filas ?: array() as $fila ) {
			$recuentos[ $fila['estado'] ] = (int) $fila['total'];
		}

		return $recuentos;
	}

	/**
	 * Envios de estos documentos, indexados por documento.
	 *
	 * @param int[] $documento_ids
	 * @return array<int, object>
	 */
	public function por_documentos( array $documento_ids ): array {
		global $wpdb;

		$documento_ids = array_values( array_filter( array_map( 'intval', $documento_ids ) ) );

		if ( ! $documento_ids ) {
			return array();
		}

		$tabla        = self::tabla();
		$placeholders = implode( ',', array_fill( 0, count( $documento_ids ), '%d' ) );
		$filas        = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders generados arriba.
				"SELECT * FROM {$tabla} WHERE documento_id IN ({$placeholders})",
				$documento_ids
			)
		);

		$por_documento = array();

		foreach ( $filas ?: array() as $fila ) {
			$por_documento[ (int) $fila->documento_id ] = $fila;
		}

		return $por_documento;
	}
}
