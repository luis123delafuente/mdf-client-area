<?php
/**
 * Confirmacion de "Eliminar" una farmacia (v0.46.0). Solo marcado: la logica
 * vive en Admin_Farmacias::gestionar_eliminar() y Farmacia_Eliminacion_Service.
 *
 * @var \MdfClientArea\Farmacia|null                  $farmacia
 * @var array{corto: string, largo: string}|null     $bloqueo    Por que no se puede eliminar (null si se puede).
 * @var string                                        $accion
 * @var string                                        $url_volver
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Eliminar farmacia</h1>

	<?php if ( null !== $bloqueo ) : ?>
		<div class="notice notice-error inline">
			<p><?php echo esc_html( $bloqueo['largo'] ); ?></p>
		</div>
		<p><a class="button" href="<?php echo esc_url( $url_volver ); ?>">Volver</a></p>
	<?php else : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong>
					<?php echo esc_html( sprintf( 'Se eliminará la farmacia «%s» (%s). No se puede deshacer.', $farmacia->get_nombre(), $farmacia->get_cif() ) ); ?>
				</strong>
			</p>
			<p>No tiene documentos ni cuenta de acceso. También se quita de las herramientas asignadas a farmacias concretas. Si se vuelve a necesitar, se puede dar de alta de nuevo (a mano o con el importador).</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $accion . '_' . $farmacia->get_id() ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $accion ); ?>" />
			<input type="hidden" name="farmacia_id" value="<?php echo esc_attr( (string) $farmacia->get_id() ); ?>" />
			<p>
				<button type="submit" class="button button-primary">Eliminar definitivamente</button>
				<a class="button" href="<?php echo esc_url( $url_volver ); ?>">Cancelar</a>
			</p>
		</form>
	<?php endif; ?>
</div>
