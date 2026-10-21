<?php
/**
 * Herramientas MDF > Herramientas: listado. Solo marcado; la logica vive en
 * Admin_Catalogo y Catalogo_Service. Nunca muestra rutas de disco.
 *
 * @var \MdfClientArea\Catalogo_Item[]    $items
 * @var \MdfClientArea\Catalogo_Termino[] $categorias
 * @var \MdfClientArea\Catalogo_Termino[] $etiquetas
 * @var \MdfClientArea\Plan[]             $planes
 * @var array{tipo: string, mensaje: string, detalles: string[]}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mdf_ca_nombres_categoria = array();
foreach ( $categorias as $mdf_ca_t ) {
	$mdf_ca_nombres_categoria[ $mdf_ca_t->get_id() ] = $mdf_ca_t->get_nombre();
}

$mdf_ca_nombres_etiqueta = array();
foreach ( $etiquetas as $mdf_ca_t ) {
	$mdf_ca_nombres_etiqueta[ $mdf_ca_t->get_id() ] = $mdf_ca_t->get_nombre();
}

$mdf_ca_nombres_plan = array();
foreach ( $planes as $mdf_ca_p ) {
	$mdf_ca_nombres_plan[ $mdf_ca_p->get_slug() ] = $mdf_ca_p->get_nombre();
}

$mdf_ca_xlsm_permitido = Catalogo_Ajustes::permite_xlsm();
?>
<div class="wrap">
	<h1 class="wp-heading-inline">Herramientas</h1>
	<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::MENU_SLUG . '&accion=nueva' ) ); ?>" class="page-title-action">Añadir herramienta</a>
	<hr class="wp-header-end" />

	<?php require __DIR__ . '/parcial-aviso-catalogo.php'; ?>

	<p class="description">
		Lo que ve cada farmacia en la página Herramientas del área de cliente. Una herramienta la ven las farmacias
		de los planes marcados más las farmacias concretas elegidas; oculta, no la ve nadie.
	</p>

	<?php if ( ! $categorias ) : ?>
		<div class="notice notice-warning inline"><p>
			Antes de crear herramientas hay que crear al menos una categoría, en
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::SLUG_CATEGORIAS ) ); ?>">Categorías</a>.
		</p></div>
	<?php endif; ?>

	<?php if ( ! $items ) : ?>
		<p>Todavía no hay ninguna herramienta.</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col">Nombre</th>
					<th scope="col">Categoría y etiquetas</th>
					<th scope="col">Recurso</th>
					<th scope="col">Visible para</th>
					<th scope="col">Estado</th>
					<th scope="col">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $mdf_ca_item ) : ?>
					<?php
					$mdf_ca_id         = $mdf_ca_item->get_id();
					$mdf_ca_planes     = array_map( static fn( string $s ): string => $mdf_ca_nombres_plan[ $s ] ?? $s, $mdf_ca_item->get_planes_slugs() );
					$mdf_ca_n_farm     = count( $mdf_ca_item->get_farmacia_ids() );
					$mdf_ca_formato    = $mdf_ca_item->get_formato();
					$mdf_ca_es_xlsm    = $mdf_ca_item->es_archivo() && Catalogo_Formatos::XLSM === $mdf_ca_formato;
					$mdf_ca_problemas  = array();

					if ( ! $mdf_ca_item->recurso_completo() ) {
						$mdf_ca_problemas[] = $mdf_ca_item->es_archivo() ? 'Falta el archivo.' : 'Falta el enlace: edítala y añádelo.';
					}

					if ( $mdf_ca_es_xlsm && ! $mdf_ca_xlsm_permitido ) {
						$mdf_ca_problemas[] = 'Excel con macros: el ajuste está apagado y ninguna farmacia la ve.';
					}

					if ( ! $mdf_ca_planes && ! $mdf_ca_n_farm ) {
						$mdf_ca_problemas[] = 'Sin planes ni farmacias: no la ve nadie.';
					}
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::MENU_SLUG . '&editar=' . $mdf_ca_id ) ); ?>"><?php echo esc_html( $mdf_ca_item->get_nombre() ); ?></a></strong>
							<?php if ( $mdf_ca_item->get_descripcion() ) : ?>
								<br /><span class="description"><?php echo esc_html( $mdf_ca_item->get_descripcion() ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $mdf_ca_nombres_categoria[ (int) $mdf_ca_item->get_categoria_id() ] ?? 'Sin categoría' ); ?>
							<?php if ( $mdf_ca_item->get_etiqueta_ids() ) : ?>
								<br /><span class="description"><?php echo esc_html( implode( ', ', array_map( static fn( int $e ): string => $mdf_ca_nombres_etiqueta[ $e ] ?? '', $mdf_ca_item->get_etiqueta_ids() ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $mdf_ca_item->es_archivo() ) : ?>
								<?php echo esc_html( Catalogo_Formatos::get_etiqueta( $mdf_ca_formato ) ); ?>
								<?php if ( $mdf_ca_item->get_tamano() ) : ?>
									(<?php echo esc_html( size_format( $mdf_ca_item->get_tamano(), 1 ) ); ?>)
								<?php endif; ?>
								<br /><span class="description">
									<?php
									$mdf_ca_config = array();
									if ( Catalogo_Formatos::es_siempre_descargable( $mdf_ca_formato ) ) {
										$mdf_ca_config[] = 'Solo descarga';
									} else {
										$mdf_ca_config[] = $mdf_ca_item->is_descargable() ? 'Visor y descarga' : 'Solo visor';
										$mdf_ca_config[] = $mdf_ca_item->con_marca_de_agua() ? 'con marca de agua' : 'sin marca de agua';
									}
									echo esc_html( implode( ', ', $mdf_ca_config ) );
									?>
								</span>
							<?php elseif ( $mdf_ca_item->get_enlace_url() ) : ?>
								Enlace: <a href="<?php echo esc_url( $mdf_ca_item->get_enlace_url() ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_parse_url( $mdf_ca_item->get_enlace_url(), PHP_URL_HOST ) ?: $mdf_ca_item->get_enlace_url() ); ?></a>
							<?php else : ?>
								Enlace (vacío)
							<?php endif; ?>
						</td>
						<td>
							<?php
							$mdf_ca_partes = array();
							if ( $mdf_ca_planes ) {
								$mdf_ca_partes[] = 'Planes: ' . implode( ', ', $mdf_ca_planes );
							}
							if ( $mdf_ca_n_farm ) {
								$mdf_ca_partes[] = sprintf( _n( '%d farmacia concreta', '%d farmacias concretas', $mdf_ca_n_farm ), $mdf_ca_n_farm );
							}
							echo $mdf_ca_partes ? esc_html( implode( ' + ', $mdf_ca_partes ) ) : '<em>Nadie</em>';
							?>
						</td>
						<td>
							<?php echo $mdf_ca_item->is_visible() ? 'Visible' : '<strong>Oculta</strong>'; ?>
							<?php foreach ( $mdf_ca_problemas as $mdf_ca_problema ) : ?>
								<br /><span class="mdf-ca-catalogo-aviso"><span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php echo esc_html( $mdf_ca_problema ); ?></span>
							<?php endforeach; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::MENU_SLUG . '&editar=' . $mdf_ca_id ) ); ?>">Editar</a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
								onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( '¿Eliminar la herramienta «' . $mdf_ca_item->get_nombre() . '»' . ( $mdf_ca_item->es_archivo() ? ' y su archivo' : '' ) . '? No se puede deshacer.' ) ); ?>);">
								<?php wp_nonce_field( 'mdf_ca_eliminar_catalogo_' . $mdf_ca_id ); ?>
								<input type="hidden" name="action" value="mdf_ca_eliminar_catalogo" />
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $mdf_ca_id ); ?>" />
								| <button type="submit" class="button-link button-link-delete">Eliminar</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
