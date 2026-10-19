<?php
/**
 * Aviso de extensiones de PHP que faltan. Solo marcado: la logica vive en
 * Admin_Requisitos_Servidor y Requisitos_Servidor.
 *
 * @var list<string> $mensajes Uno por funcion que no esta disponible.
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="notice notice-error">
	<p><strong>MDF Client Area: faltan extensiones de PHP en el servidor</strong></p>
	<?php foreach ( $mensajes as $mensaje ) : ?>
		<p><?php echo esc_html( $mensaje ); ?></p>
	<?php endforeach; ?>
	<p class="description">El resto del Área de Clientes sigue funcionando; solo se bloquean estas funciones hasta que el servidor tenga las extensiones.</p>
</div>
