<?php
/**
 * Catalogo MDF > Ajustes (solo el administrador). Solo marcado.
 *
 * @var bool $permitir_xlsm
 * @var int  $con_xlsm Herramientas con un .xlsm ya subido.
 * @var array{tipo: string, mensaje: string, detalles: string[]}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1>Ajustes del catálogo</h1>

	<?php require __DIR__ . '/parcial-aviso-catalogo.php'; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'mdf_ca_ajustes_catalogo' ); ?>
		<input type="hidden" name="action" value="mdf_ca_ajustes_catalogo" />

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row">Excel con macros</th>
					<td>
						<label>
							<input type="checkbox" name="permitir_xlsm" value="1" <?php checked( $permitir_xlsm ); ?> />
							Permitir herramientas Excel con macros (.xlsm)
						</label>
						<p class="description">
							Apagado por defecto. Encendido, quien gestiona el catálogo puede subir un .xlsm: se ofrece siempre como
							descarga, nunca en el visor, y sus macros se ejecutan en el equipo de la farmacia si las habilita.
							Apagado, no se pueden subir y los ya subidos no los ve ninguna farmacia (su descarga da «no encontrado»).
						</p>
						<p class="description">Herramientas con un .xlsm: <?php echo esc_html( (string) $con_xlsm ); ?>.</p>
					</td>
				</tr>
			</tbody>
		</table>

		<?php submit_button( 'Guardar ajustes' ); ?>
	</form>
</div>
