<?php
/**
 * Invitar a todas las farmacias sin cuenta (v0.47.0). Parcial de
 * admin-invitaciones.php. Tres estados: boton, revision previa (nada se
 * envia hasta pulsar «Empezar») y progreso de un envio en curso, que lleva
 * admin-invitaciones-masiva.js. Toda la logica en Invitacion_Masiva_Service.
 *
 * @var array<string, mixed>|null $trabajo
 * @var array<string, int>|null   $masiva_resumen
 * @var array|null                $revision
 * @var array<int, string>        $nombres_farmacias
 * @var int                       $sin_cuenta
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$minutos = static function ( int $n ): int {
	return max( 1, (int) ceil( ( ceil( $n / Invitacion_Masiva_Service::TAMANO_TANDA ) - 1 ) * Invitacion_Masiva_Service::INTERVALO_SEGUNDOS / 60 ) );
};
$ritmo   = sprintf( '%d invitaciones cada %d segundos', Invitacion_Masiva_Service::TAMANO_TANDA, Invitacion_Masiva_Service::INTERVALO_SEGUNDOS );
$url     = admin_url( 'admin.php?page=' . Admin_Invitaciones::MENU_SLUG );
?>
<div class="card" style="max-width:none;margin:1em 0 1.5em">
	<h2 style="margin-top:.5em">Invitar a todas las farmacias sin cuenta</h2>

	<?php if ( $trabajo ) : ?>
		<?php
		$hechas = $masiva_resumen['total'] - $masiva_resumen['pendientes'];
		?>
		<div id="mdf-ca-invitacion-masiva" data-pendientes="<?php echo esc_attr( (string) $masiva_resumen['pendientes'] ); ?>">
			<p>
				<progress id="mdf-ca-im-barra" max="<?php echo esc_attr( (string) $masiva_resumen['total'] ); ?>" value="<?php echo esc_attr( (string) $hechas ); ?>" style="width:100%;max-width:40em;height:1.4em"></progress>
			</p>
			<p>
				<strong>Enviadas:</strong> <span id="mdf-ca-im-enviadas"><?php echo esc_html( (string) $masiva_resumen['enviadas'] ); ?></span> &nbsp;
				<strong>Fallidas:</strong> <span id="mdf-ca-im-fallidas"><?php echo esc_html( (string) $masiva_resumen['fallidas'] ); ?></span> &nbsp;
				<strong>Omitidas:</strong> <span id="mdf-ca-im-omitidas"><?php echo esc_html( (string) $masiva_resumen['omitidas'] ); ?></span> &nbsp;
				<strong>Por enviar:</strong> <span id="mdf-ca-im-pendientes"><?php echo esc_html( (string) $masiva_resumen['pendientes'] ); ?></span>
				de <?php echo esc_html( (string) $masiva_resumen['total'] ); ?>
			</p>
			<p id="mdf-ca-im-estado" role="status" aria-live="polite">
				<?php if ( $masiva_resumen['pendientes'] > 0 ) : ?>
					Envío en pausa. Ritmo: <?php echo esc_html( $ritmo ); ?>. Deja esta página abierta mientras envía.
				<?php else : ?>
					Envío terminado.
				<?php endif; ?>
			</p>
			<noscript><p><strong>El envío necesita JavaScript.</strong></p></noscript>

			<?php if ( $masiva_resumen['pendientes'] > 0 ) : ?>
				<p>
					<button type="button" class="button button-primary" id="mdf-ca-im-continuar">
						<?php echo 0 === $hechas ? 'Empezar a enviar' : 'Continuar'; ?>
					</button>
					<button type="button" class="button" id="mdf-ca-im-parar" hidden style="display:none">Parar</button>
				</p>
			<?php endif; ?>

			<?php
			$incidencias = array();

			foreach ( $trabajo['estados'] as $id => $estado ) {
				if ( in_array( $estado['estado'], array( Invitacion_Masiva_Service::FALLIDA, Invitacion_Masiva_Service::OMITIDA ), true ) ) {
					$incidencias[ $id ] = $estado;
				}
			}
			?>
			<table class="widefat striped" id="mdf-ca-im-incidencias" style="max-width:60em<?php echo $incidencias ? '' : ';display:none'; ?>">
				<thead><tr><th>Farmacia</th><th style="width:7em">Resultado</th><th>Motivo</th></tr></thead>
				<tbody>
					<?php foreach ( $incidencias as $id => $estado ) : ?>
						<tr>
							<td><?php echo esc_html( $nombres_farmacias[ $id ] ?? 'ID ' . $id ); ?></td>
							<td><?php echo esc_html( Invitacion_Masiva_Service::FALLIDA === $estado['estado'] ? 'Fallida' : 'Omitida' ); ?></td>
							<td><?php echo esc_html( $estado['motivo'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<div id="mdf-ca-im-acciones" style="margin-top:1em<?php echo $masiva_resumen['pendientes'] > 0 ? ';display:none' : ''; ?>">
				<?php if ( $masiva_resumen['fallidas'] > 0 ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
						<?php wp_nonce_field( 'mdf_ca_invitacion_masiva_reintentar' ); ?>
						<input type="hidden" name="action" value="mdf_ca_invitacion_masiva_reintentar" />
						<?php submit_button( 'Reintentar las fallidas', 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'mdf_ca_invitacion_masiva_cerrar' ); ?>
					<input type="hidden" name="action" value="mdf_ca_invitacion_masiva_cerrar" />
					<?php submit_button( 'Cerrar este envío', 'secondary', 'submit', false ); ?>
				</form>
			</div>
			<?php if ( $masiva_resumen['pendientes'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="mdf-ca-im-cancelar" style="margin-top:.5em">
					<?php wp_nonce_field( 'mdf_ca_invitacion_masiva_cerrar' ); ?>
					<input type="hidden" name="action" value="mdf_ca_invitacion_masiva_cerrar" />
					<button type="submit" class="button-link">Cancelar el resto del envío</button>
					<span class="description">(las que falten se quedan sin invitar; lo enviado no se deshace)</span>
				</form>
			<?php endif; ?>
		</div>

	<?php elseif ( $revision ) : ?>
		<?php $n = count( $revision['invitables'] ); ?>
		<?php if ( $n > 0 ) : ?>
			<p>
				Se van a invitar <strong><?php echo esc_html( (string) $n ); ?> farmacias</strong>, con su CIF/NIF como usuario y el email guardado.
				Ritmo: <?php echo esc_html( $ritmo ); ?> (unos <?php echo esc_html( (string) $minutos( $n ) ); ?> minutos).
				Cada enlace caduca a los 7 días. Todavía no se ha enviado nada.
			</p>
			<details>
				<summary>Ver las <?php echo esc_html( (string) $n ); ?> farmacias</summary>
				<table class="widefat striped" style="max-width:60em">
					<thead><tr><th>Farmacia</th><th>Usuario</th><th>Email</th></tr></thead>
					<tbody>
						<?php foreach ( $revision['invitables'] as $farmacia ) : ?>
							<tr>
								<td><?php echo esc_html( $farmacia->get_nombre() ); ?></td>
								<td><?php echo esc_html( $farmacia->get_cif() ); ?></td>
								<td><?php echo esc_html( (string) $farmacia->get_email() ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php else : ?>
			<p>No hay ninguna farmacia sin cuenta que se pueda invitar.</p>
		<?php endif; ?>

		<?php if ( $revision['no_invitables'] ) : ?>
			<h3>No se van a invitar (<?php echo esc_html( (string) count( $revision['no_invitables'] ) ); ?>)</h3>
			<table class="widefat striped" style="max-width:60em">
				<thead><tr><th>Farmacia</th><th>Motivo</th></tr></thead>
				<tbody>
					<?php foreach ( $revision['no_invitables'] as $fila ) : ?>
						<tr>
							<td><?php echo esc_html( $fila['farmacia']->get_nombre() . ' (' . $fila['farmacia']->get_cif() . ')' ); ?></td>
							<td><?php echo esc_html( $fila['motivo'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<div style="margin-top:1em">
			<?php if ( $n > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
					<?php wp_nonce_field( 'mdf_ca_invitacion_masiva_iniciar' ); ?>
					<input type="hidden" name="action" value="mdf_ca_invitacion_masiva_iniciar" />
					<input type="hidden" name="huella" value="<?php echo esc_attr( $revision['huella'] ); ?>" />
					<?php submit_button( sprintf( 'Preparar el envío de %d invitaciones', $n ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<a class="button" href="<?php echo esc_url( $url ); ?>">Volver</a>
		</div>

	<?php else : ?>
		<p>
			Envía la invitación a todas las farmacias que aún no tienen cuenta, con su CIF/NIF como usuario y el email importado.
			Antes de enviar nada verás la lista y las que no se pueden invitar.
		</p>
		<p>
			<?php if ( $sin_cuenta > 0 ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( add_query_arg( 'masiva', 'revisar', $url ) ); ?>">Revisar el envío (<?php echo esc_html( (string) $sin_cuenta ); ?> farmacias sin cuenta)</a>
			<?php else : ?>
				<em>No hay farmacias sin cuenta.</em>
			<?php endif; ?>
		</p>
	<?php endif; ?>
</div>
