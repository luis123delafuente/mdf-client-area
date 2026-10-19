<?php
/**
 * Documentos MDF › Subir facturas (#334). Solo marcado: la subida la hace
 * assets/js/admin-subir-facturas.js (una peticion por factura) y la logica,
 * Admin_Subir_Facturas.
 *
 * @var string|null $requisitos   Mensaje si al servidor le faltan extensiones.
 * @var int         $max_bytes    Tamano maximo por factura.
 * @var int         $max_facturas Facturas por subida.
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap mdf-ca-subir-facturas">
	<h1>Subir facturas</h1>

	<p>
		Sube aquí las facturas en PDF exportadas de SAGE (Mediformplus y Formación). El portal las lee, busca la farmacia
		por el CIF del cliente y las deja <strong>pendientes de publicar</strong>: ninguna farmacia las ve hasta que las publiques
		en «Pendientes de publicar». No se envía ningún aviso por correo.
	</p>
	<p class="description">
		Puedes soltar una carpeta entera: se toman los PDF de la carpeta y de sus subcarpetas, y el resto se ignora. Volver a
		subir las mismas facturas no las duplica. Las que no se reconozcan no se guardan: al final tendrás la lista para subirlas
		a mano desde Documentos MDF.
	</p>

	<?php if ( null !== $requisitos ) : ?>
		<div class="notice notice-error inline"><p><?php echo esc_html( $requisitos ); ?></p></div>
	<?php else : ?>
		<noscript>
			<div class="notice notice-warning inline"><p>Esta pantalla necesita JavaScript. Mientras tanto, puedes subir las facturas de una en una desde Documentos MDF.</p></div>
		</noscript>

		<table class="form-table" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="mdf-ca-subir-facturas-ficheros">Facturas (PDF)</label></th>
					<td>
						<input
							type="file"
							id="mdf-ca-subir-facturas-ficheros"
							accept=".pdf,application/pdf"
							multiple
							<?php echo Admin_Zona_Archivos::atributos( 'PDF', $max_bytes, true, $max_facturas ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapados. ?>
						/>
					</td>
				</tr>
			</tbody>
		</table>

		<div id="mdf-ca-subir-facturas-preparado" hidden>
			<p id="mdf-ca-subir-facturas-encontradas" class="mdf-ca-subir-facturas__encontradas"></p>
			<p>
				<button type="button" class="button button-primary" id="mdf-ca-subir-facturas-subir">Subir</button>
			</p>
		</div>

		<div id="mdf-ca-subir-facturas-progreso" hidden>
			<h2>Subiendo</h2>
			<progress id="mdf-ca-subir-facturas-barra" max="1" value="0" aria-labelledby="mdf-ca-subir-facturas-estado" style="width:100%;max-width:36em;height:16px"></progress>
			<p id="mdf-ca-subir-facturas-estado" role="status" aria-live="polite"></p>
			<p>
				<button type="button" class="button" id="mdf-ca-subir-facturas-parar">Parar</button>
				<button type="button" class="button button-primary" id="mdf-ca-subir-facturas-continuar" hidden>Continuar</button>
			</p>
		</div>

		<div id="mdf-ca-subir-facturas-resumen" hidden>
			<h2 id="mdf-ca-subir-facturas-titulo-resumen" tabindex="-1">Resumen</h2>
			<p id="mdf-ca-subir-facturas-totales"></p>
			<div id="mdf-ca-subir-facturas-grupos"></div>
			<p>
				<button type="button" class="button" id="mdf-ca-subir-facturas-descargar" hidden>Descargar la lista de no reconocidas (.txt)</button>
				<a class="button button-primary" id="mdf-ca-subir-facturas-pendientes" href="#">Ir a «Pendientes de publicar»</a>
				<button type="button" class="button-link" id="mdf-ca-subir-facturas-otra">Subir otras facturas</button>
			</p>
		</div>
	<?php endif; ?>
</div>
