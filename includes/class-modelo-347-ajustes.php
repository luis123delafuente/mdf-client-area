<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ajustes del generador del Modelo 347 (#307), uno por sociedad emisora
 * (option mdf_ca_347_ajustes, array CIF => ajustes) para que Formacion tenga
 * los suyos cuando se habilite. Ninguna direccion de correo real vive en el
 * codigo: todas empiezan vacias y las rellena un administrador.
 *
 * - correo_admin: el correo de Administracion que aparece en la carta.
 *   Obligatorio para generar (la carta no puede salir con el hueco vacio).
 * - reply_to: Reply-To del correo con la carta. El From sigue siendo el del
 *   SMTP actual. Obligatorio para enviar.
 * - correo_prueba: destino de "Enviarme una prueba".
 * - por_tanda / segundos_tanda: ritmo de envio (prudente para el SMTP de
 *   IONOS, cuyo limite real no esta confirmado).
 * - cuerpo: plantilla del correo, con {SOCIEDAD} y {AÑO}.
 */
class Modelo_347_Ajustes {

	private const OPTION = 'mdf_ca_347_ajustes';

	public const POR_TANDA_MIN      = 1;
	public const POR_TANDA_MAX      = 20;
	public const SEGUNDOS_TANDA_MIN = 10;
	public const SEGUNDOS_TANDA_MAX = 600;
	private const CUERPO_MAX        = 4000;

	public const CUERPO_POR_DEFECTO = "Estimado cliente:\n\nLe adjuntamos la comunicación de las operaciones realizadas con {SOCIEDAD} durante el ejercicio {AÑO} a efectos de la declaración anual de operaciones con terceras personas (Modelo 347).\n\nLe rogamos que revise los importes y nos confirme su conformidad respondiendo a este correo.\n\nEsta comunicación está disponible en cualquier momento en su área de cliente de la web.\n\nUn saludo,\nAdministración {SOCIEDAD}";

	/**
	 * @return array{correo_admin: string, reply_to: string, correo_prueba: string, por_tanda: int, segundos_tanda: int, cuerpo: string}
	 */
	public static function get( string $emisor_cif ): array {
		$todos = get_option( self::OPTION, array() );
		$a     = is_array( $todos ) && isset( $todos[ $emisor_cif ] ) && is_array( $todos[ $emisor_cif ] ) ? $todos[ $emisor_cif ] : array();

		return array(
			'correo_admin'   => isset( $a['correo_admin'] ) && is_email( $a['correo_admin'] ) ? (string) $a['correo_admin'] : '',
			'reply_to'       => isset( $a['reply_to'] ) && is_email( $a['reply_to'] ) ? (string) $a['reply_to'] : '',
			'correo_prueba'  => isset( $a['correo_prueba'] ) && is_email( $a['correo_prueba'] ) ? (string) $a['correo_prueba'] : '',
			'por_tanda'      => self::entero( $a['por_tanda'] ?? 5, self::POR_TANDA_MIN, self::POR_TANDA_MAX, 5 ),
			'segundos_tanda' => self::entero( $a['segundos_tanda'] ?? 30, self::SEGUNDOS_TANDA_MIN, self::SEGUNDOS_TANDA_MAX, 30 ),
			'cuerpo'         => isset( $a['cuerpo'] ) && '' !== trim( (string) $a['cuerpo'] ) ? (string) $a['cuerpo'] : self::CUERPO_POR_DEFECTO,
		);
	}

	/**
	 * Valida y guarda. Un correo vacio es valido (sin configurar); uno no
	 * vacio tiene que ser una direccion valida.
	 *
	 * @param array<string, mixed> $datos Ya sin slashes.
	 * @return true|\WP_Error
	 */
	public static function guardar( string $emisor_cif, array $datos ) {
		if ( ! Modelo_347_Carta::emisor_habilitado( $emisor_cif ) ) {
			return new \WP_Error( 'mdf_ca_347_emisor', 'Sociedad no habilitada para el generador.' );
		}

		$limpio = array();

		foreach ( array( 'correo_admin' => 'El correo de Administración', 'reply_to' => 'El Reply-To', 'correo_prueba' => 'El correo de prueba' ) as $campo => $etiqueta ) {
			$valor = trim( sanitize_text_field( (string) ( $datos[ $campo ] ?? '' ) ) );

			if ( '' !== $valor && ! is_email( $valor ) ) {
				return new \WP_Error( 'mdf_ca_347_ajuste_correo', $etiqueta . ' no es una dirección de correo válida.' );
			}

			$limpio[ $campo ] = $valor;
		}

		$por_tanda = (int) ( $datos['por_tanda'] ?? 0 );
		$segundos  = (int) ( $datos['segundos_tanda'] ?? 0 );

		if ( $por_tanda < self::POR_TANDA_MIN || $por_tanda > self::POR_TANDA_MAX || $segundos < self::SEGUNDOS_TANDA_MIN || $segundos > self::SEGUNDOS_TANDA_MAX ) {
			return new \WP_Error(
				'mdf_ca_347_ajuste_ritmo',
				sprintf( 'Ritmo no válido: de %d a %d correos por tanda y de %d a %d segundos entre tandas.', self::POR_TANDA_MIN, self::POR_TANDA_MAX, self::SEGUNDOS_TANDA_MIN, self::SEGUNDOS_TANDA_MAX )
			);
		}

		$cuerpo = sanitize_textarea_field( (string) ( $datos['cuerpo'] ?? '' ) );

		if ( mb_strlen( $cuerpo ) > self::CUERPO_MAX ) {
			return new \WP_Error( 'mdf_ca_347_ajuste_cuerpo', sprintf( 'El cuerpo del correo no puede pasar de %d caracteres.', self::CUERPO_MAX ) );
		}

		$limpio['por_tanda']      = $por_tanda;
		$limpio['segundos_tanda'] = $segundos;
		$limpio['cuerpo']         = '' === trim( $cuerpo ) ? '' : $cuerpo;

		$todos                = get_option( self::OPTION, array() );
		$todos                = is_array( $todos ) ? $todos : array();
		$todos[ $emisor_cif ] = $limpio;

		update_option( self::OPTION, $todos, false );

		return true;
	}

	private static function entero( $valor, int $min, int $max, int $defecto ): int {
		$n = (int) $valor;

		return $n >= $min && $n <= $max ? $n : $defecto;
	}
}
