<?php
/**
 * Pestana "Publicar y enviar" del Modelo 347 (#307): cartas de una sociedad y
 * ejercicio, publicacion en lote (dos pasos), envio por tandas, reintentos,
 * reenvio explicito y prueba. Solo marcado.
 *
 * @var array<string, string>                     $emisores
 * @var string                                    $emisor
 * @var int                                       $ejercicio
 * @var array{tipo: string, mensaje: string}|null $aviso
 * @var string                                    $pub_id
 * @var array<string, mixed>|null                 $preparada
 * @var array<string, mixed>|\WP_Error            $calculo
 * @var Documento[]                               $documentos
 * @var array<int, object>                        $envios
 * @var array<string, int>                        $recuentos
 * @var array<string, mixed>                      $ajustes
 * @var array<int, Farmacia>                      $farmacias
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tab             = 'envios';
$ocultos         = static function () use ( $emisor, $ejercicio ): void {
	echo '<input type="hidden" name="emisor" value="' . esc_attr( $emisor ) . '"><input type="hidden" name="ejercicio" value="' . esc_attr( (string) $ejercicio ) . '">';
};
$etiquetas_envio = array(
	Modelo_347_Envio_Repository::PENDIENTE => 'En cola',
	Modelo_347_Envio_Repository::ENVIANDO  => 'Enviando',
	Modelo_347_Envio_Repository::ENVIADO   => 'Enviado',
	Modelo_347_Envio_Repository::FALLIDO   => 'Fallido',
	Modelo_347_Envio_Repository::CANCELADO => 'Cancelado',
);
$limite_interrumpido = gmdate( 'Y-m-d H:i:s', time() - Modelo_347_Envio_Repository::MINUTOS_INTERRUMPIDO * MINUTE_IN_SECONDS );
$fecha_local         = static fn( ?string $utc ): string => $utc ? wp_date( 'd/m/Y H:i', (int) strtotime( $utc . ' UTC' ) ) : '—';
?>
<div class="wrap">
	<?php require __DIR__ . '/parcial-modelo-347-cabecera.php'; ?>

	<form method="get" style="margin:1em 0">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin_Modelo_347::MENU_SLUG ); ?>">
		<input type="hidden" name="tab" value="envios">
		<label>Sociedad
			<select name="emisor">
				<?php foreach ( $emisores as $cif => $nombre ) : ?>
					<option value="<?php echo esc_attr( $cif ); ?>" <?php selected( $cif, $emisor ); ?>><?php echo esc_html( $nombre ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label>Ejercicio
			<select name="ejercicio">
				<?php for ( $anio = (int) wp_date( 'Y' ); $anio >= Documento_Service::EJERCICIO_MINIMO; $anio-- ) : ?>
					<option value="<?php echo esc_attr( (string) $anio ); ?>" <?php selected( $anio, $ejercicio ); ?>><?php echo esc_html( (string) $anio ); ?></option>
				<?php endfor; ?>
			</select>
		</label>
		<?php submit_button( 'Ver', 'secondary', '', false ); ?>
	</form>

	<?php if ( is_wp_error( $calculo ) ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $calculo->get_error_message() ); ?></p></div>
	<?php elseif ( $preparada ) : ?>
		<div class="notice notice-warning inline">
			<p>
				<strong>Confirmar publicación y envío.</strong>
				Se publicarán <strong><?php echo esc_html( (string) $calculo['documentos'] ); ?></strong> cartas de
				<?php echo esc_html( Factura_Emisores::nombre_corto( $emisor ) . ' del ejercicio ' . $ejercicio ); ?> en un lote (se puede deshacer)
				y se pondrán en cola <strong><?php echo esc_html( (string) $calculo['con_correo'] ); ?></strong> correos.
				<?php echo esc_html( sprintf( '%d farmacias sin cuenta activa la verán en su área, sin correo.', $calculo['sin_correo'] ) ); ?>
			</p>
			<div style="display:flex;gap:1em;margin-bottom:.5em">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'mdf_ca_347_publicar_aplicar' ); ?>
					<input type="hidden" name="action" value="mdf_ca_347_publicar_aplicar">
					<input type="hidden" name="pub" value="<?php echo esc_attr( $pub_id ); ?>">
					<?php submit_button( 'Publicar y poner en cola', 'primary', 'submit', false ); ?>
				</form>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'mdf_ca_347_publicar_cancelar' ); ?>
					<input type="hidden" name="action" value="mdf_ca_347_publicar_cancelar">
					<input type="hidden" name="pub" value="<?php echo esc_attr( $pub_id ); ?>">
					<?php $ocultos(); ?>
					<?php submit_button( 'Cancelar', 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
	<?php elseif ( $calculo['documentos'] > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mdf_ca_347_publicar_preparar' ); ?>
			<input type="hidden" name="action" value="mdf_ca_347_publicar_preparar">
			<?php $ocultos(); ?>
			<p>
				Hay <strong><?php echo esc_html( (string) $calculo['documentos'] ); ?></strong> cartas pendientes de publicar
				(<?php echo esc_html( (string) $calculo['con_correo'] ); ?> con correo).
				Ábrelas en «Ver» antes de publicar.
				<?php submit_button( 'Preparar publicación y envío…', 'primary', 'submit', false ); ?>
			</p>
		</form>
	<?php endif; ?>

	<h2>Envíos</h2>
	<p>
		<?php
		echo esc_html(
			sprintf(
				'En cola: %d · Enviados: %d · Fallidos: %d · Enviando: %d · Interrumpidos: %d · Cancelados: %d',
				$recuentos[ Modelo_347_Envio_Repository::PENDIENTE ],
				$recuentos[ Modelo_347_Envio_Repository::ENVIADO ],
				$recuentos[ Modelo_347_Envio_Repository::FALLIDO ],
				$recuentos[ Modelo_347_Envio_Repository::ENVIANDO ],
				$recuentos['interrumpido'],
				$recuentos[ Modelo_347_Envio_Repository::CANCELADO ]
			)
		);
		?>
	</p>

	<?php if ( '' === $ajustes['reply_to'] ) : ?>
		<div class="notice notice-error inline"><p>Falta el Reply-To en <a href="<?php echo esc_url( Admin_Modelo_347::url( array( 'tab' => 'ajustes', 'emisor' => $emisor ) ) ); ?>">Ajustes</a>: sin él no se envía nada.</p></div>
	<?php elseif ( $recuentos[ Modelo_347_Envio_Repository::PENDIENTE ] > 0 ) : ?>
		<div
			data-mdf-ca-347-envio
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'mdf_ca_347_enviar_tanda' ) ); ?>"
			data-emisor="<?php echo esc_attr( $emisor ); ?>"
			data-ejercicio="<?php echo esc_attr( (string) $ejercicio ); ?>"
			data-segundos="<?php echo esc_attr( (string) $ajustes['segundos_tanda'] ); ?>"
		>
			<p>
				<button type="button" class="button button-primary" data-mdf-ca-iniciar>Enviar pendientes</button>
				<button type="button" class="button" data-mdf-ca-parar hidden>Parar</button>
				<span class="description"><?php echo esc_html( sprintf( '%d correos por tanda, una tanda cada %d s. Deja esta pestaña abierta; si la cierras, se reanuda donde se quedó.', $ajustes['por_tanda'], $ajustes['segundos_tanda'] ) ); ?></span>
			</p>
			<p data-mdf-ca-progreso aria-live="polite"></p>
		</div>
	<?php endif; ?>

	<?php if ( $recuentos[ Modelo_347_Envio_Repository::FALLIDO ] > 0 ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mdf_ca_347_reintentar' ); ?>
			<input type="hidden" name="action" value="mdf_ca_347_reintentar">
			<?php $ocultos(); ?>
			<?php submit_button( 'Reintentar los fallidos', 'secondary', 'submit', false ); ?>
		</form>
	<?php endif; ?>

	<h2>Cartas (<?php echo esc_html( (string) count( $documentos ) ); ?>)</h2>
	<?php if ( ! $documentos ) : ?>
		<p>No hay cartas vigentes de esta sociedad y ejercicio.</p>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr><th>Farmacia</th><th>Carta</th><th>Publicación</th><th>Envío</th><th>Destinatario</th><th>Intentos</th><th>Último intento / enviado</th><th>Acciones</th></tr>
			</thead>
			<tbody>
				<?php foreach ( $documentos as $documento ) : ?>
					<?php
					$farmacia = $farmacias[ $documento->get_farmacia_id() ] ?? null;
					$envio    = $envios[ $documento->get_id() ] ?? null;
					$estado   = $envio ? (string) $envio->estado : '';
					$interrumpido = Modelo_347_Envio_Repository::ENVIANDO === $estado && null !== $envio->ultimo_intento_en && $envio->ultimo_intento_en < $limite_interrumpido;
					?>
					<tr>
						<td><?php echo $farmacia ? esc_html( $farmacia->get_nombre() . ' (' . $farmacia->get_cif() . ')' ) : '<em>' . esc_html( 'Farmacia ya no existe (ID ' . $documento->get_farmacia_id() . ')' ) . '</em>'; ?></td>
						<td><a href="<?php echo esc_url( Admin_Publicacion_Documentos::url_vista_previa( $documento->get_id() ) ); ?>" target="_blank" rel="noopener">Ver</a></td>
						<td><?php echo esc_html( $documento->is_publicado() ? 'Publicada' : 'Pendiente' ); ?></td>
						<td>
							<?php if ( ! $envio ) : ?>
								<?php echo esc_html( $documento->is_publicado() ? 'Sin correo (sin cuenta activa)' : '—' ); ?>
							<?php else : ?>
								<?php echo esc_html( $interrumpido ? 'Interrumpido (puede haber salido)' : ( $etiquetas_envio[ $estado ] ?? $estado ) ); ?>
								<?php if ( null !== $envio->error && '' !== $envio->error ) : ?>
									<br><small><?php echo esc_html( $envio->error ); ?></small>
								<?php endif; ?>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $envio ? (string) $envio->destinatario : '—' ); ?></td>
						<td><?php echo esc_html( $envio ? (string) $envio->intentos : '—' ); ?></td>
						<td><?php echo esc_html( $envio ? $fecha_local( $envio->enviado_en ?? $envio->ultimo_intento_en ) : '—' ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'mdf_ca_347_prueba_' . $documento->get_id() ); ?>
								<input type="hidden" name="action" value="mdf_ca_347_prueba">
								<input type="hidden" name="documento_id" value="<?php echo esc_attr( (string) $documento->get_id() ); ?>">
								<?php $ocultos(); ?>
								<button type="submit" class="button button-small" <?php disabled( '' === $ajustes['correo_prueba'] ); ?>>Enviarme una prueba</button>
							</form>
							<?php if ( $envio && ( in_array( $estado, array( Modelo_347_Envio_Repository::ENVIADO, Modelo_347_Envio_Repository::FALLIDO, Modelo_347_Envio_Repository::CANCELADO ), true ) || $interrumpido ) ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
									<?php wp_nonce_field( 'mdf_ca_347_reenviar_' . (int) $envio->id ); ?>
									<input type="hidden" name="action" value="mdf_ca_347_reenviar">
									<input type="hidden" name="envio_id" value="<?php echo esc_attr( (string) (int) $envio->id ); ?>">
									<?php $ocultos(); ?>
									<label><input type="checkbox" name="confirmar" value="1" required> Confirmo</label>
									<button type="submit" class="button button-small">Reenviar</button>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
