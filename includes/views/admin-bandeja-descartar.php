<?php
/**
 * Bandeja de facturas › Descartar (#335). Solo marcado: la logica vive en
 * Admin_Bandeja_Facturas::gestionar_descartar().
 *
 * @var array<string, mixed>|null $fila
 * @var string                    $accion
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$url_bandeja = Admin_Bandeja_Facturas::url();
?>
<div class="wrap">
	<h1>Descartar factura</h1>

	<?php if ( ! $fila ) : ?>
		<div class="notice notice-error inline"><p>La factura ya no está en la bandeja.</p></div>
		<p><a class="button" href="<?php echo esc_url( $url_bandeja ); ?>">Volver a la bandeja</a></p>
	<?php else : ?>
		<div class="notice notice-warning inline">
			<p><strong><?php echo esc_html( sprintf( 'Se descartará «%s». Se borran el fichero y su registro en la bandeja. No se puede deshacer.', $fila['nombre_original'] ) ); ?></strong></p>
			<p>Si más adelante hace falta, se puede volver a subir desde «Subir facturas».</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $accion . '_' . $fila['id'] ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $accion ); ?>" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $fila['id'] ); ?>" />
			<p>
				<button type="submit" class="button button-primary">Descartar definitivamente</button>
				<a class="button" href="<?php echo esc_url( $url_bandeja ); ?>">Cancelar</a>
			</p>
		</form>
	<?php endif; ?>
</div>
