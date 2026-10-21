<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Casos de uso de las herramientas del catalogo: valida antes de escribir y
 * nunca deja una herramienta a medias. Punto de entrada unico para el
 * backoffice (Admin_Catalogo).
 *
 * El archivo de una herramienta va a la carpeta privada, en su propia
 * subcarpeta (CARPETA_RELATIVA), con un nombre generado y la misma
 * proteccion (.htaccess de denegacion e index.php, Carpeta_Privada_
 * Proteccion). Solo lo sirve Herramienta_Endpoint.
 *
 * Orden al guardar con archivo nuevo: se escribe el fichero nuevo, despues
 * la BD en una transaccion y, solo si la BD confirma, se borra el antiguo.
 * Si la BD falla, se borra el nuevo y el antiguo sigue en uso. Al eliminar,
 * el fichero se aparta con un rename dentro de la carpeta antes del DELETE
 * (mismo patron que Documento_Eliminacion_Service) y se borra tras el COMMIT.
 */
class Catalogo_Service {

	public const CARPETA_RELATIVA = Documento_Endpoint::CARPETA_RELATIVA . '/herramientas';

	public const MAX_NOMBRE      = 255;
	public const MAX_DESCRIPCION = 255;

	private Catalogo_Repository $repository;
	private Plan_Repository $plan_repository;

	public function __construct( ?Catalogo_Repository $repository = null, ?Plan_Repository $plan_repository = null ) {
		$this->repository      = $repository ?? new Catalogo_Repository();
		$this->plan_repository = $plan_repository ?? new Plan_Repository();
	}

	public static function get_carpeta(): string {
		return ABSPATH . self::CARPETA_RELATIVA;
	}

