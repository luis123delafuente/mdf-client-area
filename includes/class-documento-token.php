<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Token de un solo uso que Documento_Visor entrega al JS del visor para que
 * su fetch() pueda pedir a Documento_Endpoint un documento NO descargable.
 * Sin token valido, el endpoint responde 404 (pegar la URL en el navegador
 * no sirve el fichero).
 *
 * - Ligado a usuario y documento: un token emitido para otro usuario u otro
 *   documento no vale.
 * - Caduca en TTL_SEGUNDOS: el visor hace el fetch nada mas cargar la pagina.
 * - Un solo uso: consumir() borra el transient y solo da el token por bueno
 *   si el borrado elimino algo. delete_transient() devuelve false si la fila
 *   ya no existe, asi que de dos peticiones simultaneas con el mismo token
 *   solo una lo consume (get + delete por separado dejaria pasar a las dos).
 * - En la BD solo se guarda el hash del token, no el token en claro.
 *
 * Es una restriccion adicional sobre documentos ya visibles: no decide quien
 * puede ver nada (eso sigue siendo solo Permissions) y su limite es el de
 * siempre, quien tiene sesion y DevTools puede reproducir la peticion del
 * visor. Es disuasion, no DRM.
 */
class Documento_Token {

	/**
	 * 120 s: el fetch ocurre en cuanto carga el script del visor (unos
	 * segundos, incluso con conexion lenta); el margen cubre una pestana
	 * que se carga en segundo plano y se activa despues. Mas largo solo
	 * alargaria la ventana de un token filtrado.
	 */
	public const TTL_SEGUNDOS = 120;

	public const PARAMETRO = 'mdf_t';

	private const PREFIJO_TRANSIENT = 'mdf_ca_vt_';

	public static function emitir( int $usuario_id, int $documento_id ): string {
		$token = bin2hex( random_bytes( 32 ) );

		set_transient(
			self::clave( $token ),
			array(
				'usuario_id'   => $usuario_id,
				'documento_id' => $documento_id,
			),
			self::TTL_SEGUNDOS
		);

		return $token;
	}

	/**
	 * Devuelve true una unica vez por token, y solo si es de este usuario y
	 * este documento. Cualquier otro caso (formato invalido, inexistente,
	 * caducado, ya consumido, de otro usuario/documento) es false.
	 */
	public static function consumir( string $token, int $usuario_id, int $documento_id ): bool {
		if ( ! preg_match( '/^[0-9a-f]{64}$/', $token ) ) {
			return false;
		}

		$clave = self::clave( $token );
		$datos = get_transient( $clave );

		if ( ! is_array( $datos ) ) {
			return false;
		}

		// Se consume siempre que exista, coincida o no: un token usado en
		// el sitio equivocado queda quemado. El borrado decide quien gana
		// si dos peticiones llegan a la vez.
		if ( ! delete_transient( $clave ) ) {
			return false;
		}

		return ( $datos['usuario_id'] ?? null ) === $usuario_id
			&& ( $datos['documento_id'] ?? null ) === $documento_id;
	}

	private static function clave( string $token ): string {
		return self::PREFIJO_TRANSIENT . hash( 'sha256', $token );
	}
}
