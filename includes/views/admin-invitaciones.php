<?php
/**
 * Vista de la pantalla de backoffice "Invitaciones MDF". Solo marcado: toda
 * la logica (crear usuario, generar token, enviar email) vive en
 * Admin_Invitaciones y Invitacion_Service, mismo criterio que el resto de
 * pantallas de backoffice del plugin.
 *
 * Cada fila usa el atributo HTML "form" en sus campos/boton en vez de
 * anidar un <form> dentro de cada <tr> (invalido dentro de una <table>):
 * el <form> real de cada fila vive fuera de la tabla, con el mismo id que
 * referencian sus campos.
 *
 * @var \MdfClientArea\Farmacia[] $farmacias
 * @var array<int, array{estado: string, etiqueta: string, email: ?string, enviada_en: ?int}> $estados
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Invitaciones MDF</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo 'error' === $aviso['tipo'] ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		Invitar una farmacia sin cuenta crea su acceso y envia un enlace para
		que active su contrasena. Reenviar a una farmacia con invitacion
		pendiente o cuenta activa genera un enlace nuevo e invalida
		cualquier enlace anterior sin usar.
	</p>

	<?php if ( ! $farmacias ) : ?>
		<p>Todavia no hay ninguna farmacia dada de alta.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Farmacia</th>
					<th>Estado</th>
					<th>Email</th>
					<th>Ultima invitacion</th>
					<th>Accion</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $farmacias as $farmacia ) : ?>
					<?php
					$estado    = $estados[ $farmacia->get_id() ];
					$form_id   = 'mdf-ca-invitar-' . $farmacia->get_id();
					?>
					<tr>
						<td><?php echo esc_html( $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')' ); ?></td>
						<td><?php echo esc_html( $estado['etiqueta'] ); ?></td>
						<td><?php echo $estado['email'] ? esc_html( $estado['email'] ) : '-'; ?></td>
						<td>
							<?php echo $estado['enviada_en'] ? esc_html( date_i18n( get_option( 'date_format' ) . ' H:i', $estado['enviada_en'] ) ) : '-'; ?>
						</td>
						<td>
							<?php if ( 'usuario_roto' === $estado['estado'] ) : ?>
								<em>El usuario vinculado ya no existe. Requiere revision manual.</em>
							<?php elseif ( 'sin_cuenta' === $estado['estado'] ) : ?>
								<input type="text" form="<?php echo esc_attr( $form_id ); ?>" name="user_login" placeholder="Usuario" style="width:120px;" required />
								<input type="email" form="<?php echo esc_attr( $form_id ); ?>" name="email" placeholder="email@farmacia.com" style="width:200px;" required />
								<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="button button-primary">Enviar invitacion</button>
							<?php else : ?>
								<button type="submit" form="<?php echo esc_attr( $form_id ); ?>" class="button">Reenviar invitacion</button>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php foreach ( $farmacias as $farmacia ) : ?>
			<?php if ( 'usuario_roto' === $estados[ $farmacia->get_id() ]['estado'] ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<form id="mdf-ca-invitar-<?php echo esc_attr( (string) $farmacia->get_id() ); ?>" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mdf_ca_enviar_invitacion' ); ?>
				<input type="hidden" name="action" value="mdf_ca_enviar_invitacion" />
				<input type="hidden" name="farmacia_id" value="<?php echo esc_attr( (string) $farmacia->get_id() ); ?>" />
			</form>
		<?php endforeach; ?>
	<?php endif; ?>
</div>
