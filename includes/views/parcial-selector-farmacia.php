<?php
/**
 * Selector de farmacia con filtro (combobox), en modo opcional: elegir
 * farmacia no es obligatorio y se puede vaciar. Solo marcado; el JS es
 * assets/js/admin-selector-farmacia.js (Admin_Documentos::encolar_
 * selector_farmacia()). Lo usan Documentos publicados (#318) y Pendientes
 * de publicar (#319). El valor se envia en el campo "farmacia_id".
 *
 * @var string                         $selector_id      id del cuadro de texto (el de la lista es "<id>-lista").
 * @var \MdfClientArea\Farmacia[]      $farmacias        Ordenadas por nombre.
 * @var \MdfClientArea\Farmacia|null   $farmacia_elegida
 * @var bool|null                      $selector_obligatorio Opcional: true = hay que elegir una (Bandeja de facturas, #335).
 * @var bool|null                      $selector_multiple   Opcional: true = varias farmacias (farmacias concretas de una
 *                                                          herramienta). Cada elegida va en la lista de debajo como una
 *                                                          casilla marcada "farmacia_ids[]" con su boton "Quitar"; sin JS,
 *                                                          se quita desmarcandola. No hay campo "farmacia_id".
 * @var \MdfClientArea\Farmacia[]|null $farmacias_elegidas  Con $selector_multiple: las ya elegidas.
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php
$selector_obligatorio = ! empty( $selector_obligatorio );
$selector_multiple    = ! empty( $selector_multiple );
?>
<div class="mdf-ca-selector-farmacia" data-mdf-ca-selector-farmacia<?php echo $selector_obligatorio ? '' : ' data-mdf-ca-opcional'; ?><?php echo $selector_multiple ? ' data-mdf-ca-multiple' : ''; ?>>
	<div class="mdf-ca-selector-farmacia__filtros">
		<div class="mdf-ca-selector-farmacia__combo">
			<input
				type="text"
				id="<?php echo esc_attr( $selector_id ); ?>"
				class="regular-text"
				placeholder="<?php echo esc_attr( $selector_obligatorio || $selector_multiple ? 'Haz clic y busca por nombre o CIF/NIF' : 'Todas las farmacias (haz clic para buscar por nombre o CIF/NIF)' ); ?>"
				autocomplete="off"
				role="combobox"
				aria-expanded="false"
				aria-controls="<?php echo esc_attr( $selector_id . '-lista' ); ?>"
				aria-autocomplete="list"
				value="<?php echo esc_attr( $farmacia_elegida ? $farmacia_elegida->get_nombre() . ' (' . $farmacia_elegida->get_cif() . ')' : '' ); ?>"
				data-mdf-ca-buscar
			/>
			<ul id="<?php echo esc_attr( $selector_id . '-lista' ); ?>" class="mdf-ca-selector-farmacia__lista" role="listbox" hidden data-mdf-ca-lista></ul>
		</div>
		<select data-mdf-ca-tipo-entidad aria-label="Filtrar por tipo de entidad">
			<option value="">Todos los tipos</option>
			<?php foreach ( Farmacia::TIPOS_ENTIDAD as $clave => $etiqueta ) : ?>
				<option value="<?php echo esc_attr( $clave ); ?>"><?php echo esc_html( $etiqueta ); ?></option>
			<?php endforeach; ?>
		</select>
	</div>
	<?php if ( $selector_multiple ) : ?>
		<input type="hidden" value="" data-mdf-ca-farmacia-id />
		<p class="mdf-ca-selector-farmacia__elegidas-titulo" data-mdf-ca-elegidas-titulo>Farmacias elegidas: <span data-mdf-ca-elegidas-n><?php echo esc_html( (string) count( (array) $farmacias_elegidas ) ); ?></span></p>
		<ul class="mdf-ca-selector-farmacia__elegidas" data-mdf-ca-elegidas>
			<?php foreach ( (array) $farmacias_elegidas as $mdf_ca_elegida ) : ?>
				<li data-farmacia-id="<?php echo esc_attr( (string) $mdf_ca_elegida->get_id() ); ?>">
					<label>
						<input type="checkbox" name="farmacia_ids[]" value="<?php echo esc_attr( (string) $mdf_ca_elegida->get_id() ); ?>" checked />
						<?php echo esc_html( $mdf_ca_elegida->get_nombre() . ' (' . $mdf_ca_elegida->get_cif() . ')' ); ?>
					</label>
					<button type="button" class="button-link button-link-delete" data-mdf-ca-quitar hidden>Quitar</button>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php else : ?>
		<input type="hidden" name="farmacia_id" value="<?php echo esc_attr( $farmacia_elegida ? (string) $farmacia_elegida->get_id() : '' ); ?>" data-mdf-ca-farmacia-id />
	<?php endif; ?>
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
