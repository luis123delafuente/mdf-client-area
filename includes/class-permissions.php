<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Punto unico de decision de visibilidad del plugin (ver CLAUDE.md, regla
 * no negociable 2). Ningun otro lugar del plugin debe decidir si algo es
 * visible para un usuario: el servido de documentos (#232) y las futuras
 * comprobaciones de catalogo por plan deben llamar aqui, no reimplementar
 * la logica.
 *
 * Cada tipo de recurso tiene su propio metodo con la firma
 * (usuario, recurso): bool, p. ej. el futuro puede_ver_item_catalogo()
 * seguira la misma convencion y reutilizara farmacia_del_usuario(), sin que
 * quien ya llama a puede_ver_documento() tenga que cambiar nada. La logica
 * de planes (Herramientas/Formacion) se resolveria dentro de ese metodo
 * nuevo, no aqui: los documentos nunca dependen del plan (CLAUDE.md).
 *
 * Toda condicion de entrada dudosa (sin sesion, sin recurso, sin farmacia
 * vinculada) devuelve false explicitamente. Ninguna excepcion sin capturar
 * debe poder escapar de esta clase.
 */
class Permissions {

	public static function puede_ver_documento( ?\WP_User $usuario, ?Documento $documento ): bool {
		if ( ! self::usuario_autenticado( $usuario ) || ! $documento ) {
			return false;
		}

		$farmacia = self::farmacia_del_usuario( $usuario );

		if ( ! $farmacia ) {
			return false;
		}

		return $farmacia->get_id() === $documento->get_farmacia_id();
	}

	private static function usuario_autenticado( ?\WP_User $usuario ): bool {
		return null !== $usuario && $usuario->exists();
	}

	/**
	 * Resuelve la farmacia del usuario a traves de Farmacia_Repository, sin
	 * duplicar la consulta por wp_user_id en ningun otro punto del plugin.
	 */
	private static function farmacia_del_usuario( \WP_User $usuario ): ?Farmacia {
		return ( new Farmacia_Repository() )->find_by_wp_user_id( $usuario->ID );
	}
}
