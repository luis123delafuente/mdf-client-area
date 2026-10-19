<?php
/**
 * Pestana "Generar cartas" del Modelo 347 (#307): subida del Excel,
 * simulacion (sin escribir nada) y avance de la generacion por tandas. Solo
 * marcado: la logica vive en Admin_Modelo_347 y los servicios Modelo_347_*.
 * Todo lo que viene del Excel se escapa al pintarlo.
 *
 * @var array<string, string>                     $emisores
 * @var string                                    $emisor
 * @var int                                       $ejercicio
 * @var array{tipo: string, mensaje: string}|null $aviso
 * @var string                                    $job_id
 * @var string                                    $sim_id
 * @var array<string, mixed>|null                 $sim
 * @var array<string, mixed>|null                 $simulacion
 * @var array<string, mixed>                      $ajustes
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$tab = 'generar';
$eur = static fn( int $centimos ): string => Modelo_347_Carta::formatear_importe( $centimos ) . ' €';
?>
<div class="wrap">
	<?php require __DIR__ . '/parcial-modelo-347-cabecera.php'; ?>

	<?php if ( ! $emisores ) : ?>
		<p>No hay ninguna sociedad con plantilla de carta.</p>
	<?php elseif ( '' !== $job_id ) : ?>

		<h2>Generando cartas</h2>
		<div
			data-mdf-ca-347-generacion
			data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( 'mdf_ca_347_generar_tanda' ) ); ?>"
			data-job="<?php echo esc_attr( $job_id ); ?>"
			data-envios="<?php echo esc_url( Admin_Modelo_347::url( array( 'tab' => 'envios', 'emisor' => $emisor ) ) ); ?>"
		>
			<p data-mdf-ca-progreso aria-live="polite">Preparando…</p>
			<progress data-mdf-ca-barra max="100" value="0" style="width:100%;max-width:40em"></progress>
			<ul data-mdf-ca-errores></ul>
			<p data-mdf-ca-fin hidden>
				Las cartas han quedado <strong>pendientes de publicar</strong>: ábrelas desde
				<a data-mdf-ca-enlace-envios href="#">Publicar y enviar</a> antes de publicar y enviar.
			</p>
			<noscript><p>Esta página necesita JavaScript para generar las cartas por tandas.</p></noscript>
		</div>

	<?php elseif ( $simulacion && $sim ) : ?>

		<h2>Simulación: <?php echo esc_html( Factura_Emisores::nombre_corto( $emisor ) . ' · ejercicio ' . $ejercicio ); ?></h2>
		<p>Nada se ha guardado todavía. La simulación caduca en 15 minutos.</p>

		<?php if ( '' === $ajustes['correo_admin'] ) : ?>
			<div class="notice notice-error inline"><p>Falta el correo de Administración en <a href="<?php echo esc_url( Admin_Modelo_347::url( array( 'tab' => 'ajustes', 'emisor' => $emisor ) ) ); ?>">Ajustes</a>: aparece en la carta y sin él no se puede generar ni previsualizar.</p></div>
		<?php endif; ?>

		<table class="widefat striped" style="max-width:48em">
			<tbody>
				<?php foreach ( Modelo_347_Simulacion_Service::ETIQUETAS as $clave => $etiqueta ) : ?>
					<tr><td><?php echo esc_html( $etiqueta ); ?></td><td style="text-align:right"><strong><?php echo esc_html( (string) $simulacion['recuentos'][ $clave ] ); ?></strong></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3>Totales para cuadrar con SAGE</h3>
		<table class="widefat" style="max-width:48em">
			<tbody>
				<tr><td>Total Importe DEV del fichero (filas con DEV legible)</td><td style="text-align:right"><strong><?php echo esc_html( $eur( $simulacion['total_dev_fichero'] ) ); ?></strong></td></tr>
				<tr><td>Total de las filas «lista»</td><td style="text-align:right"><strong><?php echo esc_html( $eur( $simulacion['total_dev_lista'] ) ); ?></strong></td></tr>
				<tr><td>Total de las filas «sin cuenta» (se generan, no se envían)</td><td style="text-align:right"><?php echo esc_html( $eur( $simulacion['total_dev_sin_cuenta'] ) ); ?></td></tr>
				<?php if ( $simulacion['filas_sin_dev'] > 0 ) : ?>
					<tr><td colspan="2"><?php echo esc_html( sprintf( 'Hay %d fila(s) con Importe DEV vacío o ilegible, fuera del total del fichero.', $simulacion['filas_sin_dev'] ) ); ?></td></tr>
				<?php endif; ?>
			</tbody>
		</table>

		<?php
		$generables       = $simulacion['recuentos'][ Modelo_347_Simulacion_Service::LISTA ] + $simulacion['recuentos'][ Modelo_347_Simulacion_Service::SIN_CUENTA ];
		$no_enviables     = $simulacion['recuentos'][ Modelo_347_Simulacion_Service::SIN_CUENTA ] + $simulacion['recuentos'][ Modelo_347_Simulacion_Service::NO_PORTAL ];
		$previsualizables = count( array_filter( $simulacion['filas'], array( Modelo_347_Simulacion_Service::class, 'es_previsualizable' ) ) );
		$puede_pintar     = '' !== $ajustes['correo_admin'];
		$url_previa       = wp_nonce_url( add_query_arg( array( 'action' => 'mdf_ca_347_vista_previa', 'sim' => $sim_id ), admin_url( 'admin-post.php' ) ), 'mdf_ca_347_vista_previa' );
		?>
		<h3>Revisar cartas</h3>
		<?php if ( ! $puede_pintar ) : ?>
			<p>Configura el correo de Administración en Ajustes para ver las cartas y descargar el ZIP.</p>
		<?php elseif ( 0 === $previsualizables ) : ?>
			<p>Ninguna fila tiene identificador válido y suma correcta: no hay ninguna carta que ver.</p>
		<?php else : ?>
			<p>
				<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $url_previa ); ?>">Vista previa (hasta 3 cartas, PDF)</a>
				<?php if ( $no_enviables > 0 ) : ?>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'mdf_ca_347_zip', 'sim' => $sim_id ), admin_url( 'admin-post.php' ) ), 'mdf_ca_347_zip' ) ); ?>">Descargar ZIP de las que no se pueden enviar (<?php echo esc_html( (string) $no_enviables ); ?>)</a>
				<?php endif; ?>
			</p>
			<p class="description">
				La vista previa sirve para cualquier fila con identificador válido y suma correcta, se genere o no; en las tablas de abajo,
				«Ver carta» abre la de cada fila. El ZIP lleva las cartas de «sin cuenta» y «no está en el portal», para enviarlas por otra vía.
				Nada de esto se guarda en el servidor.
			</p>
		<?php endif; ?>

		<?php
		// Buscador de filas (#307): mismo marcado y textos que el de
		// «Documentos publicados», pero filtra en el navegador
		// (admin-modelo-347.js): el termino no viaja al servidor ni queda en
		// la URL. Nace oculto y solo lo muestra el JS: sin JS, la pantalla
		// queda como siempre, con todas las filas visibles.
		?>
		<form data-mdf-ca-347-buscador hidden>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-347-buscar">CIF/NIF o nombre</label></th>
						<td>
							<input type="search" id="mdf-ca-347-buscar" class="regular-text" maxlength="100" autocomplete="off" data-mdf-ca-347-termino />
							<p class="description">
								Busca el texto en cualquier parte del CIF/NIF o del nombre, sin distinguir mayúsculas ni tildes; en el CIF/NIF
								se ignoran espacios, guiones y puntos. Solo filtra lo que ves en las tablas de abajo: los recuentos, los totales,
								la vista previa y el ZIP siempre son del fichero entero.
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( 'Buscar', 'primary', 'submit', false ); ?>
			<a href="#" style="margin-left:.5em" hidden data-mdf-ca-347-limpiar>Quitar filtros</a>
		</form>
		<?php // display:none en linea: el CSS de wp-admin (h1..h6 { display: block }) anula el atributo hidden. ?>
		<h2 hidden style="display:none" data-mdf-ca-347-resultado aria-live="polite"></h2>

		<?php foreach ( Modelo_347_Simulacion_Service::ETIQUETAS as $clave => $etiqueta ) : ?>
			<?php
			$filas_cat = array_filter( $simulacion['filas'], static fn( array $f ): bool => $f['categoria'] === $clave );
			if ( ! $filas_cat ) {
				continue;
			}
			?>
			<details <?php echo Modelo_347_Simulacion_Service::LISTA !== $clave ? 'open' : ''; ?> style="margin:1em 0" data-mdf-ca-347-tabla>
				<summary><strong><?php echo esc_html( $etiqueta ); ?> <span data-mdf-ca-347-recuento data-total="<?php echo esc_attr( (string) count( $filas_cat ) ); ?>">(<?php echo esc_html( (string) count( $filas_cat ) ); ?>)</span></strong></summary>
				<p hidden data-mdf-ca-347-vacia>Ninguna fila con estos filtros.</p>
				<table class="widefat striped">
					<thead><tr><th>Fila</th><th>Nombre</th><th>CIF/NIF</th><th>Importe DEV</th><th>Avisos</th><th>Carta</th></tr></thead>
					<tbody>
						<?php foreach ( $filas_cat as $f ) : ?>
							<tr data-mdf-ca-347-fila data-nif="<?php echo esc_attr( $f['nif'] ); ?>" data-nombre="<?php echo esc_attr( $f['nombre'] . ( isset( $f['farmacia_nombre'] ) ? ' ' . $f['farmacia_nombre'] : '' ) ); ?>">
								<td><?php echo esc_html( (string) $f['linea'] ); ?></td>
								<td><?php echo esc_html( $f['nombre'] ); ?><?php echo isset( $f['farmacia_nombre'] ) && $f['farmacia_nombre'] !== $f['nombre'] ? '<br><small>' . esc_html( 'En el portal: ' . $f['farmacia_nombre'] ) . '</small>' : ''; ?></td>
								<td><?php echo esc_html( '' !== $f['nif'] ? $f['nif'] : '—' ); ?></td>
								<td style="text-align:right"><?php echo esc_html( null === $f['dev'] ? '—' : $eur( (int) $f['dev'] ) ); ?></td>
								<td><?php echo esc_html( implode( '; ', $f['avisos'] ) ); ?></td>
								<td>
									<?php if ( $puede_pintar && Modelo_347_Simulacion_Service::es_previsualizable( $f ) ) : ?>
										<a target="_blank" rel="noopener" href="<?php echo esc_url( add_query_arg( 'linea', (int) $f['linea'], $url_previa ) ); ?>">Ver carta</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
		<?php endforeach; ?>

		<div style="display:flex;gap:1em;margin-top:1.5em">
			<?php if ( $generables > 0 && '' !== $ajustes['correo_admin'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'mdf_ca_347_confirmar' ); ?>
					<input type="hidden" name="action" value="mdf_ca_347_confirmar">
					<input type="hidden" name="sim" value="<?php echo esc_attr( $sim_id ); ?>">
					<input type="hidden" name="huella" value="<?php echo esc_attr( $simulacion['huella'] ); ?>">
					<?php submit_button( sprintf( 'Generar %d cartas (quedan pendientes de publicar)', $generables ), 'primary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'mdf_ca_347_cancelar' ); ?>
				<input type="hidden" name="action" value="mdf_ca_347_cancelar">
				<input type="hidden" name="sim" value="<?php echo esc_attr( $sim_id ); ?>">
				<?php submit_button( 'Descartar', 'secondary', 'submit', false ); ?>
			</form>
		</div>

	<?php else : ?>

		<h2>Subir el Excel de SAGE</h2>
		<p>
			Hoja «347» con las columnas Código, Nombre, NIF, Dirección, Población, Código postal, Trimestre 1 a 4,
			Importe DEV, Importe RECC y RECC (Email es opcional). Primero se simula: nada se guarda hasta que confirmes.
			El Excel no se guarda en el servidor.
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'mdf_ca_347_simular' ); ?>
			<input type="hidden" name="action" value="mdf_ca_347_simular">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><label for="mdf-ca-347-emisor">Sociedad</label></th>
						<td>
							<select id="mdf-ca-347-emisor" name="emisor" required>
								<?php foreach ( $emisores as $cif => $nombre ) : ?>
									<option value="<?php echo esc_attr( $cif ); ?>" <?php selected( $cif, $emisor ); ?>><?php echo esc_html( $nombre . ' (' . $cif . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-347-ejercicio">Ejercicio</label></th>
						<td>
							<select id="mdf-ca-347-ejercicio" name="ejercicio" required>
								<?php for ( $anio = (int) wp_date( 'Y' ); $anio >= Documento_Service::EJERCICIO_MINIMO; $anio-- ) : ?>
									<option value="<?php echo esc_attr( (string) $anio ); ?>" <?php selected( $anio, $ejercicio ); ?>><?php echo esc_html( (string) $anio ); ?></option>
								<?php endfor; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mdf-ca-347-excel">Excel (.xlsx)</label></th>
						<td>
							<input type="file" id="mdf-ca-347-excel" name="excel" accept=".xlsx" <?php echo Admin_Zona_Archivos::atributos( 'Excel (.xlsx)', Modelo_347_Excel_Lector::MAX_BYTES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- ya escapados. ?> required>
							<p class="description">Máximo <?php echo esc_html( (string) ( Modelo_347_Excel_Lector::MAX_BYTES / 1024 / 1024 ) ); ?> MB y <?php echo esc_html( (string) Modelo_347_Excel_Lector::MAX_FILAS ); ?> filas.</p>
						</td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( 'Simular' ); ?>
		</form>

	<?php endif; ?>
</div>
