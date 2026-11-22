<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invitar a todas las farmacias sin cuenta de una vez, por tandas (v0.47.0,
 * para el alta masiva de unas 270 farmacias). Cada invitacion es la de
 * siempre: Invitacion_Service::invitar() con usuario = CIF/NIF y el email
 * guardado por el importador. Aqui solo se decide a quien, en que orden y a
 * que ritmo.
 *
 * Flujo, en dos pasos como «Publicar todos los pendientes»:
 * 1. revisar(): lista calculada en vivo de invitables y de no invitables
 *    (con motivo) y su huella. No escribe nada.
 * 2. iniciar( $huella ): recalcula; si la huella no coincide, no empieza.
 *    Si coincide, guarda el trabajo del usuario (transient) con la lista
 *    explicita de ids: lo que se de de alta despues no entra sin revisarlo.
 * 3. tanda(): la llama el navegador cada INTERVALO_SEGUNDOS; envia como
 *    mucho TAMANO_TANDA invitaciones.
 *
 * Por que no se puede invitar dos veces a la misma farmacia, aunque haya
 * dos pestanas o dos personas a la vez: cada farmacia se procesa con un
 * cerrojo propio (GET_LOCK, igual en MySQL y MariaDB) y, ya con el
 * cerrojo, se vuelve a leer de la BD: si ya tiene cuenta con la invitacion
 * enviada, se omite. Ademas toda la tanda va con un cerrojo por usuario,
 * para que dos pestanas del mismo usuario no se pisen el trabajo.
 *
 * El trabajo no es imprescindible: si caduca o se cierra, las farmacias ya
 * invitadas tienen cuenta y una revision nueva ya no las incluye. Solo
 * guarda ids, estado y la categoria del fallo (sin emails ni nombres).
 *
 * Un envio fallido deja la cuenta creada sin invitacion enviada (estado
 * «Invitacion pendiente» sin fecha). «Reintentar las fallidas» las vuelve a
 * poner en la cola; al reintentar, invitar() reutiliza esa cuenta.
 */
class Invitacion_Masiva_Service {

	public const TAMANO_TANDA       = 5;
	public const INTERVALO_SEGUNDOS = 30;

	/** El trabajo dura lo bastante para 270 farmacias con pausas (unos 27 min). */
	private const TTL_TRABAJO = 12 * HOUR_IN_SECONDS;

	/** Margen para el reloj del navegador al exigir el intervalo. */
	private const MARGEN_INTERVALO = 3;

	private const CLAVE_TRABAJO = 'mdf_ca_invitacion_masiva_';

	public const PENDIENTE = 'pendiente';
	public const ENVIADA   = 'enviada';
	public const FALLIDA   = 'fallida';
	public const OMITIDA   = 'omitida';

	private Farmacia_Repository $farmacias;
	private Invitacion_Service $invitaciones;

	public function __construct( ?Farmacia_Repository $farmacias = null, ?Invitacion_Service $invitaciones = null ) {
		$this->farmacias    = $farmacias ?? new Farmacia_Repository();
		$this->invitaciones = $invitaciones ?? new Invitacion_Service( $this->farmacias );
	}

	/**
	 * @return array{invitables: Farmacia[], no_invitables: array<int, array{farmacia: Farmacia, motivo: string}>, huella: string}
	 */
	public function revisar(): array {
		$invitables    = array();
		$no_invitables = array();
		$emails_vistos = array();

		foreach ( $this->farmacias->find_all() as $farmacia ) {
			if ( $farmacia->get_wp_user_id() ) {
				continue;
			}

			$motivo = $this->motivo_no_invitable( $farmacia, $emails_vistos );

			if ( null !== $motivo ) {
				$no_invitables[] = array(
					'farmacia' => $farmacia,
					'motivo'   => $motivo,
				);
				continue;
			}

			$emails_vistos[ (string) $farmacia->get_email() ] = true;
			$invitables[]                                      = $farmacia;
		}

		return array(
			'invitables'    => $invitables,
			'no_invitables' => $no_invitables,
			'huella'        => self::huella( $invitables ),
		);
	}