	/**
	 * Crea ($id = 0) o actualiza una herramienta.
	 *
	 * @param array{
	 *   nombre: string, descripcion: string, categoria_id: int, etiqueta_ids: int[],
	 *   recurso: string, enlace_url: string, descargable: bool, marca_agua: bool,
	 *   plan_ids: int[], solo_farmacias: bool, farmacia_ids: int[], visible: bool
	 * } $entrada
	 * @param array|null $archivo De $_FILES; sin archivo, se conserva el actual si lo hay.
	 * @return array{item: Catalogo_Item, avisos: string[]}|\WP_Error
	 */
	public function guardar( int $id, array $entrada, ?array $archivo ) {
		$actual = $id > 0 ? $this->repository->find_by_id( $id ) : null;

		if ( $id > 0 && ! $actual ) {
			return new \WP_Error( 'mdf_ca_catalogo_no_existe', 'La herramienta que intentas editar ya no existe.' );
		}

		$nombre       = trim( $entrada['nombre'] );
		$descripcion  = trim( $entrada['descripcion'] );
		$farmacia_ids = $entrada['solo_farmacias'] ? array_values( array_unique( array_map( 'intval', $entrada['farmacia_ids'] ) ) ) : array();
		$plan_ids     = array_values( array_unique( array_map( 'intval', $entrada['plan_ids'] ) ) );
		$etiqueta_ids = array_values( array_unique( array_map( 'intval', $entrada['etiqueta_ids'] ) ) );

		$validacion = $this->validar_campos( $nombre, $descripcion, (int) $entrada['categoria_id'], $etiqueta_ids, $plan_ids, $farmacia_ids, $entrada['recurso'] );

		if ( is_wp_error( $validacion ) ) {
			return $validacion;
		}

		$datos = array(
			'nombre'             => $nombre,
			'descripcion'        => '' !== $descripcion ? $descripcion : null,
			'categoria_id'       => (int) $entrada['categoria_id'],
			'recurso'            => $entrada['recurso'],
			'visible'            => (bool) $entrada['visible'],
			'fecha_modificacion' => current_time( 'mysql', true ),
		);

		$avisos        = array();
		$fichero_nuevo = null;
		$hay_subida    = UPLOAD_ERR_NO_FILE !== ( $archivo['error'] ?? UPLOAD_ERR_NO_FILE );

		if ( Catalogo_Item::RECURSO_ENLACE === $entrada['recurso'] ) {
			$enlace = trim( $entrada['enlace_url'] );

			if ( '' === $enlace || ! self::es_url_valida( $enlace ) ) {
				return new \WP_Error( 'mdf_ca_catalogo_enlace_invalido', 'El enlace no es una URL válida (debe empezar por http:// o https://).' );
			}

			// Un enlace no tiene archivo ni configuracion: se vacia todo lo del archivo.
			$datos += array(
				'enlace_url'   => $enlace,
				'formato'      => null,
				'ruta_fichero' => null,
				'tipo_mime'    => null,
				'tamano'       => null,
				'descargable'  => false,
				'marca_agua'   => true,
			);
		} else {
			if ( $hay_subida ) {
				$subida = Catalogo_Archivo_Validator::validar( $archivo, Catalogo_Ajustes::permite_xlsm() );

				if ( is_wp_error( $subida ) ) {
					return $subida;
				}

				$fichero_nuevo = $this->escribir_fichero( $archivo['tmp_name'], $subida['formato'] );

				if ( is_wp_error( $fichero_nuevo ) ) {
					return $fichero_nuevo;
				}

				$formato = $subida['formato'];
				$datos  += array(
					'formato'      => $formato,
					'ruta_fichero' => $fichero_nuevo,
					'tipo_mime'    => $subida['mime'],
					'tamano'       => $subida['tamano'],
				);
			} elseif ( $actual && $actual->es_archivo() && $actual->recurso_completo() ) {
				$formato = (string) $actual->get_formato();
			} else {
				return new \WP_Error( 'mdf_ca_herramienta_sin_fichero', 'Selecciona un archivo.' );
			}

			// Word, PowerPoint y .xlsm: siempre descargables y sin marca (no hay visor).
			$siempre_descargable = Catalogo_Formatos::es_siempre_descargable( $formato );

			$datos += array(
				'enlace_url'  => null,
				'descargable' => $siempre_descargable || (bool) $entrada['descargable'],
				'marca_agua'  => ! $siempre_descargable && (bool) $entrada['marca_agua'],
			);

			if ( Catalogo_Formatos::XLSM === $formato && $fichero_nuevo ) {
				$avisos[] = 'Excel con macros: se ofrecerá siempre como descarga y nunca en el visor. Las macros se ejecutarán en el equipo de la farmacia si las habilita.';
			}
		}

		$guardado = $this->guardar_en_bd( $id, $datos, $plan_ids, $farmacia_ids, $etiqueta_ids );

		if ( is_wp_error( $guardado ) ) {
			if ( $fichero_nuevo ) {
				self::borrar_fichero( $fichero_nuevo );
			}

			return $guardado;
		}

		// El antiguo se borra solo cuando la BD ya apunta al nuevo (o a un enlace).
		$ruta_antigua = $actual ? $actual->get_ruta_fichero() : null;

		if ( null !== $ruta_antigua && ( $fichero_nuevo || Catalogo_Item::RECURSO_ENLACE === $entrada['recurso'] ) ) {
			self::borrar_fichero( $ruta_antigua );
		}

		if ( ! $plan_ids && ! $farmacia_ids ) {
			$avisos[] = 'No tiene ningún plan ni ninguna farmacia: no la verá ninguna farmacia.';
		}

		if ( ! $datos['visible'] ) {
			$avisos[] = 'Está oculta: no la ve ninguna farmacia hasta que la pongas como visible.';
		}

		return array(
			'item'   => $this->repository->find_by_id( $guardado ),
			'avisos' => $avisos,
		);
	}

