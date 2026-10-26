/*
 * Ventana de cada herramienta del catalogo (v0.43.0). El HTML ya viene
 * pintado por [mdf_ca_catalogo_herramientas]; aqui solo se abre y se cierra.
 * Abre: un clic en la tarjeta (salvo en sus botones) o en el nombre.
 * Cierra: la X, Esc (nativo de <dialog>) o un clic fuera de la caja.
 */
( function () {
	var lista = document.querySelector( '.mdf-ca-herramientas' );
	if ( ! lista || 'function' !== typeof HTMLDialogElement ) {
		return;
	}

	function abrir( tarjeta ) {
		var ficha = document.getElementById( tarjeta.getAttribute( 'data-ficha' ) );
		if ( ficha && ! ficha.open ) {
			ficha.showModal();
			// El foco, en la ventana y no en la X: el lector lee el titulo y
			// con el tabulador se llega a la X y a los botones.
			ficha.focus();
		}
	}

	lista.addEventListener( 'click', function ( e ) {
		var tarjeta = e.target.closest( '.mdf-ca-herramienta' );

		// Dentro de una ventana abierta, o en un enlace de la tarjeta: nada.
		if ( ! tarjeta || e.target.closest( '.mdf-ca-ficha' ) || e.target.closest( 'a' ) ) {
			return;
		}

		abrir( tarjeta );
	} );

	lista.querySelectorAll( '.mdf-ca-ficha' ).forEach( function ( ficha ) {
		var tarjeta = ficha.closest( '.mdf-ca-herramienta' );

		ficha.querySelector( '.mdf-ca-ficha__cerrar' ).addEventListener( 'click', function () {
			ficha.close();
		} );

		// El clic en el fondo llega con el propio <dialog> como destino (la
		// caja interior ocupa todo lo visible de la ventana).
		ficha.addEventListener( 'click', function ( e ) {
			if ( e.target === ficha ) {
				ficha.close();
			}
		} );

		// Al cerrar, el foco vuelve al nombre de la tarjeta.
		ficha.addEventListener( 'close', function () {
			var boton = tarjeta && tarjeta.querySelector( '.mdf-ca-herramienta__abrir-ficha' );
			if ( boton ) {
				boton.focus( { preventScroll: true } );
			}
		} );
	} );
} )();
