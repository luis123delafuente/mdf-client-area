<?php
namespace MdfClientArea;

use Elementor\Modules\GlobalClasses\Global_Classes_Parser;
use Elementor\Modules\GlobalClasses\Global_Classes_Repository;
use Elementor\Modules\Variables\Storage\Variables_Collection;
use Elementor\Modules\Variables\Storage\Variables_Repository;
use Elementor\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migracion del diseno del area (Elementor) desde local a otra instalacion
 * (v0.45.0, TEMPORAL: se quita cuando produccion tenga el diseno). Lee el
 * paquete que genera exportar.php en local (manifiesto, clases y variables
 * globales y medios) y lo importa por pasos:
 *
 * - comprobar(): solo lee.
 * - revision():  sube los medios (reutiliza los ya subidos, por SHA-256),
 *                carga variables y clases globales en el kit conservando sus
 *                IDs (como el importador de kits de Elementor) y crea una copia
 *                en borrador de cada pagina con los IDs de imagenes y enlaces
 *                de esta instalacion. Las paginas reales no se tocan.
 * - aplicar():   el diseno a las paginas reales del area (Area_Privada_Pages),
 *                guardando antes lo que tenian (solo la primera vez).
 * - deshacer():  las paginas reales vuelven a lo que tenian.
 * - limpiar():   las copias de revision a la papelera.
 *
 * La usan la pantalla temporal Admin_Migracion_Diseno y el script de WP-CLI
 * del paquete (importar.php): mismo codigo y mismo estado (option
 * mdf_ca_migracion_elementor), asi que se puede empezar por una via y seguir
 * por la otra. Cualquier problema lanza \RuntimeException con el motivo en
 * claro; nada queda a medias salvo lo ya hecho en pasos anteriores, que se
 * puede repetir.
 */
class Migracion_Diseno {

	public const OPTION          = 'mdf_ca_migracion_elementor';
	public const META_SHA        = '_mdf_migracion_sha256';
	public const META_REVISION   = '_mdf_migracion_revision_de';
	public const ELEMENTOR_MINIMA = '4.3.5';

	private const METAS_PAGINA = array( '_elementor_data', '_elementor_edit_mode', '_elementor_template_type', '_elementor_version', '_elementor_page_settings', '_wp_page_template' );

	private string $dir_datos;
	private array $manifiesto;
	private array $clases;
	private array $variables;
	private array $estado;
	private array $paginas_area;
	private array $mapa_paginas = array();

	/** @var string[] Lo que va haciendo, en claro, para la pantalla o la consola. */
	private array $registro = array();

	/** Carpeta del paquete del plugin (la que se despliega con la pantalla). */
	public static function dir_datos_plugin(): string {
		return MDF_CA_PLUGIN_DIR . 'migracion-diseno/datos';
	}

