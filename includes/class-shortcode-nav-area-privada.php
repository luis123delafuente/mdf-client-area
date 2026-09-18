<?php
namespace MdfClientArea;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shortcode de navegacion entre las cuatro secciones del area de clientes
 * (Fase 2, "Estructura de las cuatro secciones"). Solo aparece si se
 * coloca dentro del contenido de esas paginas -- que es lo unico que hace
 * Area_Privada_Pages al crearlas -- y esas paginas ya estan protegidas
 * por Role_Restrictions::proteger_area_privada(): no hace falta repetir
 * aqui ninguna comprobacion de sesion para que sea "visible solo dentro
 * del area de clientes".
 */
class Shortcode_Nav_Area_Privada {

	private const TAG = 'mdf_ca_nav_area_privada';

	public static function register_hooks(): void {
		add_shortcode( self::TAG, array( __CLASS__, 'render' ) );
	}

	public static function render(): string {
		$actual = get_queried_object_id();
		$titulos = wp_list_pluck( Area_Privada_Pages::get_paginas(), 'titulo' );
		$items   = '';

		foreach ( Area_Privada_Pages::get_page_ids() as $slug => $id ) {
			$titulo = $titulos[ $slug ] ?? '';
			$url    = get_permalink( $id );

			if ( ! $titulo || ! $url ) {
				continue;
			}

			$clase = ( $id === $actual ) ? ' class="mdf-ca-nav-area-privada__item--actual"' : '';

			$items .= '<li' . $clase . '><a href="' . esc_url( $url ) . '">' . esc_html( $titulo ) . '</a></li>';
		}

		if ( '' === $items ) {
			return '';
		}

		return '<nav class="mdf-ca-nav-area-privada"><ul>' . $items . '</ul></nav>';
	}
}
