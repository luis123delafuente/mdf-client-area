<?php
/**
 * Vista de "Documentos MDF > Pendientes de publicar". Solo marcado: la logica
 * vive en Admin_Publicacion_Documentos y en Documento_Repository. No decide
 * visibilidad (eso es Permissions).
 *
 * @var array<int, \MdfClientArea\Documento[]>  $pendientes_por_farmacia
 * @var array<int, \MdfClientArea\Farmacia>     $farmacias_por_id
 * @var \MdfClientArea\Documento[]              $recientes
 * @var int                                     $total_pendientes
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

	<h2>Pendientes de publicar (<?php echo esc_html( (string) $total_pendientes ); ?>)</h2>

	<?php if ( ! $pendientes_por_farmacia ) : ?>
		<p>No hay ningun documento pendiente de publicar.</p>
	<?php else : ?>
		<?php foreach ( $pendientes_por_farmacia as $farmacia_id => $documentos ) : ?>
			<h3>
				<?php echo esc_html( $nombre_farmacia( (int) $farmacia_id ) ); ?>
				<span class="description">(<?php echo esc_html( 1 === count( $documentos ) ? '1 documento' : count( $documentos ) . ' documentos' ); ?>)</span>
			</h3>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:.5em"
				onsubmit="return confirm( '<?php echo esc_js( 1 === count( $documentos ) ? 'Se publicara el documento pendiente de esta farmacia. ¿Continuar?' : sprintf( 'Se publicaran los %d documentos pendientes de esta farmacia. ¿Continuar?', count( $documentos ) ) ); ?>' );">
				<?php wp_nonce_field( 'mdf_ca_publicar_farmacia_' . (int) $farmacia_id ); ?>
				<input type="hidden" name="action" value="mdf_ca_publicar_farmacia" />
				<input type="hidden" name="farmacia_id" value="<?php echo esc_attr( (string) $farmacia_id ); ?>" />
				<?php submit_button( 'Publicar todos los de esta farmacia', 'secondary', 'submit', false ); ?>
			</form>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th>Documento</th>
						<th style="width:12em">Tipo</th>
						<th style="width:12em">Recibido</th>
						<th style="width:14em">Acciones</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $documentos as $documento ) : ?>
						<tr>
							<td><?php echo esc_html( $documento->get_nombre() ); ?></td>
							<td><?php echo esc_html( Documento_Tipos::get_etiqueta( $documento->get_tipo_documento() ) ); ?></td>
							<td><?php echo esc_html( $documento->get_fecha_subida() ); ?></td>
							<td>
								<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( Admin_Publicacion_Documentos::url_vista_previa( $documento->get_id() ) ); ?>">Ver</a>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( 'mdf_ca_publicar_documento_' . $documento->get_id() ); ?>
									<input type="hidden" name="action" value="mdf_ca_publicar_documento" />
									<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
									<?php submit_button( 'Publicar', 'primary', 'submit', false ); ?>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endforeach; ?>
	<?php endif; ?>

	<h2>Publicados recientemente (recepcion automatica)</h2>
	<p class="description">Los <?php echo esc_html( (string) count( $recientes ) ); ?> ultimos. Despublicar un documento lo oculta de nuevo a su farmacia.</p>

	<?php if ( ! $recientes ) : ?>
		<p>Todavia no se ha publicado ningun documento recibido automaticamente.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Documento</th>
					<th>Farmacia</th>
					<th style="width:10em">Tipo</th>
					<th style="width:12em">Recibido</th>
					<th style="width:14em">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $recientes as $documento ) : ?>
					<tr>
						<td><?php echo esc_html( $documento->get_nombre() ); ?></td>
						<td><?php echo esc_html( $nombre_farmacia( $documento->get_farmacia_id() ) ); ?></td>
						<td><?php echo esc_html( Documento_Tipos::get_etiqueta( $documento->get_tipo_documento() ) ); ?></td>
						<td><?php echo esc_html( $documento->get_fecha_subida() ); ?></td>
						<td>
							<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( Admin_Publicacion_Documentos::url_vista_previa( $documento->get_id() ) ); ?>">Ver</a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
								onsubmit="return confirm( '<?php echo esc_js( 'El documento dejara de verse en el area de su farmacia. ¿Continuar?' ); ?>' );">
								<?php wp_nonce_field( 'mdf_ca_despublicar_documento_' . $documento->get_id() ); ?>
								<input type="hidden" name="action" value="mdf_ca_despublicar_documento" />
								<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
								<?php submit_button( 'Despublicar', 'secondary', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
