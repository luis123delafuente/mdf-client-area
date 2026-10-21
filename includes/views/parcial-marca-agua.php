<?php
/**
 * Marca de agua de un documento (#321): estado y boton para quitarla o
 * ponerla, en Documentos MDF y en Pendientes de publicar. Solo marcado: la
 * accion es Admin_Documentos::gestionar_cambiar_marca() (capacidad y nonce
 * por documento) y la logica, Documento_Configuracion_Service.
 *
 * @var \MdfClientArea\Documento $documento
 * @var string                   $volver    'documentos' o 'pendientes'.
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$sin_marca = $documento->is_sin_marca_de_agua();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mdf-ca-marca-agua" style="display:inline">
	<?php wp_nonce_field( Admin_Documentos::ACCION_MARCA . '_' . $documento->get_id() ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( Admin_Documentos::ACCION_MARCA ); ?>" />
	<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
	<input type="hidden" name="sin_marca" value="<?php echo $sin_marca ? '0' : '1'; ?>" />
	<input type="hidden" name="volver" value="<?php echo esc_attr( $volver ); ?>" />
	<?php if ( $sin_marca ) : ?>
		<strong>Sin marca</strong>
	<?php else : ?>
		<span class="description">Con marca</span>
	<?php endif; ?>
	<button type="submit" class="button-link"><?php echo $sin_marca ? 'Poner marca' : 'Quitar marca'; ?></button>
</form>
