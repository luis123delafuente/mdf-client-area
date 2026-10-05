<?php
/**
 * Confirmacion de "Publicar todos los pendientes" (#316). Solo marcado: los
 * recuentos vienen del transient que preparo Admin_Publicacion_Documentos y
 * no se usan para publicar (al aplicar se recalculan en servidor).
 *
 * @var string                                $bloque_id
 * @var array<string, mixed>                  $bloque
 * @var array<string, string>                 $tipos
 * @var string                                $nonce_preparar
 * @var string                                $nonce_aplicar
 * @var string                                $nonce_cancelar
 * @var string                                $url_volver
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$filtros    = $bloque['filtros'];
$documentos = (int) $bloque['documentos'];
$farmacias  = (int) $bloque['farmacias'];
$excluidos  = (int) $bloque['excluidos'];

$plural = static function ( int $n, string $singular, string $plural ): string {
	return sprintf( '%d %s', $n, 1 === $n ? $singular : $plural );
};
?>
<div class="wrap">
	<h1>Publicar todos los pendientes</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning' ), true ) ? $aviso['tipo'] : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( 0 === $documentos ) : ?>
		<div class="notice notice-info inline">
			<p>No hay ningún documento pendiente de publicar con estos filtros.</p>
		</div>
	<?php else : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong>
					<?php
					echo esc_html(
						sprintf(
							'Esto hará visibles %s a %s.',
							$plural( $documentos, 'documento', 'documentos' ),
							$plural( $farmacias, 'farmacia', 'farmacias' )
						)
					);
					?>
				</strong>
				Revisa antes una muestra con «Ver» en la <a href="<?php echo esc_url( $url_volver ); ?>">lista de pendientes</a>.
			</p>
		</div>
	<?php endif; ?>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">Documentos que se publicarán</th>
				<td><strong><?php echo esc_html( (string) $documentos ); ?></strong></td>
			</tr>
			<tr>
				<th scope="row">Farmacias afectadas</th>
				<td><?php echo esc_html( (string) $farmacias ); ?> <span class="description">(incluidas las que aún no han activado su cuenta: lo verán al activarla)</span></td>
			</tr>
			<?php if ( $documentos > 0 ) : ?>
				<tr>
					<th scope="row">Recibidos entre</th>
					<td><?php echo esc_html( sprintf( '%s y %s', (string) $bloque['recibido_desde'], (string) $bloque['recibido_hasta'] ) ); ?></td>
				</tr>
			<?php endif; ?>
			<?php if ( $excluidos > 0 ) : ?>
				<tr>
					<th scope="row">Excluidos</th>
					<td><?php echo esc_html( sprintf( '%s cuya farmacia ya no existe: no se publicarán y seguirán pendientes.', $plural( $excluidos, 'documento', 'documentos' ) ) ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>

	<h2>Acotar</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( $nonce_preparar ); ?>
		<input type="hidden" name="action" value="mdf_ca_publicacion_bloque_preparar" />
		<input type="hidden" name="bloque" value="<?php echo esc_attr( $bloque_id ); ?>" />
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="mdf-ca-bloque-tipo">Tipo de documento</label></th>
					<td>
						<select id="mdf-ca-bloque-tipo" name="tipo">
							<option value="">Todos</option>
							<?php foreach ( $tipos as $clave => $etiqueta ) : ?>
								<option value="<?php echo esc_attr( $clave ); ?>" <?php selected( $filtros['tipo'], $clave ); ?>><?php echo esc_html( $etiqueta ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-bloque-desde">Recibidos desde</label></th>
					<td>
						<input type="date" id="mdf-ca-bloque-desde" name="desde" value="<?php echo esc_attr( (string) $filtros['desde'] ); ?>" />
						<label for="mdf-ca-bloque-hasta" style="margin-left:1em">hasta</label>
						<input type="date" id="mdf-ca-bloque-hasta" name="hasta" value="<?php echo esc_attr( (string) $filtros['hasta'] ); ?>" />
						<p class="description">Fecha de recepción (la columna «Recibido» de la lista), ambas incluidas. Vacías: sin límite.</p>
					</td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( 'Recalcular', 'secondary', 'submit', false ); ?>
	</form>

	<h2>Confirmar</h2>
	<p class="description">
		Se publicará exactamente lo contado arriba. Si mientras tanto llega, se publica o se despublica algún documento
		que entre en estos filtros, no se publicará nada y volverás a esta pantalla con los recuentos actualizados.
	</p>
	<div style="display:flex;gap:.5em;align-items:center">
		<?php if ( $documentos > 0 ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( $nonce_aplicar ); ?>
				<input type="hidden" name="action" value="mdf_ca_publicacion_bloque_aplicar" />
				<input type="hidden" name="bloque" value="<?php echo esc_attr( $bloque_id ); ?>" />
				<?php submit_button( sprintf( 'Publicar %s', $plural( $documentos, 'documento', 'documentos' ) ), 'primary', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $nonce_cancelar ); ?>
			<input type="hidden" name="action" value="mdf_ca_publicacion_bloque_cancelar" />
			<input type="hidden" name="bloque" value="<?php echo esc_attr( $bloque_id ); ?>" />
			<?php submit_button( 'Cancelar', 'secondary', 'submit', false ); ?>
		</form>
	</div>
</div>
