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
	 * PDF, imagenes rasterizadas comunes y Excel moderno (.xlsx). Se excluye
	 * a proposito SVG (puede llevar script embebido) y los Excel con
	 * capacidad de macros (.xlsm, .xlsb) y el formato binario antiguo (.xls),
	 * donde no se puede garantizar de forma fiable la ausencia de VBA. Un
	 * .xlsx legitimo no puede contener macros, pero la extension la
	 * controla quien sube el fichero: por eso validar_xlsx() inspecciona el
	 * contenido real del ZIP en vez de fiarse del nombre.
	 *
	 * Formato que exige wp_check_filetype_and_ext(): extension(es) => MIME.
	 */
	private const MIMES_PERMITIDOS = array(
		'pdf'      => 'application/pdf',
		'jpg|jpeg' => 'image/jpeg',
		'png'      => 'image/png',
		'webp'     => 'image/webp',
		'xlsx'     => self::MIME_XLSX,
	);

	public const MIME_XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

	/** Rango razonable de la fecha del documento: desde esta fecha hasta hoy mas el margen. */
	private const FECHA_DOCUMENTO_MINIMA     = '2000-01-01';
	private const FECHA_DOCUMENTO_MARGEN_DIAS = 31;

	/** Limites anti zip-bomb para validar_xlsx(): nunca se descomprime nada, solo se suman los tamanos declarados. */
	private const XLSX_MAX_ENTRADAS          = 2000;
	private const XLSX_MAX_DESCOMPRIMIDO_BYTES = 200 * 1024 * 1024;

	private Documento_Repository $repository;
	private Farmacia_Repository $farmacia_repository;

	public function __construct( ?Documento_Repository $repository = null, ?Farmacia_Repository $farmacia_repository = null ) {
		$this->repository          = $repository ?? new Documento_Repository();
		$this->farmacia_repository = $farmacia_repository ?? new Farmacia_Repository();
	}

	/**
	 * Fecha del documento valida: 'Y-m-d' estricto (2026-02-30 no vale) y dentro
	 * de un rango razonable (del 2000-01-01 a hoy mas 31 dias, en la zona
	 * horaria de WordPress). Es el UNICO sitio que lo decide: lo usan el
	 * guardado (backoffice y recepcion) y la revalidacion de
	 * Factura_Cruce_Service.
	 */
	public static function fecha_documento_valida( string $fecha ): bool {
		$zona = wp_timezone();
		$d    = \DateTimeImmutable::createFromFormat( '!Y-m-d', $fecha, $zona );

		if ( ! $d || $d->format( 'Y-m-d' ) !== $fecha ) {
			return false;
		}

		$minima = new \DateTimeImmutable( self::FECHA_DOCUMENTO_MINIMA, $zona );
		$maxima = ( new \DateTimeImmutable( 'today', $zona ) )->modify( '+' . self::FECHA_DOCUMENTO_MARGEN_DIAS . ' days' );

		return $d >= $minima && $d <= $maxima;
	}

	/**
	 * Subida desde el backoffice (Admin_Documentos).
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo Un elemento de $_FILES.
	 * @param bool $notificar false para subidas que no deben avisar a la
	 *                        farmacia (p. ej. ingesta del historico). No
	 *                        cambia la visibilidad: solo el aviso por email.
	 * @param string|null $fecha_documento 'Y-m-d'. Obligatoria para las
	 *                        facturas, opcional en el resto.
	 * @return Documento|\WP_Error
	 */
	public function subir( int $farmacia_id, string $nombre, string $tipo_documento, ?array $archivo, bool $descargable = false, bool $notificar = true, ?string $fecha_documento = null ) {
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

		// La subida de backoffice siempre publica.
		return $this->guardar( $farmacia_id, $nombre, $tipo_documento, $archivo, $descargable, $notificar, false, null, true, $fecha_documento );
	}

	/**
	 * Factura recibida por la ruta REST de recepcion (#303). Mismo camino que
	 * la subida de backoffice (validacion de fichero, nombre en disco
	 * aleatorio, carpeta privada), con tres diferencias: solo PDF, tipo
	 * "factura" siempre, y se guarda el SHA-256 del fichero (UNIQUE) para que
	 * reenviar el mismo PDF no lo duplique. La farmacia ya viene cruzada por
	 * Factura_Cruce_Service: aqui no se decide a quien pertenece.
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo Un elemento de $_FILES.
	 * @param bool $publicar false deja el documento pendiente de publicar
	 *                       (invisible para toda farmacia hasta que un
	 *                       administrador lo publica, #304).
	 * @param string|null $fecha_documento La fecha de la factura YA revalidada
	 *                       por Factura_Cruce_Service (nunca la declarada sin
	 *                       validar). Obligatoria: guardar() la exige.
	 * @return Documento|\WP_Error
	 */
	public function subir_factura_recibida( int $farmacia_id, string $nombre, ?array $archivo, string $hash_sha256, bool $notificar = true, bool $publicar = true, ?string $fecha_documento = null ) {
		if ( ! $this->farmacia_repository->find_by_id( $farmacia_id ) ) {
			return new \WP_Error( 'mdf_ca_documento_farmacia_no_existe', 'Farmacia no valida.' );
		}

		$nombre = trim( $nombre );

		if ( '' === $nombre ) {
			return new \WP_Error( 'mdf_ca_documento_nombre_vacio', 'El nombre del documento no puede estar vacio.' );
		}

		if ( 1 !== preg_match( '/^[a-f0-9]{64}$/', $hash_sha256 ) ) {
			return new \WP_Error( 'mdf_ca_documento_hash_invalido', 'Hash de fichero no valido.' );
		}

		return $this->guardar( $farmacia_id, $nombre, 'factura', $archivo, false, $notificar, true, $hash_sha256, $publicar, $fecha_documento );
	}

	/**
	 * Camino comun de subir() y subir_factura_recibida(): valida el fichero,
	 * lo mueve a la carpeta privada con nombre aleatorio y registra la fila.
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo
	 * @return Documento|\WP_Error
	 */
	private function guardar( int $farmacia_id, string $nombre, string $tipo_documento, ?array $archivo, bool $descargable, bool $notificar, bool $solo_pdf, ?string $hash_sha256, bool $publicar, ?string $fecha_documento ) {
		// Antes de tocar el fichero: si viene fecha, tiene que ser valida, y
		// una factura no se guarda sin ella (la de subida nunca la sustituye).
		if ( null !== $fecha_documento && ! self::fecha_documento_valida( $fecha_documento ) ) {
			return new \WP_Error( 'mdf_ca_documento_fecha_invalida', 'Fecha del documento no valida (formato o fuera de rango).' );
		}

		if ( 'factura' === $tipo_documento && null === $fecha_documento ) {
			return new \WP_Error( 'mdf_ca_documento_fecha_obligatoria', 'La fecha del documento es obligatoria para las facturas.' );
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
				'Tipo de fichero no permitido. Solo se aceptan PDF, JPG, PNG, WEBP y Excel (.xlsx).'
			);
		}

		if ( $solo_pdf && 'pdf' !== $filetype['ext'] ) {
			return new \WP_Error( 'mdf_ca_documento_tipo_fichero_no_permitido', 'Solo se acepta PDF.' );
		}

		// Para un PDF, WordPress da por bueno un binario cualquiera llamado
		// ".pdf" si el contenido es "application/octet-stream": el contenido
		// real tiene que ser exactamente un PDF. Si no se puede inspeccionar
		// (sin la extension fileinfo), se rechaza.
		if ( 'pdf' === $filetype['ext'] && 'application/pdf' !== self::mime_real( $archivo['tmp_name'] ) ) {
			return new \WP_Error( 'mdf_ca_documento_pdf_invalido', 'El fichero no es un PDF valido.' );
		}

		if ( 'xlsx' === $filetype['ext'] ) {
			$validacion_xlsx = self::validar_xlsx( $archivo['tmp_name'] );

			if ( is_wp_error( $validacion_xlsx ) ) {
				return $validacion_xlsx;
			}
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
			$tipo_documento,
			// Solo un Excel puede ser descargable; para el resto el flag se
			// ignora (siempre visor), aunque llegue marcado.
			$descargable && self::MIME_XLSX === $filetype['type'],
			! $notificar,
			$hash_sha256,
			$publicar,
			$fecha_documento
		);

		if ( null === $documento ) {
			wp_delete_file( $ruta_destino );

			global $wpdb;
			return new \WP_Error( 'mdf_ca_error_bd', sprintf( 'No se pudo registrar el documento: %s', $wpdb->last_error ) );
		}

		return $documento;
	}

	/** MIME real del contenido (fileinfo), o null si no se puede inspeccionar. */
	private static function mime_real( string $ruta ): ?string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return null;
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = false === $finfo ? false : finfo_file( $finfo, $ruta );

		return is_string( $mime ) ? $mime : null;
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
	 * Un .xlsx es un ZIP (OOXML). WordPress solo mira la extension, asi que
	 * aqui se comprueba que el contenido lo es de verdad y que no lleva nada
	 * ejecutable: estructura minima de libro de Excel, sin vbaProject.bin,
	 * sin objetos OLE embebidos y sin tipo de contenido "macroEnabled"
	 * (un .xlsm renombrado a .xlsx). Nada se extrae a disco.
	 *
	 * @return true|\WP_Error
	 */
	private static function validar_xlsx( string $ruta ) {
		$invalido = new \WP_Error( 'mdf_ca_documento_xlsx_invalido', 'El fichero no es un Excel (.xlsx) valido o contiene macros u objetos embebidos.' );

		if ( ! class_exists( \ZipArchive::class ) ) {
			return new \WP_Error( 'mdf_ca_documento_xlsx_sin_zip', 'El servidor no puede validar ficheros Excel. Contacta con desarrollo antes de seguir.' );
		}

		$zip = new \ZipArchive();

		if ( true !== $zip->open( $ruta, \ZipArchive::RDONLY ) ) {
			return $invalido;
		}

		try {
			if ( $zip->numFiles < 1 || $zip->numFiles > self::XLSX_MAX_ENTRADAS ) {
				return $invalido;
			}

			$nombres      = array();
			$descomprimido = 0;

			for ( $i = 0; $i < $zip->numFiles; $i++ ) {
				$stat = $zip->statIndex( $i );

				if ( false === $stat ) {
					return $invalido;
				}

				$nombres[]      = strtolower( $stat['name'] );
				$descomprimido += (int) $stat['size'];
			}

			if ( $descomprimido > self::XLSX_MAX_DESCOMPRIMIDO_BYTES ) {
				return $invalido;
			}

			if ( ! in_array( '[content_types].xml', $nombres, true ) || ! in_array( 'xl/workbook.xml', $nombres, true ) ) {
				return $invalido;
			}

			foreach ( $nombres as $nombre ) {
				if ( str_ends_with( $nombre, 'vbaproject.bin' ) || str_starts_with( $nombre, 'xl/embeddings/' ) || str_contains( $nombre, '..' ) ) {
					return $invalido;
				}
			}

			$content_types = $zip->getFromName( '[Content_Types].xml' );

			if ( false === $content_types ) {
				return $invalido;
			}

			// El tipo del libro principal decide si es xlsx o xlsm. No se
			// busca "macroEnabled" en todo el fichero: hay generadores
			// legitimos (p. ej. SheetJS) que declaran un Default generico con
			// ese texto sin que exista ninguna macro.
			$xml = simplexml_load_string( $content_types, 'SimpleXMLElement', LIBXML_NONET );

			if ( false === $xml ) {
				return $invalido;
			}

			$tipo_libro = null;

			foreach ( $xml->children() as $nodo ) {
				if ( 'Override' === $nodo->getName() && '/xl/workbook.xml' === (string) $nodo['PartName'] ) {
					$tipo_libro = (string) $nodo['ContentType'];
				}
			}

			if ( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml' !== $tipo_libro ) {
				return $invalido;
			}
		} finally {
			$zip->close();
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
