/*
 * Aceptar o rechazar un presupuesto desde el visor (v0.44.0). Solo abre y
 * cierra las ventanas de confirmacion: el envio es un formulario normal
 * (POST con nonce) y todo lo decide el servidor (Presupuesto_Service).
 * Cierra: "Cancelar", Esc (nativo de <dialog>) o un clic fuera.
 */
( function () {
	if ( 'function' !== typeof HTMLDialogElement ) {
		return;
	}

	document.querySelectorAll( '[data-mdf-ca-abrir]' ).forEach( function ( boton ) {
		var dialogo = document.getElementById( boton.getAttribute( 'data-mdf-ca-abrir' ) );

		if ( ! dialogo ) {
			return;
		}

		boton.hidden = false;

		boton.addEventListener( 'click', function () {
			dialogo.showModal();
		} );

		dialogo.querySelectorAll( '[data-mdf-ca-cerrar]' ).forEach( function ( cerrar ) {
			cerrar.addEventListener( 'click', function () {
				dialogo.close();
			} );
		} );

		dialogo.addEventListener( 'click', function ( e ) {
			if ( e.target === dialogo ) {
				dialogo.close();
			}
		} );

		dialogo.addEventListener( 'close', function () {
			boton.focus( { preventScroll: true } );
		} );

		// Un solo envio: el segundo clic no manda otro POST.
		var formulario = dialogo.querySelector( 'form' );

		formulario.addEventListener( 'submit', function () {
			formulario.querySelectorAll( 'button' ).forEach( function ( b ) {
				b.disabled = true;
			} );
		} );
	} );
} )();
