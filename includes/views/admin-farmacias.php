<?php
/**
 * Vista de la pantalla de backoffice "Farmacias MDF". Solo marcado: toda
 * la logica (validacion de CIF, duplicados, plan) vive en Admin_Farmacias
 * y Farmacia_Service, mismo criterio que el resto de pantallas de
 * backoffice del plugin.
 *
 * Alta minima para el piloto de Fase 3 (ver cabecera de Admin_Farmacias):
 * sin edicion ni borrado, sin importador -- eso es Fase 4.
 *
 * @var \MdfClientArea\Plan[]     $planes
 * @var \MdfClientArea\Farmacia[] $farmacias
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Farmacias MDF</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo 'error' === $aviso['tipo'] ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		Alta minima para el piloto: nombre, CIF/NIF y plan. Sin importador
		ni formulario completo todavia (Fase 4). Tras crear la farmacia se
		puede invitar de inmediato desde Invitaciones MDF.
	</p>

	<h2>Nueva farmacia</h2>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mdf_ca_crear_farmacia' ); ?>
		<input type="hidden" name="action" value="mdf_ca_crear_farmacia" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="mdf-ca-farmacia-nombre">Nombre</label></th>
					<td>
						<input
							type="text"
							id="mdf-ca-farmacia-nombre"
							name="nombre"
							class="regular-text"
							maxlength="255"
							required
						/>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-farmacia-cif">CIF / NIF</label></th>
					<td>
						<input
							type="text"
							id="mdf-ca-farmacia-cif"
							name="cif"
							class="regular-text"
							maxlength="9"
							placeholder="B12345674 (sociedad) o 12345678Z (persona fisica)"
							required
						/>
						<p class="description">CIF de sociedad o NIF de persona fisica. Se detecta el formato solo y se valida el digito/letra de control antes de guardar.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-farmacia-plan">Plan</label></th>
					<td>
						<select id="mdf-ca-farmacia-plan" name="plan_id">
							<option value="">-- Sin plan --</option>
							<?php foreach ( $planes as $plan ) : ?>
								<option value="<?php echo esc_attr( (string) $plan->get_id() ); ?>">
									<?php echo esc_html( $plan->get_nombre() ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php if ( ! $planes ) : ?>
							<p class="description">Todavia no hay ningun plan creado. Puedes crear la farmacia sin plan y asignarlo despues desde Planes MDF.</p>
						<?php endif; ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( 'Crear farmacia' ); ?>
	</form>

	<h2>Farmacias existentes</h2>

	<?php if ( ! $farmacias ) : ?>
		<p>Todavia no hay ninguna farmacia dada de alta.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Nombre</th>
					<th>CIF / NIF</th>
					<th>Alta</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $farmacias as $farmacia ) : ?>
					<tr>
						<td><?php echo esc_html( $farmacia->get_nombre() ); ?></td>
						<td><?php echo esc_html( $farmacia->get_cif() ); ?></td>
						<td><?php echo esc_html( $farmacia->get_fecha_alta() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description">
			Gestiona la invitacion de acceso de cada farmacia desde
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=mdf-ca-invitaciones' ) ); ?>">Invitaciones MDF</a>.
		</p>
	<?php endif; ?>
</div>
