<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ruta REST de recepcion de facturas (#303): el robot (o la ingesta del
 * historico) envia cada factura como PDF mas el resultado ya calculado por el
 * parser Parser_facturas_SAGE. Es una entrada de ficheros desde fuera: nada se
 * da por bueno de lo que declare el cliente.
 *
 * ==================================================================
 * CONTRATO
 * ==================================================================
 *
 * POST https://<sitio>/wp-json/mdf-ca/v1/facturas
 *
 * Autenticacion: HTTP Basic con el usuario "mdf-robot" y su application
 * password (ver Recepcion_Autenticacion). Cuerpo multipart/form-data:
 *
 *   pdf        Exactamente UN fichero, PDF real (se comprueba el contenido,
 *              maximo 20 MB). Su nombre original se ignora por completo.
 *              Cualquier otro fichero adjunto da 400.
 *   resultado  Objeto JSON de como maximo 8 KB: UNA linea de la salida de
 *              `python -m parser_facturas`, tal cual. Se usan solo estos
 *              campos: estado, emisor, cif, serie, numero, fecha,
 *              importe_total_centimos. Nunca se confia en ellos:
 *              Factura_Cruce_Service::cruzar() los revalida. El resto
 *              ("motivo", "es_abono", etc.) se ignora.
 *              emisor (obligatorio, #314): CIF de la sociedad del grupo que
 *              emite la factura. Tiene que estar en Factura_Emisores y la
 *              serie tiene que ser una de las suyas (serie de 1 a 3 letras,
 *              numero de 1 a 20 cifras); si no, o si falta, 422
 *              formato_inesperado.
 *   notificar  Opcional, "1" o "0" (por defecto "1"). Con "0" el documento no
 *              genera aviso por email a la farmacia (ingesta del historico).
 *
 * Respuestas (JSON, nunca con CIF ni datos personales):
 *
 *   201 {"resultado":"aceptado","documento_id":N,"publicado":true|false}
 *       Estado "asignada": documento creado en la farmacia del CIF (tipo
 *       factura, no descargable, nombre en disco aleatorio, carpeta privada),
 *       con su emisor, serie y numero guardados. Nombre visible
 *       "Factura <sociedad> <serie> <numero>" ("Abono ..." si el importe
 *       revalidado es negativo), p. ej. "Factura Formación SF 186".
 *       "publicado":false = pendiente de publicar: invisible para toda
 *       farmacia hasta que un administrador lo publica en el backoffice. Es
 *       lo normal mientras la option mdf_ca_recepcion_requiere_aprobacion
 *       este activa (lo esta por defecto); apagada, nace publicado.
 *   200 {"resultado":"duplicado","documento_id":N}
 *       Ya estaba guardado para esa farmacia el mismo PDF (SHA-256) o la
 *       misma factura (mismo emisor, serie y numero, aunque el PDF tenga
 *       otros bytes). No se escribe nada.
 *   422 {"resultado":"excepcion","estado":"...","motivo":"..."}
 *       No se guarda nada. estado: sin_texto, formato_inesperado,
 *       numero_discrepante, sin_cif, cif_invalido, cif_sin_farmacia o
 *       documento_en_otra_farmacia (el mismo PDF, o la misma factura, ya esta
 *       guardado para OTRA farmacia; no dice cual). El motivo es un texto
 *       fijo del servidor.
 *   Cualquier error: {"code":"mdf_ca_peticion_rechazada","message":"Peticion rechazada."}
 *       con el mismo cuerpo siempre; solo cambia el codigo HTTP, para que el
 *       robot sepa si reintentar:
 *         401 sin credenciales, credenciales erroneas o usuario sin el rol
 *             (indistinguibles entre si)
 *         400 peticion o fichero no validos
 *         429 tope diario de documentos aceptados alcanzado
 *         503 recepcion apagada (interruptor)
 *         500 error interno
 *
 * Orden: autenticacion y capacidad (permission_callback) > interruptor >
 * tope diario > forma de la peticion > cruce con las farmacias > hash y
 * duplicados > guardado. Cada paso que falla corta sin escribir nada.
 */
class Recepcion_Documentos_Endpoint {

	public const REST_NAMESPACE = 'mdf-ca/v1';
	public const RUTA           = '/facturas';

	private const MAX_JSON_BYTES   = 8192;
	private const MAX_CAMPO_BYTES  = 200;
	private const CAMPOS_RESULTADO = array( 'estado', 'emisor', 'cif', 'serie', 'numero', 'fecha', 'importe_total_centimos' );

	/** Textos fijos del servidor para cada excepcion (el motivo del cliente no se devuelve). */
	private const MOTIVOS = array(
		Factura_Cruce_Service::SIN_TEXTO          => 'El PDF no tiene texto extraible.',
		Factura_Cruce_Service::FORMATO_INESPERADO => 'La factura no sigue el formato esperado.',
		Factura_Cruce_Service::NUMERO_DISCREPANTE => 'El numero de factura no coincide con el del nombre del fichero.',
		Factura_Cruce_Service::SIN_CIF            => 'La factura no tiene CIF/NIF de cliente.',
		Factura_Cruce_Service::CIF_INVALIDO       => 'El CIF/NIF del cliente no es valido.',
		Factura_Cruce_Service::CIF_SIN_FARMACIA   => 'Ninguna farmacia dada de alta tiene ese CIF/NIF.',
		'documento_en_otra_farmacia'              => 'El documento ya esta guardado para otra farmacia.',
	);

	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'registrar_ruta' ) );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'uniformar_errores' ), 10, 3 );
	}

	public static function registrar_ruta(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			self::RUTA,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'recibir' ),
				'permission_callback' => array( Recepcion_Autenticacion::class, 'autorizar' ),
				// Fuera del indice publico de la API.
				'show_in_index'       => false,
			)
		);
	}

	/**
	 * Cualquier error de nuestro namespace (incluidos los de autenticacion
	 * del nucleo de WordPress, que llegan aqui ya convertidos en respuesta)
	 * sale con el mismo cuerpo, sin pistas de que fallo. 401 y 403 se
	 * unifican en 401. Una respuesta propia con "resultado" (la 422 de las
	 * excepciones) no es un error generico y se deja como esta.
	 *
	 * @param \WP_HTTP_Response $respuesta
	 * @return \WP_HTTP_Response
	 */
	public static function uniformar_errores( $respuesta, \WP_REST_Server $server, \WP_REST_Request $request ) {
		if ( ! $respuesta instanceof \WP_REST_Response || 0 !== strpos( $request->get_route(), '/' . self::REST_NAMESPACE . '/' ) ) {
			return $respuesta;
		}

		$datos = $respuesta->get_data();

		if ( ! $respuesta->is_error() || ( is_array( $datos ) && isset( $datos['resultado'] ) ) ) {
			return $respuesta;
		}

		$estado = in_array( $respuesta->get_status(), array( 401, 403 ), true ) ? 401 : $respuesta->get_status();

		return new \WP_REST_Response( self::cuerpo_error(), $estado );
	}

	/** @return array{code: string, message: string} */
	private static function cuerpo_error(): array {
		return array(
			'code'    => 'mdf_ca_peticion_rechazada',
			'message' => 'Peticion rechazada.',
		);
	}

	private static function error( int $status ): \WP_Error {
		return new \WP_Error( 'mdf_ca_peticion_rechazada', 'Peticion rechazada.', array( 'status' => $status ) );
	}

	/** Un error de peticion o de fichero: se cuenta como rechazado. */
	private static function rechazar( int $status = 400 ): \WP_Error {
		Recepcion_Registro::registrar( Recepcion_Registro::RECHAZADO );

		return self::error( $status );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function recibir( \WP_REST_Request $request ) {
		// Con la recepcion apagada o el tope alcanzado, no se escribe nada
		// (tampoco el registro).
		if ( ! Recepcion_Registro::esta_activa() ) {
			return self::error( 503 );
		}

		if ( Recepcion_Registro::tope_alcanzado() ) {
			return self::error( 429 );
		}

		$archivo   = self::leer_archivo( $request );
		$resultado = self::leer_resultado( $request );
		$notificar = self::leer_notificar( $request );

		if ( null === $archivo || null === $resultado || null === $notificar ) {
			return self::rechazar();
		}

		// El cruce revalida todo. Es_abono se recalcula del signo del importe:
		// no se acepta el que declare el cliente.
		$cruce = ( new Factura_Cruce_Service() )->cruzar( $resultado );

		if ( Factura_Cruce_Service::ASIGNADA !== $cruce['estado'] ) {
			return self::excepcion( $cruce['estado'] );
		}

		$hash = hash_file( 'sha256', $archivo['tmp_name'] );

		if ( false === $hash ) {
			return self::rechazar();
		}

		$repositorio = new Documento_Repository();
		$factura     = array(
			'emisor_cif' => $cruce['emisor'],
			'serie'      => $cruce['serie'],
			'numero'     => $cruce['numero'],
		);
		$existentes  = self::buscar_existentes( $repositorio, $hash, $factura );

		if ( $existentes ) {
			return self::respuesta_duplicado( $existentes, (int) $cruce['farmacia_id'] );
		}

		$etiqueta  = $cruce['importe_total_centimos'] < 0 ? 'Abono' : 'Factura';
		$publicar  = ! Recepcion_Registro::requiere_aprobacion();
		$documento = ( new Documento_Service() )->subir_factura_recibida(
			(int) $cruce['farmacia_id'],
			sprintf( '%s %s %s %s', $etiqueta, Factura_Emisores::nombre_corto( $cruce['emisor'] ), $cruce['serie'], $cruce['numero'] ),
			$archivo,
			$hash,
			$factura,
			$notificar,
			$publicar,
			$cruce['fecha'] // revalidada por Factura_Cruce_Service, nunca la declarada
		);

		if ( is_wp_error( $documento ) ) {
			// Dos envios de la misma factura a la vez: el UNIQUE del hash o el
			// de la factura hace fallar el segundo INSERT. Si ahora existe, es
			// un duplicado (o la misma factura en otra farmacia).
			$existentes = self::buscar_existentes( $repositorio, $hash, $factura );

			if ( $existentes ) {
				return self::respuesta_duplicado( $existentes, (int) $cruce['farmacia_id'] );
			}

			return self::rechazar( in_array( $documento->get_error_code(), array( 'mdf_ca_error_bd', 'mdf_ca_documento_carpeta_no_disponible', 'mdf_ca_documento_error_escritura' ), true ) ? 500 : 400 );
		}

		Recepcion_Registro::registrar( Recepcion_Registro::ACEPTADO );

		return new \WP_REST_Response(
			array(
				'resultado'    => 'aceptado',
				'documento_id' => $documento->get_id(),
				'publicado'    => $documento->is_publicado(),
			),
			201
		);
	}

	/**
	 * Documentos ya guardados con el mismo PDF (hash) o la misma factura
	 * (emisor, serie y numero), sin repetir: normalmente uno o ninguno.
	 *
	 * @param array{emisor_cif: string, serie: string, numero: string} $factura
	 * @return Documento[]
	 */
	private static function buscar_existentes( Documento_Repository $repositorio, string $hash, array $factura ): array {
		$existentes = array();

		foreach ( array(
			$repositorio->find_by_hash( $hash ),
			$repositorio->find_by_factura( $factura['emisor_cif'], $factura['serie'], $factura['numero'] ),
		) as $documento ) {
			if ( $documento ) {
				$existentes[ $documento->get_id() ] = $documento;
			}
		}

		return array_values( $existentes );
	}

	/** @param Documento[] $existentes No vacio. */
	private static function respuesta_duplicado( array $existentes, int $farmacia_id ): \WP_REST_Response {
		// El mismo PDF o la misma factura para otra farmacia no es un
		// duplicado: es un dato incoherente, y no se escribe nada.
		foreach ( $existentes as $existente ) {
			if ( $existente->get_farmacia_id() !== $farmacia_id ) {
				return self::excepcion( 'documento_en_otra_farmacia' );
			}
		}

		Recepcion_Registro::registrar( Recepcion_Registro::DUPLICADO );

		return new \WP_REST_Response(
			array(
				'resultado'    => 'duplicado',
				'documento_id' => $existentes[0]->get_id(),
			),
			200
		);
	}

	private static function excepcion( string $estado ): \WP_REST_Response {
		if ( ! isset( self::MOTIVOS[ $estado ] ) ) {
			$estado = Factura_Cruce_Service::FORMATO_INESPERADO;
		}

		Recepcion_Registro::registrar( Recepcion_Registro::EXCEPCION );

		return new \WP_REST_Response(
			array(
				'resultado' => 'excepcion',
				'estado'    => $estado,
				'motivo'    => self::MOTIVOS[ $estado ],
			),
			422
		);
	}

	/**
	 * Exactamente un fichero "pdf". El nombre original se sustituye antes de
	 * validar: no se usa para nada.
	 *
	 * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
	 */
	private static function leer_archivo( \WP_REST_Request $request ): ?array {
		$ficheros = $request->get_file_params();

		if ( 1 !== count( $ficheros ) || ! isset( $ficheros['pdf'] ) || ! is_array( $ficheros['pdf'] ) ) {
			return null;
		}

		$pdf = $ficheros['pdf'];

		// Sintaxis pdf[] de multipart: cada campo seria un array.
		if ( ! isset( $pdf['tmp_name'], $pdf['error'], $pdf['size'] ) || ! is_string( $pdf['tmp_name'] ) || ! is_int( $pdf['error'] ) || ! is_numeric( $pdf['size'] ) ) {
			return null;
		}

		return array(
			'name'     => 'factura.pdf',
			'type'     => 'application/pdf',
			'tmp_name' => $pdf['tmp_name'],
			'error'    => $pdf['error'],
			'size'     => (int) $pdf['size'],
		);
	}

	/**
	 * JSON del parser reducido a los campos conocidos y a valores simples.
	 *
	 * @return array<string, mixed>|null null si no es valido.
	 */
	private static function leer_resultado( \WP_REST_Request $request ): ?array {
		$bruto = $request->get_param( 'resultado' );

		if ( ! is_string( $bruto ) || '' === $bruto || strlen( $bruto ) > self::MAX_JSON_BYTES ) {
			return null;
		}

		$datos = json_decode( $bruto, true, 8 );

		if ( ! is_array( $datos ) || array_is_list( $datos ) ) {
			return null;
		}

		$resultado = array();

		foreach ( self::CAMPOS_RESULTADO as $campo ) {
			if ( ! array_key_exists( $campo, $datos ) ) {
				continue;
			}

			$valor = $datos[ $campo ];

			if ( null !== $valor && ! is_string( $valor ) && ! is_int( $valor ) ) {
				return null;
			}

			if ( is_string( $valor ) && strlen( $valor ) > self::MAX_CAMPO_BYTES ) {
				return null;
			}

			$resultado[ $campo ] = $valor;
		}

		return $resultado;
	}

	private static function leer_notificar( \WP_REST_Request $request ): ?bool {
		$valor = $request->get_param( 'notificar' );

		if ( null === $valor ) {
			return true;
		}

		if ( '1' === $valor || 1 === $valor ) {
			return true;
		}

		if ( '0' === $valor || 0 === $valor ) {
			return false;
		}

		return null;
	}
}
