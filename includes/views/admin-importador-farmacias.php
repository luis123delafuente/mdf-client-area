<?php
/**
 * Vista de "Farmacias MDF > Importar CSV". Solo marcado: el parseo, la
 * simulacion y la aplicacion viven en Admin_Importador_Farmacias,
 * Farmacia_Csv_Parser y Farmacia_Import_Service. Todo dato que sale del
 * CSV se escapa al pintarlo.
 *
 * @var array{tipo: string, mensaje: string}|null $aviso
 * @var string                                     $lote_id
 * @var array{filas: array, fichero: string}|null  $lote
 * @var array<int, array<string, mixed>>|null      $resultados
 * @var string|null                                $huella
 * @var int                                        $max_filas
 * @var string                                     $max_tamano
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$etiquetas_accion = array(
	Farmacia_Import_Service::ACCION_CREAR       => 'Crear',
	Farmacia_Import_Service::ACCION_ACTUALIZAR  => 'Actualizar',
	Farmacia_Import_Service::ACCION_SIN_CAMBIOS => 'Sin cambios',
	Farmacia_Import_Service::ACCION_RECHAZADA   => 'Rechazada',
);
?>
<div class="wrap">
	<h1>Importar farmacias desde CSV</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning' ), true ) ? $aviso['tipo'] : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( null === $resultados ) : ?>

		<p class="description">
			Primera fila de cabecera con las columnas <code>nombre</code>, <code>cif</code> (o <code>nif</code>) y <code>plan</code> (slug del plan).
			Separador <code>;</code> o <code>,</code>. Maximo <?php echo esc_html( $max_tamano ); ?> y <?php echo esc_html( (string) $max_filas ); ?> filas.
			Subir el fichero solo simula: no se guarda nada hasta confirmar.
			La importacion no crea usuarios ni envia invitaciones.
		</p>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mdf_ca_simular_importacion' ); ?>
			<input type="hidden" name="action" value="mdf_ca_simular_importacion" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-import-csv">Fichero CSV</label></th>
						<td><input type="file" id="mdf-ca-import-csv" name="csv" accept=".csv,text/csv" required /></td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( 'Simular importacion' ); ?>
		</form>

	<?php else : ?>

		<?php
		$conteo = array_count_values( array_column( $resultados, 'accion' ) );
		$hay_cambios = ! empty( $conteo[ Farmacia_Import_Service::ACCION_CREAR ] ) || ! empty( $conteo[ Farmacia_Import_Service::ACCION_ACTUALIZAR ] );
		?>

		<h2>Simulacion: <?php echo esc_html( $lote['fichero'] ); ?></h2>

		<p>
			<?php foreach ( $etiquetas_accion as $accion => $etiqueta ) : ?>
				<strong><?php echo esc_html( $etiqueta ); ?>:</strong> <?php echo esc_html( (string) ( $conteo[ $accion ] ?? 0 ) ); ?> &nbsp;
			<?php endforeach; ?>
		</p>
		<p class="description">Todavia no se ha guardado nada. Las filas rechazadas se ignoran al confirmar.</p>

		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:5em">Linea</th>
					<th style="width:8em">Resultado</th>
					<th>CIF / NIF</th>
					<th>Nombre</th>
					<th>Plan</th>
					<th>Detalle</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $resultados as $fila ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $fila['linea'] ); ?></td>
						<td><strong><?php echo esc_html( $etiquetas_accion[ $fila['accion'] ] ); ?></strong></td>
						<td><?php echo esc_html( $fila['cif'] ); ?></td>
						<td><?php echo esc_html( $fila['nombre'] ); ?></td>
						<td><?php echo esc_html( $fila['plan_nombre'] ?? $fila['plan'] ); ?></td>
						<td>
							<?php if ( Farmacia_Import_Service::ACCION_RECHAZADA === $fila['accion'] ) : ?>
								<?php echo esc_html( implode( ' ', $fila['motivos'] ) ); ?>
							<?php elseif ( Farmacia_Import_Service::ACCION_ACTUALIZAR === $fila['accion'] ) : ?>
								<?php if ( $fila['nombre_anterior'] !== $fila['nombre'] ) : ?>
									Nombre antes: <?php echo esc_html( (string) $fila['nombre_anterior'] ); ?><br />
								<?php endif; ?>
								<?php if ( $fila['plan_anterior'] !== $fila['plan_nombre'] ) : ?>
									Plan antes: <?php echo esc_html( $fila['plan_anterior'] ?? 'sin plan' ); ?>
								<?php endif; ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div style="margin-top:1em">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'mdf_ca_confirmar_importacion' ); ?>
				<input type="hidden" name="action" value="mdf_ca_confirmar_importacion" />
				<input type="hidden" name="importacion" value="<?php echo esc_attr( $lote_id ); ?>" />
				<input type="hidden" name="huella" value="<?php echo esc_attr( (string) $huella ); ?>" />
				<?php submit_button( 'Confirmar importacion', 'primary', 'submit', false, $hay_cambios ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
				<?php wp_nonce_field( 'mdf_ca_cancelar_importacion' ); ?>
				<input type="hidden" name="action" value="mdf_ca_cancelar_importacion" />
				<input type="hidden" name="importacion" value="<?php echo esc_attr( $lote_id ); ?>" />
				<?php submit_button( 'Cancelar', 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php if ( ! $hay_cambios ) : ?>
			<p class="description">No hay nada que crear ni actualizar.</p>
		<?php endif; ?>

	<?php endif; ?>
</div>
