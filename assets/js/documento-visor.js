/**
 * Logica del visor de documentos (#248). Sin build step ni dependencias de
 * npm en este plugin: se carga como <script type="module"> directo desde
 * Documento_Visor, con PDF.js vendorizado en assets/vendor/pdfjs/ (ver su
 * cabecera para el motivo). Nada de esto decide si el usuario puede ver el
 * documento -- eso lo sigue resolviendo Documento_Endpoint via Permissions
 * cuando se hace el fetch() de mas abajo; este fichero solo pinta lo que el
 * endpoint ya decidio servir.
 *
 * Resumen de lo que impide y lo que NO impide, para no prometer de mas:
 * - Bloquea el menu contextual (botón derecho) y Ctrl/Cmd+P, Ctrl/Cmd+S via
 *   keydown: funciona para el atajo de teclado en Chrome/Firefox/Edge con
 *   la pagina enfocada. NO bloquea el menu del navegador (File > Imprimir,
 *   "Guardar pagina como" desde el menu hamburguesa): un preventDefault()
 *   de JS no puede interceptar eso, no es un fallo de esta implementacion,
 *   es una limitacion de la plataforma web.
 * - beforeprint vacia el canvas antes de que se abra cualquier dialogo de
 *   impresion (venga del atajo o del menu): defensa real y fiable, a
 *   diferencia del bloqueo de atajos.
 * - La marca de agua se pinta en los mismos pixeles del canvas (no como
 *   capa CSS aparte), asi que cualquier copia que se consiga sacar del
 *   contenido (captura de pantalla, "guardar imagen" si alguien desactiva
 *   JS antes de que cargue el bloqueo de menu contextual, etc.) se lleva el
 *   CIF y nombre de la farmacia grabados encima. No es una barrera
 *   infranqueable -- es una marca de agua, no un DRM -- pero cualquier
 *   copia que salga es trazable.
 * - PrintScreen (captura de pantalla del sistema operativo) no se puede
 *   bloquear desde una pagina web, con ninguna tecnica: ni esta ni ninguna
 *   otra lo consigue. No se intenta.
 */
