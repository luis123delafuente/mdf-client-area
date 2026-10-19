/**
 * Documentos MDF › Subir facturas (#334). La seleccion la hace la zona de
 * archivos (admin-zona-archivos.js, modo lote); esto sube las facturas de una
 * en una a admin-ajax (accion mdf_ca_subir_factura, con su nonce), con barra
 * de progreso, recuento en vivo, "Parar" / "Continuar" y reintento de las que
 * fallan por conexion o servidor. Volver a subir lo mismo no duplica nada:
 * el servidor responde "ya estaba".
 *
 * El resumen y la lista descargable de no reconocidas (nombre de fichero y
 * motivo) se hacen aqui, en el navegador: el servidor no guarda nada de las
 * no reconocidas.
 */
( function () {
	'use strict';

	var datos = window.mdfCaSubirFacturas;
	var input = document.getElementById( 'mdf-ca-subir-facturas-ficheros' );

	if ( ! datos || ! input ) {
		return;
	}

	function $( id ) {
		return document.getElementById( 'mdf-ca-subir-facturas-' + id );
	}

	var preparado = $( 'preparado' );
	var encontradas = $( 'encontradas' );
	var botonSubir = $( 'subir' );
	var progreso = $( 'progreso' );
	var barra = $( 'barra' );
	var estado = $( 'estado' );
	var botonParar = $( 'parar' );
	var botonContinuar = $( 'continuar' );
	var resumen = $( 'resumen' );
	var tituloResumen = $( 'titulo-resumen' );
	var totales = $( 'totales' );
	var grupos = $( 'grupos' );
	var botonDescargar = $( 'descargar' );
	var enlacePendientes = $( 'pendientes' );
	var botonOtra = $( 'otra' );

	var GRUPOS = [
		{ clave: 'subida', titulo: 'Subidas (pendientes de publicar)', columna: 'Farmacia', abierto: false },
		{ clave: 'repetida', titulo: 'Ya estaban (no se han duplicado)', columna: 'Farmacia', abierto: false },
		{ clave: 'no_reconocida', titulo: 'No reconocidas (no se han guardado)', columna: 'Motivo', abierto: true },
		{ clave: 'error', titulo: 'Con error (vuelve a intentarlo)', columna: 'Motivo', abierto: true },
	];

	/** @type {{archivo: File, resultado: ?{grupo: string, motivo: string, farmacia: ?string}}[]} */
	var items = [];
	var enMarcha = false;
	var parando = false;
	var inicio = 0;

	enlacePendientes.href = datos.urlPendientes;
	mostrar( botonContinuar, false );
	mostrar( botonDescargar, false );

	input.addEventListener( 'change', function () {
		if ( ! enMarcha ) {
			prepararSeleccion();
		}
	} );

	botonSubir.addEventListener( 'click', function () {
		var archivos = Array.prototype.slice.call( input.files );

		if ( ! archivos.length || archivos.length > datos.maxFacturas ) {
			return;
		}

		items = archivos.map( function ( archivo ) {
			return { archivo: archivo, resultado: null };
		} );
		inicio = Date.now();
		resumen.hidden = true;
		preparado.hidden = true;
		arrancar();
	} );

	botonParar.addEventListener( 'click', function () {
		parando = true;
		botonParar.disabled = true;
		botonParar.textContent = 'Parando…';
		anunciar( 'Parando: se termina la factura en curso y no se sube ninguna más.' );
	} );

	botonContinuar.addEventListener( 'click', arrancar );

	botonDescargar.addEventListener( 'click', descargarNoReconocidas );

	botonOtra.addEventListener( 'click', function () {
		items = [];
		resumen.hidden = true;
		progreso.hidden = true;
		input.dispatchEvent( new Event( 'mdf-ca-zona-vaciar' ) );
		prepararSeleccion();
		input.closest( '.mdf-ca-zona-archivos' ).querySelector( '.mdf-ca-zona-archivos__zona' ).focus();
	} );

	window.addEventListener( 'beforeunload', function ( e ) {
		if ( enMarcha ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	// El CSS de wp-admin da display a .button y anula el atributo hidden.
	function mostrar( el, visible ) {
		el.hidden = ! visible;
		el.style.display = visible ? '' : 'none';
	}

	function formatearTamano( bytes ) {
		var mb = bytes / 1048576;

		return mb >= 0.1 ? mb.toLocaleString( 'es-ES', { maximumFractionDigits: 1 } ) + ' MB' : Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB';
	}

	function plural( n, uno, varios ) {
		return n.toLocaleString( 'es-ES' ) + ' ' + ( 1 === n ? uno : varios );
	}

	function prepararSeleccion() {
		var archivos = Array.prototype.slice.call( input.files );
		var bytes = archivos.reduce( function ( suma, a ) {
			return suma + a.size;
		}, 0 );

		preparado.hidden = ! archivos.length;
		botonSubir.disabled = ! archivos.length || archivos.length > datos.maxFacturas;
		encontradas.textContent = archivos.length
			? plural( archivos.length, 'factura encontrada', 'facturas encontradas' ) + ' (' + formatearTamano( bytes ) + ').'
			: '';
	}

	function pendientes() {
		return items.filter( function ( item ) {
			return ! item.resultado;
		} );
	}

	function contar( grupo ) {
		return items.filter( function ( item ) {
			return item.resultado && grupo === item.resultado.grupo;
		} ).length;
	}

	function anunciar( texto ) {
		estado.textContent = texto;
	}

	function pintarProgreso() {
		var hechas = items.length - pendientes().length;

		barra.max = items.length;
		barra.value = hechas;
		anunciar(
			hechas + ' de ' + items.length + ': ' +
			plural( contar( 'subida' ), 'subida', 'subidas' ) + ', ' +
			contar( 'repetida' ) + ' ya estaban, ' +
			plural( contar( 'no_reconocida' ), 'no reconocida', 'no reconocidas' ) +
			( contar( 'error' ) ? ', ' + plural( contar( 'error' ), 'con error', 'con error' ) : '' ) + '.'
		);
	}

	function arrancar() {
		if ( enMarcha ) {
			return;
		}

		enMarcha = true;
		parando = false;
		input.disabled = true;
		progreso.hidden = false;
		mostrar( botonParar, true );
		botonParar.disabled = false;
		botonParar.textContent = 'Parar';
		mostrar( botonContinuar, false );
		pintarProgreso();
		siguiente();
	}

	function siguiente() {
		var cola = pendientes();

		if ( ! cola.length ) {
			terminar( false );
			return;
		}

		if ( parando ) {
			terminar( true );
			return;
		}

		subir( cola[ 0 ] ).then( function ( resultado ) {
			cola[ 0 ].resultado = resultado;
			pintarProgreso();

			if ( resultado.parar ) {
				parando = true;
				terminar( true, resultado.motivo );
				return;
			}

			siguiente();
		} );
	}

	/** Una factura. Un fallo de red o de servidor no corta la subida: queda "con error". */
	function subir( item ) {
		var cuerpo = new FormData();

		cuerpo.append( 'action', datos.accion );
		cuerpo.append( '_ajax_nonce', datos.nonce );
		cuerpo.append( 'factura', item.archivo, item.archivo.name );

		return window.fetch( datos.ajaxUrl, { method: 'POST', body: cuerpo, credentials: 'same-origin' } )
			.then( function ( respuesta ) {
				return respuesta.json().catch( function () {
					return null;
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.grupo ) {
					return { grupo: 'error', motivo: 'no se ha podido subir (respuesta inesperada del servidor); vuelve a intentarlo', farmacia: null, parar: false };
				}

				return json;
			}, function () {
				return { grupo: 'error', motivo: 'no se ha podido subir (sin conexión con el servidor); vuelve a intentarlo', farmacia: null, parar: false };
			} );
	}

	function terminar( parada, motivoParada ) {
		var quedan = pendientes().length;

		enMarcha = false;
		input.disabled = false;
		mostrar( botonParar, false );
		mostrar( botonContinuar, quedan > 0 && ! motivoParada );

		if ( motivoParada ) {
			anunciar( 'Subida detenida: ' + motivoParada );
		} else if ( parada ) {
			anunciar( 'Parado. ' + estado.textContent + ' Quedan ' + quedan + ' por subir: pulsa «Continuar» cuando quieras.' );
		}

		pintarResumen( quedan );
		resumen.hidden = false;

		if ( ! parada ) {
			tituloResumen.focus();
		}
	}

	function pintarResumen( quedan ) {
		var segundos = Math.round( ( Date.now() - inicio ) / 1000 );
		var noReconocidas = contar( 'no_reconocida' );

		totales.textContent = plural( items.length - quedan, 'factura procesada', 'facturas procesadas' ) + ' de ' + items.length +
			' en ' + ( segundos >= 60 ? Math.floor( segundos / 60 ) + ' min ' : '' ) + ( segundos % 60 ) + ' s.' +
			( quedan ? ' Quedan ' + quedan + ' sin subir.' : '' );

		grupos.textContent = '';

		GRUPOS.forEach( function ( g ) {
			var filas = items.filter( function ( item ) {
				return item.resultado && g.clave === item.resultado.grupo;
			} );

			if ( ! filas.length ) {
				return;
			}

			var detalles = document.createElement( 'details' );
			var titulo = document.createElement( 'summary' );
			var tabla = document.createElement( 'table' );
			var cabecera = tabla.createTHead().insertRow();
			var cuerpo = tabla.createTBody();

			detalles.open = g.abierto || filas.length <= 10;
			titulo.innerHTML = '';
			titulo.appendChild( document.createElement( 'strong' ) ).textContent = g.titulo + ': ' + filas.length;
			tabla.className = 'wp-list-table widefat fixed striped';
			tabla.style.maxWidth = '60em';

			[ 'Fichero', g.columna ].forEach( function ( texto ) {
				var th = document.createElement( 'th' );

				th.scope = 'col';
				th.textContent = texto;
				cabecera.appendChild( th );
			} );

			filas.forEach( function ( item ) {
				var fila = cuerpo.insertRow();

				fila.insertCell().textContent = item.archivo.name;
				fila.insertCell().textContent = 'Farmacia' === g.columna ? ( item.resultado.farmacia || '—' ) : item.resultado.motivo;
			} );

			detalles.appendChild( titulo );

			if ( 'no_reconocida' === g.clave ) {
				var ayuda = document.createElement( 'p' );

				ayuda.className = 'description';
				ayuda.textContent = 'No se han guardado. Puedes subirlas a mano desde Documentos MDF, eligiendo la farmacia; descarga la lista para tenerlas a mano.';
				detalles.appendChild( ayuda );
			}

			detalles.appendChild( tabla );

			if ( 'error' === g.clave ) {
				var reintentar = document.createElement( 'button' );

				reintentar.type = 'button';
				reintentar.className = 'button';
				reintentar.textContent = 'Reintentar las ' + filas.length + ' con error';
				reintentar.addEventListener( 'click', function () {
					filas.forEach( function ( item ) {
						item.resultado = null;
					} );
					resumen.hidden = true;
					arrancar();
				} );
				detalles.appendChild( document.createElement( 'p' ) ).appendChild( reintentar );
			}

			grupos.appendChild( detalles );
		} );

		mostrar( botonDescargar, noReconocidas > 0 );
	}

	/** Solo nombre de fichero y motivo, uno por linea. Nada se guarda en el servidor. */
	function descargarNoReconocidas() {
		var hoy = new Date();
		var fecha = hoy.getFullYear() + '-' + String( hoy.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( hoy.getDate() ).padStart( 2, '0' );
		var lineas = items.filter( function ( item ) {
			return item.resultado && 'no_reconocida' === item.resultado.grupo;
		} ).map( function ( item ) {
			return item.archivo.name + '\t' + item.resultado.motivo;
		} );
		var texto = 'Facturas no reconocidas (' + fecha + ')\r\nFichero\tMotivo\r\n' + lineas.join( '\r\n' ) + '\r\n';
		var url = URL.createObjectURL( new Blob( [ '﻿' + texto ], { type: 'text/plain;charset=utf-8' } ) );
		var enlace = document.createElement( 'a' );

		enlace.href = url;
		enlace.download = 'facturas-no-reconocidas-' + fecha + '.txt';
		document.body.appendChild( enlace );
		enlace.click();
		enlace.remove();
		window.setTimeout( function () {
			URL.revokeObjectURL( url );
		}, 1000 );
	}
} )();
