<?php
/**
 * Aviso de la proteccion de la carpeta privada. Solo marcado: la logica
 * vive en Admin_Proteccion_Carpeta y Carpeta_Privada_Proteccion.
 *
 * @var string|null                                $aviso    Confirmacion tras "Volver a comprobar".
 * @var list<array{nivel: string, texto: string}>  $mensajes Problemas detectados (vacio si todo bien).
 * @var string                                     $nivel    error | warning.
 * @var string                                     $pagina   Pantalla del plugin a la que volver.
 * @var string                                     $carpeta  Ruta relativa.
 * @var string                                     $accion
 * @var string                                     $nonce
 * @var string                                     $fecha    Ultima comprobacion.
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( $aviso ) : ?>
	<div class="notice notice-success is-dismissible">
		<p><?php echo esc_html( $aviso ); ?></p>
	</div>
<?php endif; ?>

<?php if ( $mensajes ) : ?>
	<div class="notice notice-<?php echo esc_attr( $nivel ); ?>">
		<p><strong><?php echo esc_html( 'Proteccion de la carpeta de documentos privados (' . $carpeta . ')' ); ?></strong></p>
		<?php foreach ( $mensajes as $mensaje ) : ?>
			<p><?php echo esc_html( $mensaje['texto'] ); ?></p>
		<?php endforeach; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $accion ); ?>">
			<input type="hidden" name="pagina" value="<?php echo esc_attr( $pagina ); ?>">
			<?php wp_nonce_field( $nonce ); ?>
			<p>
				<button type="submit" class="button">Volver a comprobar</button>
				<span class="description"><?php echo esc_html( 'Ultima comprobacion: ' . $fecha . '.' ); ?></span>
			</p>
		</form>
	</div>
<?php endif; ?>
