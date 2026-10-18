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
					detener( resumen + '. Terminado: recarga la página para ver el detalle.' );
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
