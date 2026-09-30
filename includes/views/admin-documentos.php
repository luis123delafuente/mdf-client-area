<?php
/**
 * Vista de la pantalla de backoffice "Documentos MDF". Solo marcado: toda
 * la logica de negocio (validacion, guardado de fichero, persistencia)
 * vive en Admin_Documentos y Documento_Service, mismo criterio que
 * admin-planes.php.
 *
 * @var \MdfClientArea\Farmacia[]        $farmacias
 * @var array<int, \MdfClientArea\Farmacia> $farmacias_por_id
 * @var \MdfClientArea\Documento[]       $documentos
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Documentos MDF</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo 'error' === $aviso['tipo'] ? 'error' : 'success'; ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<h2>Subir documento</h2>

	<?php if ( ! $farmacias ) : ?>
		<p>
			Todavia no hay ninguna farmacia dada de alta. Da de alta una farmacia
			antes de poder subirle un documento.
		</p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'mdf_ca_subir_documento' ); ?>
			<input type="hidden" name="action" value="mdf_ca_subir_documento" />

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-documento-farmacia">Farmacia</label></th>
						<td>
							<div class="mdf-ca-selector-farmacia" data-mdf-ca-selector-farmacia>
								<p>
									<input
										type="search"
										id="mdf-ca-documento-farmacia-buscar"
										class="regular-text"
										placeholder="Buscar por nombre o CIF/NIF"
										autocomplete="off"
										data-mdf-ca-buscar
									/>
									<select data-mdf-ca-tipo-entidad aria-label="Filtrar por tipo de entidad">
										<option value="">Todos los tipos</option>
										<?php foreach ( Farmacia::TIPOS_ENTIDAD as $clave => $etiqueta ) : ?>
											<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
										<?php endforeach; ?>
									</select>
								</p>
								<select id="mdf-ca-documento-farmacia" name="farmacia_id" required data-mdf-ca-farmacia>
									<option value="">-- Selecciona una farmacia --</option>
									<?php foreach ( $farmacias as $farmacia ) : ?>
										<option
											value="<?php echo esc_attr( (string) $farmacia->get_id() ); ?>"
											data-tipo="<?php echo esc_attr( $farmacia->get_tipo_entidad() ); ?>"
											data-busqueda="<?php echo esc_attr( $farmacia->get_nombre() . ' ' . $farmacia->get_cif() ); ?>"
										>
											<?php echo esc_html( $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ') - ' . $farmacia->get_tipo_entidad_etiqueta() ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<p class="description" data-mdf-ca-contador aria-live="polite"></p>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-documento-nombre">Nombre del documento</label></th>
						<td>
							<input
								type="text"
								id="mdf-ca-documento-nombre"
								name="nombre"
								class="regular-text"
								maxlength="255"
								required
							/>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-documento-tipo">Tipo</label></th>
						<td>
							<select id="mdf-ca-documento-tipo" name="tipo_documento" required>
								<option value="">-- Selecciona un tipo --</option>
								<?php foreach ( Documento_Tipos::get_opciones() as $clave => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-documento-fichero">Fichero</label></th>
						<td>
							<input
								type="file"
								id="mdf-ca-documento-fichero"
								name="documento"
								accept=".pdf,.jpg,.jpeg,.png,.webp,.xlsx"
								required
							/>
							<p class="description">PDF, JPG, PNG, WEBP o Excel (.xlsx, sin macros). Tamano maximo: 20 MB. Los Excel no se muestran en el visor: el cliente los descarga.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Descarga</th>
						<td>
							<label for="mdf-ca-documento-descargable">
								<input type="checkbox" id="mdf-ca-documento-descargable" name="descargable" value="1" />
								Permitir que la farmacia descargue el fichero
							</label>
							<p class="description">
								Solo aplica a Excel. Sin marcar (por defecto), el cliente solo puede
								verlo en el visor, con marca de agua, sin boton de descarga.
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( 'Subir documento' ); ?>
		</form>
	<?php endif; ?>

	<h2>Documentos existentes</h2>

	<?php if ( ! $documentos ) : ?>
		<p>Todavia no hay ningun documento subido.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th>Nombre</th>
					<th>Farmacia</th>
					<th>Tipo</th>
					<th>Tamano</th>
					<th>Descarga</th>
					<th>Subido</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $documentos as $documento ) : ?>
					<?php $farmacia = $farmacias_por_id[ $documento->get_farmacia_id() ] ?? null; ?>
					<tr>
						<td><?php echo esc_html( $documento->get_nombre() ); ?></td>
						<td>
							<?php if ( $farmacia ) : ?>
								<?php echo esc_html( $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')' ); ?>
							<?php else : ?>
								<em>Farmacia ya no existe (ID <?php echo esc_html( (string) $documento->get_farmacia_id() ); ?>)</em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( Documento_Tipos::get_etiqueta( $documento->get_tipo_documento() ) ); ?></td>
						<td>
							<?php echo $documento->get_tamano_bytes() ? esc_html( size_format( $documento->get_tamano_bytes() ) ) : '-'; ?>
						</td>
						<td><?php echo Documento_Service::MIME_XLSX === $documento->get_tipo_mime() ? ( $documento->is_descargable() ? 'Si' : 'No' ) : '-'; ?></td>
						<td><?php echo esc_html( $documento->get_fecha_subida() ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