	/**
	 * Borra la herramienta, sus relaciones y su archivo, todo o nada.
	 *
	 * @return true|\WP_Error
	 */
	public function eliminar( int $id ) {
		global $wpdb;

		$item = $this->repository->find_by_id( $id );

		if ( ! $item ) {
			return new \WP_Error( 'mdf_ca_catalogo_no_existe', 'La herramienta que intentas eliminar ya no existe.' );
		}

		$fichero  = $item->get_ruta_fichero() ? self::ruta_en_carpeta( $item->get_ruta_fichero() ) : null;
		$apartado = null;

		$wpdb->query( 'START TRANSACTION' );

		if ( $fichero && is_file( $fichero ) ) {
			$apartado = dirname( $fichero ) . DIRECTORY_SEPARATOR . '.eliminando-' . bin2hex( random_bytes( 8 ) );

			if ( ! @rename( $fichero, $apartado ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- el aviso lo da el WP_Error.
				$wpdb->query( 'ROLLBACK' );
				return new \WP_Error( 'mdf_ca_catalogo_error_fichero', 'No se pudo eliminar: el archivo de la herramienta no se pudo apartar. No se ha cambiado nada.' );
			}
		}

		if ( ! $this->repository->delete( $id ) || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );

			if ( $apartado ) {
				@rename( $apartado, $fichero ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			}

			return new \WP_Error( 'mdf_ca_error_bd', 'No se pudo eliminar la herramienta. No se ha cambiado nada.' );
		}

		if ( $apartado ) {
			wp_delete_file( $apartado );
		}

		return true;
	}

	/**
	 * Ruta absoluta de un fichero de la carpeta de herramientas, o null si
	 * el nombre guardado no es uno generado por nosotros. Herramienta_
	 * Endpoint comprueba ademas realpath + prefijo antes de leer.
	 */
	public static function ruta_en_carpeta( string $ruta_fichero ): ?string {
		if ( ! preg_match( '/^h-[0-9a-f]{32}\.[a-z]{3,4}$/D', $ruta_fichero ) ) {
			return null;
		}

		return self::get_carpeta() . DIRECTORY_SEPARATOR . $ruta_fichero;
	}

	/**
	 * @return int|\WP_Error id de la herramienta
	 */
	private function guardar_en_bd( int $id, array $datos, array $plan_ids, array $farmacia_ids, array $etiqueta_ids ) {
		global $wpdb;

		$wpdb->query( 'START TRANSACTION' );

		if ( $id > 0 ) {
			$ok = $this->repository->update( $id, $datos );
		} else {
			$id = (int) $this->repository->insert( $datos );
			$ok = $id > 0;
		}

		$ok = $ok
			&& $this->repository->set_planes( $id, $plan_ids )
			&& $this->repository->set_farmacias( $id, $farmacia_ids )
			&& $this->repository->set_etiquetas( $id, $etiqueta_ids );

		if ( ! $ok || false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'mdf_ca_error_bd', 'No se pudo guardar la herramienta. No se ha cambiado nada.' );
		}

		return $id;
	}

	/**
	 * Mueve el archivo subido a la carpeta de herramientas con un nombre
	 * generado (h-{32 hex}.{formato}). Si la carpeta no existe, no se puede
	 * escribir o su .htaccess no deniega, no se guarda nada.
	 *
	 * @return string|\WP_Error nombre del fichero dentro de la carpeta
	 */
	private function escribir_fichero( string $tmp, string $formato ) {
		$estado = Carpeta_Privada_Proteccion::asegurar( self::CARPETA_RELATIVA );

		if ( 'existe' !== $estado['carpeta'] || ! $estado['escribible'] || 'deniega' !== $estado['htaccess'] ) {
			return new \WP_Error( 'mdf_ca_herramienta_carpeta', 'La carpeta privada de herramientas no existe, no se puede escribir o no está protegida. Contacta con desarrollo.' );
		}

		$nombre = 'h-' . bin2hex( random_bytes( 16 ) ) . '.' . Catalogo_Formatos::get_extension( $formato );

		if ( ! move_uploaded_file( $tmp, self::get_carpeta() . DIRECTORY_SEPARATOR . $nombre ) ) {
			return new \WP_Error( 'mdf_ca_herramienta_error_subida', 'No se pudo guardar el archivo. Inténtalo de nuevo.' );
		}

		return $nombre;
	}

