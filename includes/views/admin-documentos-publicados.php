<?php
/**
 * Vista de "Documentos MDF > Documentos publicados" (#318). Solo marcado: la
 * logica vive en Admin_Documentos_Publicados y en Documento_Repository. No
 * decide visibilidad (eso es Permissions).
 *
 * @var \MdfClientArea\Farmacia[]               $farmacias        Ordenadas por nombre.
 * @var \MdfClientArea\Documento[]              $documentos       Solo la pagina actual.
 * @var callable(int): string                   $nombre_farmacia
 * @var \MdfClientArea\Farmacia|null            $farmacia_elegida
 * @var string                                  $q
 * @var int                                     $total
 * @var int                                     $pagina
 * @var int                                     $paginas
 * @var int                                     $por_pagina
 * @var callable(int): string                   $url_pagina
 * @var string                                  $origen_url
 * @var array<string, mixed>                    $filtros
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$desde_n = $total > 0 ? ( $pagina - 1 ) * $por_pagina + 1 : 0;
$hasta_n = min( $total, $pagina * $por_pagina );
?>
<div class="wrap">
	<h1>Documentos publicados</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning' ), true ) ? $aviso['tipo'] : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		Todos los documentos que sus farmacias ven ahora, también los antiguos. Busca por farmacia o por nombre para
		despublicar uno (vuelve a «Pendientes de publicar») o para ver su historial. Despublicar no retira lo que la
		farmacia ya haya visto o descargado.
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin_Documentos_Publicados::MENU_SLUG ); ?>" />
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="mdf-ca-publicados-farmacia">Farmacia</label></th>
					<td>
						<div class="mdf-ca-selector-farmacia" data-mdf-ca-selector-farmacia data-mdf-ca-opcional>
							<div class="mdf-ca-selector-farmacia__filtros">
								<div class="mdf-ca-selector-farmacia__combo">
									<input
										type="text"
										id="mdf-ca-publicados-farmacia"
										class="regular-text"
										placeholder="Todas las farmacias (haz clic para buscar por nombre o CIF/NIF)"
										autocomplete="off"
										role="combobox"
										aria-expanded="false"
										aria-controls="mdf-ca-publicados-farmacia-lista"
										aria-autocomplete="list"
										value="<?php echo esc_attr( $farmacia_elegida ? $farmacia_elegida->get_nombre() . ' (' . $farmacia_elegida->get_cif() . ')' : '' ); ?>"
										data-mdf-ca-buscar
									/>
									<ul id="mdf-ca-publicados-farmacia-lista" class="mdf-ca-selector-farmacia__lista" role="listbox" hidden data-mdf-ca-lista></ul>
								</div>
								<select data-mdf-ca-tipo-entidad aria-label="Filtrar por tipo de entidad">
									<option value="">Todos los tipos</option>
									<?php foreach ( Farmacia::TIPOS_ENTIDAD as $clave => $etiqueta ) : ?>
										<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
									<?php endforeach; ?>
								</select>
							</div>
							<input type="hidden" name="farmacia_id" value="<?php echo esc_attr( $farmacia_elegida ? (string) $farmacia_elegida->get_id() : '' ); ?>" data-mdf-ca-farmacia-id />
							<script type="application/json" data-mdf-ca-farmacias>
								<?php
								echo wp_json_encode(
									array_map(
										static fn( Farmacia $f ): array => array(
											'id'     => $f->get_id(),
											'nombre' => $f->get_nombre(),
											'cif'    => $f->get_cif(),
											'tipo'   => $f->get_tipo_entidad(),
											'tipoEt' => $f->get_tipo_entidad_etiqueta(),
										),
										$farmacias
									),
									JSON_HEX_TAG | JSON_HEX_AMP
								);
								?>
							</script>
							<p class="description" data-mdf-ca-contador aria-live="polite"></p>
						</div>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-publicados-q">Nombre del documento</label></th>
					<td>
						<input type="search" id="mdf-ca-publicados-q" name="q" class="regular-text" maxlength="100" value="<?php echo esc_attr( $q ); ?>" />
						<p class="description">Busca el texto tal cual, en cualquier parte del nombre.</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( 'Buscar', 'primary', 'submit', false ); ?>
		<?php if ( $filtros ) : ?>
			<a href="<?php echo esc_url( Admin_Documentos_Publicados::url_listado() ); ?>" style="margin-left:.5em">Quitar filtros</a>
		<?php endif; ?>
	</form>

	<h2>
		<?php
		echo esc_html(
			0 === $total
				? 'Ningún documento publicado con estos filtros'
				: sprintf( 'Documentos %d–%d de %d', $desde_n, $hasta_n, $total )
		);
		?>
	</h2>

	<?php if ( $documentos ) : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Documento</th>
					<th>Farmacia</th>
					<th style="width:10em">Tipo</th>
					<th style="width:9em">Fecha documento</th>
					<th style="width:12em">Recibido</th>
					<th style="width:19em">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $documentos as $documento ) : ?>
					<tr>
						<td><?php echo esc_html( $documento->get_nombre() ); ?></td>
						<td><?php echo esc_html( $nombre_farmacia( $documento->get_farmacia_id() ) ); ?></td>
						<td><?php echo esc_html( Documento_Tipos::get_etiqueta( $documento->get_tipo_documento() ) ); ?></td>
						<td><?php echo esc_html( Documento::formatear_fecha_corta( $documento->get_fecha_documento() ) ); ?></td>
						<td><?php echo esc_html( $documento->get_fecha_subida() ); ?></td>
						<td>
							<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( Admin_Publicacion_Documentos::url_vista_previa( $documento->get_id() ) ); ?>">Ver</a>
							<a href="<?php echo esc_url( Admin_Documentos_Publicados::url_historial( $documento->get_id() ) ); ?>">Historial</a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
								onsubmit="return confirm( '<?php echo esc_js( 'El documento dejará de verse en el área de su farmacia. ¿Continuar?' ); ?>' );">
								<?php wp_nonce_field( 'mdf_ca_despublicar_documento_' . $documento->get_id() ); ?>
								<input type="hidden" name="action" value="mdf_ca_despublicar_documento" />
								<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
								<?php if ( $farmacia_elegida ) : ?>
									<input type="hidden" name="farmacia_id" value="<?php echo esc_attr( (string) $farmacia_elegida->get_id() ); ?>" />
								<?php endif; ?>
								<?php if ( '' !== $q ) : ?>
									<input type="hidden" name="q" value="<?php echo esc_attr( $q ); ?>" />
								<?php endif; ?>
								<?php if ( $pagina > 1 ) : ?>
									<input type="hidden" name="paged" value="<?php echo esc_attr( (string) $pagina ); ?>" />
								<?php endif; ?>
								<?php submit_button( 'Despublicar', 'secondary', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $paginas > 1 ) : ?>
			<p class="tablenav-pages" style="margin-top:1em">
				<?php if ( $pagina > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( $url_pagina( $pagina - 1 ) ); ?>">« Anterior</a>
				<?php endif; ?>
				<span style="margin:0 .75em">Página <?php echo esc_html( (string) $pagina ); ?> de <?php echo esc_html( (string) $paginas ); ?></span>
				<?php if ( $pagina < $paginas ) : ?>
					<a class="button" href="<?php echo esc_url( $url_pagina( $pagina + 1 ) ); ?>">Siguiente »</a>
				<?php endif; ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
