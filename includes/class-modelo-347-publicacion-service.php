<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Publicar y enviar" de las cartas del Modelo 347 de una sociedad y un
 * ejercicio (#307), en dos pasos como "Publicar todos los pendientes":
 *
 * 1. calcular(): ids EXACTOS publicables (347 pendientes y vigentes de esa
 *    sociedad y ejercicio, con farmacia existente), cuantos tendran correo y
 *    la huella.
 * 2. aplicar(): recalcula y solo escribe si la huella coincide. En UNA
 *    transaccion crea el lote (filtros {"tipo":"modelo_347","emisor","ejercicio"}),
 *    publica exactamente esos ids con Documento_Repository::cambiar_estado()
 *    (eventos de origen modelo_347, mismo lote) y encola un envio por cada
 *    carta cuya farmacia tenga cuenta activa con email. O todo o nada.
 *
 * Deshacer el lote es el de siempre (Despublicacion_Lote_Service), que
 * ademas cancela los envios aun pendientes de ese lote. Lo ya enviado o
 * visto no se puede retirar.
 */
class Modelo_347_Publicacion_Service {

	public const ERROR_CAMBIADO = 'mdf_ca_347_publicacion_cambiada';

	private Documento_Repository $documentos;
	private Farmacia_Repository $farmacias;
	private Publicacion_Lote_Repository $lotes;
	private Modelo_347_Envio_Repository $envios;

	public function __construct() {
		$this->documentos = new Documento_Repository();
		$this->farmacias  = new Farmacia_Repository();
		$this->lotes      = new Publicacion_Lote_Repository();
		$this->envios     = new Modelo_347_Envio_Repository();
	}

	/**
	 * @return array{ids: int[], documentos: int, con_correo: int, sin_correo: int, huella: string}|\WP_Error
	 */
	public function calcular( string $emisor_cif, int $ejercicio ) {
		$ids = $this->documentos->find_ids_modelo_347_publicables( $emisor_cif, $ejercicio );

		if ( null === $ids ) {
			return new \WP_Error( 'mdf_ca_347_publicacion_error_bd', 'No se pudieron leer las cartas pendientes. No se ha publicado nada.' );
		}

		$destinatarios = $this->destinatarios( $ids );

		return array(
			'ids'        => $ids,
			'documentos' => count( $ids ),
			'con_correo' => count( $destinatarios ),
			'sin_correo' => count( $ids ) - count( $destinatarios ),
			'huella'     => hash( 'sha256', $emisor_cif . ':' . $ejercicio . ':' . implode( ',', $ids ) . '|' . implode( ',', array_keys( $destinatarios ) ) ),
		);
	}

	/**
	 * @return array{publicados: int, encolados: int, lote_id: int}|\WP_Error
	 */
	public function aplicar( string $emisor_cif, int $ejercicio, string $huella, int $usuario_id ) {
		global $wpdb;

		foreach ( array( DB_Schema::get_documentos_table_name(), DB_Schema::get_publicacion_lotes_table_name(), DB_Schema::get_publicacion_eventos_table_name(), DB_Schema::get_modelo_347_envios_table_name() ) as $tabla ) {
			if ( ! DB_Schema::tabla_es_innodb( $tabla ) ) {
				return new \WP_Error( 'mdf_ca_347_sin_transacciones', 'Alguna tabla no admite transacciones (no es InnoDB). No se ha publicado nada.' );
			}
		}

		$actual = $this->calcular( $emisor_cif, $ejercicio );

		if ( is_wp_error( $actual ) ) {
			return $actual;
		}

		if ( ! hash_equals( $actual['huella'], $huella ) ) {
			return new \WP_Error( self::ERROR_CAMBIADO, 'Las cartas pendientes han cambiado desde que preparaste la publicación. No se ha publicado nada: revisa la confirmación actualizada.' );
		}

		if ( ! $actual['ids'] ) {
			return new \WP_Error( 'mdf_ca_347_nada_que_publicar', 'No hay cartas pendientes de publicar.' );
		}

		$error = new \WP_Error( 'mdf_ca_347_publicacion_error_bd', 'No se pudieron publicar las cartas. No se ha publicado ninguna.' );

		$wpdb->query( 'START TRANSACTION' );

		$lote_id    = $this->lotes->crear(
			$usuario_id,
			array(
				'tipo'      => 'modelo_347',
				'emisor'    => $emisor_cif,
				'ejercicio' => $ejercicio,
			)
		);
		$publicados = false === $lote_id
			? false
			: $this->documentos->cambiar_estado( $actual['ids'], true, Publicacion_Evento_Repository::ORIGEN_MODELO_347, $usuario_id, (int) $lote_id );

		if ( false === $publicados || ! $this->lotes->actualizar_documentos( (int) $lote_id, $publicados ) || $publicados !== $actual['documentos'] ) {
			$wpdb->query( 'ROLLBACK' );

			return false === $publicados || $publicados === $actual['documentos'] ? $error : new \WP_Error( self::ERROR_CAMBIADO, 'Alguna carta cambió de estado en el último instante. No se ha publicado nada: vuelve a preparar la publicación.' );
		}

		$encolados = $this->envios->encolar( $this->destinatarios( $actual['ids'] ), (int) $lote_id, $usuario_id );

		if ( false === $encolados ) {
			$wpdb->query( 'ROLLBACK' );

			return $error;
		}

		$wpdb->query( 'COMMIT' );

		Recepcion_Registro::registrar( Recepcion_Registro::PUBLICADO_BLOQUE, $publicados, Recepcion_Registro::ORIGEN_BACKOFFICE );

		return array(
			'publicados' => $publicados,
			'encolados'  => $encolados,
			'lote_id'    => (int) $lote_id,
		);
	}

	/**
	 * Cartas de estos ids cuya farmacia tiene cuenta activa con email.
	 *
	 * @param int[] $ids
	 * @return array<int, array{farmacia_id: int, destinatario: string}> documento_id => datos
	 */
	private function destinatarios( array $ids ): array {
		$resultado = array();

		foreach ( $ids as $id ) {
			$documento = $this->documentos->find_by_id( $id );
			$farmacia  = $documento ? $this->farmacias->find_by_id( $documento->get_farmacia_id() ) : null;
			$email     = $farmacia ? Modelo_347_Simulacion_Service::email_de_cuenta_activa( $farmacia ) : null;

			if ( null !== $email ) {
				$resultado[ $id ] = array(
					'farmacia_id'  => $farmacia->get_id(),
					'destinatario' => $email,
				);
			}
		}

		return $resultado;
	}
}
