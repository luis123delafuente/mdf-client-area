<?php
/**
 * Vista de "Documentos MDF > Avisos por email". Solo marcado: la logica
 * vive en Admin_Avisos_Documentos y Aviso_Documentos_Service.
 *
 * @var bool                                       $activo
 * @var bool                                       $encendido       Valor del interruptor de esta pantalla.
 * @var bool                                       $forzado_apagado
 * @var int|false                                  $proxima
 * @var array{documentos: int, farmacias_activas: int, farmacias_sin_cuenta: int} $pendientes
 * @var array{tipo: string, mensaje: string}|null  $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Avisos por email de documentos nuevos</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning' ), true ) ? $aviso['tipo'] : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		Cada dia, a partir de las 09:00, cada farmacia con cuenta activa y documentos nuevos recibe un unico email con el numero
		de documentos y su desglose por tipo, y un enlace a Documentacion. Nunca incluye nombres de documento, importes, CIF ni
		enlaces a los ficheros. Las farmacias sin cuenta activa no reciben nada: sus documentos quedan pendientes para el primer
		aviso tras activarla.
	</p>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">Estado</th>
				<td>
					<strong><?php echo $activo ? 'Activados' : 'Desactivados'; ?></strong>
					<?php if ( $forzado_apagado ) : ?>
						<p class="description">Apagados por la constante <code>MDF_CA_AVISOS_DESACTIVADOS</code> de wp-config.php, aunque se activen aqui.</p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5em">
						<?php wp_nonce_field( 'mdf_ca_avisos_interruptor' ); ?>
						<input type="hidden" name="action" value="mdf_ca_avisos_interruptor" />
						<input type="hidden" name="activar" value="<?php echo $encendido ? '0' : '1'; ?>" />
						<?php submit_button( $encendido ? 'Desactivar avisos' : 'Activar avisos', 'secondary', 'submit', false ); ?>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row">Pendientes de aviso</th>
				<td>
					<?php echo esc_html( (string) $pendientes['documentos'] ); ?> documentos:
					<?php echo esc_html( (string) $pendientes['farmacias_activas'] ); ?> farmacias con cuenta activa,
					<?php echo esc_html( (string) $pendientes['farmacias_sin_cuenta'] ); ?> sin cuenta activa.
				</td>
			</tr>
			<tr>
				<th scope="row">Proxima ejecucion</th>
				<td>
					<?php echo $proxima ? esc_html( wp_date( 'd/m/Y H:i', $proxima ) ) : 'Sin programar (se programa en la siguiente visita al sitio)'; ?>
					<p class="description">WP-Cron solo se dispara con visitas al sitio: con poco trafico, el envio puede salir mas tarde de las 09:00.</p>
				</td>
			</tr>
		</tbody>
	</table>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mdf_ca_avisos_enviar_ahora' ); ?>
		<input type="hidden" name="action" value="mdf_ca_avisos_enviar_ahora" />
		<?php submit_button( 'Enviar ahora', 'primary', 'submit', true, $activo ? array() : array( 'disabled' => 'disabled' ) ); ?>
		<p class="description">Respeta el interruptor y el limite de un aviso al dia por farmacia.</p>
	</form>
</div>