	/**
	 * Los mismos motivos por los que invitar() rechazaria el alta, dichos
	 * antes de enviar nada.
	 *
	 * @param array<string, bool> $emails_vistos Emails ya incluidos en esta revision.
	 */
	private function motivo_no_invitable( Farmacia $farmacia, array $emails_vistos ): ?string {
		$email = (string) $farmacia->get_email();

		if ( '' === $email ) {
			return 'Sin email: importalo en el CSV o invitala a mano.';
		}

		if ( ! is_email( $email ) ) {
			return 'El email guardado no es valido.';
		}

		if ( isset( $emails_vistos[ $email ] ) ) {
			return 'Otra farmacia de esta lista tiene el mismo email.';
		}

		if ( email_exists( $email ) ) {
			return 'Ya hay una cuenta con este email.';
		}

		if ( username_exists( $farmacia->get_cif() ) ) {
			return 'Ya hay un usuario con este CIF/NIF como nombre.';
		}

		return null;
	}

	/** @param Farmacia[] $invitables */
	private static function huella( array $invitables ): string {
		$partes = array_map(
			static fn( Farmacia $f ): string => $f->get_id() . ':' . $f->get_cif() . ':' . $f->get_email(),
			$invitables
		);

		return hash( 'sha256', implode( '|', $partes ) );
	}

	/**
	 * @return array<string, mixed>|\WP_Error El trabajo creado.
	 */
	public function iniciar( int $usuario_id, string $huella ) {
		if ( $this->trabajo( $usuario_id ) && $this->resumen( $usuario_id )['pendientes'] > 0 ) {
			return new \WP_Error( 'mdf_ca_invitacion_masiva_en_curso', 'Ya tienes un envio a medias. Continualo o cierralo antes de empezar otro.' );
		}

		$revision = $this->revisar();

		if ( ! hash_equals( $revision['huella'], $huella ) ) {
			return new \WP_Error( 'mdf_ca_invitacion_masiva_cambiada', 'La lista de farmacias ha cambiado desde que la revisaste. Revisala de nuevo; no se ha enviado nada.' );
		}

		if ( ! $revision['invitables'] ) {
			return new \WP_Error( 'mdf_ca_invitacion_masiva_vacia', 'No hay ninguna farmacia que invitar.' );
		}

		$estados = array();

		foreach ( $revision['invitables'] as $farmacia ) {
			$estados[ $farmacia->get_id() ] = array(
				'estado' => self::PENDIENTE,
				'motivo' => '',
			);
		}

		$trabajo = array(
			'creado'      => time(),
			'ultima'      => 0,
			'estados'     => $estados,
		);

		set_transient( self::CLAVE_TRABAJO . $usuario_id, $trabajo, self::TTL_TRABAJO );

		return $trabajo;
	}

	/** @return array<string, mixed>|null */
	public function trabajo( int $usuario_id ): ?array {
		$trabajo = get_transient( self::CLAVE_TRABAJO . $usuario_id );

		return is_array( $trabajo ) && isset( $trabajo['estados'] ) ? $trabajo : null;
	}

	public function cerrar( int $usuario_id ): void {
		delete_transient( self::CLAVE_TRABAJO . $usuario_id );
	}

	/**
	 * @return array{total: int, pendientes: int, enviadas: int, fallidas: int, omitidas: int}
	 */
	public function resumen( int $usuario_id ): array {
		$trabajo = $this->trabajo( $usuario_id );
		$cuenta  = array(
			self::PENDIENTE => 0,
			self::ENVIADA   => 0,
			self::FALLIDA   => 0,
			self::OMITIDA   => 0,
		);

		foreach ( $trabajo['estados'] ?? array() as $estado ) {
			++$cuenta[ $estado['estado'] ];
		}

		return array(
			'total'      => array_sum( $cuenta ),
			'pendientes' => $cuenta[ self::PENDIENTE ],
			'enviadas'   => $cuenta[ self::ENVIADA ],
			'fallidas'   => $cuenta[ self::FALLIDA ],
			'omitidas'   => $cuenta[ self::OMITIDA ],
		);
	}

	/** Vuelve a poner en cola las fallidas. Devuelve cuantas. */
	public function reintentar_fallidas( int $usuario_id ): int {
		$trabajo = $this->trabajo( $usuario_id );

		if ( ! $trabajo ) {
			return 0;
		}

		$n = 0;

		foreach ( $trabajo['estados'] as $id => $estado ) {
			if ( self::FALLIDA === $estado['estado'] ) {
				$trabajo['estados'][ $id ] = array(
					'estado' => self::PENDIENTE,
					'motivo' => '',
				);
				++$n;
			}
		}

		set_transient( self::CLAVE_TRABAJO . $usuario_id, $trabajo, self::TTL_TRABAJO );

		return $n;
	}

