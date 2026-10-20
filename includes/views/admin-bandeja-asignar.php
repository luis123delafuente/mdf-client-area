<?php
/**
 * Bandeja de facturas › Asignar a farmacia (#335). Solo marcado: la logica
 * vive en Admin_Bandeja_Facturas::gestionar_asignar() y
 * Bandeja_Facturas_Service::asignar(). Lo leido del PDF se muestra y no se
 * edita; solo se piden los datos que faltan.
 *
 * @var array{tipo: string, mensaje: string}|null $aviso
 * @var array<string, mixed>|null                 $fila
 * @var \MdfClientArea\Farmacia[]                 $farmacias
 * @var \MdfClientArea\Farmacia|null              $farmacia_elegida
 * @var bool                                      $cif_distinto
 * @var array{emisor: string, serie: string, numero: string, fecha: string, es_abono: bool, sin_marca: bool} $previo
 *      Lo escrito en el intento anterior, si el servidor lo devolvio con un error.
 * @var string                                    $accion
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$url_bandeja = Admin_Bandeja_Facturas::url();
?>
<div class="wrap">
	<h1>Asignar factura a una farmacia</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( $aviso['tipo'] ); ?>"><p><?php echo esc_html( $aviso['mensaje'] ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $fila ) : ?>
		<div class="notice notice-error inline"><p>La factura ya no está en la bandeja (puede que otra persona la haya asignado o descartado).</p></div>
		<p><a class="button" href="<?php echo esc_url( $url_bandeja ); ?>">Volver a la bandeja</a></p>
	<?php else : ?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">Fichero</th>
					<td>
						<?php echo esc_html( $fila['nombre_original'] ); ?>
						<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( Admin_Bandeja_Facturas::url_ver( (int) $fila['id'] ) ); ?>">Ver</a>
					</td>
				</tr>
				<tr>
					<th scope="row">Motivo</th>
					<td><?php echo esc_html( Admin_Bandeja_Facturas::MOTIVOS[ $fila['motivo'] ] ?? $fila['motivo'] ); ?></td>
				</tr>
				<tr>
					<th scope="row">Datos leídos</th>
					<td><?php echo esc_html( Admin_Bandeja_Facturas::datos_leidos( $fila ) ); ?></td>
				</tr>
			</tbody>
		</table>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $accion . '_' . $fila['id'] ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $accion ); ?>" />
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $fila['id'] ); ?>" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-bandeja-farmacia">Farmacia</label></th>
						<td>
							<?php
							$selector_id          = 'mdf-ca-bandeja-farmacia';
							$selector_obligatorio = true;
							require MDF_CA_PLUGIN_DIR . 'includes/views/parcial-selector-farmacia.php';
							?>
							<?php if ( null !== $fila['cif_leido'] ) : ?>
								<p class="description"><?php echo esc_html( 'CIF leído en la factura: ' . $fila['cif_leido'] . '. Si no es el de la farmacia que eliges, se te pedirá que lo confirmes.' ); ?></p>
							<?php else : ?>
								<p class="description">La factura no trae un CIF de cliente legible: comprueba en «Ver» que es de la farmacia que eliges.</p>
							<?php endif; ?>
						</td>
					</tr>

					<?php if ( null === $fila['emisor_cif'] ) : ?>
						<tr>
							<th scope="row"><label for="mdf-ca-bandeja-emisor">Sociedad que factura</label></th>
							<td>
								<select id="mdf-ca-bandeja-emisor" name="emisor" required>
									<option value="">-- Selecciona una sociedad --</option>
									<?php foreach ( Factura_Emisores::get_opciones() as $cif_emisor => $nombre_emisor ) : ?>
										<option value="<?php echo esc_attr( $cif_emisor ); ?>" <?php selected( $previo['emisor'], $cif_emisor ); ?>><?php echo esc_html( $nombre_emisor . ' (' . $cif_emisor . ')' ); ?></option>
									<?php endforeach; ?>
								</select>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( null === $fila['serie'] || null === $fila['numero'] ) : ?>
						<tr>
							<th scope="row"><label for="mdf-ca-bandeja-serie">Serie y número</label></th>
							<td>
								<input type="text" id="mdf-ca-bandeja-serie" name="serie" class="small-text" maxlength="3" pattern="[A-Za-z]{1,3}" placeholder="A" value="<?php echo esc_attr( (string) ( $fila['serie'] ?? $previo['serie'] ) ); ?>" <?php echo null !== $fila['serie'] ? 'readonly' : 'required'; ?> aria-label="Serie" />
								<input type="text" id="mdf-ca-bandeja-numero" name="numero" class="regular-text" maxlength="20" pattern="[0-9]{1,20}" inputmode="numeric" placeholder="123" value="<?php echo esc_attr( (string) ( $fila['numero'] ?? $previo['numero'] ) ); ?>" <?php echo null !== $fila['numero'] ? 'readonly' : 'required'; ?> aria-label="Número" />
								<p class="description">Como aparecen en la factura: serie (A en Mediformplus, SF en Formación) y número, solo cifras.</p>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( null === $fila['fecha_documento'] ) : ?>
						<tr>
							<th scope="row"><label for="mdf-ca-bandeja-fecha">Fecha de la factura</label></th>
							<td>
								<input type="date" id="mdf-ca-bandeja-fecha" name="fecha" value="<?php echo esc_attr( $previo['fecha'] ); ?>" required />
								<p class="description">No se ha podido leer: escríbela como aparece en la factura (con ella la farmacia la ve agrupada por mes).</p>
							</td>
						</tr>
					<?php endif; ?>

					<?php if ( null === $fila['es_abono'] ) : ?>
						<tr>
							<th scope="row">Abono</th>
							<td><label><input type="checkbox" name="es_abono" value="1" <?php checked( $previo['es_abono'] ); ?> /> Es un abono (importe negativo)</label></td>
						</tr>
					<?php endif; ?>

					<tr>
						<th scope="row">Marca de agua</th>
						<td><label><input type="checkbox" name="sin_marca" value="1" <?php checked( $previo['sin_marca'] ); ?> /> Mostrar sin marca de agua</label></td>
					</tr>

					<?php if ( $cif_distinto ) : ?>
						<tr>
							<th scope="row">Confirmación</th>
							<td>
								<div class="notice notice-warning inline">
									<p><?php echo esc_html( sprintf( 'El CIF leído en la factura (%s) no es el de %s (%s).', $fila['cif_leido'], $farmacia_elegida->get_nombre(), $farmacia_elegida->get_cif() ) ); ?></p>
								</div>
								<label><input type="checkbox" name="confirmar_cif" value="1" required /> Confirmo que esta factura es de <?php echo esc_html( $farmacia_elegida->get_nombre() ); ?> aunque el CIF no coincida</label>
							</td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>

			<p>
				<button type="submit" class="button button-primary">Asignar (queda pendiente de publicar)</button>
				<a class="button" href="<?php echo esc_url( $url_bandeja ); ?>">Cancelar</a>
			</p>
		</form>
	<?php endif; ?>
</div>
