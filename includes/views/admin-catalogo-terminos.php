<?php
/**
 * Catalogo MDF > Categorias / Etiquetas: crear, renombrar y borrar. Una sola
 * vista para las dos (misma forma). Una categoria en uso no se puede borrar;
 * una etiqueta en uso si, tras la confirmacion "Se quitara de N
 * herramientas". El slug no cambia al renombrar y se muestra para MKT.
 * Solo marcado; las reglas viven en Catalogo_Termino_Service.
 *
 * @var string                            $clase       categorias | etiquetas
 * @var string                            $slug        slug de la pantalla
 * @var \MdfClientArea\Catalogo_Termino[] $terminos
 * @var array<int, int>                   $uso         id => herramientas que lo usan
 * @var \MdfClientArea\Catalogo_Termino|null $a_eliminar Etiqueta cuya confirmacion de borrado se muestra.
 * @var array{tipo: string, mensaje: string, detalles: string[]}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mdf_ca_es_cat   = 'categorias' === $clase;
$mdf_ca_titulo   = $mdf_ca_es_cat ? 'Categorías' : 'Etiquetas';
$mdf_ca_singular = $mdf_ca_es_cat ? 'categoría' : 'etiqueta';
$mdf_ca_volver   = admin_url( 'admin.php?page=' . $slug );
?>
<div class="wrap">
	<h1><?php echo esc_html( $mdf_ca_titulo ); ?></h1>

	<?php require __DIR__ . '/parcial-aviso-catalogo.php'; ?>

	<?php if ( $a_eliminar ) : ?>
		<?php $mdf_ca_n = $uso[ $a_eliminar->get_id() ] ?? 0; ?>
		<div class="notice notice-warning inline">
			<p><strong>¿Borrar la etiqueta «<?php echo esc_html( $a_eliminar->get_nombre() ); ?>»?</strong></p>
			<p>
				<?php
				echo esc_html(
					$mdf_ca_n > 0
						? sprintf( _n( 'Se quitará de %d herramienta.', 'Se quitará de %d herramientas.', $mdf_ca_n ), $mdf_ca_n ) . ' No se puede deshacer.'
						: 'No la usa ninguna herramienta. No se puede deshacer.'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mdf_ca_eliminar_termino_etiquetas_' . $a_eliminar->get_id() ); ?>
				<input type="hidden" name="action" value="mdf_ca_eliminar_termino" />
				<input type="hidden" name="clase" value="etiquetas" />
				<input type="hidden" name="id" value="<?php echo esc_attr( (string) $a_eliminar->get_id() ); ?>" />
				<p>
					<button type="submit" class="button button-primary">Borrar la etiqueta</button>
					<a href="<?php echo esc_url( $mdf_ca_volver ); ?>" class="button">Cancelar</a>
				</p>
			</form>
		</div>
	<?php endif; ?>

	<h2>Añadir <?php echo esc_html( $mdf_ca_singular ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mdf_ca_guardar_termino_' . $clase . '_0' ); ?>
		<input type="hidden" name="action" value="mdf_ca_guardar_termino" />
		<input type="hidden" name="clase" value="<?php echo esc_attr( $clase ); ?>" />
		<input type="hidden" name="id" value="0" />
		<label for="mdf-ca-termino-nuevo" class="screen-reader-text">Nombre</label>
		<input type="text" id="mdf-ca-termino-nuevo" name="nombre" class="regular-text" maxlength="<?php echo esc_attr( (string) Catalogo_Termino_Service::MAX_NOMBRE ); ?>" required placeholder="Nombre" />
		<?php submit_button( 'Añadir', 'primary', 'submit', false ); ?>
	</form>

	<h2><?php echo esc_html( $mdf_ca_titulo ); ?> existentes</h2>
	<?php if ( ! $terminos ) : ?>
		<p>Todavía no hay ninguna.</p>
	<?php else : ?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th scope="col">Nombre</th>
					<th scope="col">Slug (para MKT, no cambia)</th>
					<th scope="col">Herramientas</th>
					<th scope="col">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $terminos as $mdf_ca_t ) : ?>
					<?php
					$mdf_ca_id = $mdf_ca_t->get_id();
					$mdf_ca_n  = $uso[ $mdf_ca_id ] ?? 0;
					?>
					<tr>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( 'mdf_ca_guardar_termino_' . $clase . '_' . $mdf_ca_id ); ?>
								<input type="hidden" name="action" value="mdf_ca_guardar_termino" />
								<input type="hidden" name="clase" value="<?php echo esc_attr( $clase ); ?>" />
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $mdf_ca_id ); ?>" />
								<label for="mdf-ca-termino-<?php echo esc_attr( (string) $mdf_ca_id ); ?>" class="screen-reader-text">Nombre</label>
								<input type="text" id="mdf-ca-termino-<?php echo esc_attr( (string) $mdf_ca_id ); ?>" name="nombre" class="regular-text" maxlength="<?php echo esc_attr( (string) Catalogo_Termino_Service::MAX_NOMBRE ); ?>" required
									value="<?php echo esc_attr( $mdf_ca_t->get_nombre() ); ?>" />
								<button type="submit" class="button">Renombrar</button>
							</form>
						</td>
						<td><code><?php echo esc_html( $mdf_ca_t->get_slug() ); ?></code></td>
						<td><?php echo esc_html( (string) $mdf_ca_n ); ?></td>
						<td>
							<?php if ( $mdf_ca_es_cat && $mdf_ca_n > 0 ) : ?>
								<span class="description">En uso: no se puede borrar</span>
							<?php elseif ( ! $mdf_ca_es_cat && $mdf_ca_n > 0 ) : ?>
								<a href="<?php echo esc_url( add_query_arg( 'eliminar', $mdf_ca_id, $mdf_ca_volver ) ); ?>" class="button-link-delete">Borrar…</a>
							<?php else : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'mdf_ca_eliminar_termino_' . $clase . '_' . $mdf_ca_id ); ?>
									<input type="hidden" name="action" value="mdf_ca_eliminar_termino" />
									<input type="hidden" name="clase" value="<?php echo esc_attr( $clase ); ?>" />
									<input type="hidden" name="id" value="<?php echo esc_attr( (string) $mdf_ca_id ); ?>" />
									<button type="submit" class="button-link button-link-delete">Borrar</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
