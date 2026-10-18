<?php
/**
 * Pestana "Ajustes" del Modelo 347 (#307), por sociedad. Solo marcado: la
 * validacion vive en Modelo_347_Ajustes::guardar().
 *
 * @var array<string, string>                     $emisores
 * @var string                                    $emisor
 * @var array{tipo: string, mensaje: string}|null $aviso
 * @var array<string, mixed>                      $ajustes
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tab = 'ajustes';
?>
<div class="wrap">
	<?php require __DIR__ . '/parcial-modelo-347-cabecera.php'; ?>

	<?php if ( ! $emisores ) : ?>
		<p>No hay ninguna sociedad con plantilla de carta.</p>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'mdf_ca_347_ajustes' ); ?>
			<input type="hidden" name="action" value="mdf_ca_347_ajustes">
			<input type="hidden" name="emisor" value="<?php echo esc_attr( $emisor ); ?>">

			<h2><?php echo esc_html( ( Factura_Emisores::nombre_corto( $emisor ) ?? $emisor ) . ' (' . $emisor . ')' ); ?></h2>

			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-347-correo-admin">Correo de Administración</label></th>
						<td>
							<input type="email" id="mdf-ca-347-correo-admin" name="correo_admin" class="regular-text" value="<?php echo esc_attr( $ajustes['correo_admin'] ); ?>">
							<p class="description">Aparece en el texto de la carta. Obligatorio para generar.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-347-reply-to">Reply-To</label></th>
						<td>
							<input type="email" id="mdf-ca-347-reply-to" name="reply_to" class="regular-text" value="<?php echo esc_attr( $ajustes['reply_to'] ); ?>">
							<p class="description">Dirección a la que responden las farmacias. El remitente (From) sigue siendo el del SMTP del sitio. Obligatorio para enviar.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-347-prueba">Dirección de prueba</label></th>
						<td>
							<input type="email" id="mdf-ca-347-prueba" name="correo_prueba" class="regular-text" value="<?php echo esc_attr( $ajustes['correo_prueba'] ); ?>">
							<p class="description">Destino de «Enviarme una prueba».</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Ritmo de envío</th>
						<td>
							<label><input type="number" name="por_tanda" min="<?php echo esc_attr( (string) Modelo_347_Ajustes::POR_TANDA_MIN ); ?>" max="<?php echo esc_attr( (string) Modelo_347_Ajustes::POR_TANDA_MAX ); ?>" value="<?php echo esc_attr( (string) $ajustes['por_tanda'] ); ?>" class="small-text"> correos por tanda</label>,
							<label>una tanda cada <input type="number" name="segundos_tanda" min="<?php echo esc_attr( (string) Modelo_347_Ajustes::SEGUNDOS_TANDA_MIN ); ?>" max="<?php echo esc_attr( (string) Modelo_347_Ajustes::SEGUNDOS_TANDA_MAX ); ?>" value="<?php echo esc_attr( (string) $ajustes['segundos_tanda'] ); ?>" class="small-text"> segundos</label>
							<p class="description">Prudente por el límite del SMTP de IONOS (sin confirmar). Por defecto, 5 correos cada 30 s.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-347-cuerpo">Cuerpo del correo</label></th>
						<td>
							<textarea id="mdf-ca-347-cuerpo" name="cuerpo" rows="12" class="large-text"><?php echo esc_textarea( $ajustes['cuerpo'] ); ?></textarea>
							<p class="description">Texto plano. {SOCIEDAD} y {AÑO} se sustituyen al enviar. Vacío = texto por defecto. Asunto fijo: «<?php echo esc_html( Modelo_347_Envio_Service::asunto( (int) wp_date( 'Y' ) - 1 ) ); ?>».</p>
						</td>
					</tr>
				</tbody>
			</table>

			<?php submit_button( 'Guardar ajustes' ); ?>
		</form>
	<?php endif; ?>
</div>
