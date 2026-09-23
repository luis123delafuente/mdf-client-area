<?php
/**
 * Vista de la pantalla de backoffice "Catalogo MDF". Solo marcado: toda la
 * logica de negocio (validacion, guardado, borrado) vive en Admin_Catalogo
 * y Catalogo_Service, mismo criterio que admin-planes.php y
 * admin-documentos.php.
 *
 * @var \MdfClientArea\Plan[]          $planes
 * @var \MdfClientArea\Catalogo_Item[] $items
 * @var \MdfClientArea\Catalogo_Item|null $item_en_edicion
 * @var int[]                          $planes_del_item_en_edicion
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Catalogo MDF</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo 'error' === $aviso['tipo'] ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<h2><?php echo $item_en_edicion ? 'Editar item de catalogo' : 'Nuevo item de catalogo'; ?></h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mdf_ca_guardar_catalogo' ); ?>
		<input type="hidden" name="action" value="mdf_ca_guardar_catalogo" />
		<?php if ( $item_en_edicion ) : ?>
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $item_en_edicion->get_id() ); ?>" />
		<?php endif; ?>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="mdf-ca-catalogo-seccion">Seccion</label></th>
					<td>
						<select id="mdf-ca-catalogo-seccion" name="seccion" required>
							<?php foreach ( Catalogo_Secciones::get_opciones() as $clave => $etiqueta ) : ?>
								<option
									value="<?php echo esc_attr( $clave ); ?>"
									<?php selected( $item_en_edicion ? $item_en_edicion->get_seccion() : '', $clave ); ?>
								><?php echo esc_html( $etiqueta ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-catalogo-nombre">Nombre</label></th>
					<td>
						<input
							type="text"
							id="mdf-ca-catalogo-nombre"
							name="nombre"
							class="regular-text"
							maxlength="255"
							required
							value="<?php echo esc_attr( $item_en_edicion ? $item_en_edicion->get_nombre() : '' ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-catalogo-tipo">Tipo</label></th>
					<td>
						<select id="mdf-ca-catalogo-tipo" name="tipo" required>
							<?php foreach ( Catalogo_Tipos::get_opciones() as $clave => $etiqueta ) : ?>
								<option
									value="<?php echo esc_attr( $clave ); ?>"
									<?php selected( $item_en_edicion ? $item_en_edicion->get_tipo() : Catalogo_Tipos::VISUALIZABLE, $clave ); ?>
								><?php echo esc_html( $etiqueta ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-catalogo-enlace">Enlace (opcional)</label></th>
					<td>
						<input
							type="url"
							id="mdf-ca-catalogo-enlace"
							name="enlace_url"
							class="regular-text"
							placeholder="https://..."
							value="<?php echo esc_attr( $item_en_edicion ? (string) $item_en_edicion->get_enlace_url() : '' ); ?>"
						/>
						<p class="description">
							Enlace al recurso descargable, o a la Academia para un item de
							Formacion. Dejalo vacio si el item no tiene enlace.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Planes</th>
					<td>
						<?php if ( ! $planes ) : ?>
							<p>
								Todavia no hay ningun plan creado. Puedes guardar el item igualmente,
								pero no sera visible para ninguna farmacia hasta que le asignes al
								menos un plan.
							</p>
						<?php else : ?>
							<fieldset>
								<?php foreach ( $planes as $plan ) : ?>
									<label style="display:block;">
										<input
											type="checkbox"
											name="plan_ids[]"
											value="<?php echo esc_attr( (string) $plan->get_id() ); ?>"
											<?php checked( in_array( $plan->get_id(), $planes_del_item_en_edicion, true ) ); ?>
										/>
										<?php echo esc_html( $plan->get_nombre() ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description">
								Sin ningun plan marcado, el item no sera visible para ninguna
								farmacia (criterio restrictivo por defecto).
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( $item_en_edicion ? 'Guardar cambios' : 'Crear item' ); ?>

		<?php if ( $item_en_edicion ) : ?>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mdf-ca-catalogo' ) ); ?>" class="button">Cancelar edicion</a>
		<?php endif; ?>
	</form>

	<h2>Items existentes</h2>

	<?php if ( ! $items ) : ?>
		<p>Todavia no hay ningun item de catalogo creado.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Seccion</th>
					<th>Nombre</th>
					<th>Tipo</th>
					<th>Planes</th>
					<th>Enlace</th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<tr>
						<td><?php echo esc_html( Catalogo_Secciones::get_etiqueta( $item->get_seccion() ) ); ?></td>
						<td><?php echo esc_html( $item->get_nombre() ); ?></td>
						<td><?php echo esc_html( Catalogo_Tipos::get_etiqueta( $item->get_tipo() ) ); ?></td>
						<td>
							<?php echo $item->get_planes_slugs() ? esc_html( implode( ', ', $item->get_planes_slugs() ) ) : '<em>Ninguno (no visible)</em>'; ?>
						</td>
						<td>
							<?php if ( $item->get_enlace_url() ) : ?>
								<a href="<?php echo esc_url( $item->get_enlace_url() ); ?>" target="_blank" rel="noopener noreferrer">Ver</a>
							<?php else : ?>
								-
							<?php endif; ?>
						</td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mdf-ca-catalogo&editar=' . $item->get_id() ) ); ?>">Editar</a>
							|
							<a
								href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mdf_ca_eliminar_catalogo&id=' . $item->get_id() ), 'mdf_ca_eliminar_catalogo_' . $item->get_id() ) ); ?>"
								onclick="return confirm('¿Eliminar el item &quot;<?php echo esc_js( $item->get_nombre() ); ?>&quot;?');"
							>Eliminar</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
