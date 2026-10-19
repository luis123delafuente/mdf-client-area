<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contrasenas de aplicacion de WordPress desactivadas en todo el sitio
 * (#336). Solo las usaba el robot de recepcion, ya retirado: nadie entra por
 * la API REST ni por XML-RPC con credenciales.
 *
 * Con el filtro a false, WordPress no muestra la seccion del perfil, no deja
 * crearlas por REST (/wp/v2/users/{id}/application-passwords) y
 * wp_authenticate_application_password() ignora la cabecera Authorization:
 * una peticion con Basic Auth se trata como anonima, aunque la contrasena
 * fuera valida antes de desplegar (las del robot las revoca ademas
 * Robot_Retirado).
 */
class Contrasenas_Aplicacion {

	public static function register_hooks(): void {
		add_filter( 'wp_is_application_passwords_available', '__return_false' );
	}
}
