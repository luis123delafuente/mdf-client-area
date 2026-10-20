<?php
/**
 * Portada «Inicio» del backoffice (#337). Solo marcado: la logica vive en
 * Admin_Inicio::render_pagina(), que solo pasa las tarjetas que el usuario
 * puede usar.
 *
 * @var array<int, array{titulo: string, texto: string, url: string, cuenta: int|null}> $tarjetas
 */

namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$usuario = wp_get_current_user();
?>
<div class="wrap mdf-ca-inicio">
	<h1>Inicio</h1>
	<p><?php echo esc_html( sprintf( 'Hola, %s. Estas son las tareas habituales del Área de Clientes.', $usuario->display_name ) ); ?></p>

	<?php if ( ! $tarjetas ) : ?>
		<p>Tu cuenta no tiene ninguna tarea asignada. Habla con el administrador.</p>
	<?php else : ?>
		<ul class="mdf-ca-inicio__tarjetas">
			<?php foreach ( $tarjetas as $tarjeta ) : ?>
				<li class="mdf-ca-inicio__tarjeta">
					<a class="mdf-ca-inicio__enlace" href="<?php echo esc_url( $tarjeta['url'] ); ?>">
						<span class="mdf-ca-inicio__titulo">
							<?php echo esc_html( $tarjeta['titulo'] ); ?>
							<?php if ( null !== $tarjeta['cuenta'] ) : ?>
								<span class="mdf-ca-inicio__cuenta<?php echo $tarjeta['cuenta'] > 0 ? ' mdf-ca-inicio__cuenta--aviso' : ''; ?>" aria-label="<?php echo esc_attr( sprintf( '%d pendientes', $tarjeta['cuenta'] ) ); ?>"><?php echo esc_html( (string) $tarjeta['cuenta'] ); ?></span>
							<?php endif; ?>
						</span>
						<span class="mdf-ca-inicio__texto"><?php echo esc_html( $tarjeta['texto'] ); ?></span>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</div>