	/**
	 * @throws \RuntimeException Si falta algo para importar (usuario, Elementor, paquete o paginas).
	 */
	public function __construct( string $dir_datos ) {
		$this->dir_datos = rtrim( $dir_datos, '/' );

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'unfiltered_html' ) ) {
			throw new \RuntimeException( 'Hace falta un usuario administrador (con permiso para HTML sin filtrar).' );
		}

		if ( ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( Plugin::class ) ) {
			throw new \RuntimeException( 'Elementor no está activo. Instálalo (versión ' . self::ELEMENTOR_MINIMA . ') y actívalo antes de seguir.' );
		}

		if ( version_compare( ELEMENTOR_VERSION, self::ELEMENTOR_MINIMA, '<' ) ) {
			throw new \RuntimeException( 'Elementor ' . ELEMENTOR_VERSION . ' es anterior a ' . self::ELEMENTOR_MINIMA . ', la del diseño. Actualízalo antes de seguir.' );
		}

		$this->manifiesto = self::leer_json( $this->dir_datos . '/manifiesto.json' );
		$this->clases     = self::leer_json( $this->dir_datos . '/global-classes.json' );
		$this->variables  = self::leer_json( $this->dir_datos . '/global-variables.json' );

		if ( ! $this->kit() ) {
			throw new \RuntimeException( 'Elementor no tiene kit activo. Abre una vez Elementor > Ajustes en el escritorio y vuelve a intentarlo.' );
		}

		// Paginas reales del area aqui, por la misma clave que en local.
		$this->paginas_area = Area_Privada_Pages::get_page_ids() + Area_Privada_Pages::get_paginas_adicionales_ids();
		$faltan             = array();

		foreach ( $this->manifiesto['paginas'] as $pagina ) {
			$id = (int) ( $this->paginas_area[ $pagina['clave'] ] ?? 0 );

			if ( ! $id || ! get_post( $id ) ) {
				$faltan[] = $pagina['clave'];
				continue;
			}

			$this->mapa_paginas[ (int) $pagina['id_local'] ] = $id;
		}

		if ( $faltan ) {
			throw new \RuntimeException( 'Faltan páginas del área: ' . implode( ', ', $faltan ) . '. Carga cualquier página de la web para que el plugin las cree y vuelve a intentarlo.' );
		}

		$estado       = get_option( self::OPTION, array() );
		$this->estado = ( is_array( $estado ) ? $estado : array() ) + array(
			'medios'   => array(),
			'revision' => array(),
			'copia'    => array(),
			'aplicado' => false,
		);
	}

	/** @return string[] */
	public function registro(): array {
		return $this->registro;
	}

	/**
	 * Lo que hay y lo que haria, sin cambiar nada.
	 *
	 * @return array{elementor: string, version_distinta: bool, paquete: array, paginas: array<int, array>, ajenos: string[], estado: string}
	 */
	public function comprobar(): array {
		$paginas = array();

		foreach ( $this->manifiesto['paginas'] as $pagina ) {
			$id        = $this->mapa_paginas[ (int) $pagina['id_local'] ];
			$revision  = (int) ( $this->estado['revision'][ $pagina['clave'] ] ?? 0 );
			$paginas[] = array(
				'clave'        => $pagina['clave'],
				'titulo'       => $pagina['titulo'],
				'id'           => $id,
				'titulo_aqui'  => get_the_title( $id ),
				'estado'       => get_post_status( $id ),
				'url'          => get_permalink( $id ),
				'revision_id'  => $revision && get_post( $revision ) && 'trash' !== get_post_status( $revision ) ? $revision : 0,
			);
		}

		return array(
			'elementor'         => ELEMENTOR_VERSION,
			'version_distinta'  => version_compare( ELEMENTOR_VERSION, $this->manifiesto['elementor_version'], '!=' ),
			'paquete'           => array(
				'generado'  => $this->manifiesto['generado'],
				'elementor' => $this->manifiesto['elementor_version'],
				'clases'    => count( $this->clases['items'] ?? array() ),
				'variables' => count( $this->variables['data'] ?? array() ),
				'paginas'   => count( $this->manifiesto['paginas'] ),
				'medios'    => count( $this->manifiesto['medios'] ),
			),
			'paginas'           => $paginas,
			'ajenos'            => $this->ajenos_en_kit(),
			'estado'            => $this->estado['aplicado'] ? 'aplicado' : ( $this->estado['revision'] ? 'revision' : 'sin_importar' ),
		);
	}

	/**
	 * @throws \RuntimeException
	 * @return array<string, int> clave => ID de la copia de revision.
	 */
	public function revision( bool $forzar = false ): array {
		$ajenos = $this->ajenos_en_kit();

		if ( $ajenos && ! $forzar ) {
			throw new \RuntimeException( 'El kit de Elementor ya tiene clases o variables que no son del paquete (' . implode( ', ', $ajenos ) . '). Se sustituirían: para hacerlo, repite marcando «sustituir».' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		// 1. Medios: se reutiliza el que ya se subio con el mismo contenido.
		foreach ( $this->manifiesto['medios'] as $medio ) {
			$existente = get_posts(
				array(
					'post_type'      => 'attachment',
					'post_status'    => 'inherit',
					'meta_key'       => self::META_SHA, // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => $medio['sha256'], // phpcs:ignore WordPress.DB.SlowDBQuery
					'fields'         => 'ids',
					'posts_per_page' => 1,
				)
			);

			if ( $existente ) {
				$this->estado['medios'][ $medio['id_local'] ] = (int) $existente[0];
				continue;
			}

			$origen = $this->dir_datos . '/medios/' . basename( $medio['fichero'] );

			if ( ! is_readable( $origen ) || hash_file( 'sha256', $origen ) !== $medio['sha256'] ) {
				throw new \RuntimeException( "La imagen {$medio['fichero']} del paquete falta o está dañada." );
			}

			$tmp = wp_tempnam( $medio['fichero'] );
			copy( $origen, $tmp );

			$id = media_handle_sideload(
				array(
					'name'     => preg_replace( '/^\d+-/', '', basename( $medio['fichero'] ) ),
					'tmp_name' => $tmp,
				),
				0,
				$medio['titulo']
			);

			if ( is_wp_error( $id ) ) {
				wp_delete_file( $tmp );
				throw new \RuntimeException( "No se pudo subir {$medio['fichero']}: " . $id->get_error_message() );
			}

			update_post_meta( $id, self::META_SHA, $medio['sha256'] );

			if ( '' !== $medio['alt'] ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $medio['alt'] );
			}

			$this->estado['medios'][ $medio['id_local'] ] = (int) $id;
			$this->registro[] = "Imagen subida: {$medio['fichero']} (ID {$id}).";
		}

		$this->guardar_estado();

		// 2. Variables (antes que las clases, que las usan) y clases globales.
		$kit = $this->kit();

		// save() devuelve false tambien cuando lo guardado ya era identico
		// (update_post_meta no reescribe un valor igual: pasa al repetir este
		// paso). Solo es error si lo que queda no son las del paquete.
		$repo_variables = new Variables_Repository( $kit );

		if ( false === $repo_variables->save( Variables_Collection::hydrate( $this->variables ) )
			&& ( $repo_variables->load()->serialize()['data'] ?? null ) != ( Variables_Collection::hydrate( $this->variables )->serialize()['data'] ?? array() ) ) { // phpcs:ignore Universal.Operators.StrictComparisons -- mismo contenido, el orden de claves puede variar.
			throw new \RuntimeException( 'No se pudieron guardar las variables globales.' );
		}

		$clases          = $this->clases;
		$clases['order'] = Global_Classes_Parser::sanitize_order( $clases['items'] ?? array(), $clases['order'] ?? array() );
		$validas         = Global_Classes_Parser::make()->parse( $clases );

		if ( ! $validas->is_valid() ) {
			throw new \RuntimeException( 'Las clases globales del paquete no son válidas para este Elementor.' );
		}

		$validas = $validas->unwrap();
		Global_Classes_Repository::make( $kit )->put( $validas['items'], $validas['order'] );
		$this->registro[] = 'Kit: ' . count( $this->variables['data'] ) . ' variables y ' . count( $validas['items'] ) . ' clases cargadas.';

		// 3. Copias de revision en borrador.
		foreach ( $this->manifiesto['paginas'] as $pagina ) {
			$id = (int) ( $this->estado['revision'][ $pagina['clave'] ] ?? 0 );

			if ( ! $id || ! get_post( $id ) || 'trash' === get_post_status( $id ) ) {
				$id = wp_insert_post(
					array(
						'post_type'   => 'page',
						'post_status' => 'draft',
						'post_title'  => '[Revisión] ' . $pagina['titulo'],
					),
					true
				);

				if ( is_wp_error( $id ) ) {
					throw new \RuntimeException( 'No se pudo crear la copia de revisión de ' . $pagina['clave'] . ': ' . $id->get_error_message() );
				}

				update_post_meta( $id, self::META_REVISION, $pagina['clave'] );
			}

			$this->guardar_diseno( (int) $id, $pagina, $this->remapear( $pagina['elementor_data'] ) );
			$this->estado['revision'][ $pagina['clave'] ] = (int) $id;
			$this->guardar_estado();

			$this->registro[] = 'Copia de revisión de ' . $pagina['clave'] . ': ID ' . $id . '.';
		}

		Plugin::$instance->files_manager->clear_cache();

		return $this->estado['revision'];
	}

	/** @throws \RuntimeException */
	public function aplicar(): void {
		if ( count( $this->estado['revision'] ) !== count( $this->manifiesto['paginas'] ) ) {
			throw new \RuntimeException( 'Primero crea las copias de revisión y revísalas.' );
		}

		foreach ( $this->manifiesto['paginas'] as $pagina ) {
			$id = $this->mapa_paginas[ (int) $pagina['id_local'] ];

			// Copia de lo que tenia, solo la primera vez.
			if ( ! isset( $this->estado['copia'][ $pagina['clave'] ] ) ) {
				$metas = array();

				foreach ( self::METAS_PAGINA as $meta ) {
					$metas[ $meta ] = metadata_exists( 'post', $id, $meta ) ? get_post_meta( $id, $meta, true ) : null;
				}

				$this->estado['copia'][ $pagina['clave'] ] = array(
					'titulo' => get_post_field( 'post_title', $id ),
					'metas'  => $metas,
				);
				$this->guardar_estado();
			}

			$this->guardar_diseno( $id, $pagina, $this->remapear( $pagina['elementor_data'] ) );
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $pagina['titulo'],
				)
			);

			$this->registro[] = 'Aplicado: ' . $pagina['clave'] . ' (ID ' . $id . ').';
		}

		$this->estado['aplicado'] = true;
		$this->guardar_estado();
		Plugin::$instance->files_manager->clear_cache();
	}

	/** @throws \RuntimeException */
	public function deshacer(): void {
		if ( ! $this->estado['copia'] ) {
			throw new \RuntimeException( 'No hay nada que deshacer: el diseño no se ha aplicado.' );
		}

		foreach ( $this->estado['copia'] as $clave => $copia ) {
			$id = (int) ( $this->paginas_area[ $clave ] ?? 0 );

			if ( ! $id ) {
				$this->registro[] = "La página {$clave} ya no existe: se salta.";
				continue;
			}

			foreach ( $copia['metas'] as $meta => $valor ) {
				if ( null === $valor ) {
					delete_post_meta( $id, $meta );
				} else {
					update_post_meta( $id, $meta, wp_slash( $valor ) );
				}
			}

			delete_post_meta( $id, '_elementor_css' );
			delete_post_meta( $id, '_elementor_element_cache' );
			wp_update_post(
				array(
					'ID'         => $id,
					'post_title' => $copia['titulo'],
				)
			);

			$this->registro[] = "Restaurada: {$clave} (ID {$id}).";
		}

		$this->estado['copia']    = array();
		$this->estado['aplicado'] = false;
		$this->guardar_estado();
		Plugin::$instance->files_manager->clear_cache();
	}

	public function limpiar(): void {
		foreach ( $this->estado['revision'] as $clave => $id ) {
			if ( $id && get_post( $id ) && get_post_meta( $id, self::META_REVISION, true ) === $clave ) {
				wp_trash_post( $id );
				$this->registro[] = "A la papelera: copia de revisión de {$clave} (ID {$id}).";
			}
		}

		$this->estado['revision'] = array();
		$this->guardar_estado();
	}

	// ------------------------------------------------------------------

	/** Clases y variables del kit que no son del paquete (las sustituiria revision()). @return string[] */
	private function ajenos_en_kit(): array {
		$kit    = $this->kit();
		$ajenos = array();

		foreach ( Global_Classes_Repository::make( $kit )->all()->get()['items'] ?? array() as $id => $clase ) {
			if ( ! isset( $this->clases['items'][ $id ] ) ) {
				$ajenos[] = $clase['label'] ?? $id;
			}
		}

		foreach ( ( new Variables_Repository( $kit ) )->load()->serialize()['data'] ?? array() as $id => $var ) {
			if ( empty( $var['deleted'] ) && ! isset( $this->variables['data'][ $id ] ) ) {
				$ajenos[] = $var['label'] ?? $id;
			}
		}

		return $ajenos;
	}

	/**
	 * IDs de imagenes y de paginas enlazadas a los de esta instalacion. Un ID
	 * sin equivalente aborta: mejor no importar que dejar enlaces rotos.
	 *
	 * @throws \RuntimeException
	 */
	private function remapear( array $nodo ): array {
		if ( ( $nodo['$$type'] ?? null ) === 'image-attachment-id' && is_int( $nodo['value'] ?? null ) ) {
			if ( ! isset( $this->estado['medios'][ $nodo['value'] ] ) ) {
				throw new \RuntimeException( "Imagen sin equivalente (ID local {$nodo['value']}): crea antes las copias de revisión." );
			}

			$nodo['value'] = (int) $this->estado['medios'][ $nodo['value'] ];

			return $nodo;
		}

		// {"destination":{"$$type":"query","value":{"id":{"$$type":"number","value":N}}}}
		if ( isset( $nodo['destination']['$$type'], $nodo['destination']['value']['id']['value'] ) && 'query' === $nodo['destination']['$$type'] ) {
			$id_local = (int) $nodo['destination']['value']['id']['value'];

			if ( ! isset( $this->mapa_paginas[ $id_local ] ) ) {
				throw new \RuntimeException( "Enlace a una página que no es del área (ID local {$id_local})." );
			}

			$nodo['destination']['value']['id']['value'] = $this->mapa_paginas[ $id_local ];
		}

		foreach ( $nodo as $clave => $hijo ) {
			if ( is_array( $hijo ) ) {
				$nodo[ $clave ] = $this->remapear( $hijo );
			}
		}

		return $nodo;
	}

	/** @throws \RuntimeException */
	private function guardar_diseno( int $post_id, array $pagina, array $elementos ): void {
		update_post_meta( $post_id, '_wp_page_template', $pagina['plantilla'] ?: 'elementor_canvas' );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );

		$documento = Plugin::$instance->documents->get( $post_id, false );

		if ( ! $documento ) {
			throw new \RuntimeException( "Elementor no puede abrir la página {$post_id}." );
		}

		$ok = $documento->save(
			array(
				'elements' => $elementos,
				'settings' => is_array( $pagina['ajustes_pagina'] ) ? $pagina['ajustes_pagina'] : array(),
			)
		);

		if ( false === $ok ) {
			throw new \RuntimeException( "Elementor no ha guardado la página {$post_id}." );
		}

		delete_post_meta( $post_id, '_elementor_css' );
		delete_post_meta( $post_id, '_elementor_element_cache' );
	}

	private function kit() {
		$kit = Plugin::$instance->kits_manager->get_active_kit();

		return $kit && $kit->get_id() ? $kit : null;
	}

	private function guardar_estado(): void {
		update_option( self::OPTION, $this->estado, false );
	}

	/** @throws \RuntimeException */
	private static function leer_json( string $ruta ): array {
		$datos = is_readable( $ruta ) ? json_decode( (string) file_get_contents( $ruta ), true ) : null;

		if ( ! is_array( $datos ) ) {
			throw new \RuntimeException( 'Falta o está dañado el paquete de migración (' . basename( $ruta ) . ').' );
		}

		return $datos;
	}
}
