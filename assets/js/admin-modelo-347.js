/**
 * Modelo 347 (#307): tandas por AJAX.
 * - Generacion: pide tandas seguidas hasta que el servidor dice "terminado".
 * - Envio: pide una tanda, espera los segundos del ajuste y repite mientras
 *   queden pendientes; se puede parar. El servidor tambien impone el ritmo
 *   (responde "espera" si se le llama antes de tiempo) y reclama cada carta
 *   antes de enviarla, asi que dos pestanas no duplican envios.
 * Todo el texto se pinta con textContent: nada del servidor se interpreta como HTML.
 */
( function () {
	'use strict';

	function post( url, datos ) {
		var cuerpo = new URLSearchParams();

		Object.keys( datos ).forEach( function ( k ) {
			cuerpo.append( k, datos[ k ] );
		} );

		return fetch( url, { method: 'POST', credentials: 'same-origin', body: cuerpo } ).then( function ( r ) {
			return r.json().catch( function () {
				return { success: false, data: { mensaje: 'Respuesta no válida del servidor (' + r.status + ').' } };
			} );
		} );
	}

	// ---------------------------------------------------------------
	// Generacion
	// ---------------------------------------------------------------
	var gen = document.querySelector( '[data-mdf-ca-347-generacion]' );

	if ( gen ) {
		var genTexto = gen.querySelector( '[data-mdf-ca-progreso]' );
		var genBarra = gen.querySelector( '[data-mdf-ca-barra]' );
		var genErrores = gen.querySelector( '[data-mdf-ca-errores]' );
		var genFin = gen.querySelector( '[data-mdf-ca-fin]' );

		gen.querySelector( '[data-mdf-ca-enlace-envios]' ).href = gen.dataset.envios;

		var siguiente = function () {
			post( gen.dataset.ajax, { action: 'mdf_ca_347_generar_tanda', _ajax_nonce: gen.dataset.nonce, job: gen.dataset.job } ).then( function ( res ) {
				if ( ! res.success ) {
					genTexto.textContent = res.data && res.data.mensaje ? res.data.mensaje : 'Error al generar.';
					return;
				}

				var d = res.data;
				genBarra.max = d.total;
				genBarra.value = d.hechas;
				genTexto.textContent = d.hechas + ' de ' + d.total + ' procesadas · ' + d.creadas + ' creadas · ' + d.omitidas + ' ya existían · ' + d.errores.length + ' con error';

				genErrores.textContent = '';
				d.errores.forEach( function ( e ) {
					var li = document.createElement( 'li' );
					li.textContent = e;
					genErrores.appendChild( li );
				} );

				if ( d.terminado ) {
					genFin.hidden = false;
				} else {
					siguiente();
				}
			} ).catch( function () {
				genTexto.textContent = 'Se ha perdido la conexión. Recarga la página: se continúa donde se quedó (y lo ya creado no se duplica).';
			} );
		};

		siguiente();
	}

	// ---------------------------------------------------------------
	// Buscador de filas de la simulacion
	// ---------------------------------------------------------------
	// Filtra en el navegador las tablas ya pintadas: ninguna peticion, el
	// termino no va al servidor ni a la URL. Solo cambia lo que se ve; los
	// recuentos, totales, vista previa y ZIP siguen siendo del fichero entero.
	var buscador = document.querySelector( '[data-mdf-ca-347-buscador]' );

	if ( buscador ) {
		var termino = buscador.querySelector( '[data-mdf-ca-347-termino]' );
		var limpiar = buscador.querySelector( '[data-mdf-ca-347-limpiar]' );
		var resultado = document.querySelector( '[data-mdf-ca-347-resultado]' );

		// Sin tildes ni mayusculas: "martinez" encuentra "Martínez" y "MARTINEZ".
		var normalizar = function ( texto ) {
			return String( texto ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).toLowerCase().replace( /\s+/g, ' ' ).trim();
		};

		// Identificador fiscal: ademas sin espacios, guiones ni puntos.
		var normalizarId = function ( texto ) {
			return normalizar( texto ).replace( /[\s.\-]/g, '' );
		};

		var tablas = Array.prototype.map.call( document.querySelectorAll( '[data-mdf-ca-347-tabla]' ), function ( tabla ) {
			return {
				nodo: tabla,
				abierta: tabla.open,
				recuento: tabla.querySelector( '[data-mdf-ca-347-recuento]' ),
				vacia: tabla.querySelector( '[data-mdf-ca-347-vacia]' ),
				filas: Array.prototype.map.call( tabla.querySelectorAll( '[data-mdf-ca-347-fila]' ), function ( fila ) {
					return { nodo: fila, nombre: normalizar( fila.dataset.nombre || '' ), nif: normalizarId( fila.dataset.nif || '' ) };
				} )
			};
		} );

		var totalFilas = tablas.reduce( function ( n, t ) {
			return n + t.filas.length;
		}, 0 );

		var aplicar = function () {
			var q = normalizar( termino.value );
			var qId = normalizarId( termino.value );
			var coinciden = 0;

			tablas.forEach( function ( t ) {
				var n = 0;

				t.filas.forEach( function ( f ) {
					var visible = '' === q || ( f.nombre.indexOf( q ) !== -1 ) || ( '' !== qId && f.nif.indexOf( qId ) !== -1 );

					f.nodo.hidden = ! visible;
					n += visible ? 1 : 0;
				} );

				coinciden += n;

				if ( '' === q ) {
					t.recuento.textContent = '(' + t.filas.length + ')';
					t.vacia.hidden = true;
					t.nodo.open = t.abierta;
				} else {
					t.recuento.textContent = '(' + n + ' de ' + t.filas.length + ')';
					t.vacia.hidden = n > 0;
					t.nodo.open = n > 0;
				}
			} );

			limpiar.hidden = '' === q;
			// Ademas de hidden, display: el CSS de wp-admin da display:block a
			// los h2 y anula el atributo hidden.
			resultado.hidden = '' === q;
			resultado.style.display = '' === q ? 'none' : '';
			resultado.textContent = '' === q
				? ''
				: ( 0 === coinciden ? 'Ninguna fila con estos filtros' : 'Filas que coinciden: ' + coinciden + ' de ' + totalFilas );
		};

		buscador.hidden = false;

		buscador.addEventListener( 'submit', function ( e ) {
			e.preventDefault(); // nunca se envia: filtra aqui mismo
			aplicar();
		} );

		termino.addEventListener( 'input', aplicar );

		limpiar.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			termino.value = '';
			aplicar();
			termino.focus();
		} );
	}

	// ---------------------------------------------------------------
	// Envio
	// ---------------------------------------------------------------
	var env = document.querySelector( '[data-mdf-ca-347-envio]' );

	if ( env ) {
		var envTexto = env.querySelector( '[data-mdf-ca-progreso]' );
		var iniciar = env.querySelector( '[data-mdf-ca-iniciar]' );
		var parar = env.querySelector( '[data-mdf-ca-parar]' );
		var segundos = parseInt( env.dataset.segundos, 10 ) || 30;
		var activo = false;
		var temporizador = null;
		var totales = { enviados: 0, fallidos: 0, cancelados: 0 };

		var detener = function ( mensaje ) {
			activo = false;
			clearTimeout( temporizador );
			iniciar.hidden = false;
			parar.hidden = true;

			if ( mensaje ) {
				envTexto.textContent = mensaje;
			}
		};

		var tanda = function () {
			if ( ! activo ) {
				return;
			}

			post( env.dataset.ajax, {
				action: 'mdf_ca_347_enviar_tanda',
				_ajax_nonce: env.dataset.nonce,
				emisor: env.dataset.emisor,
				ejercicio: env.dataset.ejercicio
			} ).then( function ( res ) {
				if ( ! res.success ) {
					detener( res.data && res.data.mensaje ? res.data.mensaje : 'Error al enviar.' );
					return;
				}

				var d = res.data;
				totales.enviados += d.enviados;
				totales.fallidos += d.fallidos;
				totales.cancelados += d.cancelados;

				var resumen = 'Enviados: ' + totales.enviados + ' · Fallidos: ' + totales.fallidos + ' · Cancelados: ' + totales.cancelados + ' · Quedan: ' + d.quedan;

				if ( 0 === d.quedan ) {
					detener( resumen + '. Terminado: recarga la página para ver el detalle.' + ( totales.fallidos > 0 ? ' ' + totales.fallidos + ' fallidos: ver el motivo en la tabla.' : '' ) );
					return;
				}

				var espera = d.espera > 0 ? d.espera : segundos;
				envTexto.textContent = resumen + '. Siguiente tanda en ' + espera + ' s…';
				temporizador = setTimeout( tanda, espera * 1000 );
			} ).catch( function () {
				detener( 'Se ha perdido la conexión. Vuelve a pulsar «Enviar pendientes»: lo ya enviado no se repite.' );
			} );
		};

		iniciar.addEventListener( 'click', function () {
			activo = true;
			iniciar.hidden = true;
			parar.hidden = false;
			envTexto.textContent = 'Enviando…';
			tanda();
		} );

		parar.addEventListener( 'click', function () {
			detener( 'Parado. Lo que quede sigue en cola.' );
		} );
	}
} )();
