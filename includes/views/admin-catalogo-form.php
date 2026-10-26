<?php
/**
 * Herramientas MDF > Herramientas: formulario de alta y edicion. Solo marcado;
 * la validacion vive en Catalogo_Service (el servidor fuerza la
 * configuracion de Word, PowerPoint y .xlsm aunque el navegador mande otra
 * cosa). Sin JS funciona igual: todo se ve, las etiquetas son casillas y las
 * farmacias elegidas se quitan desmarcandolas (para anadir hace falta JS).
 * admin-catalogo.js solo oculta lo que no aplica y avisa.
 *
 * @var \MdfClientArea\Catalogo_Item|null  $item
 * @var array<string, mixed>               $valores
 * @var \MdfClientArea\Catalogo_Termino[]  $categorias
 * @var \MdfClientArea\Catalogo_Termino[]  $etiquetas
 * @var \MdfClientArea\Plan[]              $planes
 * @var \MdfClientArea\Farmacia[]          $farmacias
 * @var bool                               $permitir_xlsm
 * @var string                             $selector_id
 * @var array{tipo: string, mensaje: string, detalles: string[]}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mdf_ca_archivo_actual = $item && $item->es_archivo() && $item->recurso_completo() ? $item : null;
$mdf_ca_es_archivo     = Catalogo_Item::RECURSO_ARCHIVO === $valores['recurso'];
$mdf_ca_por_id         = array();

foreach ( $farmacias as $mdf_ca_f ) {
	$mdf_ca_por_id[ $mdf_ca_f->get_id() ] = $mdf_ca_f;
}

// Para el parcial del selector en modo multiple.
$selector_multiple   = true;
$farmacia_elegida    = null;
$farmacias_elegidas  = array_values( array_filter( array_map( static fn( $id ) => $mdf_ca_por_id[ (int) $id ] ?? null, $valores['farmacia_ids'] ) ) );
?>
<div class="wrap mdf-ca-herramienta">
	<h1><?php echo $item ? 'Editar herramienta' : 'Añadir herramienta'; ?></h1>

	<?php require __DIR__ . '/parcial-aviso-catalogo.php'; ?>

	<?php if ( ! $categorias ) : ?>
		<div class="notice notice-error inline"><p>
			No hay ninguna categoría. Crea al menos una en
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::SLUG_CATEGORIAS ) ); ?>">Categorías</a>.
		</p></div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data"
		data-mdf-ca-herramienta-form
		data-formato-actual="<?php echo esc_attr( $mdf_ca_archivo_actual ? (string) $mdf_ca_archivo_actual->get_formato() : '' ); ?>">
		<?php wp_nonce_field( 'mdf_ca_guardar_catalogo' ); ?>
		<input type="hidden" name="action" value="mdf_ca_guardar_catalogo" />
		<input type="hidden" name="id" value="<?php echo esc_attr( (string) ( $item ? $item->get_id() : 0 ) ); ?>" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="mdf-ca-h-nombre">Nombre</label></th>
					<td>
						<input type="text" id="mdf-ca-h-nombre" name="nombre" class="regular-text" maxlength="<?php echo esc_attr( (string) Catalogo_Service::MAX_NOMBRE ); ?>" required
							value="<?php echo esc_attr( $valores['nombre'] ); ?>" />
						<p class="description">Es también el nombre del archivo que se descarga.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-h-descripcion">Descripción breve <span class="description">(opcional)</span></label></th>
					<td>
						<input type="text" id="mdf-ca-h-descripcion" name="descripcion" class="large-text" maxlength="<?php echo esc_attr( (string) Catalogo_Service::MAX_DESCRIPCION ); ?>"
							value="<?php echo esc_attr( $valores['descripcion'] ); ?>" />
						<p class="description">Una línea. Se ve en la tarjeta de la herramienta.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-h-descripcion-detallada">Descripción detallada <span class="description">(opcional)</span></label></th>
					<td>
						<textarea id="mdf-ca-h-descripcion-detallada" name="descripcion_detallada" class="large-text" rows="4" maxlength="<?php echo esc_attr( (string) Catalogo_Service::MAX_DESCRIPCION_DETALLADA ); ?>"><?php echo esc_textarea( $valores['descripcion_detallada'] ); ?></textarea>
						<p class="description">Un párrafo que explica con más detalle qué hace la herramienta. Se ve al abrirla en el área de clientes; si se deja vacío, se muestra la descripción breve. Máximo <?php echo esc_html( (string) Catalogo_Service::MAX_DESCRIPCION_DETALLADA ); ?> caracteres.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="mdf-ca-h-categoria">Categoría</label></th>
					<td>
						<select id="mdf-ca-h-categoria" name="categoria_id" required>
							<option value="">Elige una categoría</option>
							<?php foreach ( $categorias as $mdf_ca_cat ) : ?>
								<option value="<?php echo esc_attr( (string) $mdf_ca_cat->get_id() ); ?>" <?php selected( (int) $valores['categoria_id'], $mdf_ca_cat->get_id() ); ?>><?php echo esc_html( $mdf_ca_cat->get_nombre() ); ?></option>
							<?php endforeach; ?>
						</select>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::SLUG_CATEGORIAS ) ); ?>">Gestionar categorías</a>
					</td>
				</tr>
				<tr>
					<th scope="row">Etiquetas <span class="description">(opcional)</span></th>
					<td>
						<?php if ( ! $etiquetas ) : ?>
							<p class="description">No hay etiquetas.
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::SLUG_ETIQUETAS ) ); ?>">Crear etiquetas</a></p>
						<?php else : ?>
							<fieldset class="mdf-ca-etiquetas" data-mdf-ca-etiquetas>
								<legend class="screen-reader-text">Etiquetas</legend>
								<div class="mdf-ca-etiquetas__casillas" data-mdf-ca-etiquetas-casillas>
									<?php foreach ( $etiquetas as $mdf_ca_et ) : ?>
										<label>
											<input type="checkbox" name="etiqueta_ids[]" value="<?php echo esc_attr( (string) $mdf_ca_et->get_id() ); ?>"
												data-nombre="<?php echo esc_attr( $mdf_ca_et->get_nombre() ); ?>"
												<?php checked( in_array( $mdf_ca_et->get_id(), array_map( 'intval', $valores['etiqueta_ids'] ), true ) ); ?> />
											<?php echo esc_html( $mdf_ca_et->get_nombre() ); ?>
										</label>
									<?php endforeach; ?>
								</div>
							</fieldset>
							<p class="description">Se eligen de la lista de
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::SLUG_ETIQUETAS ) ); ?>">Etiquetas</a>; aquí no se crean.</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Recurso</th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">Recurso</legend>
							<label><input type="radio" name="recurso" value="archivo" <?php checked( $mdf_ca_es_archivo ); ?> data-mdf-ca-recurso /> Archivo</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="recurso" value="enlace" <?php checked( ! $mdf_ca_es_archivo ); ?> data-mdf-ca-recurso /> Enlace</label>
						</fieldset>

						<div class="mdf-ca-herramienta__bloque" data-mdf-ca-bloque-archivo>
							<?php if ( $mdf_ca_archivo_actual ) : ?>
								<p>Archivo actual: <strong><?php echo esc_html( Catalogo_Formatos::get_etiqueta( $mdf_ca_archivo_actual->get_formato() ) ); ?></strong>
									(<?php echo esc_html( size_format( (int) $mdf_ca_archivo_actual->get_tamano(), 1 ) ); ?>).
									Si eliges otro, sustituye al actual y el actual se borra.</p>
							<?php endif; ?>
							<label for="mdf-ca-h-archivo"><?php echo $mdf_ca_archivo_actual ? 'Sustituir el archivo' : 'Archivo'; ?></label><br />
							<input type="file" id="mdf-ca-h-archivo" name="archivo"
								accept="<?php echo esc_attr( Catalogo_Formatos::accept( $permitir_xlsm ) ); ?>"
								<?php echo Admin_Zona_Archivos::atributos( Catalogo_Formatos::texto_admitidos( $permitir_xlsm ), Catalogo_Archivo_Validator::TAMANO_MAXIMO_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapado. ?> />
							<p class="description">
								<?php echo esc_html( Catalogo_Formatos::texto_admitidos( $permitir_xlsm ) ); ?>, hasta <?php echo esc_html( (string) ( Catalogo_Archivo_Validator::TAMANO_MAXIMO_BYTES / 1024 / 1024 ) ); ?> MB.
								No se admiten documentos con macros (.docm, .pptm)<?php echo $permitir_xlsm ? '' : ' ni Excel con macros (.xlsm)'; ?>.
							</p>
							<?php if ( $permitir_xlsm ) : ?>
								<p class="mdf-ca-catalogo-aviso" data-mdf-ca-aviso-xlsm hidden>
									<span class="dashicons dashicons-warning" aria-hidden="true"></span>
									Excel con macros: se ofrecerá siempre como descarga, nunca en el visor, y sus macros se ejecutarán en el equipo de la farmacia si las habilita. Súbelo solo si confías en su origen.
								</p>
							<?php endif; ?>
						</div>

						<div class="mdf-ca-herramienta__bloque" data-mdf-ca-bloque-enlace>
							<label for="mdf-ca-h-enlace">Enlace</label><br />
							<input type="url" id="mdf-ca-h-enlace" name="enlace_url" class="large-text" placeholder="https://"
								value="<?php echo esc_attr( $valores['enlace_url'] ); ?>" />
							<p class="description">Solo http:// o https://. Se abre en una pestaña nueva.</p>
						</div>
					</td>
				</tr>
				<tr data-mdf-ca-bloque-config>
					<th scope="row">Configuración</th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">Configuración</legend>
							<label><input type="checkbox" name="descargable" value="1" <?php checked( (bool) $valores['descargable'] ); ?> data-mdf-ca-descargable /> Descargable</label><br />
							<label><input type="checkbox" name="marca_agua" value="1" <?php checked( (bool) $valores['marca_agua'] ); ?> data-mdf-ca-marca /> Marca de agua</label>
						</fieldset>
						<p class="description">
							La marca de agua la pinta el visor con el CIF y el nombre de la farmacia que la abre.
							Si además es descargable, se ve con marca y el archivo descargado va sin ella.
							Sin «Descargable», solo se puede ver en el visor.
						</p>
						<p class="mdf-ca-catalogo-aviso" data-mdf-ca-config-forzada hidden>
							<span class="dashicons dashicons-info" aria-hidden="true"></span>
							Word, PowerPoint y Excel con macros no se pueden ver en el visor: se descargan siempre y no llevan marca de agua.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Visualización</th>
					<td>
						<fieldset data-mdf-ca-visibilidad>
							<legend>Planes que la ven</legend>
							<?php if ( ! $planes ) : ?>
								<p class="description">Todavía no hay ningún plan.</p>
							<?php endif; ?>
							<?php foreach ( $planes as $mdf_ca_plan ) : ?>
								<label style="display:block;">
									<input type="checkbox" name="plan_ids[]" value="<?php echo esc_attr( (string) $mdf_ca_plan->get_id() ); ?>" data-mdf-ca-plan
										<?php checked( in_array( $mdf_ca_plan->get_id(), array_map( 'intval', $valores['plan_ids'] ), true ) ); ?> />
									<?php echo esc_html( $mdf_ca_plan->get_nombre() ); ?>
								</label>
							<?php endforeach; ?>

							<p style="margin-top:1em;">
								<label><input type="checkbox" name="solo_farmacias" value="1" <?php checked( (bool) $valores['solo_farmacias'] ); ?> data-mdf-ca-solo-farmacias /> Solo para farmacias concretas</label>
							</p>
							<div class="mdf-ca-herramienta__bloque" data-mdf-ca-bloque-farmacias>
								<label for="<?php echo esc_attr( $selector_id ); ?>">Añadir farmacia</label>
								<?php require __DIR__ . '/parcial-selector-farmacia.php'; ?>
							</div>
							<p class="description">Si marcas planes y farmacias, se suman: la ven las farmacias de esos planes más las elegidas.</p>
						</fieldset>
						<p class="mdf-ca-catalogo-aviso" data-mdf-ca-aviso-nadie hidden role="status">
							<span class="dashicons dashicons-warning" aria-hidden="true"></span>
							No has marcado ningún plan ni ninguna farmacia: no la verá ninguna farmacia.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Estado</th>
					<td>
						<fieldset>
							<legend class="screen-reader-text">Estado</legend>
							<label><input type="radio" name="visible" value="1" <?php checked( (bool) $valores['visible'] ); ?> /> Visible</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="visible" value="0" <?php checked( ! $valores['visible'] ); ?> /> Oculta</label>
						</fieldset>
						<p class="description">Una herramienta oculta no la ve ninguna farmacia.</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( $item ? 'Guardar cambios' : 'Crear herramienta', 'primary', 'submit', false ); ?>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . Admin_Catalogo::MENU_SLUG ) ); ?>" class="button">Cancelar</a>
	</form>
</div>
