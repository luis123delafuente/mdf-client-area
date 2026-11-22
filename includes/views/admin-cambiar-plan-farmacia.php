<?php
/**
 * «Cambiar plan» de una farmacia (v0.48.0). Solo marcado: la lógica vive en
 * Admin_Farmacias::gestionar_cambiar_plan() y Farmacia_Service::cambiar_plan().
 *
 * @var \MdfClientArea\Farmacia|null                  $farmacia
 * @var \MdfClientArea\Plan[]                         $planes
 * @var array{tipo: string, mensaje: string}|null     $aviso
 * @var string                                        $accion
 * @var string                                        $url_volver
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Cambiar plan</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo 'error' === $aviso['tipo'] ? 'error' : 'success'; ?> inline">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( null === $farmacia ) : ?>
		<div class="notice notice-error inline">
			<p>La farmacia no existe.</p>
		</div>
		<p><a class="button" href="<?php echo esc_url( $url_volver ); ?>">Volver</a></p>
	<?php elseif ( ! $planes ) : ?>
		<div class="notice notice-error inline">
			<p>Todavía no hay ningún plan creado. Crea uno primero desde Planes MDF.</p>
		</div>
		<p><a class="button" href="<?php echo esc_url( $url_volver ); ?>">Volver</a></p>
	<?php else : ?>
		<p>
			<?php echo esc_html( sprintf( 'Farmacia «%s» (%s).', $farmacia->get_nombre(), $farmacia->get_cif() ) ); ?>
			El plan decide qué herramientas y qué bloques del área ve la farmacia. El cambio se aplica al momento; sus documentos, su cuenta y su invitación no cambian.
		</p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $accion . '_' . $farmacia->get_id() ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $accion ); ?>" />
			<input type="hidden" name="farmacia_id" value="<?php echo esc_attr( (string) $farmacia->get_id() ); ?>" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-cambiar-plan">Plan</label></th>
						<td>
							<select id="mdf-ca-cambiar-plan" name="plan_id" required>
								<?php if ( null === $farmacia->get_plan_id() ) : ?>
									<option value="">-- Sin plan: elige uno --</option>
								<?php endif; ?>
								<?php foreach ( $planes as $plan ) : ?>
									<option value="<?php echo esc_attr( (string) $plan->get_id() ); ?>" <?php selected( $farmacia->get_plan_id(), $plan->get_id() ); ?>>
										<?php echo esc_html( $plan->get_nombre() ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</tbody>
			</table>

			<p>
				<button type="submit" class="button button-primary">Guardar plan</button>
				<a class="button" href="<?php echo esc_url( $url_volver ); ?>">Cancelar</a>
			</p>
		</form>
	<?php endif; ?>
</div>
