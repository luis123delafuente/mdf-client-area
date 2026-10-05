<?php
/**
 * Confirmacion de "Despublicar lote" (#317). Solo marcado: los recuentos
 * vienen del transient que preparo Admin_Publicacion_Documentos y no se usan
 * para despublicar (al aplicar se recalculan en servidor).
 *
 * @var string                                $deshacer_id
 * @var array<string, mixed>                  $deshacer
 * @var object|null                           $lote
 * @var string                                $quien
 * @var string                                $fecha_lote
 * @var string                                $nonce_aplicar
 * @var string                                $nonce_cancelar
 * @var string                                $url_volver
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$documentos      = (int) $deshacer['documentos'];
$farmacias       = (int) $deshacer['farmacias'];
$documentos_lote = (int) $deshacer['documentos_lote'];
$otros           = max( 0, $documentos_lote - $documentos );

$plural = static function ( int $n, string $singular, string $plural ): string {
	return sprintf( '%d %s', $n, 1 === $n ? $singular : $plural );
};
?>
<div class="wrap">
	<h1>Despublicar lote</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning' ), true ) ? $aviso['tipo'] : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 0 === $documentos ) : ?>
		<div class="notice notice-info inline">
			<p>Ningún documento de este lote sigue publicado por él: no hay nada que despublicar.</p>
		</div>
	<?php else : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							'Esto ocultará %s a %s: volverán a «Pendientes de publicar» y sus farmacias dejarán de verlos.',
							$plural( $documentos, 'documento', 'documentos' ),
							$plural( $farmacias, 'farmacia', 'farmacias' )
						)
					);
					?>
				</strong>
			</p>
			<p><strong>Deshacer no retira lo que las farmacias ya hayan visto o descargado.</strong></p>
		</div>
	<?php endif; ?>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">Lote</th>
				<td><?php echo esc_html( $lote ? sprintf( 'Publicado el %s por %s', $fecha_lote, $quien ) : 'El lote ya no existe' ); ?></td>
			</tr>
			<tr>
				<th scope="row">Documentos del lote</th>
				<td>
					<?php
					echo esc_html(
						sprintf(
							'De %s del lote, %s publicados.',
							$plural( $documentos_lote, 'documento', 'documentos' ),
							1 === $documentos ? '1 sigue' : $documentos . ' siguen'
						)
					);
					?>
					<?php if ( $otros > 0 ) : ?>
						<p class="description">
							<?php
							echo esc_html(
								1 === $otros
									? 'El otro cambió de estado por otra vía (despublicado a mano, o despublicado y vuelto a publicar por otro camino): no se tocará.'
									: sprintf( 'Los otros %d cambiaron de estado por otra vía (despublicados a mano, o despublicados y vueltos a publicar por otro camino): no se tocarán.', $otros )
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row">Farmacias afectadas</th>
				<td><?php echo esc_html( (string) $farmacias ); ?></td>
			</tr>
		</tbody>
	</table>

	<p class="description">
		Se despublicará exactamente lo contado arriba. Si mientras tanto alguno de estos documentos cambia de estado, no se
		despublicará nada y volverás a esta pantalla con los recuentos actualizados. Los avisos por email ya enviados no se
		retiran, y un documento que ya se avisó no se volverá a avisar si se publica de nuevo.
	</p>

	<div style="display:flex;gap:.5em;align-items:center">
		<?php if ( $documentos > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( $nonce_aplicar ); ?>
				<input type="hidden" name="action" value="mdf_ca_despublicar_lote_aplicar" />
				<input type="hidden" name="deshacer" value="<?php echo esc_attr( $deshacer_id ); ?>" />
				<?php submit_button( sprintf( 'Despublicar %s', $plural( $documentos, 'documento', 'documentos' ) ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $nonce_cancelar ); ?>
			<input type="hidden" name="action" value="mdf_ca_despublicar_lote_cancelar" />
			<input type="hidden" name="deshacer" value="<?php echo esc_attr( $deshacer_id ); ?>" />
			<?php submit_button( 'Cancelar', 'secondary', 'submit', false ); ?>
		</form>
		<a href="<?php echo esc_url( $url_volver ); ?>">Volver a la lista</a>
	</div>
</div>
