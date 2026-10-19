<?php
/**
 * Documentos MDF › Bandeja de facturas (#335). Solo marcado: la logica vive
 * en Admin_Bandeja_Facturas y Bandeja_Facturas_Service.
 *
 * @var array{tipo: string, mensaje: string}|null $aviso
 * @var array<int, array<string, mixed>>         $filas
 * @var int                                       $total
 * @var int                                       $caducan_pronto
 * @var int                                       $dias
 * @var array{recomprobar: string, ajustes: string} $acciones
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Bandeja de facturas</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( $aviso['tipo'] ); ?> is-dismissible"><p><?php echo esc_html( $aviso['mensaje'] ); ?></p></div>
	<?php endif; ?>

	<p>
		Facturas que «Subir facturas» ha leído pero no ha podido asignar a una farmacia. Ninguna farmacia las ve. Puedes verlas,
		asignarlas a mano (quedan pendientes de publicar) o descartarlas. Si das de alta una farmacia que faltaba, pulsa
		«Volver a comprobar todas» y las suyas pasarán solas a Pendientes de publicar.
	</p>

	<?php if ( $caducan_pronto > 0 ) : ?>
		<div class="notice notice-warning inline">
			<p><?php echo esc_html( sprintf( '%d %s en los próximos %d días (llevan casi %d días en la bandeja). Asígnalas o descártalas antes si las necesitas.', $caducan_pronto, 1 === $caducan_pronto ? 'factura se borrará sola' : 'facturas se borrarán solas', Bandeja_Facturas_Service::DIAS_AVISO, $dias ) ); ?></p>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:1em 0">
		<?php wp_nonce_field( $acciones['recomprobar'] ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( $acciones['recomprobar'] ); ?>" />
		<button type="submit" class="button" <?php disabled( 0 === $total ); ?>>Volver a comprobar todas</button>
		<span class="description"><?php echo esc_html( sprintf( '%d %s en la bandeja.', $total, 1 === $total ? 'factura' : 'facturas' ) ); ?></span>
	</form>

	<?php if ( ! $filas ) : ?>
		<p>La bandeja está vacía.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th style="width:18%">Fichero</th>
					<th>Motivo</th>
					<th>Datos leídos</th>
					<th style="width:12%">Subida</th>
					<th style="width:10%">Se borra</th>
					<th style="width:20%">Acciones</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $filas as $fila ) : ?>
					<?php
					$subida  = (int) strtotime( $fila['subido_en'] . ' UTC' );
					$caduca  = Bandeja_Facturas_Service::caduca_en( $fila );
					$pronto  = $caduca - time() < Bandeja_Facturas_Service::DIAS_AVISO * DAY_IN_SECONDS;
					$usuario = $fila['usuario_id'] ? get_userdata( (int) $fila['usuario_id'] ) : false;
					?>
					<tr>
						<td><?php echo esc_html( $fila['nombre_original'] ); ?></td>
						<td><?php echo esc_html( Admin_Bandeja_Facturas::MOTIVOS[ $fila['motivo'] ] ?? $fila['motivo'] ); ?></td>
						<td><?php echo esc_html( Admin_Bandeja_Facturas::datos_leidos( $fila ) ); ?></td>
						<td>
							<?php echo esc_html( sprintf( 'hace %s', human_time_diff( $subida ) ) ); ?>
							<br><span class="description"><?php echo esc_html( wp_date( 'd/m/Y', $subida ) . ( $usuario ? ' · ' . $usuario->display_name : '' ) ); ?></span>
						</td>
						<td>
							<?php if ( $pronto ) : ?>
								<strong style="color:#b32d2e"><?php echo esc_html( $caduca <= time() ? 'hoy' : 'en ' . human_time_diff( $caduca ) ); ?></strong>
							<?php else : ?>
								<?php echo esc_html( wp_date( 'd/m/Y', $caduca ) ); ?>
							<?php endif; ?>
						</td>
						<td>
							<a class="button button-small" target="_blank" rel="noopener" href="<?php echo esc_url( Admin_Bandeja_Facturas::url_ver( (int) $fila['id'] ) ); ?>">Ver</a>
							<a class="button button-small button-primary" href="<?php echo esc_url( Admin_Bandeja_Facturas::url( array( 'asignar' => (int) $fila['id'] ) ) ); ?>">Asignar a farmacia</a>
							<a class="button-link button-link-delete" href="<?php echo esc_url( Admin_Bandeja_Facturas::url( array( 'descartar' => (int) $fila['id'] ) ) ); ?>">Descartar</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( $total > count( $filas ) ) : ?>
			<p class="description"><?php echo esc_html( sprintf( 'Se muestran las %d más antiguas de %d.', count( $filas ), $total ) ); ?></p>
		<?php endif; ?>
	<?php endif; ?>

	<h2>Borrado automático</h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( $acciones['ajustes'] ); ?>
		<input type="hidden" name="action" value="<?php echo esc_attr( $acciones['ajustes'] ); ?>" />
		<p>
			<label for="mdf-ca-bandeja-dias">Lo que lleve más de</label>
			<input type="number" id="mdf-ca-bandeja-dias" name="dias" class="small-text" min="<?php echo esc_attr( (string) Bandeja_Facturas_Service::DIAS_MINIMO ); ?>" max="<?php echo esc_attr( (string) Bandeja_Facturas_Service::DIAS_MAXIMO ); ?>" value="<?php echo esc_attr( (string) $dias ); ?>" required />
			días en la bandeja se borra solo (el fichero y su registro), una vez al día.
			<button type="submit" class="button">Guardar</button>
		</p>
	</form>
</div>
