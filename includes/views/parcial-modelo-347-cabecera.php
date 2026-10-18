<?php
/**
 * Cabecera comun de la pantalla Modelo 347 (#307): titulo, pestanas y aviso.
 *
 * @var string                                  $tab
 * @var array{tipo: string, mensaje: string}|null $aviso
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pestanas = array(
	'generar' => 'Generar cartas',
	'envios'  => 'Publicar y enviar',
	'ajustes' => 'Ajustes',
);
?>
<h1>Modelo 347</h1>

<nav class="nav-tab-wrapper">
	<?php foreach ( $pestanas as $clave => $etiqueta ) : ?>
		<a href="<?php echo esc_url( Admin_Modelo_347::url( array( 'tab' => $clave ) ) ); ?>" class="nav-tab<?php echo $tab === $clave ? ' nav-tab-active' : ''; ?>"><?php echo esc_html( $etiqueta ); ?></a>
	<?php endforeach; ?>
</nav>

<?php if ( $aviso ) : ?>
	<div class="notice notice-<?php echo esc_attr( in_array( $aviso['tipo'], array( 'error', 'warning', 'success' ), true ) ? $aviso['tipo'] : 'info' ); ?>">
		<p><?php echo esc_html( $aviso['mensaje'] ); ?></p>
	</div>
<?php endif; ?>
