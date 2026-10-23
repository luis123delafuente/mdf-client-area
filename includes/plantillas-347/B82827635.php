<?php
/**
 * Carta del Modelo 347 de Mediformplus (B82827635) a sus clientes (#307),
 * fiel a MODELO_CARTA_347.docx: A4, margenes 2,5 cm arriba/abajo y 3 cm a
 * los lados, logo arriba a la derecha (4,5 x 3 cm), cuerpo en Times New
 * Roman 12 pt (aqui Liberation Serif, metricamente identica y con licencia
 * OFL para embeberla) y pie con los datos de la sociedad en la fuente del
 * tema (aqui Liberation Sans). Lo resaltado en amarillo en el original es lo
 * variable; en la carta no se pinta.
 *
 * Solo maqueta. Recibe la lista de cartas ($cartas, una o varias: la vista
 * previa junta varias en un PDF, cada una en su pagina) y los datos comunes
 * ($comun), todo YA ESCAPADO para HTML por Modelo_347_Carta (nunca texto
 * crudo del Excel), y devuelve el HTML que dompdf convierte en PDF. Ningun recurso remoto: el logo es un fichero local
 * dentro del chroot de dompdf.
 *
 * $comun: emisor_razon_social, emisor_cif, emisor_direccion, firma (lineas
 * ya escapadas unidas con <br>, o vacia), correo_admin y logo (ruta local).
 * Los datos de la sociedad salen de Modelo_347_Ajustes (pestana Ajustes),
 * no de esta plantilla.
 * Cada carta: razon_social, nif, domicilio, ejercicio, t1, t2, t3, t4 y
 * total (importes ya formateados, "1.234,56").
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	'logo'   => __DIR__ . '/img/mediformplus.png',
	'render' => static function ( array $cartas, array $comun ): string {
		ob_start();
		?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
	/* Interlineado sencillo de Word con Times New Roman 12 pt = 13,8 pt.
	   dompdf multiplica line-height por la altura propia de la fuente (1,264
	   en Liberation Serif, medido): 0.91 x 12 x 1,264 = 13,8 pt. Con 1.15 la
	   carta pasaba a dos paginas (el original ocupa una). Pie a 11 pt:
	   Liberation Sans es mas ancha que Aptos y a 12 pt partia la linea. */
	@page { size: A4; margin: 4.7cm 3cm 2.7cm 3cm; }
	body { font-family: "liberation serif"; font-size: 12pt; color: #000; line-height: 0.91; }
	.cabecera { position: fixed; top: -3.45cm; right: 0; text-align: right; }
	.cabecera img { width: 4.54cm; height: 3.03cm; }
	.pie { position: fixed; bottom: -1.45cm; left: 0; right: 0; font-family: "liberation sans"; font-size: 11pt; line-height: 0.91; }
	p { margin: 0 0 14pt 0; text-align: left; }
	.destinatario { margin-bottom: 14pt; }
	table.detalle { border-collapse: separate; border-spacing: 0.75pt; margin: 0 0 14pt 0; }
	table.detalle td { padding: 0.75pt 4pt 0.75pt 0.75pt; vertical-align: middle; line-height: 0.91; }
	table.detalle .cabecera-tabla td { font-weight: bold; text-align: center; }
	table.detalle .total td { font-weight: bold; }
	.correo { color: #0000FF; font-weight: bold; text-decoration: underline; }
	.carta { page-break-after: always; }
	.carta:last-child { page-break-after: auto; }
	/* Firma (ajuste opcional): sin margen inferior, para que quepa en la
	   misma pagina que el resto (la carta la ocupa casi entera). */
	.firma { margin-bottom: 0; }
</style>
</head>
<body>
	<div class="cabecera"><img src="<?php echo $comun['logo']; // phpcs:ignore WordPress.Security.EscapeOutput -- ya escapado ?>" alt=""></div>
	<div class="pie"><?php echo $comun['emisor_razon_social'] . ' ' . $comun['emisor_cif'] . '. - ' . $comun['emisor_direccion']; // phpcs:ignore WordPress.Security.EscapeOutput -- ya escapado ?></div>

	<?php foreach ( $cartas as $v ) : ?>
	<div class="carta">
	<p>&nbsp;</p>
	<p>Estimado/a cliente:</p>
	<p class="destinatario"><?php echo $v['razon_social']; // phpcs:ignore WordPress.Security.EscapeOutput -- ya escapado ?><br>CIF/NIF: <?php echo $v['nif']; // phpcs:ignore WordPress.Security.EscapeOutput ?><br>Domicilio fiscal: <?php echo $v['domicilio']; // phpcs:ignore WordPress.Security.EscapeOutput ?></p>
	<p>Con motivo de la presentación de la declaración anual de operaciones con terceras personas, correspondiente al <b>modelo 347 del ejercicio <?php echo $v['ejercicio']; // phpcs:ignore WordPress.Security.EscapeOutput ?></b>, y de conformidad con lo establecido en el artículo 93 de la Ley 58/2003, de 17 de diciembre, General Tributaria, y en los artículos 31 y siguientes del Real Decreto 1065/2007, de 27 de julio, nos ponemos en contacto con usted para comunicarle los importes de las operaciones realizadas por <b><?php echo $comun['emisor_razon_social']; // phpcs:ignore WordPress.Security.EscapeOutput ?> (<?php echo $comun['emisor_cif']; // phpcs:ignore WordPress.Security.EscapeOutput ?>)</b> con su empresa durante el citado ejercicio, según nuestros registros contables.</p>
	<p>A continuación, detallamos los importes registrados, desglosados por trimestres:</p>
	<p><b>DETALLE DE LAS OPERACIONES DEL EJERCICIO <?php echo $v['ejercicio']; // phpcs:ignore WordPress.Security.EscapeOutput ?></b></p>
	<table class="detalle">
		<tr class="cabecera-tabla"><td>Periodo</td><td>Importe de las operaciones</td></tr>
		<tr><td>Primer trimestre (T1)</td><td><?php echo $v['t1']; // phpcs:ignore WordPress.Security.EscapeOutput ?> €</td></tr>
		<tr><td>Segundo trimestre (T2)</td><td><?php echo $v['t2']; // phpcs:ignore WordPress.Security.EscapeOutput ?> €</td></tr>
		<tr><td>Tercer trimestre (T3)</td><td><?php echo $v['t3']; // phpcs:ignore WordPress.Security.EscapeOutput ?> €</td></tr>
		<tr><td>Cuarto trimestre (T4)</td><td><?php echo $v['t4']; // phpcs:ignore WordPress.Security.EscapeOutput ?> €</td></tr>
		<tr class="total"><td>TOTAL ANUAL</td><td><?php echo $v['total']; // phpcs:ignore WordPress.Security.EscapeOutput ?> €</td></tr>
	</table>
	<p>Le rogamos que revise la información indicada y nos confirme su conformidad respondiendo a este correo electrónico, con el fin de comprobar que los datos registrados por ambas partes son coincidentes y evitar posibles discrepancias en la información comunicada a la Agencia Tributaria.</p>
	<p>En caso de detectar alguna diferencia, le agradeceríamos que nos lo comunicara a la mayor brevedad posible, indicando el trimestre y los importes afectados, para que podamos realizar las comprobaciones oportunas antes de presentar la declaración.</p>
	<p>Para cualquier consulta o aclaración, puede contactar con nuestro Departamento de Administración a través del correo electrónico <span class="correo"><?php echo $comun['correo_admin']; // phpcs:ignore WordPress.Security.EscapeOutput ?></span></p>
	<p>Agradecemos de antemano su colaboración y atención.</p>
	<?php if ( '' !== $comun['firma'] ) : ?>
	<p class="firma"><?php echo $comun['firma']; // phpcs:ignore WordPress.Security.EscapeOutput -- ya escapado ?></p>
	<?php endif; ?>
	</div>
	<?php endforeach; ?>
</body>
</html>
		<?php
		return (string) ob_get_clean();
	},
);
