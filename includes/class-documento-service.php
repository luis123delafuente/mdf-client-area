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
	public const TAMANO_MAXIMO_BYTES = 20 * 1024 * 1024;

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

	public const MIME_XLSX = Xlsx_Validator::MIME;

	/** Rango razonable de la fecha del documento: desde esta fecha hasta hoy mas el margen. */
	private const FECHA_DOCUMENTO_MINIMA     = '2000-01-01';
	private const FECHA_DOCUMENTO_MARGEN_DIAS = 31;

	/** Primer ejercicio admitido para un Modelo 347 (#307); el ultimo es el ano en curso. */
	public const EJERCICIO_MINIMO = 2000;

	private Documento_Repository $repository;
	private Farmacia_Repository $farmacia_repository;
	private Publicacion_Evento_Repository $eventos;

	public function __construct( ?Documento_Repository $repository = null, ?Farmacia_Repository $farmacia_repository = null, ?Publicacion_Evento_Repository $eventos = null ) {
		$this->repository          = $repository ?? new Documento_Repository();
		$this->farmacia_repository = $farmacia_repository ?? new Farmacia_Repository();
		$this->eventos             = $eventos ?? new Publicacion_Evento_Repository();
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
	 * Ejercicio de un Modelo 347 valido (#307): del 2000 al ano en curso (en
	 * la zona horaria de WordPress). Es el UNICO sitio que lo decide.
	 */
	public static function ejercicio_valido( int $ejercicio ): bool {
		return $ejercicio >= self::EJERCICIO_MINIMO && $ejercicio <= (int) wp_date( 'Y' );
	}

	/**
	 * Subida desde el backoffice (Admin_Documentos).
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo Un elemento de $_FILES.
	 * @param bool $notificar false para subidas que no deben avisar a la
	 *                        farmacia (p. ej. ingesta del historico). No
	 *                        cambia la visibilidad: solo el aviso por email.
	 * @param string|null $fecha_documento 'Y-m-d'. Obligatoria para las
	 *                        facturas, opcional en el resto. Un Modelo 347 no
	 *                        la usa (se ignora): lleva ejercicio.
	 * @param int|null    $ejercicio  Solo Modelo 347 (#307), obligatorio.
	 * @param string|null $emisor_cif Solo Modelo 347, obligatorio: una
	 *                        sociedad de Factura_Emisores. En el resto de tipos
	 *                        ejercicio y emisor se ignoran.
	 * @return Documento|\WP_Error
	 */
	public function subir( int $farmacia_id, string $nombre, string $tipo_documento, ?array $archivo, bool $descargable = false, bool $notificar = true, ?string $fecha_documento = null, ?int $ejercicio = null, ?string $emisor_cif = null ) {
		if ( ! $this->farmacia_repository->find_by_id( $farmacia_id ) ) {
			return new \WP_Error( 'mdf_ca_documento_farmacia_no_existe', 'Selecciona una farmacia valida.' );
		}

		if ( ! Documento_Tipos::es_valido( $tipo_documento ) ) {
			return new \WP_Error( 'mdf_ca_documento_tipo_invalido', 'Selecciona un tipo de documento valido.' );
		}

		$modelo_347 = null;

		if ( 'modelo_347' === $tipo_documento ) {
			$modelo_347 = self::validar_modelo_347( $ejercicio, $emisor_cif );

			if ( is_wp_error( $modelo_347 ) ) {
				return $modelo_347;
			}

			// El 347 se agrupa por ejercicio, nunca por una fecha, y su nombre
			// visible lo fija el servidor (el escrito a mano se ignora).
			$fecha_documento = null;
			$nombre          = self::nombre_modelo_347( $modelo_347 );
		}

		$nombre = trim( $nombre );

		if ( '' === $nombre ) {
			return new \WP_Error( 'mdf_ca_documento_nombre_vacio', 'El nombre del documento no puede estar vacio.' );
		}

		// La subida de backoffice siempre publica.
		return $this->guardar( $farmacia_id, $nombre, $tipo_documento, $archivo, $descargable, $notificar, false, null, true, $fecha_documento, null, $modelo_347 );
	}

	/**
	 * Nombre visible de un Modelo 347 (#307), unico sitio que lo decide:
	 * "Modelo 347 Mediformplus 2025", "Modelo 347 Formación 2025".
	 *
	 * @param array{emisor_cif: string, ejercicio: int} $modelo_347 Ya validado.
	 */
	public static function nombre_modelo_347( array $modelo_347 ): string {
		return sprintf( 'Modelo 347 %s %d', Factura_Emisores::nombre_corto( $modelo_347['emisor_cif'] ), $modelo_347['ejercicio'] );
	}

	/**
	 * Ejercicio y emisor de un Modelo 347, validados (#307).
	 *
	 * @return array{emisor_cif: string, ejercicio: int}|\WP_Error
	 */
	private static function validar_modelo_347( ?int $ejercicio, ?string $emisor_cif ) {
		if ( null === $ejercicio ) {
			return new \WP_Error( 'mdf_ca_documento_ejercicio_obligatorio', 'El ejercicio es obligatorio para el Modelo 347.' );
		}

		if ( ! self::ejercicio_valido( $ejercicio ) ) {
			return new \WP_Error( 'mdf_ca_documento_ejercicio_invalido', sprintf( 'Ejercicio no valido: tiene que estar entre %d y el ano en curso.', self::EJERCICIO_MINIMO ) );
		}

		$emisor_cif = null === $emisor_cif ? '' : Identificador_Fiscal_Validator::normalizar( $emisor_cif );

		if ( ! Factura_Emisores::es_emisor( $emisor_cif ) ) {
			return new \WP_Error( 'mdf_ca_documento_emisor_obligatorio', 'Selecciona la sociedad emisora del Modelo 347.' );
		}

		return array(
			'emisor_cif' => $emisor_cif,
			'ejercicio'  => $ejercicio,
		);
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
	 * @param array{emisor_cif: string, serie: string, numero: string} $factura
	 *                       Emisor, serie y numero YA revalidados por
	 *                       Factura_Cruce_Service (#314). UNIQUE: la misma
	 *                       factura no se guarda dos veces aunque cambien los
	 *                       bytes del PDF.
	 * @param bool $publicar false deja el documento pendiente de publicar
	 *                       (invisible para toda farmacia hasta que un
	 *                       administrador lo publica, #304).
	 * @param string|null $fecha_documento La fecha de la factura YA revalidada
	 *                       por Factura_Cruce_Service (nunca la declarada sin
	 *                       validar). Obligatoria: guardar() la exige.
	 * @param string|null $origen_alta null en la recepcion REST (sin evento si
	 *                       nace pendiente, como siempre). "Subir facturas"
	 *                       (#334) pasa ORIGEN_SUBIDA_FACTURAS: el alta deja
	 *                       siempre un evento con ese origen y el usuario,
	 *                       "recibido" si nace pendiente, en la misma
	 *                       transaccion que el INSERT.
	 * @param string|null $accion_alta Accion de ese evento si nace pendiente
	 *                       (por defecto "recibido"; la bandeja, #335, usa
	 *                       "asignado_manual").
	 *
	 * $archivo puede ser una subida HTTP o, con 'desde_disco' => true, un PDF
	 * que ya esta en la carpeta privada (la bandeja de facturas, #335): mismas
	 * validaciones salvo is_uploaded_file(), y se copia en vez de moverse
	 * (quien llama borra el original cuando todo ha ido bien). La clave solo
	 * la pone el codigo del plugin: $_FILES nunca la trae.
	 * @return Documento|\WP_Error
	 */
	public function subir_factura_recibida( int $farmacia_id, string $nombre, ?array $archivo, string $hash_sha256, array $factura, bool $notificar = true, bool $publicar = true, ?string $fecha_documento = null, ?string $origen_alta = null, ?string $accion_alta = null ) {
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

		// Defensa en profundidad: quien llama ya lo ha revalidado.
		if ( ! isset( $factura['emisor_cif'], $factura['serie'], $factura['numero'] )
			|| ! is_string( $factura['emisor_cif'] ) || ! is_string( $factura['serie'] ) || ! is_string( $factura['numero'] )
			|| ! Factura_Emisores::admite( $factura['emisor_cif'], $factura['serie'] )
			|| 1 !== preg_match( '/^\d{1,20}$/D', $factura['numero'] ) ) {
			return new \WP_Error( 'mdf_ca_documento_factura_invalida', 'Emisor, serie o numero de factura no validos.' );
		}

		return $this->guardar( $farmacia_id, $nombre, 'factura', $archivo, false, $notificar, true, $hash_sha256, $publicar, $fecha_documento, $factura, null, $origen_alta, $accion_alta );
	}

	/**
	 * Camino comun de subir() y subir_factura_recibida(): valida el fichero,
	 * lo mueve a la carpeta privada con nombre aleatorio y registra la fila.
	 *
	 * @param array{name?: string, type?: string, tmp_name?: string, error?: int, size?: int}|null $archivo
	 * @param array{emisor_cif: string, serie: string, numero: string}|null $factura Solo la recepcion.
	 * @param array{emisor_cif: string, ejercicio: int}|null $modelo_347 Solo el Modelo 347 (#307), ya validado.
	 * @return Documento|\WP_Error
	 */
	private function guardar( int $farmacia_id, string $nombre, string $tipo_documento, ?array $archivo, bool $descargable, bool $notificar, bool $solo_pdf, ?string $hash_sha256, bool $publicar, ?string $fecha_documento, ?array $factura, ?array $modelo_347 = null, ?string $origen_alta = null, ?string $accion_alta = null ) {
		// Antes de tocar el fichero: si viene fecha, tiene que ser valida, y
		// una factura no se guarda sin ella (la de subida nunca la sustituye).
		if ( null !== $fecha_documento && ! self::fecha_documento_valida( $fecha_documento ) ) {
			return new \WP_Error( 'mdf_ca_documento_fecha_invalida', 'Fecha del documento no valida (formato o fuera de rango).' );
		}

		if ( 'factura' === $tipo_documento && null === $fecha_documento ) {
			return new \WP_Error( 'mdf_ca_documento_fecha_obligatoria', 'La fecha del documento es obligatoria para las facturas.' );
		}

		// Un unico Modelo 347 vigente por farmacia, emisor y ejercicio (#307):
		// nunca se sustituye en silencio. El UNIQUE modelo_347 lo garantiza
		// ademas frente a dos subidas a la vez (ver mas abajo).
		if ( null !== $modelo_347 && $this->repository->find_modelo_347_vigente( $farmacia_id, $modelo_347['emisor_cif'], $modelo_347['ejercicio'] ) ) {
			return self::error_modelo_347_ya_existe( $modelo_347 );
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

		$tmp         = $archivo['tmp_name'];
		$desde_disco = ! empty( $archivo['desde_disco'] );

		return $this->registrar(
			$farmacia_id,
			$nombre,
			$tipo_documento,
			$filetype['ext'],
			$filetype['type'],
			(int) $archivo['size'],
			static fn( string $destino ): bool => $desde_disco ? copy( $tmp, $destino ) : move_uploaded_file( $tmp, $destino ),
			$descargable,
			$notificar,
			$hash_sha256,
			$publicar,
			$fecha_documento,
			$factura,
			$modelo_347,
			$origen_alta,
			$accion_alta
		);
	}

	/**
	 * Carta del Modelo 347 generada en el servidor (#307, generador desde el
	 * Excel de SAGE): mismo camino que la subida manual de un 347 (mismo
	 * validador de ejercicio y emisor, mismo nombre visible, misma unicidad
	 * por farmacia, emisor y ejercicio, misma carpeta privada y mismo
	 * registro), con dos diferencias: el PDF llega en memoria (nunca hubo
	 * subida HTTP) y el documento nace PENDIENTE de publicar, para que
	 * Administracion revise alguna carta antes de publicar y enviar. Sin hash:
	 * cada generacion produce bytes distintos (fecha interna del PDF).
	 *
	 * notificado_en queda en NULL: el 347 no entra en el aviso generico
	 * (Documento_Repository::find_pendientes_aviso() lo excluye) y lo marca el
	 * envio propio del 347 cuando el correo sale.
	 *
	 * @return Documento|\WP_Error
	 */
	public function guardar_modelo_347_generado( int $farmacia_id, string $emisor_cif, int $ejercicio, string $pdf ) {
		if ( ! $this->farmacia_repository->find_by_id( $farmacia_id ) ) {
			return new \WP_Error( 'mdf_ca_documento_farmacia_no_existe', 'Farmacia no valida.' );
		}

		$modelo_347 = self::validar_modelo_347( $ejercicio, $emisor_cif );

		if ( is_wp_error( $modelo_347 ) ) {
			return $modelo_347;
		}

		if ( $this->repository->find_modelo_347_vigente( $farmacia_id, $modelo_347['emisor_cif'], $modelo_347['ejercicio'] ) ) {
			return self::error_modelo_347_ya_existe( $modelo_347 );
		}

		$tamano = strlen( $pdf );

		if ( $tamano < 1 || $tamano > self::TAMANO_MAXIMO_BYTES || ! str_starts_with( $pdf, '%PDF-' ) || 'application/pdf' !== self::mime_real_buffer( $pdf ) ) {
			return new \WP_Error( 'mdf_ca_documento_pdf_invalido', 'El fichero no es un PDF valido.' );
		}

		return $this->registrar(
			$farmacia_id,
			self::nombre_modelo_347( $modelo_347 ),
			'modelo_347',
			'pdf',
			'application/pdf',
			$tamano,
			static function ( string $destino ) use ( $pdf ): bool {
				// 'x': nunca sobrescribe (el nombre es aleatorio de 128 bits).
				$fichero = fopen( $destino, 'xb' );

				if ( false === $fichero ) {
					return false;
				}

				$escritos = fwrite( $fichero, $pdf );
				fclose( $fichero );

				if ( strlen( $pdf ) !== $escritos ) {
					wp_delete_file( $destino );

					return false;
				}

				chmod( $destino, 0644 );

				return true;
			},
			false,
			// "true" = notificado_en queda en NULL hasta que salga su correo
			// (Modelo_347_Envio_Service lo marca entonces). El aviso generico
			// no lo ve igualmente: excluye el tipo modelo_347.
			true,
			null,
			false,
			null,
			null,
			$modelo_347
		);
	}

	/**
	 * Parte comun de cualquier alta de documento, con el fichero ya validado:
	 * carpeta privada disponible, transaccion si nace publicado, nombre en
	 * disco aleatorio, escritura ($escribir: mover la subida o volcar bytes),
	 * INSERT (con el error de BD silenciado cuando un UNIQUE puede delatar
	 * datos), evento si nace publicado y vuelta atras si algo falla.
	 *
	 * @param callable(string): bool $escribir Recibe la ruta destino; true si el fichero ha quedado escrito.
	 * @param array{emisor_cif: string, serie: string, numero: string}|null $factura
	 * @param array{emisor_cif: string, ejercicio: int}|null $modelo_347
	 * @return Documento|\WP_Error
	 */
	private function registrar( int $farmacia_id, string $nombre, string $tipo_documento, string $extension, string $tipo_mime, int $tamano, callable $escribir, bool $descargable, bool $notificar, ?string $hash_sha256, bool $publicar, ?string $fecha_documento, ?array $factura, ?array $modelo_347, ?string $origen_alta = null, ?string $accion_alta = null ) {
		$carpeta = Documento_Endpoint::get_carpeta_documentos();

		if ( ! is_dir( $carpeta ) || ! is_writable( $carpeta ) ) {
			return new \WP_Error(
				'mdf_ca_documento_carpeta_no_disponible',
				'La carpeta de documentos privados no esta disponible en el servidor. Contacta con desarrollo antes de seguir.'
			);
		}

		// Un documento que nace publicado deja su evento de publicacion en la
		// misma transaccion que el INSERT (#318): hace falta InnoDB. Se
		// comprueba antes de tocar el disco. Uno que nace pendiente por la
		// recepcion REST no escribe evento; con $origen_alta ("Subir
		// facturas", #334) deja siempre uno, "recibido" si nace pendiente.
		$con_evento = $publicar || null !== $origen_alta;

		if ( $con_evento
			&& ( ! DB_Schema::tabla_es_innodb( DB_Schema::get_documentos_table_name() )
				|| ! DB_Schema::tabla_es_innodb( DB_Schema::get_publicacion_eventos_table_name() ) ) ) {
			return new \WP_Error(
				'mdf_ca_publicacion_sin_transacciones',
				'Las tablas de documentos o de eventos no admiten transacciones (no son InnoDB). No se ha guardado nada.'
			);
		}

		$nombre_fichero = self::generar_nombre_fichero( $farmacia_id, $extension );
		$ruta_destino   = $carpeta . DIRECTORY_SEPARATOR . $nombre_fichero;

		if ( ! $escribir( $ruta_destino ) ) {
			return new \WP_Error( 'mdf_ca_documento_error_escritura', 'No se pudo guardar el fichero en el servidor.' );
		}

		// En la recepcion, un INSERT que pierde una carrera choca con un UNIQUE
		// (hash o factura) y $wpdb escribiria en el log "Duplicate entry ..."
		// con la serie y el numero: se silencia y el error no lleva el texto
		// de la BD. Quien llama vuelve a buscar y responde duplicado.
		// Lo mismo con el UNIQUE modelo_347 (#307).
		global $wpdb;
		$silenciar = null !== $factura || null !== $modelo_347;
		$errores   = $silenciar ? $wpdb->suppress_errors( true ) : null;

		if ( $con_evento ) {
			$wpdb->query( 'START TRANSACTION' );
		}

		try {
			$documento = $this->repository->insert(
				$farmacia_id,
				$nombre,
				$nombre_fichero,
				$tipo_mime,
				$tamano,
				$tipo_documento,
				// Solo un Excel puede ser descargable; para el resto el flag se
				// ignora (siempre visor), aunque llegue marcado.
				$descargable && self::MIME_XLSX === $tipo_mime,
				! $notificar,
				$hash_sha256,
				$publicar,
				$fecha_documento,
				$factura,
				$modelo_347
			);
		} finally {
			if ( $silenciar ) {
				$wpdb->suppress_errors( $errores );
			}
		}

		if ( null === $documento ) {
			if ( $con_evento ) {
				$wpdb->query( 'ROLLBACK' );
			}

			wp_delete_file( $ruta_destino );

			// Otra subida del mismo 347 gano la carrera: el mismo mensaje claro.
			if ( null !== $modelo_347 && $this->repository->find_modelo_347_vigente( $farmacia_id, $modelo_347['emisor_cif'], $modelo_347['ejercicio'] ) ) {
				return self::error_modelo_347_ya_existe( $modelo_347 );
			}

			return new \WP_Error(
				'mdf_ca_error_bd',
				$silenciar ? 'No se pudo registrar el documento.' : sprintf( 'No se pudo registrar el documento: %s', $wpdb->last_error )
			);
		}

		if ( $con_evento ) {
			// Quien lo da de alta: el robot (recepcion sin aprobacion), el
			// administrador (subida de backoffice) o quien usa "Subir facturas".
			$origen = $origen_alta ?? ( null !== $factura ? Publicacion_Evento_Repository::ORIGEN_RECEPCION : Publicacion_Evento_Repository::ORIGEN_SUBIDA_BACKOFFICE );
			$evento = $this->eventos->insertar_para(
				array( $documento->get_id() => null ),
				$publicar ? Publicacion_Evento_Repository::ACCION_PUBLICADO : ( $accion_alta ?? Publicacion_Evento_Repository::ACCION_RECIBIDO ),
				$origen,
				get_current_user_id()
			);

			if ( false === $evento ) {
				$wpdb->query( 'ROLLBACK' );
				wp_delete_file( $ruta_destino );

				return new \WP_Error( 'mdf_ca_error_bd', 'No se pudo registrar el documento.' );
			}

			$wpdb->query( 'COMMIT' );
		}

		return $documento;
	}

	/**
	 * Unico sitio del texto. Lleva el nombre corto de la sociedad del grupo y
	 * el ejercicio, nunca datos de la farmacia.
	 *
	 * @param array{emisor_cif: string, ejercicio: int} $modelo_347
	 */
	private static function error_modelo_347_ya_existe( array $modelo_347 ): \WP_Error {
		return new \WP_Error(
			'mdf_ca_documento_modelo_347_ya_existe',
			sprintf(
				'Esta farmacia ya tiene un Modelo 347 de %s del ejercicio %d. Para subir una correccion, retira antes el anterior ("Retirar para correccion" en la lista de documentos).',
				Factura_Emisores::nombre_corto( $modelo_347['emisor_cif'] ),
				$modelo_347['ejercicio']
			)
		);
	}

	/** MIME real de un contenido en memoria (fileinfo), o null si no se puede inspeccionar. */
	private static function mime_real_buffer( string $contenido ): ?string {
		if ( ! function_exists( 'finfo_open' ) ) {
			return null;
		}

		$finfo = finfo_open( FILEINFO_MIME_TYPE );
		$mime  = false === $finfo ? false : finfo_buffer( $finfo, $contenido );

		return is_string( $mime ) ? $mime : null;
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

		if ( empty( $archivo['desde_disco'] ) ? ! is_uploaded_file( $archivo['tmp_name'] ) : ! is_file( $archivo['tmp_name'] ) ) {
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
	 * Validacion por contenido de un .xlsx: vive en Xlsx_Validator (la
	 * comparte el generador del Modelo 347), con el mismo comportamiento.
	 *
	 * @return true|\WP_Error
	 */
	private static function validar_xlsx( string $ruta ) {
		return Xlsx_Validator::validar( $ruta );
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
