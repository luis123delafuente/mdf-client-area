/**
 * Invitar a todas las farmacias sin cuenta (v0.47.0): pide al servidor una
 * tanda cada pocos segundos hasta que no quede ninguna. El servidor decide
 * a quien y exige el intervalo; aqui solo se espera lo que el diga, se
 * pinta el progreso y se puede parar (la tanda en curso termina).
 */
( function () {
	'use strict';

	const cfg = window.mdfCaInvitacionMasiva;
	const raiz = document.getElementById( 'mdf-ca-invitacion-masiva' );

	if ( ! cfg || ! raiz ) {
		return;
	}

	const $ = ( id ) => document.getElementById( id );
	const btnContinuar = $( 'mdf-ca-im-continuar' );
	const btnParar = $( 'mdf-ca-im-parar' );
	const estado = $( 'mdf-ca-im-estado' );
	const tabla = $( 'mdf-ca-im-incidencias' );
	const acciones = $( 'mdf-ca-im-acciones' );
	const cancelar = $( 'mdf-ca-im-cancelar' );

	let enMarcha = false;
	let temporizador = null;
	let cuentaAtras = null;

	const mostrar = ( el, si ) => {
		if ( ! el ) {
			return;
		}
		el.hidden = ! si;
		el.style.display = si ? '' : 'none';
	};

	const pintarResumen = ( r ) => {
		$( 'mdf-ca-im-enviadas' ).textContent = r.enviadas;
		$( 'mdf-ca-im-fallidas' ).textContent = r.fallidas;
		$( 'mdf-ca-im-omitidas' ).textContent = r.omitidas;
		$( 'mdf-ca-im-pendientes' ).textContent = r.pendientes;
		$( 'mdf-ca-im-barra' ).value = r.total - r.pendientes;
	};

	const anadirIncidencia = ( fila ) => {
		const tr = document.createElement( 'tr' );
		[ fila.farmacia, 'fallida' === fila.estado ? 'Fallida' : 'Omitida', fila.motivo ].forEach( ( texto ) => {
			const td = document.createElement( 'td' );
			td.textContent = texto;
			tr.appendChild( td );
		} );
		tabla.tBodies[ 0 ].appendChild( tr );
		mostrar( tabla, true );
	};

	const ponerEnMarcha = ( si ) => {
		enMarcha = si;
		mostrar( btnContinuar, ! si );
		mostrar( btnParar, si );
		mostrar( cancelar, ! si );
	};

	const parar = ( texto ) => {
		clearTimeout( temporizador );
		clearInterval( cuentaAtras );
		ponerEnMarcha( false );
		if ( btnContinuar ) {
			btnContinuar.textContent = 'Continuar';
		}
		estado.textContent = texto;
	};

	const terminar = () => {
		clearTimeout( temporizador );
		clearInterval( cuentaAtras );
		enMarcha = false;
		mostrar( btnContinuar, false );
		mostrar( btnParar, false );
		mostrar( cancelar, false );
		estado.textContent = 'Envío terminado. Recarga la página para ver el estado de cada farmacia en la tabla.';
		// Las acciones (reintentar, cerrar) dependen del resultado: se recarga.
		window.setTimeout( () => window.location.reload(), 1500 );
	};

	const esperar = ( segundos ) => {
		let quedan = segundos;
		estado.textContent = 'Siguiente tanda en ' + quedan + ' s. Deja esta página abierta.';
		clearInterval( cuentaAtras );
		cuentaAtras = setInterval( () => {
			quedan -= 1;
			if ( quedan > 0 && enMarcha ) {
				estado.textContent = 'Siguiente tanda en ' + quedan + ' s. Deja esta página abierta.';
			}
		}, 1000 );
		temporizador = setTimeout( () => {
			clearInterval( cuentaAtras );
			tanda();
		}, segundos * 1000 );
	};

	const tanda = async () => {
		if ( ! enMarcha ) {
			return;
		}
		estado.textContent = 'Enviando…';

		const cuerpo = new FormData();
		cuerpo.append( 'action', cfg.accion );
		cuerpo.append( '_ajax_nonce', cfg.nonce );

		let datos;
		try {
			const res = await fetch( cfg.ajaxUrl, { method: 'POST', body: cuerpo, credentials: 'same-origin' } );
			datos = await res.json();
		} catch ( e ) {
			// Red o servidor caidos: nada se pierde, se puede continuar.
			parar( 'No se pudo contactar con el servidor. Comprueba la conexión y pulsa «Continuar».' );
			return;
		}

		if ( ! datos || ! datos.success ) {
			parar( ( datos && datos.data && datos.data.mensaje ) || 'El servidor no ha aceptado la petición. Recarga la página.' );
			return;
		}

		const d = datos.data;
		pintarResumen( d.resumen );
		d.procesadas.filter( ( f ) => 'fallida' === f.estado || 'omitida' === f.estado ).forEach( anadirIncidencia );

		if ( 0 === d.resumen.pendientes ) {
			terminar();
			return;
		}

		if ( enMarcha ) {
			esperar( Math.max( 1, d.espera ) );
		} else {
			parar( 'Envío en pausa. Pulsa «Continuar» para seguir.' );
		}
	};

	if ( btnContinuar ) {
		btnContinuar.addEventListener( 'click', () => {
			ponerEnMarcha( true );
			tanda();
		} );
	}

	if ( btnParar ) {
		btnParar.addEventListener( 'click', () => {
			// Si hay una peticion en vuelo, termina esa tanda y se para.
			enMarcha = false;
			parar( 'Envío en pausa. Pulsa «Continuar» para seguir.' );
		} );
	}

	window.addEventListener( 'beforeunload', ( e ) => {
		if ( enMarcha ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	mostrar( acciones, '0' === raiz.dataset.pendientes );
} )();
