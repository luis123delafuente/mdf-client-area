/**
 * Documentos MDF (#307): con el tipo Modelo 347 se muestran ejercicio y
 * sociedad emisora y se ocultan la fecha y el nombre (lo fija el servidor);
 * con el resto, al
 * reves. Solo presentacion: el servidor exige y valida los campos.
 */
( function () {
	'use strict';

	var tipo = document.getElementById( 'mdf-ca-documento-tipo' );

	if ( ! tipo ) {
		return;
	}

	function actualizar() {
		var es347 = 'modelo_347' === tipo.value;

		document.querySelectorAll( '[data-mdf-ca-solo-347]' ).forEach( function ( fila ) {
			fila.hidden = ! es347;
		} );

		document.querySelectorAll( '[data-mdf-ca-solo-no-347]' ).forEach( function ( fila ) {
			fila.hidden = es347;
		} );

		// Un campo oculto con required bloquearia el envio del formulario.
		var nombre = document.getElementById( 'mdf-ca-documento-nombre' );

		if ( nombre ) {
			nombre.required = ! es347;
		}
	}

	tipo.addEventListener( 'change', actualizar );
	actualizar();
} )();
