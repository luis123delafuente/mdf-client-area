<?php
/**
 * Vista de la pantalla de backoffice "Planes MDF". Solo marcado: toda la
 * logica de negocio (validacion, guardado, borrado) vive en Admin_Planes y
 * Plan_Service, esta plantilla solo pinta las variables que le pasa
 * Admin_Planes::render_pagina() ($planes, $plan_en_edicion, $aviso).
 *
 * @var \MdfClientArea\Plan[]      $planes
 * @var \MdfClientArea\Plan|null   $plan_en_edicion
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Planes MDF</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo 'error' === $aviso['tipo'] ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php echo $plan_en_edicion ? 'Editar plan' : 'Nuevo plan'; ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mdf_ca_guardar_plan' ); ?>
		<input type="hidden" name="action" value="mdf_ca_guardar_plan" />
		<?php if ( $plan_en_edicion ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $plan_en_edicion->get_id() ); ?>" />
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="mdf-ca-plan-nombre">Nombre</label></th>
					<td>
						<input
							type="text"
							id="mdf-ca-plan-nombre"
							name="nombre"
							class="regular-text"
							maxlength="100"
							required
							value="<?php echo esc_attr( $plan_en_edicion ? $plan_en_edicion->get_nombre() : '' ); ?>"
						/>
					</td>
				</tr>
				<?php if ( $plan_en_edicion ) : ?>
					<tr>
						<th scope="row">Identificador (slug)</th>
						<td>
							<code><?php echo esc_html( $plan_en_edicion->get_slug() ); ?></code>
							<p class="description">
								Se fijo al crear el plan y no cambia al renombrarlo: es lo que usan
								los shortcodes <code>[mdf_ca_si_plan planes="..."]</code> ya publicados
								para decidir que planes ven un bloque. Renombrar el plan no rompe esos
								shortcodes.
							</p>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php submit_button( $plan_en_edicion ? 'Guardar cambios' : 'Crear plan' ); ?>

		<?php if ( $plan_en_edicion ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mdf-ca-planes' ) ); ?>" class="button">Cancelar edicion</a>
		<?php endif; ?>
	</form>

	<h2>Planes existentes</h2>

	<?php if ( ! $planes ) : ?>
		<p>Todavia no hay ningun plan creado.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Nombre</th>
					<th>Slug</th>
					<th>Creado</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $planes as $plan ) : ?>
					<tr>
						<td><?php echo esc_html( $plan->get_nombre() ); ?></td>
						<td><code><?php echo esc_html( $plan->get_slug() ); ?></code></td>
						<td><?php echo esc_html( $plan->get_fecha_creacion() ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mdf-ca-planes&editar=' . $plan->get_id() ) ); ?>">Editar</a>
							|
							<a
								href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mdf_ca_eliminar_plan&id=' . $plan->get_id() ), 'mdf_ca_eliminar_plan_' . $plan->get_id() ) ); ?>"
								onclick="return confirm('¿Eliminar el plan &quot;<?php echo esc_js( $plan->get_nombre() ); ?>&quot;? Esto falla si alguna farmacia lo tiene asignado.');"
							>Eliminar</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
