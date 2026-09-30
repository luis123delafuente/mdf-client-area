/**
 * Buscador del desplegable de farmacia en "Documentos MDF". Filtra por
 * nombre o CIF/NIF (sin distinguir mayusculas ni acentos) y por tipo de
 * entidad. Solo es comodidad de interfaz: el servidor sigue validando
 * farmacia_id en Documento_Service, asi que sin JS el select funciona igual.
 */
( function () {
	'use strict';

	function normalizar( texto ) {
		return texto
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase();
	}

	function iniciar( contenedor ) {
		var buscador = contenedor.querySelector( '[data-mdf-ca-buscar]' );
		var filtroTipo = contenedor.querySelector( '[data-mdf-ca-tipo-entidad]' );
		var select = contenedor.querySelector( '[data-mdf-ca-farmacia]' );
		var contador = contenedor.querySelector( '[data-mdf-ca-contador]' );
		if ( ! buscador || ! filtroTipo || ! select ) {
			return;
		}

		var placeholder = select.options[ 0 ];
		var farmacias = Array.prototype.slice.call( select.options, 1 ).map( function ( option ) {
			return {
				option: option,
				tipo: option.getAttribute( 'data-tipo' ),
				texto: normalizar( option.getAttribute( 'data-busqueda' ) || '' ),
			};
		} );

		function filtrar() {
			var terminos = normalizar( buscador.value ).split( /\s+/ ).filter( Boolean );
			var tipo = filtroTipo.value;
			var seleccionada = select.value;
			var visibles = farmacias.filter( function ( f ) {
				return ( ! tipo || f.tipo === tipo ) &&
					terminos.every( function ( t ) { return f.texto.indexOf( t ) !== -1; } );
			} );

			select.innerHTML = '';
			select.appendChild( placeholder );
			visibles.forEach( function ( f ) {
				select.appendChild( f.option );
			} );
			select.value = visibles.some( function ( f ) { return f.option.value === seleccionada; } ) ? seleccionada : '';

			if ( contador ) {
				contador.textContent = visibles.length === farmacias.length
					? farmacias.length + ' farmacias'
					: visibles.length + ' de ' + farmacias.length + ' farmacias';
			}
			return visibles;
		}

		buscador.addEventListener( 'input', filtrar );
		filtroTipo.addEventListener( 'change', filtrar );

		// Enter en el buscador: selecciona si solo queda una coincidencia, y nunca envia el formulario.
		buscador.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' ) {
				return;
			}
			e.preventDefault();
			var visibles = filtrar();
			if ( visibles.length === 1 ) {
				select.value = visibles[ 0 ].option.value;
			}
		} );

		// Con una unica coincidencia mientras se escribe, se preselecciona.
		buscador.addEventListener( 'input', function () {
			if ( buscador.value.trim() !== '' && select.options.length === 2 ) {
				select.selectedIndex = 1;
			}
		} );

		filtrar();
	}

	document.querySelectorAll( '[data-mdf-ca-selector-farmacia]' ).forEach( iniciar );
} )();
