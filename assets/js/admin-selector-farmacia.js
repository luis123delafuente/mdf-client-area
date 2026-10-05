/**
 * Combobox de farmacia en "Documentos MDF": al hacer clic se despliega la
 * lista completa, y al escribir queda solo lo que encaja con el nombre o el
 * CIF/NIF (sin distinguir mayusculas ni acentos, varias palabras en
 * cualquier orden). El filtro de tipo de entidad acota la lista. Solo es
 * comodidad de interfaz: el servidor sigue validando farmacia_id en
 * Documento_Service.
 *
 * Con el atributo data-mdf-ca-opcional en el contenedor (p. ej. el buscador
 * de "Documentos publicados") elegir farmacia no es obligatorio: se puede
 * enviar sin ninguna, y al vaciar el cuadro se quita la seleccion.
 */
( function () {
	'use strict';

	function normalizar( texto ) {
		return texto
			.normalize( 'NFD' )
			.replace( /[̀-ͯ]/g, '' )
			.toLowerCase();
	}

	function etiqueta( f ) {
		return f.nombre + ' (' + f.cif + ')';
	}

	function iniciar( contenedor ) {
		var input = contenedor.querySelector( '[data-mdf-ca-buscar]' );
		var lista = contenedor.querySelector( '[data-mdf-ca-lista]' );
		var filtroTipo = contenedor.querySelector( '[data-mdf-ca-tipo-entidad]' );
		var campoId = contenedor.querySelector( '[data-mdf-ca-farmacia-id]' );
		var contador = contenedor.querySelector( '[data-mdf-ca-contador]' );
		var datos = contenedor.querySelector( '[data-mdf-ca-farmacias]' );
		if ( ! input || ! lista || ! filtroTipo || ! campoId || ! datos ) {
			return;
		}

		var farmacias = JSON.parse( datos.textContent ).map( function ( f ) {
			f.texto = normalizar( f.nombre + ' ' + f.cif );
			f.nombreNorm = normalizar( f.nombre );
			return f;
		} );
		var seleccionada = null;
		var visibles = [];
		var activa = -1;
		var form = input.form;
		var opcional = contenedor.hasAttribute( 'data-mdf-ca-opcional' );

		// En modo opcional la farmacia puede venir ya elegida desde el servidor
		// (campo oculto con id y cuadro con su nombre): se recupera de la lista.
		if ( opcional && campoId.value ) {
			farmacias.forEach( function ( f ) {
				if ( String( f.id ) === campoId.value ) {
					seleccionada = f;
				}
			} );
		}

		function filtrar( consulta ) {
			var terminos = normalizar( consulta ).split( /\s+/ ).filter( Boolean );
			var tipo = filtroTipo.value;
			var primero = terminos[ 0 ] || '';

			return farmacias
				.filter( function ( f ) {
					return ( ! tipo || f.tipo === tipo ) &&
						terminos.every( function ( t ) { return f.texto.indexOf( t ) !== -1; } );
				} )
				// Primero las que empiezan por lo escrito; el resto mantiene el orden alfabetico.
				.map( function ( f, i ) {
					return { f: f, rango: primero && f.nombreNorm.indexOf( primero ) === 0 ? 0 : 1, i: i };
				} )
				.sort( function ( a, b ) { return a.rango - b.rango || a.i - b.i; } )
				.map( function ( x ) { return x.f; } );
		}

		function pintar() {
			lista.innerHTML = '';

			if ( ! visibles.length ) {
				var vacio = document.createElement( 'li' );
				vacio.className = 'mdf-ca-selector-farmacia__vacio';
				vacio.textContent = 'Ninguna farmacia coincide.';
				lista.appendChild( vacio );
			}

			visibles.forEach( function ( f, i ) {
				var li = document.createElement( 'li' );
				li.className = 'mdf-ca-selector-farmacia__opcion' + ( i === activa ? ' is-activa' : '' );
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', seleccionada && seleccionada.id === f.id ? 'true' : 'false' );
				li.dataset.indice = String( i );
				li.appendChild( document.createTextNode( f.nombre ) );
				var detalle = document.createElement( 'small' );
				detalle.textContent = f.cif + ' - ' + f.tipoEt;
				li.appendChild( detalle );
				lista.appendChild( li );
			} );

			contador.textContent = visibles.length === farmacias.length
				? farmacias.length + ' farmacias'
				: visibles.length + ' de ' + farmacias.length + ' farmacias';
		}

		function abrir( consulta ) {
			visibles = filtrar( consulta );
			activa = visibles.length ? 0 : -1;
			pintar();
			lista.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			asegurarActivaVisible();
		}

		function cerrar() {
			lista.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			// Si quedo texto a medias que no es la seleccion, se restaura.
			input.value = seleccionada ? etiqueta( seleccionada ) : '';
		}

		function seleccionar( f ) {
			seleccionada = f;
			campoId.value = String( f.id );
			input.value = etiqueta( f );
			input.setCustomValidity( '' );
			lista.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
		}

		function asegurarActivaVisible() {
			var el = lista.querySelector( '.is-activa' );
			if ( el && el.scrollIntoView ) {
				el.scrollIntoView( { block: 'nearest' } );
			}
		}

		function moverActiva( delta ) {
			if ( ! visibles.length ) {
				return;
			}
			activa = ( activa + delta + visibles.length ) % visibles.length;
			pintar();
			asegurarActivaVisible();
		}

		// Clic/foco: lista completa (sin filtrar por texto) para poder hojearla.
		input.addEventListener( 'focus', function () {
			input.select();
			abrir( '' );
		} );
		input.addEventListener( 'click', function () {
			if ( lista.hidden ) {
				input.select();
				abrir( '' );
			}
		} );

		// Al escribir, la seleccion previa deja de valer y la lista se filtra en vivo.
		input.addEventListener( 'input', function () {
			seleccionada = null;
			campoId.value = '';
			abrir( input.value );
		} );

		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				e.preventDefault();
				if ( lista.hidden ) {
					abrir( seleccionada ? '' : input.value );
				} else {
					moverActiva( e.key === 'ArrowDown' ? 1 : -1 );
				}
			} else if ( e.key === 'Enter' ) {
				// Nunca envia el formulario desde el buscador.
				e.preventDefault();
				if ( ! lista.hidden && activa >= 0 ) {
					seleccionar( visibles[ activa ] );
				}
			} else if ( e.key === 'Escape' && ! lista.hidden ) {
				e.preventDefault();
				cerrar();
			} else if ( e.key === 'Tab' && ! lista.hidden ) {
				cerrar();
			}
		} );

		// mousedown (no click) para elegir antes de que el input pierda el foco.
		lista.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
			var li = e.target.closest( '[data-indice]' );
			if ( li ) {
				seleccionar( visibles[ Number( li.dataset.indice ) ] );
			}
		} );
		lista.addEventListener( 'mousemove', function ( e ) {
			var li = e.target.closest( '[data-indice]' );
			var i = li ? Number( li.dataset.indice ) : -1;
			if ( i >= 0 && i !== activa ) {
				var anterior = lista.querySelector( '.is-activa' );
				if ( anterior ) {
					anterior.classList.remove( 'is-activa' );
				}
				activa = i;
				li.classList.add( 'is-activa' );
			}
		} );

		input.addEventListener( 'blur', function () {
			if ( ! lista.hidden ) {
				cerrar();
			}
		} );

		filtroTipo.addEventListener( 'change', function () {
			if ( seleccionada && filtroTipo.value && seleccionada.tipo !== filtroTipo.value ) {
				seleccionada = null;
				campoId.value = '';
				input.value = '';
			}
			abrir( seleccionada ? '' : input.value );
			input.focus();
		} );

		// Modo opcional: vaciar el cuadro quita la seleccion.
		if ( opcional ) {
			input.addEventListener( 'input', function () {
				if ( input.value === '' ) {
					seleccionada = null;
					campoId.value = '';
				}
			} );
		}

		if ( form ) {
			form.addEventListener( 'submit', function ( e ) {
				if ( opcional && ! input.value ) {
					campoId.value = '';
					return;
				}

				if ( ! campoId.value ) {
					e.preventDefault();
					input.setCustomValidity( 'Selecciona una farmacia de la lista.' );
					input.reportValidity();
				}
			} );
		}

		contador.textContent = farmacias.length + ' farmacias';
	}

	document.querySelectorAll( '[data-mdf-ca-selector-farmacia]' ).forEach( iniciar );
} )();
