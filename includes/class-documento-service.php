<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Caso de uso de negocio para subir un documento y asignarlo a una
 * farmacia desde el backoffice (#246). Valida antes de escribir, nunca deja
 * un registro a medias -- mismo espiritu que Farmacia_Service y
 * Plan_Service, extendido aqui a un recurso externo (el fichero en disco):
 * si la insercion en BD falla despues de escribir el fichero, se borra el
 * fichero para no dejar un huerfano sin fila que lo referencie.
 *
 * No decide visibilidad ni sirve el fichero: eso sigue siendo
 * exclusivamente Permissions::puede_ver_documento() y Documento_Endpoint,
 * que no se tocan. Esta clase solo se encarga de que el fichero acabe en
 * Clientes/private-docs/ con una fila valida en wp_mdf_ca_documentos.
 */
class Documento_Service {

	/**
	 * 20 MB: comodo para un PDF escaneado o una foto de factura, con margen
	 * de sobra por debajo del upload_max_filesize/post_max_size de 64 MB
	 * del hosting (ver CLAUDE.md) para que el propio PHP nunca trunque la
	 * subida antes de que este limite se aplique.
	 */
	private const TAMANO_MAXIMO_BYTES = 20 * 1024 * 1024;

	/**
	 * Solo PDF e imagenes rasterizadas comunes: es lo que cubre contratos,
	 * facturas, presupuestos y entregables (documentos generados o
	 * escaneados, nunca ejecutables ni plantillas con macros). Se excluye a
	 * proposito cualquier formato de Office (macros) y SVG (puede llevar
	 * script embebido) aunque tecnicamente pudieran aparecer como
	 * "entregable": el coste de pedir que se suba en PDF es minimo frente
	 * al riesgo de aceptar formatos con capacidad de ejecutar codigo en una
	 * pantalla de administracion.
	 *
	 * Formato que exige wp_check_filetype_and_ext(): extension(es) => MIME.
	 */
	private const MIMES_PERMITIDOS = array(
		'pdf'      => 'application/pdf',
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
	);

	private Documento_Repository $repository;
	private Farmacia_Repository $farmacia_repository;

	public function __construct( ?Documento_Repository $repository = null, ?Farmacia_Repository $farmacia_repository = null ) {
		$this->repository          = $repository ?? new Documento_Repository();
		$this->farmacia_repository = $farmacia_repository ?? new Farmacia_Repository();
	}

	/**
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo Un elemento de $_FILES.
	 * @return Documento|\WP_Error
	 */
	public function subir( int $farmacia_id, string $nombre, string $tipo_documento, ?array $archivo ) {
		if ( ! $this->farmacia_repository->find_by_id( $farmacia_id ) ) {
			return new \WP_Error( 'mdf_ca_documento_farmacia_no_existe', 'Selecciona una farmacia valida.' );
		}

		$nombre = trim( $nombre );

		if ( '' === $nombre ) {
			return new \WP_Error( 'mdf_ca_documento_nombre_vacio', 'El nombre del documento no puede estar vacio.' );
		}

		if ( ! Documento_Tipos::es_valido( $tipo_documento ) ) {
			return new \WP_Error( 'mdf_ca_documento_tipo_invalido', 'Selecciona un tipo de documento valido.' );
		}

		$validacion_archivo = $this->validar_archivo( $archivo );

		if ( is_wp_error( $validacion_archivo ) ) {
			return $validacion_archivo;
		}

		// wp_check_filetype_and_ext() no se fia de la extension ni del
		// Content-Type que declara el navegador (ambos manipulables por
		// quien sube el fichero): inspecciona el contenido real del
		// fichero temporal contra la lista blanca de MIMES_PERMITIDOS.
		$filetype = wp_check_filetype_and_ext( $archivo['tmp_name'], $archivo['name'], self::MIMES_PERMITIDOS );

		if ( ! $filetype['ext'] || ! $filetype['type'] ) {
			return new \WP_Error(
				'mdf_ca_documento_tipo_fichero_no_permitido',
				'Tipo de fichero no permitido. Solo se aceptan PDF, JPG, PNG y WEBP.'
			);
		}

		$carpeta = Documento_Endpoint::get_carpeta_documentos();

		if ( ! is_dir( $carpeta ) || ! is_writable( $carpeta ) ) {
			return new \WP_Error(
				'mdf_ca_documento_carpeta_no_disponible',
				'La carpeta de documentos privados no esta disponible en el servidor. Contacta con desarrollo antes de seguir.'
			);
		}

		$nombre_fichero = self::generar_nombre_fichero( $farmacia_id, $filetype['ext'] );
		$ruta_destino   = $carpeta . DIRECTORY_SEPARATOR . $nombre_fichero;

		if ( ! move_uploaded_file( $archivo['tmp_name'], $ruta_destino ) ) {
			return new \WP_Error( 'mdf_ca_documento_error_escritura', 'No se pudo guardar el fichero en el servidor.' );
		}

		$documento = $this->repository->insert(
			$farmacia_id,
			$nombre,
			$nombre_fichero,
			$filetype['type'],
			(int) $archivo['size'],
			$tipo_documento
		);

		if ( null === $documento ) {
			wp_delete_file( $ruta_destino );

			global $wpdb;
			return new \WP_Error( 'mdf_ca_error_bd', sprintf( 'No se pudo registrar el documento: %s', $wpdb->last_error ) );
		}

		return $documento;
	}

	/**
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo
	 * @return true|\WP_Error
	 */
	private function validar_archivo( ?array $archivo ) {
		$error = $archivo['error'] ?? UPLOAD_ERR_NO_FILE;

		if ( UPLOAD_ERR_NO_FILE === $error ) {
			return new \WP_Error( 'mdf_ca_documento_sin_fichero', 'Selecciona un fichero para subir.' );
		}

		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			return new \WP_Error( 'mdf_ca_documento_fichero_excede_limite_servidor', 'El fichero supera el limite de subida configurado en el servidor.' );
		}

		if ( UPLOAD_ERR_OK !== $error ) {
			return new \WP_Error( 'mdf_ca_documento_error_subida', 'Ha ocurrido un error al subir el fichero. Intentalo de nuevo.' );
		}

		if ( ! is_uploaded_file( $archivo['tmp_name'] ) ) {
			return new \WP_Error( 'mdf_ca_documento_error_subida', 'Ha ocurrido un error al subir el fichero. Intentalo de nuevo.' );
		}

		if ( (int) $archivo['size'] > self::TAMANO_MAXIMO_BYTES ) {
			return new \WP_Error(
				'mdf_ca_documento_fichero_demasiado_grande',
				sprintf( 'El fichero supera el tamano maximo permitido (%d MB).', self::TAMANO_MAXIMO_BYTES / 1024 / 1024 )
			);
		}

		return true;
	}

	/**
	 * El nombre en disco nunca es el nombre original del fichero subido:
	 * es {farmacia_id}-{16 bytes aleatorios en hex}.{extension validada}.
	 * Tres motivos:
	 *
	 * 1. Colisiones: dos documentos distintos (incluso de la misma
	 *    farmacia) pueden subirse con el mismo nombre original sin
	 *    problema, cada fichero en disco tiene su propio nombre unico --
	 *    128 bits de aleatoriedad hacen la probabilidad de choque
	 *    despreciable, no hace falta comprobar existencia antes de escribir.
	 * 2. Rutas: el nombre original puede traer espacios, acentos, barras o
	 *    secuencias como "../" -- generar el nombre en vez de sanearlo
	 *    evita esa clase entera de problemas en vez de intentar cubrir
	 *    todos los casos de un sanitizador.
	 * 3. Defensa en profundidad: el nombre en disco no revela nada del
	 *    documento (ni su nombre, ni su tipo declarado) si por lo que sea
	 *    se listara el directorio -- el nombre visible para humanos vive
	 *    solo en wp_mdf_ca_documentos.nombre, nunca en el filesystem.
	 */
	private static function generar_nombre_fichero( int $farmacia_id, string $extension ): string {
		return $farmacia_id . '-' . bin2hex( random_bytes( 16 ) ) . '.' . $extension;
	}
}
