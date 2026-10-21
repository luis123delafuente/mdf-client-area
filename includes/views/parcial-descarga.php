<?php
/**
 * Descarga de un documento (v0.36.0): estado y boton para activarla o
 * desactivarla, en Documentos MDF y en Pendientes de publicar, junto a la
 * marca de agua. Solo marcado: la accion es Admin_Documentos::
 * gestionar_cambiar_descarga() (capacidad y nonce por documento) y la logica,
 * Documento_Configuracion_Service.
 *
 * @var \MdfClientArea\Documento $documento
 * @var string                   $volver    'documentos' o 'pendientes'.
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$descargable = $documento->is_descargable();
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="mdf-ca-descarga" style="display:inline">
	<?php wp_nonce_field( Admin_Documentos::ACCION_DESCARGA . '_' . $documento->get_id() ); ?>
	<input type="hidden" name="action" value="<?php echo esc_attr( Admin_Documentos::ACCION_DESCARGA ); ?>" />
	<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
	<input type="hidden" name="descargable" value="<?php echo $descargable ? '0' : '1'; ?>" />
	<input type="hidden" name="volver" value="<?php echo esc_attr( $volver ); ?>" />
	<?php if ( $descargable ) : ?>
		<span class="description">Descargable</span>
	<?php else : ?>
		<strong>Solo visor</strong>
	<?php endif; ?>
	<button type="submit" class="button-link"><?php echo $descargable ? 'Quitar descarga' : 'Permitir descarga'; ?></button>
</form>
