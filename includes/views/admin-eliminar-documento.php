<?php
/**
 * Confirmacion de "Eliminar" un documento no publicado (v0.28.0). Solo marcado:
 * la logica vive en Admin_Documentos::gestionar_eliminar() y
 * Documento_Eliminacion_Service.
 *
 * @var \MdfClientArea\Documento|null $documento
 * @var string|null                   $motivo     Por que no se puede eliminar (null si se puede).
 * @var \MdfClientArea\Farmacia|null  $farmacia
 * @var string                        $que        "la factura" o "el documento".
 * @var string                        $accion
 * @var string                        $volver
 * @var string                        $url_volver
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Eliminar documento</h1>

	<?php if ( null !== $motivo ) : ?>
		<div class="notice notice-error inline">
			<p><?php echo esc_html( $motivo ); ?></p>
		</div>
		<p><a class="button" href="<?php echo esc_url( $url_volver ); ?>">Volver</a></p>
	<?php else : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							'Se eliminará %s «%s» de la farmacia «%s». No se puede deshacer.',
							$que,
							$documento->get_nombre(),
							$farmacia ? $farmacia->get_nombre() : sprintf( 'ID %d, ya no existe', $documento->get_farmacia_id() )
						)
					);
					?>
				</strong>
			</p>
			<p>Se borran el documento y su fichero del servidor. En su historial queda que se eliminó, quién y cuándo. Si era una factura, después se puede volver a subir.</p>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $accion . '_' . $documento->get_id() ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $accion ); ?>" />
			<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
			<input type="hidden" name="volver" value="<?php echo esc_attr( $volver ); ?>" />
			<p>
				<button type="submit" class="button button-primary">Eliminar definitivamente</button>
				<a class="button" href="<?php echo esc_url( $url_volver ); ?>">Cancelar</a>
			</p>
		</form>
	<?php endif; ?>
</div>
