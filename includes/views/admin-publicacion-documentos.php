<?php
/**
 * Vista de "Documentos MDF > Pendientes de publicar". Solo marcado: la logica
 * vive en Admin_Publicacion_Documentos y en Documento_Repository. No decide
 * visibilidad (eso es Permissions).
 *
 * @var array<int, array{farmacia_id: int, coinciden: int, documentos: \MdfClientArea\Documento[], hay_mas: bool}> $grupos
 *      Solo los de la pagina actual.
 * @var array<int, int>                         $pendientes_farmacia Todos los pendientes de cada farmacia de la pagina (SQL).
 * @var array{documentos: int, farmacias: int}  $totales             Todos los pendientes (SQL).
 * @var array{documentos: int, farmacias: int}  $filtrados           Con los filtros (SQL).
 * @var \MdfClientArea\Filtro_Listado_Documentos $filtro           Ya con la pagina limitada al rango.
 * @var bool                                    $por_farmacia        Filtrado por una farmacia: se pagina por sus documentos.
 * @var int                                     $paginas
 * @var int                                     $docs_por_grupo
 * @var \MdfClientArea\Farmacia[]               $farmacias           Para el selector, por nombre.
 * @var \MdfClientArea\Farmacia|null            $farmacia_elegida
 * @var callable(int): string                   $url_pagina
 * @var callable(int): string                   $url_farmacia
 * @var string                                  $url_sin_filtros
 * @var array<int, \MdfClientArea\Farmacia>     $farmacias_por_id
 * @var int                                     $total_pendientes
 * @var string                                  $nonce_preparar
 * @var string                                  $nonce_deshacer
 * @var array<int, array<string, mixed>>        $historial
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$nombre_farmacia = static function ( int $farmacia_id ) use ( $farmacias_por_id ): string {
	return isset( $farmacias_por_id[ $farmacia_id ] )
		? $farmacias_por_id[ $farmacia_id ]->get_nombre()
		: sprintf( 'Farmacia ya no existe (ID %d)', $farmacia_id );
};
?>
<div class="wrap">
	<h1>Documentos pendientes de publicar</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning' ), true ) ? $aviso['tipo'] : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		Los documentos que llegan por la recepcion automatica quedan aqui, invisibles para todas las farmacias, hasta que
		los publicas. Usa "Ver" para revisarlos antes (vista previa solo para administradores, con marca de agua y sin
		descarga). Al publicar un documento, su farmacia lo ve y entra en su siguiente aviso por email.
	</p>

	<h2>Pendientes de publicar</h2>

	<p>
		<strong>
			<?php
			echo esc_html(
				0 === $totales['documentos']
					? 'No hay ningún documento pendiente de publicar.'
					: sprintf(
						'%s pendientes de %s.',
						1 === $totales['documentos'] ? '1 documento' : number_format_i18n( $totales['documentos'] ) . ' documentos',
						1 === $totales['farmacias'] ? '1 farmacia' : number_format_i18n( $totales['farmacias'] ) . ' farmacias'
					)
			);
			?>
		</strong>
	</p>

	<?php if ( $total_pendientes > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
			<?php wp_nonce_field( $nonce_preparar ); ?>
			<input type="hidden" name="action" value="mdf_ca_publicacion_bloque_preparar" />
			<?php submit_button( 'Publicar todos los pendientes…', 'primary', 'submit', false ); ?>
			<span class="description" style="margin-left:.5em">
				Antes verás cuántos documentos y farmacias se publicarán, y podrás acotar por tipo y por fecha de recepción. No se publica nada hasta que confirmes.
			</span>
		</form>

		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="mdf-ca-publicacion-documentos" />
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-pendientes-farmacia">Farmacia</label></th>
						<td>
							<?php
							$selector_id = 'mdf-ca-pendientes-farmacia';
							require MDF_CA_PLUGIN_DIR . 'includes/views/parcial-selector-farmacia.php';
							?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-pendientes-q">Nombre del documento</label></th>
						<td>
							<input type="search" id="mdf-ca-pendientes-q" name="q" class="regular-text" maxlength="<?php echo esc_attr( (string) Filtro_Listado_Documentos::MAX_NOMBRE ); ?>" value="<?php echo esc_attr( $filtro->q ); ?>" />
							<p class="description">Busca el texto tal cual, en cualquier parte del nombre.</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( 'Buscar', 'secondary', 'submit', false ); ?>
			<?php if ( $filtro->hay_filtros() ) : ?>
				<a href="<?php echo esc_url( $url_sin_filtros ); ?>" style="margin-left:.5em">Quitar filtros</a>
			<?php endif; ?>
		</form>

		<?php if ( $filtro->hay_filtros() ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						'Con estos filtros: %s de %s.',
						1 === $filtrados['documentos'] ? '1 documento' : number_format_i18n( $filtrados['documentos'] ) . ' documentos',
						1 === $filtrados['farmacias'] ? '1 farmacia' : number_format_i18n( $filtrados['farmacias'] ) . ' farmacias'
					)
				);
				?>
			</p>
		<?php endif; ?>

		<p class="description">
			Primero las farmacias que llevan más tiempo esperando; dentro de cada una, por orden de llegada. Lo que llega
			mientras revisas se añade al final, así que pasar de página no repite ni salta farmacias. Si otra persona publica
			a la vez, recarga la página antes de seguir.
		</p>
	<?php endif; ?>

	<?php if ( $total_pendientes > 0 && ! $grupos ) : ?>
		<p>Ningún documento pendiente con estos filtros.</p>
	<?php endif; ?>

	<?php foreach ( $grupos as $grupo ) : ?>
		<?php
		$farmacia_id = $grupo['farmacia_id'];
		$todos       = $pendientes_farmacia[ $farmacia_id ] ?? $grupo['coinciden'];
		$documentos  = $grupo['documentos'];
		?>
		<h3>
			<?php echo esc_html( $nombre_farmacia( $farmacia_id ) ); ?>
			<span class="description">
				<?php
				echo esc_html(
					'(' . ( 1 === $todos ? '1 pendiente' : number_format_i18n( $todos ) . ' pendientes' )
					. ( '' !== $filtro->q ? sprintf( '; %d coincide%s con la búsqueda', $grupo['coinciden'], 1 === $grupo['coinciden'] ? '' : 'n' ) : '' )
					. ')'
				);
				?>
			</span>
		</h3>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:.5em"
			onsubmit="return confirm( '<?php echo esc_js( 1 === $todos ? 'Se publicará el documento pendiente de esta farmacia. ¿Continuar?' : sprintf( 'Se publicarán los %d documentos pendientes de esta farmacia (todos, no solo los que se ven en esta página). ¿Continuar?', $todos ) ); ?>' );">
			<?php wp_nonce_field( 'mdf_ca_publicar_farmacia_' . $farmacia_id ); ?>
			<input type="hidden" name="action" value="mdf_ca_publicar_farmacia" />
			<input type="hidden" name="farmacia_id" value="<?php echo esc_attr( (string) $farmacia_id ); ?>" />
			<?php foreach ( $filtro->campos_retorno() as $campo => $valor ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $campo ); ?>" value="<?php echo esc_attr( $valor ); ?>" />
			<?php endforeach; ?>
			<?php submit_button( sprintf( 'Publicar los %s de esta farmacia', 1 === $todos ? '1' : number_format_i18n( $todos ) ), 'secondary', 'submit', false ); ?>
		</form>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Documento</th>
					<th style="width:10em">Tipo</th>
					<th style="width:9em">Fecha documento</th>
					<th style="width:12em">Recibido</th>
					<th style="width:14em">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $documentos as $documento ) : ?>
					<tr>
						<td><?php echo esc_html( $documento->get_nombre() ); ?></td>
						<td><?php echo esc_html( Documento_Tipos::get_etiqueta( $documento->get_tipo_documento() ) ); ?></td>
						<td><?php echo esc_html( Documento::formatear_fecha_corta( $documento->get_fecha_documento() ) ); ?></td>
						<td><?php echo esc_html( $documento->get_fecha_subida() ); ?></td>
						<td>
							<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( Admin_Publicacion_Documentos::url_vista_previa( $documento->get_id() ) ); ?>">Ver</a>
							<a href="<?php echo esc_url( Admin_Documentos_Publicados::url_historial( $documento->get_id() ) ); ?>">Historial</a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'mdf_ca_publicar_documento_' . $documento->get_id() ); ?>
								<input type="hidden" name="action" value="mdf_ca_publicar_documento" />
								<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
								<?php foreach ( $filtro->campos_retorno() as $campo => $valor ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $campo ); ?>" value="<?php echo esc_attr( $valor ); ?>" />
								<?php endforeach; ?>
								<?php submit_button( 'Publicar', 'primary', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $grupo['hay_mas'] ) : ?>
			<p class="description">
				<?php echo esc_html( sprintf( 'Se muestran los %d primeros de %d.', $docs_por_grupo, $grupo['coinciden'] ) ); ?>
				<a href="<?php echo esc_url( $url_farmacia( $farmacia_id ) ); ?>">Ver todos los de esta farmacia</a>
			</p>
		<?php endif; ?>
	<?php endforeach; ?>

	<?php if ( $paginas > 1 ) : ?>
		<p class="tablenav-pages" style="margin-top:1em">
			<?php if ( $filtro->pagina > 1 ) : ?>
				<a class="button" href="<?php echo esc_url( $url_pagina( $filtro->pagina - 1 ) ); ?>">« Anterior</a>
			<?php endif; ?>
			<span style="margin:0 .75em">
				<?php
				echo esc_html(
					sprintf(
						'Página %d de %d (%s)',
						$filtro->pagina,
						$paginas,
						$por_farmacia ? 'documentos de esta farmacia' : 'farmacias'
					)
				);
				?>
			</span>
			<?php if ( $filtro->pagina < $paginas ) : ?>
				<a class="button" href="<?php echo esc_url( $url_pagina( $filtro->pagina + 1 ) ); ?>">Siguiente »</a>
			<?php endif; ?>
		</p>
	<?php endif; ?>

	<h2>Documentos publicados</h2>
	<p class="description">
		Para corregir un documento ya publicado (despublicarlo, o ver quien lo publico y cuando), busca entre todos los
		publicados por farmacia o por nombre en
		<a href="<?php echo esc_url( Admin_Documentos_Publicados::url_listado() ); ?>">Documentos MDF › Documentos publicados</a>.
	</p>

	<h2>Lotes publicados en bloque</h2>
	<p class="description">
		Cada «Publicar todos los pendientes» crea un lote que se puede deshacer aquí. Los documentos publicados uno a uno,
		por farmacia o antes de la versión 0.19.0 no pertenecen a ningún lote y no se pueden deshacer en bloque: se
		despublican uno a uno arriba. Deshacer no retira lo que las farmacias ya hayan visto o descargado.
	</p>

	<?php if ( ! $historial ) : ?>
		<p>Todavía no se ha publicado ningún lote.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:9em">Fecha</th>
					<th>Publicado por</th>
					<th>Filtros</th>
					<th style="width:7em">Documentos</th>
					<th style="width:8em">Siguen publicados</th>
					<th>Estado</th>
					<th style="width:11em">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $historial as $lote ) : ?>
					<tr>
						<td><?php echo esc_html( $lote['fecha'] ); ?></td>
						<td><?php echo esc_html( $lote['quien'] ); ?></td>
						<td><?php echo esc_html( $lote['filtros'] ); ?></td>
						<td><?php echo esc_html( (string) $lote['documentos'] ); ?></td>
						<td><?php echo esc_html( (string) $lote['siguen'] ); ?></td>
						<td>
							<?php
							echo esc_html(
								$lote['deshecho']
									? sprintf( 'Deshecho el %s por %s (%d despublicados)', $lote['deshecho_en'], $lote['deshecho_por'], $lote['despublicados'] )
									: 'Aplicado'
							);
							?>
						</td>
						<td>
							<?php if ( ! $lote['deshecho'] && $lote['siguen'] > 0 ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( $nonce_deshacer ); ?>
									<input type="hidden" name="action" value="mdf_ca_despublicar_lote_preparar" />
									<input type="hidden" name="lote_id" value="<?php echo esc_attr( (string) $lote['id'] ); ?>" />
									<?php submit_button( 'Despublicar lote…', 'secondary', 'submit', false ); ?>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