	/**
	 * Envia la siguiente tanda.
	 *
	 * @return array{resumen: array, espera: int, procesadas: array<int, array{id: int, estado: string, motivo: string}>}|\WP_Error
	 */
	public function tanda( int $usuario_id ) {
		global $wpdb;

		$cerrojo = 'mdf_ca_inv_masiva_u' . $usuario_id;

		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $cerrojo ) ) ) {
			return new \WP_Error( 'mdf_ca_invitacion_masiva_ocupada', 'Este envio ya se esta ejecutando en otra pestana.' );
		}

		try {
			$trabajo = $this->trabajo( $usuario_id );

			if ( ! $trabajo ) {
				return new \WP_Error( 'mdf_ca_invitacion_masiva_sin_trabajo', 'No hay ningun envio en curso.' );
			}

			$falta = (int) $trabajo['ultima'] + self::INTERVALO_SEGUNDOS - self::MARGEN_INTERVALO - time();

			if ( $falta > 0 ) {
				return array(
					'resumen'    => $this->resumen( $usuario_id ),
					'espera'     => $falta + self::MARGEN_INTERVALO,
					'procesadas' => array(),
				);
			}

			$procesadas = array();

			foreach ( $trabajo['estados'] as $id => $estado ) {
				if ( count( $procesadas ) >= self::TAMANO_TANDA ) {
					break;
				}

				if ( self::PENDIENTE !== $estado['estado'] ) {
					continue;
				}

				$resultado                 = $this->invitar_una( (int) $id );
				$trabajo['estados'][ $id ] = $resultado;
				$procesadas[]              = array( 'id' => (int) $id ) + $resultado;
			}

			$trabajo['ultima'] = time();
			set_transient( self::CLAVE_TRABAJO . $usuario_id, $trabajo, self::TTL_TRABAJO );

			$resumen = $this->resumen( $usuario_id );

			return array(
				'resumen'    => $resumen,
				'espera'     => $resumen['pendientes'] > 0 ? self::INTERVALO_SEGUNDOS : 0,
				'procesadas' => $procesadas,
			);
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $cerrojo ) );
		}
	}

	/**
	 * Una farmacia, con su cerrojo y releida de la BD ya con el.
	 *
	 * @return array{estado: string, motivo: string}
	 */
	private function invitar_una( int $farmacia_id ): array {
		global $wpdb;

		$cerrojo = 'mdf_ca_invitar_f' . $farmacia_id;

		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 0 )', $cerrojo ) ) ) {
			// Otra peticion la esta invitando ahora mismo: se deja para la siguiente tanda.
			return array(
				'estado' => self::PENDIENTE,
				'motivo' => '',
			);
		}

		try {
			$farmacia = $this->farmacias->find_by_id( $farmacia_id );

			if ( ! $farmacia ) {
				return self::resultado( self::OMITIDA, 'La farmacia ya no existe.' );
			}

			if ( $farmacia->get_wp_user_id() ) {
				$usuario = get_userdata( $farmacia->get_wp_user_id() );

				// Solo se reintenta la cuenta que creo un envio fallido: cliente
				// y sin ninguna invitacion enviada. Cualquier otra, nunca.
				if ( ! $usuario || ! Roles::user_is_cliente( $usuario ) || get_user_meta( $usuario->ID, Invitacion_Service::META_INVITACION_ENVIADA, true ) ) {
					return self::resultado( self::OMITIDA, 'Ya tenia cuenta con la invitacion enviada.' );
				}

				$invitada = $this->invitaciones->invitar( $farmacia_id );
			} else {
				$motivo = $this->motivo_no_invitable( $farmacia, array() );

				if ( null !== $motivo ) {
					return self::resultado( self::OMITIDA, $motivo );
				}

				$invitada = $this->invitaciones->invitar( $farmacia_id, $farmacia->get_cif(), (string) $farmacia->get_email() );
			}

			if ( is_wp_error( $invitada ) ) {
				return self::resultado( self::FALLIDA, $invitada->get_error_message() );
			}

			return self::resultado( self::ENVIADA, '' );
		} finally {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $cerrojo ) );
		}
	}

	/** @return array{estado: string, motivo: string} */
	private static function resultado( string $estado, string $motivo ): array {
		return array(
			'estado' => $estado,
			'motivo' => $motivo,
		);
	}
}