	private static function borrar_fichero( string $ruta_fichero ): void {
		$ruta = self::ruta_en_carpeta( $ruta_fichero );

		if ( $ruta && is_file( $ruta ) ) {
			wp_delete_file( $ruta );
		}

		if ( $ruta && is_file( $ruta ) ) {
			// Sin datos de la herramienta en el log: solo el nombre generado.
			error_log( 'mdf-client-area: no se pudo borrar el archivo de herramienta ' . $ruta_fichero ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * @param int[] $etiqueta_ids
	 * @param int[] $plan_ids
	 * @param int[] $farmacia_ids
	 * @return true|\WP_Error
	 */
	private function validar_campos( string $nombre, string $descripcion, int $categoria_id, array $etiqueta_ids, array $plan_ids, array $farmacia_ids, string $recurso ) {
		if ( '' === $nombre ) {
			return new \WP_Error( 'mdf_ca_catalogo_nombre_vacio', 'El nombre no puede estar vacío.' );
		}

		if ( mb_strlen( $nombre ) > self::MAX_NOMBRE ) {
			return new \WP_Error( 'mdf_ca_catalogo_nombre_largo', sprintf( 'El nombre no puede tener más de %d caracteres.', self::MAX_NOMBRE ) );
		}

		if ( mb_strlen( $descripcion ) > self::MAX_DESCRIPCION ) {
			return new \WP_Error( 'mdf_ca_catalogo_descripcion_larga', sprintf( 'La descripción no puede tener más de %d caracteres.', self::MAX_DESCRIPCION ) );
		}

		if ( ! Catalogo_Termino_Repository::categorias()->find_by_id( $categoria_id ) ) {
			return new \WP_Error( 'mdf_ca_catalogo_categoria_invalida', 'Elige una categoría.' );
		}

		$etiquetas = Catalogo_Termino_Repository::etiquetas();

		foreach ( $etiqueta_ids as $etiqueta_id ) {
			if ( ! $etiquetas->find_by_id( $etiqueta_id ) ) {
				return new \WP_Error( 'mdf_ca_catalogo_etiqueta_invalida', 'Una de las etiquetas elegidas ya no existe.' );
			}
		}

		foreach ( $plan_ids as $plan_id ) {
			if ( ! $this->plan_repository->find_by_id( $plan_id ) ) {
				return new \WP_Error( 'mdf_ca_catalogo_plan_invalido', 'Uno de los planes seleccionados ya no existe.' );
			}
		}

		if ( $farmacia_ids ) {
			$existentes = array_map( static fn( Farmacia $f ): int => $f->get_id(), ( new Farmacia_Repository() )->find_all() );

			if ( array_diff( $farmacia_ids, $existentes ) ) {
				return new \WP_Error( 'mdf_ca_catalogo_farmacia_invalida', 'Una de las farmacias elegidas ya no existe.' );
			}
		}

		if ( ! in_array( $recurso, array( Catalogo_Item::RECURSO_ENLACE, Catalogo_Item::RECURSO_ARCHIVO ), true ) ) {
			return new \WP_Error( 'mdf_ca_catalogo_recurso_invalido', 'Elige si el recurso es un enlace o un archivo.' );
		}

		return true;
	}

	/**
	 * Validacion de formato, no de alcanzabilidad: wp_http_validate_url()
	 * (descartado a proposito) hace una resolucion DNS real del host y
	 * rechaza URLs validas cuyo DNS no resuelve en ese instante desde el
	 * servidor. Este enlace nunca lo pide el servidor, solo se pinta como
	 * <a href> en el area de cliente.
	 */
	private static function es_url_valida( string $url ): bool {
		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return false;
		}

		return in_array( strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ), array( 'http', 'https' ), true );
	}
}
