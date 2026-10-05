<?php
/**
 * Historial de publicaciones de un documento (#318). Solo marcado y solo
 * lectura: ids de usuario, fechas, acciones, origenes y lotes. Ni IP, ni
 * datos fiscales.
 *
 * @var \MdfClientArea\Documento|null  $documento
 * @var array<int, array<string,string>> $filas
 * @var string                         $farmacia
 * @var string                         $estado
 * @var string                         $recibido
 * @var bool                           $sin_registro
 * @var string                         $desde_legible
 * @var string                         $version_desde
 * @var bool                           $truncado
 * @var int                            $total
 * @var string                         $url_publicados
 * @var string                         $url_pendientes
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Historial del documento</h1>

	<?php if ( ! $documento ) : ?>
		<div class="notice notice-error inline">
			<p>El documento no existe.</p>
		</div>
	<?php else : ?>
		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">Documento</th>
					<td><?php echo esc_html( $documento->get_nombre() ); ?></td>
				</tr>
				<tr>
					<th scope="row">Farmacia</th>
					<td><?php echo esc_html( $farmacia ); ?></td>
				</tr>
				<tr>
					<th scope="row">Estado actual</th>
					<td><strong><?php echo esc_html( $estado ); ?></strong></td>
				</tr>
				<tr>
					<th scope="row">Recibido</th>
					<td><?php echo esc_html( $recibido ); ?></td>
				</tr>
			</tbody>
		</table>

		<h2>Publicaciones y despublicaciones</h2>

		<?php if ( $sin_registro ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<strong>Sin registro anterior a la versión <?php echo esc_html( $version_desde ); ?></strong><?php echo '' !== $desde_legible ? esc_html( ' (' . $desde_legible . ')' ) : ''; ?>.
					Este documento ya existía antes: no se sabe quién lo publicó, ni cuándo, ni si se cambió su estado antes
					de esa fecha. Lo que aparece debajo es lo registrado desde entonces.
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $filas ) : ?>
			<?php if ( $truncado ) : ?>
				<p class="description"><?php echo esc_html( sprintf( 'Se muestran los primeros %d de %d eventos.', count( $filas ), $total ) ); ?></p>
			<?php endif; ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width:11em">Fecha</th>
						<th style="width:9em">Acción</th>
						<th>Cómo</th>
						<th>Quién</th>
						<th style="width:6em">Lote</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $filas as $fila ) : ?>
						<tr>
							<td><?php echo esc_html( $fila['fecha'] ); ?></td>
							<td><?php echo esc_html( $fila['accion'] ); ?></td>
							<td><?php echo esc_html( $fila['origen'] ); ?></td>
							<td><?php echo esc_html( $fila['quien'] ); ?></td>
							<td><?php echo esc_html( $fila['lote'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php elseif ( ! $sin_registro && ! $documento->is_publicado() ) : ?>
			<p>Todavía no se ha publicado: llegó pendiente de publicar y sigue así. Nunca ha sido visible para su farmacia.</p>
		<?php elseif ( ! $sin_registro ) : ?>
			<p>No hay ningún evento registrado para este documento.</p>
		<?php endif; ?>
	<?php endif; ?>

	<p style="margin-top:1.5em">
		<a href="<?php echo esc_url( $url_publicados ); ?>">« Documentos publicados</a> |
		<a href="<?php echo esc_url( $url_pendientes ); ?>">Pendientes de publicar</a>
	</p>
</div>
