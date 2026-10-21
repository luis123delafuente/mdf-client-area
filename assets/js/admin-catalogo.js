/**
 * Formulario de una herramienta del catalogo (Catalogo MDF > Herramientas).
 * Solo comodidad de interfaz: el servidor (Catalogo_Service) valida todo y
 * fuerza la configuracion de Word, PowerPoint y .xlsm aunque llegue otra
 * cosa. Sin JS el formulario se ve entero y funciona igual.
 *
 * - Recurso: muestra el bloque del archivo o el del enlace; con un enlace se
 *   oculta la configuracion.
 * - Configuracion: con un .docx, .pptx o .xlsm (el elegido o, si no se elige
 *   otro, el actual) "Descargable" queda marcada y fija, "Marca de agua"
 *   desmarcada y fija, y se explica por que.
 * - Visualizacion: "Solo para farmacias concretas" muestra el selector
 *   (modo multiple de admin-selector-farmacia.js) y se avisa si no se ha
 *   marcado ningun plan ni ninguna farmacia.
 * - Etiquetas: las casillas pasan a un cuadro con autocompletado y fichas.
 *   Solo se eligen de la lista: no se crean desde aqui.
 */
( function () {
	'use strict';

	var SIEMPRE_DESCARGABLES = [ 'docx', 'pptx', 'xlsm' ];

	var form = document.querySelector( '[data-mdf-ca-herramienta-form]' );
	if ( ! form ) {
		return;
	}

	function todos( selector ) {
		return Array.prototype.slice.call( form.querySelectorAll( selector ) );
	}

	function normalizar( texto ) {
		return texto.normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase();
	}

	/* ---------- Recurso y configuracion ---------- */

	var radiosRecurso = todos( '[data-mdf-ca-recurso]' );
	var bloqueArchivo = form.querySelector( '[data-mdf-ca-bloque-archivo]' );
	var bloqueEnlace = form.querySelector( '[data-mdf-ca-bloque-enlace]' );
	var filaConfig = form.querySelector( '[data-mdf-ca-bloque-config]' );
	var inputArchivo = form.querySelector( 'input[type="file"][name="archivo"]' );
	var inputEnlace = form.querySelector( 'input[name="enlace_url"]' );
	var casillaDescargable = form.querySelector( '[data-mdf-ca-descargable]' );
	var casillaMarca = form.querySelector( '[data-mdf-ca-marca]' );
	var notaForzada = form.querySelector( '[data-mdf-ca-config-forzada]' );
	var avisoXlsm = form.querySelector( '[data-mdf-ca-aviso-xlsm]' );
	var formatoActual = form.getAttribute( 'data-formato-actual' ) || '';
	// Lo que el usuario tenia marcado antes de que un formato lo forzara.
	var previo = { descargable: casillaDescargable.checked, marca: casillaMarca.checked };
	var forzado = false;

	function recursoElegido() {
		var marcado = radiosRecurso.filter( function ( r ) { return r.checked; } )[ 0 ];
		return marcado ? marcado.value : 'archivo';
	}

	function formatoElegido() {
		if ( inputArchivo && inputArchivo.files && inputArchivo.files.length ) {
			var nombre = inputArchivo.files[ 0 ].name.toLowerCase();
			var punto = nombre.lastIndexOf( '.' );
			var ext = punto === -1 ? '' : nombre.slice( punto + 1 );
			return ext === 'jpeg' ? 'jpg' : ext;
		}
		return formatoActual;
	}

	function actualizarRecurso() {
		var esArchivo = recursoElegido() === 'archivo';
		var formato = formatoElegido();
		var fijo = esArchivo && SIEMPRE_DESCARGABLES.indexOf( formato ) !== -1;

		bloqueArchivo.hidden = ! esArchivo;
		bloqueEnlace.hidden = esArchivo;
		filaConfig.hidden = ! esArchivo;
		if ( inputEnlace ) {
			inputEnlace.required = ! esArchivo;
		}

		if ( fijo && ! forzado ) {
			previo = { descargable: casillaDescargable.checked, marca: casillaMarca.checked };
			casillaDescargable.checked = true;
			casillaMarca.checked = false;
		} else if ( ! fijo && forzado ) {
			casillaDescargable.checked = previo.descargable;
			casillaMarca.checked = previo.marca;
		}

		forzado = fijo;
		casillaDescargable.disabled = fijo;
		casillaMarca.disabled = fijo;
		notaForzada.hidden = ! fijo;

		if ( avisoXlsm ) {
			avisoXlsm.hidden = ! ( esArchivo && formato === 'xlsm' && inputArchivo.files && inputArchivo.files.length );
		}
	}

	radiosRecurso.forEach( function ( r ) {
		r.addEventListener( 'change', actualizarRecurso );
	} );
	if ( inputArchivo ) {
		inputArchivo.addEventListener( 'change', actualizarRecurso );
	}
	actualizarRecurso();

	/* ---------- Visualizacion ---------- */

	var casillasPlan = todos( '[data-mdf-ca-plan]' );
	var casillaSolo = form.querySelector( '[data-mdf-ca-solo-farmacias]' );
	var bloqueFarmacias = form.querySelector( '[data-mdf-ca-bloque-farmacias]' );
	var avisoNadie = form.querySelector( '[data-mdf-ca-aviso-nadie]' );

	function hayFarmacias() {
		return casillaSolo.checked && form.querySelectorAll( 'input[name="farmacia_ids[]"]:checked' ).length > 0;
	}

	function actualizarVisibilidad() {
		bloqueFarmacias.hidden = ! casillaSolo.checked;
		var hayPlan = casillasPlan.some( function ( c ) { return c.checked; } );
		avisoNadie.hidden = hayPlan || hayFarmacias();
	}

	casillasPlan.forEach( function ( c ) {
		c.addEventListener( 'change', actualizarVisibilidad );
	} );
	casillaSolo.addEventListener( 'change', actualizarVisibilidad );
	form.addEventListener( 'mdf-ca-farmacias-cambiadas', actualizarVisibilidad );
	actualizarVisibilidad();

	/* ---------- Etiquetas ---------- */

	var fieldsetEtiquetas = form.querySelector( '[data-mdf-ca-etiquetas]' );
	if ( fieldsetEtiquetas ) {
		iniciarEtiquetas( fieldsetEtiquetas );
	}

	function iniciarEtiquetas( fieldset ) {
		var casillas = Array.prototype.slice.call( fieldset.querySelectorAll( 'input[name="etiqueta_ids[]"]' ) );
		var contenedorCasillas = fieldset.querySelector( '[data-mdf-ca-etiquetas-casillas]' );
		var idLista = 'mdf-ca-etiquetas-lista';

		var combo = document.createElement( 'div' );
		combo.className = 'mdf-ca-etiquetas__combo';

		var fichas = document.createElement( 'ul' );
		fichas.className = 'mdf-ca-etiquetas__fichas';
		fichas.setAttribute( 'aria-label', 'Etiquetas elegidas' );

		var input = document.createElement( 'input' );
		input.type = 'text';
		input.className = 'regular-text';
		input.placeholder = 'Escribe para buscar una etiqueta';
		input.setAttribute( 'role', 'combobox' );
		input.setAttribute( 'aria-autocomplete', 'list' );
		input.setAttribute( 'aria-expanded', 'false' );
		input.setAttribute( 'aria-controls', idLista );
		input.setAttribute( 'aria-label', 'Añadir etiqueta' );
		input.autocomplete = 'off';

		var lista = document.createElement( 'ul' );
		lista.id = idLista;
		lista.className = 'mdf-ca-etiquetas__lista';
		lista.setAttribute( 'role', 'listbox' );
		lista.hidden = true;

		combo.appendChild( fichas );
		combo.appendChild( input );
		combo.appendChild( lista );
		fieldset.appendChild( combo );
		contenedorCasillas.hidden = true;

		var visibles = [];
		var activa = -1;

		function pintarFichas() {
			fichas.innerHTML = '';
			casillas.filter( function ( c ) { return c.checked; } ).forEach( function ( c ) {
				var li = document.createElement( 'li' );
				li.className = 'mdf-ca-etiquetas__ficha';
				li.appendChild( document.createTextNode( c.getAttribute( 'data-nombre' ) + ' ' ) );
				var quitar = document.createElement( 'button' );
				quitar.type = 'button';
				quitar.className = 'mdf-ca-etiquetas__quitar';
				quitar.setAttribute( 'aria-label', 'Quitar la etiqueta ' + c.getAttribute( 'data-nombre' ) );
				quitar.textContent = '×';
				quitar.addEventListener( 'click', function () {
					c.checked = false;
					pintarFichas();
					input.focus();
				} );
				li.appendChild( quitar );
				fichas.appendChild( li );
			} );
		}

		function abrir() {
			var terminos = normalizar( input.value ).split( /\s+/ ).filter( Boolean );
			visibles = casillas.filter( function ( c ) {
				var nombre = normalizar( c.getAttribute( 'data-nombre' ) );
				return ! c.checked && terminos.every( function ( t ) { return nombre.indexOf( t ) !== -1; } );
			} );
			activa = visibles.length ? 0 : -1;
			pintarLista();
			lista.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
		}

		function cerrar() {
			lista.hidden = true;
			input.setAttribute( 'aria-expanded', 'false' );
			input.removeAttribute( 'aria-activedescendant' );
		}

		function pintarLista() {
			lista.innerHTML = '';
			if ( ! visibles.length ) {
				var vacio = document.createElement( 'li' );
				vacio.className = 'mdf-ca-etiquetas__vacio';
				vacio.textContent = 'Ninguna etiqueta coincide.';
				lista.appendChild( vacio );
				return;
			}
			visibles.forEach( function ( c, i ) {
				var li = document.createElement( 'li' );
				li.id = idLista + '-' + i;
				li.className = 'mdf-ca-etiquetas__opcion' + ( i === activa ? ' is-activa' : '' );
				li.setAttribute( 'role', 'option' );
				li.setAttribute( 'aria-selected', i === activa ? 'true' : 'false' );
				li.dataset.indice = String( i );
				li.textContent = c.getAttribute( 'data-nombre' );
				lista.appendChild( li );
			} );
			if ( activa >= 0 ) {
				input.setAttribute( 'aria-activedescendant', idLista + '-' + activa );
			}
		}

		function elegir( c ) {
			c.checked = true;
			input.value = '';
			pintarFichas();
			abrir();
		}

		input.addEventListener( 'focus', abrir );
		input.addEventListener( 'input', abrir );
		input.addEventListener( 'blur', cerrar );
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				e.preventDefault();
				if ( lista.hidden ) {
					abrir();
				} else if ( visibles.length ) {
					activa = ( activa + ( e.key === 'ArrowDown' ? 1 : -1 ) + visibles.length ) % visibles.length;
					pintarLista();
				}
			} else if ( e.key === 'Enter' ) {
				// Nunca envia el formulario desde aqui ni crea una etiqueta nueva.
				e.preventDefault();
				if ( ! lista.hidden && activa >= 0 ) {
					elegir( visibles[ activa ] );
				}
			} else if ( e.key === 'Escape' ) {
				cerrar();
			} else if ( e.key === 'Backspace' && input.value === '' ) {
				var marcadas = casillas.filter( function ( c ) { return c.checked; } );
				if ( marcadas.length ) {
					marcadas[ marcadas.length - 1 ].checked = false;
					pintarFichas();
				}
			}
		} );
		lista.addEventListener( 'mousedown', function ( e ) {
			e.preventDefault();
			var li = e.target.closest( '[data-indice]' );
			if ( li ) {
				elegir( visibles[ Number( li.dataset.indice ) ] );
			}
		} );

		pintarFichas();
	}
} )();
