<?php
/**
 * Herramientas > Migracion del diseno (v0.45.0, TEMPORAL). Solo marcado.
 *
 * @var array{tipo: string, mensaje: string, detalles: string[]}|null $aviso
 * @var string|null $error        Lo que impide importar (Elementor, paquete, paginas).
 * @var array|null  $comprobacion Migracion_Diseno::comprobar().
 * @var string      $accion
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mdf_ca_boton = static function ( string $paso, string $texto, string $clase = 'button', string $extra = '' ) use ( $accion ): void {
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0 0 16px;">
		<?php wp_nonce_field( $accion . '_' . $paso ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( $accion ); ?>" />
		<input type="hidden" name="paso" value="<?php echo esc_attr( $paso ); ?>" />
		<?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- marcado fijo de esta vista. ?>
		<button type="submit" class="<?php echo esc_attr( $clase ); ?>"><?php echo esc_html( $texto ); ?></button>
	</form>
	<?php
};
$mdf_ca_confirmar = '<p><label><input type="checkbox" name="confirmar" value="1" required /> Confirmo: cambia lo que ven las farmacias.</label></p>';
?>
<div class="wrap">
	<h1>Migración del diseño</h1>

	<p>
		Lleva a esta web el diseño del área de clientes hecho con Elementor en local: imágenes, colores, fuente y clases globales,
		y las 11 páginas del área. Herramienta <strong>temporal</strong>: se retira cuando el diseño esté puesto.
	</p>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo 'error' === $aviso['tipo'] ? 'error' : 'success'; ?>">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
			<?php if ( $aviso['detalles'] ) : ?>
				<details><summary>Detalle (<?php echo esc_html( (string) count( $aviso['detalles'] ) ); ?>)</summary>
					<ul style="margin-left: 1.5em; list-style: disc;">
						<?php foreach ( $aviso['detalles'] as $mdf_ca_linea ) : ?>
							<li><?php echo esc_html( $mdf_ca_linea ); ?></li>
						<?php endforeach; ?>
					</ul>
				</details>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( $error ) : ?>
		<div class="notice notice-error"><p><strong>No se puede importar todavía:</strong> <?php echo esc_html( $error ); ?></p></div>
	<?php else : ?>
		<?php
		$mdf_ca_textos_estado = array(
			'sin_importar' => 'Sin importar.',
			'revision'     => 'Copias de revisión creadas. Las páginas reales siguen como estaban.',
			'aplicado'     => 'Diseño APLICADO a las páginas reales.',
		);
		?>
		<h2>Situación</h2>
		<ul style="list-style: disc; margin-left: 1.5em;">
			<li>Elementor <?php echo esc_html( $comprobacion['elementor'] ); ?> activo<?php echo $comprobacion['version_distinta'] ? esc_html( ' (el diseño se hizo con la ' . $comprobacion['paquete']['elementor'] . ': revisa bien las copias)' ) : ''; ?>.</li>
			<li>
				Paquete del <?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', strtotime( $comprobacion['paquete']['generado'] ) ), 'd/m/Y H:i' ) ); ?>:
				<?php echo esc_html( sprintf( '%d páginas, %d imágenes, %d clases y %d variables globales.', $comprobacion['paquete']['paginas'], $comprobacion['paquete']['medios'], $comprobacion['paquete']['clases'], $comprobacion['paquete']['variables'] ) ); ?>
			</li>
			<li><strong><?php echo esc_html( $mdf_ca_textos_estado[ $comprobacion['estado'] ] ); ?></strong></li>
		</ul>

		<?php if ( $comprobacion['ajenos'] ) : ?>
			<div class="notice notice-warning inline"><p>
				El kit de Elementor de esta web ya tiene clases o variables que no son del paquete (<?php echo esc_html( implode( ', ', $comprobacion['ajenos'] ) ); ?>).
				Crear las copias de revisión las sustituiría. Si no sabes de dónde salen, para y pregunta.
			</p></div>
		<?php endif; ?>

		<table class="wp-list-table widefat fixed striped" style="max-width: 900px;">
			<thead><tr><th>Página</th><th>Página real en esta web</th><th>Copia de revisión</th></tr></thead>
			<tbody>
				<?php foreach ( $comprobacion['paginas'] as $mdf_ca_pagina ) : ?>
					<tr>
						<td><?php echo esc_html( $mdf_ca_pagina['titulo'] ); ?></td>
						<td><a href="<?php echo esc_url( $mdf_ca_pagina['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $mdf_ca_pagina['titulo_aqui'] ); ?></a> <span class="description">(ID <?php echo esc_html( (string) $mdf_ca_pagina['id'] ); ?>, <?php echo esc_html( $mdf_ca_pagina['estado'] ); ?>)</span></td>
						<td>
							<?php if ( $mdf_ca_pagina['revision_id'] ) : ?>
								<a href="<?php echo esc_url( get_preview_post_link( $mdf_ca_pagina['revision_id'] ) ); ?>" target="_blank" rel="noopener">Vista previa</a>
							<?php else : ?>
								—
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Pasos</h2>

		<h3>1. Crear las copias de revisión</h3>
		<p>Sube las imágenes, carga el kit y crea una copia en borrador de cada página. No toca lo que ven las farmacias. Se puede repetir.</p>
		<?php
		$mdf_ca_boton(
			'revision',
			$comprobacion['estado'] === 'sin_importar' ? 'Crear copias de revisión' : 'Volver a crear las copias de revisión',
			'button button-primary',
			$comprobacion['ajenos'] ? '<p><label><input type="checkbox" name="sustituir" value="1" /> Sustituir las clases y variables que ya tiene el kit.</label></p>' : ''
		);
		?>

		<h3>2. Aplicar a las páginas reales</h3>
		<p>Cuando las copias estén bien: pasa el diseño a las páginas del área (guarda antes lo que tenían). Después, pruébalo con una cuenta de farmacia.</p>
		<?php $mdf_ca_boton( 'aplicar', 'Aplicar el diseño', 'button button-primary', $mdf_ca_confirmar ); ?>

		<h3>Si algo va mal: deshacer</h3>
		<p>Devuelve las páginas reales a lo que tenían antes de aplicar.</p>
		<?php $mdf_ca_boton( 'deshacer', 'Deshacer', 'button', $mdf_ca_confirmar ); ?>

		<h3>3. Al terminar: limpiar</h3>
		<p>Manda a la papelera las copias de revisión.</p>
		<?php $mdf_ca_boton( 'limpiar', 'Quitar copias de revisión' ); ?>
	<?php endif; ?>
</div>
