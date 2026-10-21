<?php
/**
 * Aviso de resultado de las pantallas del Herramientas MDF, con detalles
 * opcionales (p. ej. "no la vera nadie"). Solo marcado.
 *
 * @var array{tipo: string, mensaje: string, detalles: string[]}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! $aviso ) {
	return;
}

$mdf_ca_clase_aviso = in_array( $aviso['tipo'], array( 'error', 'warning', 'success' ), true ) ? $aviso['tipo'] : 'info';
?>
<div class="notice notice-<?php echo esc_attr( $mdf_ca_clase_aviso ); ?> is-dismissible">
	<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
	<?php if ( ! empty( $aviso['detalles'] ) ) : ?>
		<ul class="ul-disc">
			<?php foreach ( $aviso['detalles'] as $mdf_ca_detalle ) : ?>
				<li><?php echo esc_html( $mdf_ca_detalle ); ?></li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
