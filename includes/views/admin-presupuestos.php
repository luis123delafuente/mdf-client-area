<?php
/**
 * Documentos MDF > Presupuestos (v0.44.0). Solo marcado: la logica vive en
 * Admin_Presupuestos y Presupuesto_Service.
 *
 * @var \MdfClientArea\Documento[]                 $presupuestos
 * @var array<int, \MdfClientArea\Farmacia>        $farmacias
 * @var string                                     $filtro
 * @var int                                        $con_aviso
 * @var string                                     $email_responsable
 * @var bool                                       $es_admin
 * @var array{tipo: string, mensaje: string}|null  $aviso
 * @var array{cambiar: string, reintentar: string, ajustes: string} $acciones
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$mdf_ca_filtros = array( '' => 'Todos' ) + array(
	Presupuesto_Estado::PENDIENTE => 'Pendientes',
	Presupuesto_Estado::ACEPTADO  => 'Aceptados',
	Presupuesto_Estado::RECHAZADO => 'Rechazados',
);
?>
<div class="wrap">
	<h1>Presupuestos</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning', 'success' ), true ) ? $aviso['tipo'] : 'info' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( '' === $email_responsable ) : ?>
		<div class="notice notice-warning">
			<p>Falta el correo del responsable de presupuestos (abajo, en «Ajustes»). Mientras no esté, los avisos de aceptación y rechazo quedan pendientes.</p>
		</div>
	<?php endif; ?>

	<?php if ( $con_aviso ) : ?>
		<div class="notice notice-error">
			<p>
				<?php echo esc_html( sprintf( 1 === $con_aviso ? 'Hay %d aviso al responsable sin enviar.' : 'Hay %d avisos al responsable sin enviar.', $con_aviso ) ); ?>
				Se vuelve a intentar cada hora.
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( $acciones['reintentar'] ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( $acciones['reintentar'] ); ?>" />
				<p><button type="submit" class="button">Reintentar ahora</button></p>
			</form>
		</div>
	<?php endif; ?>

	<p>
		Las farmacias aceptan o rechazan sus presupuestos pendientes desde el visor del área de clientes. Aquí puedes ponerlos en
		«Pendiente» (por ejemplo, para que la farmacia vuelva a responder) o en «Aceptado» (aceptado por otra vía). El rechazo solo lo hace la farmacia.
	</p>

	<ul class="subsubsub">
		<?php
		$mdf_ca_i = 0;
		foreach ( $mdf_ca_filtros as $mdf_ca_clave => $mdf_ca_texto ) :
			?>
			<li>
				<a href="<?php echo esc_url( Admin_Presupuestos::url_listado( '' === $mdf_ca_clave ? array() : array( 'estado' => $mdf_ca_clave ) ) ); ?>"<?php echo $filtro === $mdf_ca_clave ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $mdf_ca_texto ); ?></a><?php echo ++$mdf_ca_i < count( $mdf_ca_filtros ) ? ' |' : ''; ?>
			</li>
		<?php endforeach; ?>
	</ul>

	<?php if ( ! $presupuestos ) : ?>
		<p style="clear: both;">No hay presupuestos<?php echo '' !== $filtro ? esc_html( ' ' . mb_strtolower( $mdf_ca_filtros[ $filtro ] ) ) : ''; ?>.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped" style="clear: both;">
			<thead>
				<tr>
					<th>Presupuesto</th>
					<th>Farmacia</th>
					<th>Estado</th>
					<th>Motivo del rechazo</th>
					<th>Cambiar estado</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $presupuestos as $documento ) : ?>
					<?php
					$presupuesto = $documento->get_presupuesto();
					$farmacia    = $farmacias[ $documento->get_farmacia_id() ] ?? null;
					$usuario     = $presupuesto->get_cambiado_por() ? get_userdata( $presupuesto->get_cambiado_por() ) : false;
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $documento->get_nombre() ); ?></strong>
							<?php if ( ! $documento->is_publicado() ) : ?>
								<br /><span class="description">Sin publicar: la farmacia no lo ve.</span>
							<?php endif; ?>
							<br /><a href="<?php echo esc_url( Admin_Documentos_Publicados::url_historial( $documento->get_id() ) ); ?>">Historial</a>
						</td>
						<td>
							<?php echo $farmacia ? esc_html( $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')' ) : '<em>' . esc_html( 'Farmacia ya no existe (ID ' . $documento->get_farmacia_id() . ')' ) . '</em>'; ?>
						</td>
						<td>
							<strong><?php echo esc_html( $presupuesto->get_etiqueta() ); ?></strong>
							<?php if ( ! $presupuesto->es_pendiente() ) : ?>
								<br />
								<span class="description">
									<?php
									echo esc_html(
										( $presupuesto->es_de_la_farmacia() ? 'Por la farmacia' : 'Por MDF' )
										. ( $presupuesto->get_cambiado_en() ? ', ' . get_date_from_gmt( $presupuesto->get_cambiado_en(), 'd/m/Y H:i' ) : '' )
										. ( $usuario ? ' (' . $usuario->display_name . ')' : '' )
									);
									?>
								</span>
							<?php endif; ?>
							<?php if ( $presupuesto->tiene_aviso_pendiente() ) : ?>
								<br /><span style="color: #b32d2e;">Aviso al responsable sin enviar</span>
							<?php endif; ?>
						</td>
						<td><?php echo $presupuesto->get_motivo() ? nl2br( esc_html( $presupuesto->get_motivo() ) ) : '—'; ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<?php wp_nonce_field( $acciones['cambiar'] . '_' . $documento->get_id() ); ?>
								<input type="hidden" name="action" value="<?php echo esc_attr( $acciones['cambiar'] ); ?>" />
								<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>" />
								<label class="screen-reader-text" for="mdf-ca-presupuesto-<?php echo esc_attr( (string) $documento->get_id() ); ?>">Nuevo estado de <?php echo esc_html( $documento->get_nombre() ); ?></label>
								<select id="mdf-ca-presupuesto-<?php echo esc_attr( (string) $documento->get_id() ); ?>" name="estado">
									<?php foreach ( Presupuesto_Estado::opciones_mdf() as $mdf_ca_clave => $mdf_ca_texto ) : ?>
										<option value="<?php echo esc_attr( $mdf_ca_clave ); ?>" <?php selected( $presupuesto->get_estado(), $mdf_ca_clave ); ?>><?php echo esc_html( $mdf_ca_texto ); ?></option>
									<?php endforeach; ?>
								</select>
								<button type="submit" class="button button-small">Cambiar</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<h2>Ajustes</h2>

	<?php if ( $es_admin ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( $acciones['ajustes'] ); ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( $acciones['ajustes'] ); ?>" />
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-presupuestos-email">Correo del responsable</label></th>
						<td>
							<input type="email" id="mdf-ca-presupuestos-email" name="email_responsable" class="regular-text" value="<?php echo esc_attr( $email_responsable ); ?>" />
							<p class="description">Recibe un aviso cada vez que una farmacia acepta o rechaza un presupuesto, con el motivo si lo rechaza. Sale desde el correo del área de clientes.</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( 'Guardar ajustes' ); ?>
		</form>
	<?php else : ?>
		<p>
			Correo del responsable: <strong><?php echo '' !== $email_responsable ? esc_html( $email_responsable ) : 'sin configurar'; ?></strong>.
			Solo el administrador puede cambiarlo.
		</p>
	<?php endif; ?>
</div>
