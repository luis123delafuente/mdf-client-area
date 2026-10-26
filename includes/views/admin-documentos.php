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
								<div class="mdf-ca-selector-farmacia__filtros">
									<div class="mdf-ca-selector-farmacia__combo">
										<input
											type="text"
											id="mdf-ca-documento-farmacia"
											class="regular-text"
											placeholder="Haz clic y busca por nombre o CIF/NIF"
											autocomplete="off"
											role="combobox"
											aria-expanded="false"
											aria-controls="mdf-ca-documento-farmacia-lista"
											aria-autocomplete="list"
											data-mdf-ca-buscar
										/>
										<ul id="mdf-ca-documento-farmacia-lista" class="mdf-ca-selector-farmacia__lista" role="listbox" hidden data-mdf-ca-lista></ul>
									</div>
									<select data-mdf-ca-tipo-entidad aria-label="Filtrar por tipo de entidad">
										<option value="">Todos los tipos</option>
										<?php foreach ( Farmacia::TIPOS_ENTIDAD as $clave => $etiqueta ) : ?>
											<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
										<?php endforeach; ?>
									</select>
								</div>
								<input type="hidden" name="farmacia_id" value="" data-mdf-ca-farmacia-id />
								<script type="application/json" data-mdf-ca-farmacias>
									<?php
									echo wp_json_encode(
										array_map(
											static fn( Farmacia $f ): array => array(
												'id'     => $f->get_id(),
												'nombre' => $f->get_nombre(),
												'cif'    => $f->get_cif(),
												'tipo'   => $f->get_tipo_entidad(),
												'tipoEt' => $f->get_tipo_entidad_etiqueta(),
											),
											$farmacias
										),
										JSON_HEX_TAG | JSON_HEX_AMP
									);
									?>
								</script>
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
							<p class="description">Es también el nombre del fichero que descarga la farmacia.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-documento-tipo">Tipo</label></th>
						<td>
							<select id="mdf-ca-documento-tipo" name="tipo_documento" required>
								<option value="">-- Selecciona un tipo --</option>
								<?php foreach ( Documento_Tipos::get_opciones_subida_manual() as $clave => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								Para subir facturas usa <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Subir_Facturas::MENU_SLUG ) ); ?>">Subir facturas</a> (también sirve para una sola);
								para el Modelo 347, usa <a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Modelo_347::MENU_SLUG ) ); ?>">Modelo 347</a>.
							</p>
						</td>
					</tr>
					<tr data-mdf-ca-solo-presupuesto>
						<th scope="row"><label for="mdf-ca-documento-presupuesto-estado">Estado del presupuesto</label></th>
						<td>
							<select id="mdf-ca-documento-presupuesto-estado" name="presupuesto_estado">
								<option value="">-- Selecciona el estado --</option>
								<?php foreach ( Presupuesto_Estado::opciones_mdf() as $clave => $etiqueta ) : ?>
									<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								Solo para presupuestos. «Pendiente»: la farmacia podrá aceptarlo o rechazarlo desde el área de clientes.
								«Aceptado»: ya se aceptó por otra vía; la farmacia lo verá como aceptado y no se envía ningún aviso.
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-documento-fecha">Fecha del documento</label></th>
						<td>
							<input type="date" id="mdf-ca-documento-fecha" name="fecha_documento" min="2000-01-01" />
							<p class="description">Opcional. La fecha del documento, no la de subida.</p>
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
								<?php echo Admin_Zona_Archivos::atributos( 'PDF, JPG, PNG, WEBP o Excel (.xlsx)', Documento_Service::TAMANO_MAXIMO_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapados. ?>
								required
							/>
							<p class="description">PDF, JPG, PNG, WEBP o Excel (.xlsx, sin macros). Tamaño máximo: 20 MB.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Configuración</th>
						<td>
							<fieldset>
								<legend class="screen-reader-text">Configuración</legend>
								<label for="mdf-ca-documento-descargable">
									<input type="checkbox" id="mdf-ca-documento-descargable" name="descargable" value="1" <?php checked( Documento_Service::POR_DEFECTO_DESCARGABLE ); ?> />
									Descargable
								</label><br />
								<label for="mdf-ca-documento-marca-agua">
									<input type="checkbox" id="mdf-ca-documento-marca-agua" name="marca_agua" value="1" <?php checked( ! Documento_Service::POR_DEFECTO_SIN_MARCA ); ?> />
									Marca de agua
								</label>
							</fieldset>
							<p class="description">
								Descargable: la farmacia ve «Descargar» en su listado y en el visor. Sin marcar, solo puede verlo en el visor.
								Marca de agua: el visor pinta el CIF y el nombre de la farmacia; lo descargado va siempre sin ella.
								Las dos se pueden cambiar después desde esta lista o desde «Pendientes de publicar».
							</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( 'Subir documento' ); ?>
		</form>
		<script>
			// Estado del presupuesto (v0.44.0): solo con el tipo presupuesto, y
			// entonces obligatorio. Sin JS la fila se ve siempre y el servidor
			// lo valida igual.
			( function () {
				var tipo = document.getElementById( 'mdf-ca-documento-tipo' );
				var fila = document.querySelector( '[data-mdf-ca-solo-presupuesto]' );
				var estado = document.getElementById( 'mdf-ca-documento-presupuesto-estado' );

				if ( ! tipo || ! fila || ! estado ) {
					return;
				}

				function actualizar() {
					var esPresupuesto = <?php echo wp_json_encode( Presupuesto_Estado::TIPO_DOCUMENTO ); ?> === tipo.value;
					fila.hidden = ! esPresupuesto;
					estado.disabled = ! esPresupuesto;
					estado.required = esPresupuesto;
				}

				tipo.addEventListener( 'change', actualizar );
				actualizar();
			} )();
		</script>
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
					<th>Fecha documento</th>
					<th>Subido</th>
					<th>Modelo 347</th>
					<th>Marca de agua</th>
					<th>Acciones</th>
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
						<td>
							<?php
							$volver = 'documentos';
							require MDF_CA_PLUGIN_DIR . 'includes/views/parcial-descarga.php';
							?>
						</td>
						<td>
							<?php if ( null !== $documento->get_ejercicio() ) : ?>
								<?php echo esc_html( 'Ejercicio ' . $documento->get_ejercicio() ); ?>
							<?php else : ?>
								<?php echo esc_html( Documento::formatear_fecha_corta( $documento->get_fecha_documento() ) ); ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $documento->get_fecha_subida() ); ?></td>
						<td>
							<?php if ( $documento->is_retirado() ) : ?>
								<em>Retirado para corrección</em>
							<?php elseif ( $documento->es_modelo_347_vigente() ) : ?>
								<?php echo esc_html( ( Factura_Emisores::nombre_corto( (string) $documento->get_emisor_cif() ) ?? '' ) . ( $documento->is_publicado() ? ' · Publicado' : ' · Pendiente' ) ); ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'mdf_ca_retirar_modelo_347_' . $documento->get_id() ); ?>
									<input type="hidden" name="action" value="mdf_ca_retirar_modelo_347" />
									<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
									<label><input type="checkbox" name="confirmar" value="1" required /> Confirmo</label>
									<button type="submit" class="button button-small">Retirar para corrección</button>
								</form>
								<span class="description">Después, vuelve a generarlo desde el Excel en «Modelo 347».</span>
							<?php else : ?>
								-
							<?php endif; ?>
						</td>
						<td>
							<?php
							$volver = 'documentos';
							require MDF_CA_PLUGIN_DIR . 'includes/views/parcial-marca-agua.php';
							?>
						</td>
						<td>
							<a href="<?php echo esc_url( Admin_Documentos_Publicados::url_historial( $documento->get_id() ) ); ?>">Historial</a>
							<?php if ( Admin_Documentos::se_puede_eliminar( $documento ) ) : ?>
								<a class="button button-small" href="<?php echo esc_url( Admin_Documentos::url_eliminar( $documento->get_id(), 'documentos' ) ); ?>">Eliminar</a>
							<?php elseif ( $documento->is_publicado() && 'modelo_347' !== $documento->get_tipo_documento() ) : ?>
								<span class="description">Publicado</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