( function () {
	'use strict';

	var config = window.MDF_CA_VISOR || {};

	var elLienzo    = document.getElementById( 'mdf-ca-visor-lienzo' );
	var elCanvas    = document.getElementById( 'mdf-ca-visor-canvas' );
	var elEstado    = document.getElementById( 'mdf-ca-visor-estado' );
	var elBarra     = document.querySelector( '.mdf-ca-visor__barra' );
	var elAnterior  = document.getElementById( 'mdf-ca-visor-anterior' );
	var elSiguiente = document.getElementById( 'mdf-ca-visor-siguiente' );
	var elPagina    = document.getElementById( 'mdf-ca-visor-pagina' );
	var ctx         = elCanvas.getContext( '2d' );

	/** @type {import('../vendor/pdfjs/pdf.min.mjs')|null} */
	var pdfjsLib = null;
	var pdfActual = null; // Documento PDF.js ya parseado (estructura, no paginas renderizadas).
	var paginaActual = 1;
	var totalPaginas = 1;
	var renderizando = false;
	var esImagenUnica = false;
	var bitmapImagen = null;

	iniciar();

	function iniciar() {
		elBarra.hidden = true;
		mostrarEstado( 'Cargando documento...' );
		bloquearMenuContextual();
		bloquearAtajosTeclado();
		blanquearAlImprimir();

		elAnterior.addEventListener( 'click', function () {
			irAPagina( paginaActual - 1 );
		} );
		elSiguiente.addEventListener( 'click', function () {
			irAPagina( paginaActual + 1 );
		} );
		elCanvas.addEventListener( 'dragstart', function ( e ) {
			e.preventDefault();
		} );

		var reintentando = false;
		window.addEventListener( 'resize', debounce( function () {
			if ( ! reintentando && ( pdfActual || bitmapImagen ) ) {
				renderizarPaginaActual();
			}
		}, 200 ) );

		cargarDocumento();
	}

	function cargarDocumento() {
		fetch( config.endpointUrl, { credentials: 'same-origin' } )
			.then( function ( respuesta ) {
				if ( ! respuesta.ok ) {
					// Documento_Endpoint responde siempre 404 para sesion
					// caducada, documento ajeno o documento inexistente --
					// un solo mensaje generico aqui, sin distinguir el
					// motivo (mismo criterio de no dar pistas que ya usa el
					// propio endpoint).
					mostrarEstado(
						'No se puede mostrar el documento. Puede que ya no este disponible o que tu sesion haya caducado.',
						construirEnlaceReintento()
					);
					return null;
				}

				var tipoContenido = respuesta.headers.get( 'Content-Type' ) || '';
				return respuesta.arrayBuffer().then( function ( buffer ) {
					return { buffer: buffer, tipoContenido: tipoContenido };
				} );
			} )
			.then( function ( resultado ) {
				if ( ! resultado ) {
					return;
				}

				if ( resultado.tipoContenido.indexOf( 'pdf' ) !== -1 ) {
					return abrirPdf( resultado.buffer );
				}

				if ( resultado.tipoContenido.indexOf( 'image/' ) === 0 ) {
					return abrirImagen( resultado.buffer, resultado.tipoContenido );
				}

				mostrarEstado( 'Este tipo de documento no se puede visualizar aqui.' );
			} )
			.catch( function () {
				// Fallo de red a mitad de la carga (conexion caida, etc.),
				// no un rechazo de permiso -- distinto del caso !respuesta.ok
				// de arriba, mismo mensaje de reintentar.
				mostrarEstado( 'No se pudo cargar el documento. Comprueba tu conexion.', construirEnlaceReintento() );
			} );
	}

	function construirEnlaceReintento() {
		var boton = document.createElement( 'button' );
		boton.type = 'button';
		boton.className = 'mdf-ca-visor__boton';
		boton.textContent = 'Reintentar';
		boton.style.marginTop = '12px';
		boton.addEventListener( 'click', function () {
			ocultarEstado();
			elBarra.hidden = true;
			mostrarEstado( 'Cargando documento...' );
			cargarDocumento();
		} );
		return boton;
	}

	/**
	 * Carga el documento PDF completo en memoria de una vez (el fetch ya
	 * trajo todos los bytes), pero PDF.js solo parsea la ESTRUCTURA del
	 * documento aqui -- no rasteriza ninguna pagina todavia. El rasterizado
	 * a canvas (lo caro en CPU/memoria) pasa unicamente en
	 * renderizarPaginaActual(), una pagina cada vez, bajo demanda al
	 * navegar. Es la forma de no reventar memoria/tiempo con un documento
	 * de muchas paginas sin tener que hacer una peticion de red separada
	 * (y autenticada) por cada pagina.
	 */
	function abrirPdf( buffer ) {
		return cargarPdfJs().then( function () {
			return pdfjsLib.getDocument( { data: buffer } ).promise;
		} ).then( function ( pdf ) {
			pdfActual = pdf;
			totalPaginas = pdf.numPages;
			paginaActual = 1;
			esImagenUnica = false;
			elBarra.hidden = false;
			ocultarEstado();
			return renderizarPaginaActual();
		} ).catch( function () {
			mostrarEstado( 'No se pudo procesar el PDF. Puede que el fichero este daniado.' );
		} );
	}

	function cargarPdfJs() {
		if ( pdfjsLib ) {
			return Promise.resolve();
		}

		return import( config.pdfjsBase + '/pdf.min.mjs' ).then( function ( modulo ) {
			pdfjsLib = modulo;
			pdfjsLib.GlobalWorkerOptions.workerSrc = config.pdfjsBase + '/pdf.worker.min.mjs';
		} );
	}

	function abrirImagen( buffer, tipoContenido ) {
		var blob = new Blob( [ buffer ], { type: tipoContenido } );

		return createImageBitmap( blob ).then( function ( bitmap ) {
			bitmapImagen = bitmap;
			esImagenUnica = true;
			totalPaginas = 1;
			paginaActual = 1;
			elBarra.hidden = true; // Una sola "pagina", no hace falta paginacion.
			ocultarEstado();
			return renderizarPaginaActual();
		} ).catch( function () {
			mostrarEstado( 'No se pudo procesar la imagen. Puede que el fichero este daniado.' );
		} );
	}

	function irAPagina( numero ) {
		if ( renderizando || numero < 1 || numero > totalPaginas ) {
			return;
		}

		paginaActual = numero;
		renderizarPaginaActual();
	}

	function renderizarPaginaActual() {
		if ( renderizando ) {
			return Promise.resolve();
		}

		renderizando = true;
		actualizarControles();

		var promesa = esImagenUnica ? renderizarImagenActual() : renderizarPaginaPdf( paginaActual );

		return promesa.then( function () {
			dibujarMarcaDeAgua();
			renderizando = false;
			actualizarControles();
		} ).catch( function () {
			renderizando = false;
			mostrarEstado( 'No se pudo renderizar el documento.' );
		} );
	}

	function renderizarImagenActual() {
		var anchoDisponible = Math.min( elLienzo.clientWidth || 900, 1400 );
		var escala          = Math.min( anchoDisponible / bitmapImagen.width, 2 );

		elCanvas.width  = bitmapImagen.width * escala;
		elCanvas.height = bitmapImagen.height * escala;

		ctx.clearRect( 0, 0, elCanvas.width, elCanvas.height );
		ctx.drawImage( bitmapImagen, 0, 0, elCanvas.width, elCanvas.height );

		return Promise.resolve();
	}

	function renderizarPaginaPdf( numeroPagina ) {
		return pdfActual.getPage( numeroPagina ).then( function ( pagina ) {
			var viewportBase = pagina.getViewport( { scale: 1 } );
			var anchoDisponible = Math.min( elLienzo.clientWidth || 900, 1400 );
			var escala = Math.min( Math.max( anchoDisponible / viewportBase.width, 0.5 ), 2 );
			var viewport = pagina.getViewport( { scale: escala } );

			elCanvas.width  = viewport.width;
			elCanvas.height = viewport.height;

			return pagina.render( { canvasContext: ctx, viewport: viewport } ).promise;
		} );
	}

	/**
	 * Se pinta DESPUES del contenido, sobre el mismo canvas: forma parte de
	 * los mismos pixeles que la pagina/imagen, no es una capa CSS separada
	 * que se pueda ocultar con devtools sin perder tambien el documento.
	 * Repetida en diagonal para que no haya ningun hueco grande sin marca
	 * util para recortar.
	 *
	 * El paso del patron (pasoX/pasoY) ya no es una constante fija: se mide
	 * con ctx.measureText() el ancho real que ocupa el texto (CIF + nombre
	 * de farmacia, longitud variable segun la farmacia) con la fuente ya
	 * aplicada al contexto, y se le suma un margen proporcional a ese ancho
	 * -- asi dos repeticiones consecutivas de una misma fila nunca se tocan
	 * ni se cruzan, tanto con nombres cortos como largos. El paso vertical
	 * sigue el mismo criterio, proporcional al tamanio de fuente en vez de
	 * a una fraccion fija del canvas.
	 */
	function dibujarMarcaDeAgua() {
		var texto = config.marcaAgua;

		if ( ! texto ) {
			return;
		}

		var ancho = elCanvas.width;
		var alto  = elCanvas.height;
		var tamanioFuente = Math.max( 14, Math.round( ancho / 40 ) );

		ctx.save();
		ctx.globalAlpha = 0.15;
		ctx.fillStyle = '#000000';
		ctx.font = tamanioFuente + 'px sans-serif';
		ctx.textAlign = 'center';
		ctx.textBaseline = 'middle';

		ctx.translate( ancho / 2, alto / 2 );
		ctx.rotate( -Math.PI / 6 );
		ctx.translate( -ancho / 2, -alto / 2 );

		var anchoTexto = ctx.measureText( texto ).width;
		var pasoX = anchoTexto * 2.3;
		var pasoY = tamanioFuente * 5.5;
		var margen = Math.max( ancho, alto );

		for ( var y = -margen; y < alto + margen; y += pasoY ) {
			for ( var x = -margen; x < ancho + margen; x += pasoX ) {
				ctx.fillText( texto, x, y );
			}
		}

		ctx.restore();
	}

	function actualizarControles() {
		elAnterior.disabled = renderizando || paginaActual <= 1;
		elSiguiente.disabled = renderizando || paginaActual >= totalPaginas;
		elPagina.textContent = totalPaginas > 1 ? ( 'Pagina ' + paginaActual + ' de ' + totalPaginas ) : '';
	}

	function mostrarEstado( mensaje, elementoExtra ) {
		elEstado.textContent = mensaje;
		elEstado.hidden = false;
		elLienzo.hidden = true;

		if ( elementoExtra ) {
			elEstado.appendChild( document.createElement( 'br' ) );
			elEstado.appendChild( elementoExtra );
		}
	}

	function ocultarEstado() {
		elEstado.hidden = true;
		elEstado.textContent = '';
		elLienzo.hidden = false;
	}

	function bloquearMenuContextual() {
		document.addEventListener( 'contextmenu', function ( e ) {
			e.preventDefault();
		} );
	}

	/**
	 * Bloquea el atajo de teclado, no el menu del navegador (ver cabecera
	 * del fichero). e.key llega en minuscula en los navegadores relevantes
	 * al usarse junto a Ctrl/Cmd, pero se normaliza con toLowerCase() por
	 * si acaso.
	 */
	function bloquearAtajosTeclado() {
		document.addEventListener( 'keydown', function ( e ) {
			var tecla = ( e.key || '' ).toLowerCase();
			var esImprimir = ( e.ctrlKey || e.metaKey ) && tecla === 'p';
			var esGuardar   = ( e.ctrlKey || e.metaKey ) && tecla === 's';

			if ( esImprimir || esGuardar ) {
				e.preventDefault();
			}
		} );
	}

	/**
	 * Defensa real (a diferencia del bloqueo de atajos): si se llega a
	 * abrir un dialogo de impresion por cualquier via (atajo bloqueado
	 * arriba, pero tambien el menu del navegador, que no se puede
	 * interceptar), el canvas se vacia justo antes de que el navegador
	 * capture su contenido para la vista previa de impresion, y se
	 * restaura despues.
	 */
	function blanquearAlImprimir() {
		window.addEventListener( 'beforeprint', function () {
			ctx.save();
			ctx.fillStyle = '#ffffff';
			ctx.fillRect( 0, 0, elCanvas.width, elCanvas.height );
			ctx.fillStyle = '#000000';
			ctx.font = '20px sans-serif';
			ctx.textAlign = 'center';
			ctx.textBaseline = 'middle';
			ctx.fillText( 'Documento no disponible para impresion', elCanvas.width / 2, elCanvas.height / 2 );
			ctx.restore();
		} );

		window.addEventListener( 'afterprint', function () {
			if ( pdfActual || bitmapImagen ) {
				renderizarPaginaActual();
			}
		} );
	}

	function debounce( fn, espera ) {
		var temporizador = null;
		return function () {
			var args = arguments;
			clearTimeout( temporizador );
			temporizador = setTimeout( function () {
				fn.apply( null, args );
			}, espera );
		};
	}
} )();
