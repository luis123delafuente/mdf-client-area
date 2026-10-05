<?php
/**
 * Vista de "Documentos MDF > Recepcion automatica". Solo marcado: la logica
 * vive en Admin_Recepcion_Documentos, Recepcion_Registro y
 * Recepcion_Autenticacion.
 *
 * @var bool                                         $activa
 * @var bool                                         $encendida       Valor del interruptor de esta pantalla.
 * @var bool                                         $forzada_apagada
 * @var int                                          $tope
 * @var bool                                         $requiere_aprobacion
 * @var int                                          $pendientes_publicacion
 * @var int                                          $aceptados_hoy
 * @var array<string, array<string, int>>            $recuentos
 * @var array{tipo: string, mensaje: string}|null    $aviso
 * @var array{rol_correcto: bool, passwords: int, ultimo_uso: int, enlace: string}|null $robot_info
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$columnas = array(
	Recepcion_Registro::ACEPTADO  => 'Aceptados',
	Recepcion_Registro::DUPLICADO => 'Duplicados',
	Recepcion_Registro::EXCEPCION => 'Excepciones',
	Recepcion_Registro::RECHAZADO => 'Rechazados',
	Recepcion_Registro::PUBLICADO_BLOQUE => 'Publicados en bloque',
);
?>
<div class="wrap">
	<h1>Recepcion automatica de documentos</h1>

	<?php if ( $aviso ) : ?>
		<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning' ), true ) ? $aviso['tipo'] : 'success' ); ?> is-dismissible">
			<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
		</div>
	<?php endif; ?>

	<p class="description">
		Ruta REST autenticada por la que el robot envia facturas (PDF mas el resultado del parser). Solo el estado
		"asignada" crea un documento en la farmacia del CIF/NIF. El resto se devuelve como excepcion y no se guarda nada.
		Con la recepcion apagada, el endpoint rechaza todo y no escribe nada. Los recuentos no incluyen CIF, nombres ni importes.
	</p>

	<table class="form-table" role="presentation">
		<tbody>
			<tr>
				<th scope="row">Estado</th>
				<td>
					<strong><?php echo $activa ? 'Activada' : 'Desactivada'; ?></strong>
					<?php if ( $forzada_apagada ) : ?>
						<p class="description">Apagada por la constante <code>MDF_CA_RECEPCION_DESACTIVADA</code> de wp-config.php, aunque se active aqui.</p>
					<?php endif; ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5em">
						<?php wp_nonce_field( 'mdf_ca_recepcion_interruptor' ); ?>
						<input type="hidden" name="action" value="mdf_ca_recepcion_interruptor" />
						<input type="hidden" name="activar" value="<?php echo $encendida ? '0' : '1'; ?>" />
						<?php submit_button( $encendida ? 'Desactivar recepcion' : 'Activar recepcion', 'secondary', 'submit', false ); ?>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row">Aprobacion antes de publicar</th>
				<td>
					<strong><?php echo $requiere_aprobacion ? 'Activada' : 'Desactivada'; ?></strong>
					<p class="description">
						Activada (recomendado): los documentos recibidos quedan pendientes de publicar, invisibles para todas las farmacias,
						hasta que un administrador los publica. Desactivada: se publican directamente.
						<?php if ( $pendientes_publicacion > 0 ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=mdf-ca-publicacion-documentos' ) ); ?>">
								Hay <?php echo esc_html( (string) $pendientes_publicacion ); ?> documentos pendientes de publicar.
							</a>
						<?php endif; ?>
					</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5em">
						<?php wp_nonce_field( 'mdf_ca_recepcion_aprobacion' ); ?>
						<input type="hidden" name="action" value="mdf_ca_recepcion_aprobacion" />
						<input type="hidden" name="activar" value="<?php echo $requiere_aprobacion ? '0' : '1'; ?>" />
						<?php submit_button( $requiere_aprobacion ? 'Desactivar aprobacion' : 'Activar aprobacion', 'secondary', 'submit', false ); ?>
					</form>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="mdf-ca-recepcion-tope">Tope diario</label></th>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'mdf_ca_recepcion_tope' ); ?>
						<input type="hidden" name="action" value="mdf_ca_recepcion_tope" />
						<input type="number" id="mdf-ca-recepcion-tope" name="tope" min="1" max="<?php echo esc_attr( (string) Recepcion_Registro::TOPE_MAXIMO ); ?>" value="<?php echo esc_attr( (string) $tope ); ?>" class="small-text" required />
						<?php submit_button( 'Guardar', 'secondary', 'submit', false ); ?>
					</form>
					<p class="description">Documentos aceptados por dia. Hoy: <?php echo esc_html( (string) $aceptados_hoy ); ?> de <?php echo esc_html( (string) $tope ); ?>.</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Usuario robot</th>
				<td>
					<?php if ( ! $robot_info ) : ?>
						<p>Todavia no existe. Es un usuario tecnico sin acceso interactivo: solo puede usar esta ruta con su contrasena de aplicacion.</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'mdf_ca_recepcion_crear_robot' ); ?>
							<input type="hidden" name="action" value="mdf_ca_recepcion_crear_robot" />
							<?php submit_button( 'Crear usuario robot', 'secondary', 'submit', false ); ?>
						</form>
					<?php else : ?>
						<p>
							Usuario <code><?php echo esc_html( Admin_Recepcion_Documentos::ROBOT_LOGIN ); ?></code>
							<?php echo $robot_info['rol_correcto'] ? '' : '(¡no tiene el rol Robot MDF!)'; ?>:
							<?php echo esc_html( (string) $robot_info['passwords'] ); ?> contrasenas de aplicacion,
							ultimo uso: <?php echo $robot_info['ultimo_uso'] ? esc_html( wp_date( 'd/m/Y H:i', $robot_info['ultimo_uso'] ) ) : 'nunca'; ?>.
						</p>
						<p class="description">
							La contrasena de aplicacion se crea y se revoca en
							<a href="<?php echo esc_url( $robot_info['enlace'] ); ?>">el perfil del robot</a>
							(seccion "Contrasenas de aplicacion"). WordPress la muestra una sola vez: guardala en el gestor de contrasenas.
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<h2>Ultimos 14 dias</h2>
	<?php if ( ! $recuentos ) : ?>
		<p>Todavia no se ha recibido ninguna peticion autenticada.</p>
	<?php else : ?>
		<table class="wp-list-table widefat fixed striped" style="max-width:700px">
			<thead>
				<tr>
					<th>Dia</th>
					<?php foreach ( $columnas as $etiqueta ) : ?>
						<th><?php echo esc_html( $etiqueta ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $recuentos as $dia => $resultados ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd/m/Y', strtotime( $dia . ' 12:00:00' ) ) ); ?></td>
						<?php foreach ( array_keys( $columnas ) as $clave ) : ?>
							<td><?php echo esc_html( (string) ( $resultados[ $clave ] ?? 0 ) ); ?></td>
						<?php endforeach; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
