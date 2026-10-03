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
 * - Excel (.xlsx): SheetJS (vendorizado en assets/vendor/sheetjs/) parsea el
 *   libro en el navegador y el visor dibuja la cuadricula en el mismo canvas
 *   que un PDF, con la misma marca de agua: sin texto seleccionable ni
 *   copiable y sin boton de descarga. Igual que con un PDF, los bytes llegan
 *   al navegador via fetch(): un usuario con devtools puede sacarlos de la
 *   pestana Red. Es disuasion y trazabilidad (marca de agua), no DRM.
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
	var elHoja      = document.getElementById( 'mdf-ca-visor-hoja' );
	var elDescargar = document.getElementById( 'mdf-ca-visor-descargar' ); // Solo existe si el servidor permite descargar.
	var ctx         = elCanvas.getContext( '2d' );

	/** @type {import('../vendor/pdfjs/pdf.min.mjs')|null} */
	var pdfjsLib = null;
	var pdfActual = null; // Documento PDF.js ya parseado (estructura, no paginas renderizadas).
	var paginaActual = 1;
	var totalPaginas = 1;
	var renderizando = false;
	var esImagenUnica = false;
	var bitmapImagen = null;

	// Excel: libro de SheetJS ya parseado y la hoja que se esta mostrando.
	// Filas por "pagina" del Excel: tantas como quepan en un canvas seguro. Los
	// navegadores limitan el tamano de un canvas (Chrome ~32.000 px de alto,
	// Safari ~16 millones de pixeles de area), asi que se calcula por hoja a
	// partir del ancho real y se topa en un maximo; casi todos los Excel
	// caben enteros en una sola vista y solo los muy largos se paginan.
	var MAX_FILAS_POR_PAGINA = 300;
	var MAX_AREA_CANVAS      = 12000000;
	var MAX_FILAS        = 2000;
	var MAX_COLUMNAS     = 60;
	var libroActual = null;
	var estilosLibro = null; // { xfs[], rutasHojas[] } leidos del propio .xlsx; null si no se pudieron leer.
	var hojaActual  = null; // { ws, filas, columnas, anchos[], anchoTotal }

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
		elHoja.addEventListener( 'change', function () {
			seleccionarHoja( elHoja.value );
		} );
		elCanvas.addEventListener( 'dragstart', function ( e ) {
			e.preventDefault();
		} );

		var reintentando = false;
		window.addEventListener( 'resize', debounce( function () {
			if ( ! reintentando && hayContenido() ) {
				renderizarPaginaActual();
			}
		}, 200 ) );

		cargarDocumento();
	}

	function cargarDocumento() {
		// Token de un solo uso emitido al renderizar esta pagina (solo si el
		// documento no es descargable). Este es el unico fetch() al endpoint:
		// PDF.js recibe los bytes por { data } y SheetJS se carga del plugin.
		var url = config.token
			? config.endpointUrl + ( config.endpointUrl.indexOf( '?' ) === -1 ? '?' : '&' ) + encodeURIComponent( config.tokenParam ) + '=' + encodeURIComponent( config.token )
			: config.endpointUrl;

		fetch( url, { credentials: 'same-origin' } )
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

				if ( resultado.tipoContenido.indexOf( 'spreadsheetml' ) !== -1 ) {
					return abrirExcel( resultado.buffer );
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
		// El token es de un solo uso y caduca: repetir el fetch() con el
		// mismo fallaria siempre. Recargar la pagina emite uno nuevo (y, si la
		// sesion caduco, lleva al login).
		boton.addEventListener( 'click', function () {
			window.location.reload();
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

	function hayContenido() {
		return !! ( pdfActual || bitmapImagen || hojaActual );
	}

	function cargarSheetJs() {
		if ( window.XLSX ) {
			return Promise.resolve();
		}

		return new Promise( function ( resolver, rechazar ) {
			var script = document.createElement( 'script' );
			script.src = config.sheetjsUrl;
			script.onload = resolver;
			script.onerror = rechazar;
			document.head.appendChild( script );
		} );
	}

	function abrirExcel( buffer ) {
		return cargarSheetJs().then( function () {
			libroActual = window.XLSX.read( new Uint8Array( buffer ), {
				type: 'array',
				bookFiles: true, // Expone los ficheros internos del ZIP: de ahi se leen los estilos.
				cellFormula: false,
				cellStyles: true, // Necesario para leer anchos de columna (!cols).
				sheetRows: MAX_FILAS + 1
			} );

			try {
				estilosLibro = leerEstilosLibro();
			} catch ( e ) {
				estilosLibro = null; // Sin estilos se ve igual, solo que sin colores.
			}

			elHoja.innerHTML = '';
			libroActual.SheetNames.forEach( function ( nombre ) {
				var opcion = document.createElement( 'option' );
				opcion.value = nombre;
				opcion.textContent = nombre;
				elHoja.appendChild( opcion );
			} );
			elHoja.hidden = libroActual.SheetNames.length < 2;
			if ( elDescargar ) {
				elDescargar.hidden = false;
			}
			esImagenUnica = false;
			elBarra.hidden = false;
			ocultarEstado();

			return seleccionarHoja( libroActual.SheetNames[ 0 ] );
		} ).catch( function () {
			mostrarEstado( 'No se pudo procesar el Excel. Puede que el fichero este daniado.' );
		} );
	}

	/* ---------- Estilos de Excel (colores, negrita, cursiva, alineacion) ----------
	 * SheetJS Community no los expone, pero estan en el propio .xlsx: se leen
	 * aqui de xl/styles.xml, del tema y de cada hoja. Es una aproximacion de
	 * lo habitual (fondos, color de texto, negrita, cursiva, alineacion
	 * horizontal y celdas combinadas); no cubre bordes, formato condicional,
	 * degradados ni graficos. Cualquier fallo al leerlos deja el visor
	 * funcionando sin estilos. */

	// Paleta "indexed" estandar de Excel (indices 0-63).
	var PALETA_INDEXADA = [
		'000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF',
		'000000', 'FFFFFF', 'FF0000', '00FF00', '0000FF', 'FFFF00', 'FF00FF', '00FFFF',
		'800000', '008000', '000080', '808000', '800080', '008080', 'C0C0C0', '808080',
		'9999FF', '993366', 'FFFFCC', 'CCFFFF', '660066', 'FF8080', '0066CC', 'CCCCFF',
		'000080', 'FF00FF', 'FFFF00', '00FFFF', '800080', '800000', '008080', '0000FF',
		'00CCFF', 'CCFFFF', 'CCFFCC', 'FFFF99', '99CCFF', 'FF99CC', 'CC99FF', 'FFCC99',
		'3366FF', '33CCCC', '99CC00', 'FFCC00', 'FF9900', 'FF6600', '666699', '969696',
		'003366', '339966', '003300', '333300', '993300', '993366', '333399', '333333'
	];

	// Indice de tema de Excel -> posicion dentro de <a:clrScheme> (Excel intercambia los dos primeros pares).
	var ORDEN_TEMA = [ 1, 0, 3, 2, 4, 5, 6, 7, 8, 9, 10, 11 ];

	function textoDeFichero( ruta ) {
		var f = libroActual.files && libroActual.files[ ruta ];
		return f && f.content ? new TextDecoder( 'utf-8' ).decode( f.content ) : null;
	}

	function parsearXml( texto ) {
		return texto ? new DOMParser().parseFromString( texto, 'application/xml' ) : null;
	}

	function hijos( doc, nombre ) {
		var padre = doc ? doc.getElementsByTagNameNS( '*', nombre )[ 0 ] : null;
		return padre ? Array.prototype.slice.call( padre.children ) : [];
	}

	function hijo( nodo, nombre ) {
		return nodo ? Array.prototype.find.call( nodo.children, function ( h ) { return h.localName === nombre; } ) || null : null;
	}

	function leerTema() {
		var esquema = parsearXml( textoDeFichero( 'xl/theme/theme1.xml' ) );
		var lista = hijos( esquema, 'clrScheme' ).map( function ( color ) {
			var h = color.firstElementChild;
			// sysClr (negro/blanco del sistema) trae val="windowText"; el color real esta en lastClr.
			return h ? ( h.localName === 'sysClr' ? h.getAttribute( 'lastClr' ) : h.getAttribute( 'val' ) ) : null;
		} );
		return ORDEN_TEMA.map( function ( i ) { return lista[ i ] ? '#' + lista[ i ] : null; } );
	}

	function aplicarTinte( hex, tinte ) {
		var r = parseInt( hex.slice( 1, 3 ), 16 ) / 255;
		var g = parseInt( hex.slice( 3, 5 ), 16 ) / 255;
		var b = parseInt( hex.slice( 5, 7 ), 16 ) / 255;
		var max = Math.max( r, g, b ), min = Math.min( r, g, b );
		var l = ( max + min ) / 2, h = 0, sat = 0;

		if ( max !== min ) {
			var d = max - min;
			sat = l > 0.5 ? d / ( 2 - max - min ) : d / ( max + min );
			h = max === r ? ( g - b ) / d + ( g < b ? 6 : 0 ) : ( max === g ? ( b - r ) / d + 2 : ( r - g ) / d + 4 );
			h /= 6;
		}

		l = tinte < 0 ? l * ( 1 + tinte ) : l * ( 1 - tinte ) + tinte;

		function canal( p, q, t ) {
			if ( t < 0 ) { t += 1; }
			if ( t > 1 ) { t -= 1; }
			if ( t < 1 / 6 ) { return p + ( q - p ) * 6 * t; }
			if ( t < 1 / 2 ) { return q; }
			if ( t < 2 / 3 ) { return p + ( q - p ) * ( 2 / 3 - t ) * 6; }
			return p;
		}

		var rr, gg, bb;
		if ( sat === 0 ) {
			rr = gg = bb = l;
		} else {
			var q = l < 0.5 ? l * ( 1 + sat ) : l + sat - l * sat;
			var pp = 2 * l - q;
			rr = canal( pp, q, h + 1 / 3 );
			gg = canal( pp, q, h );
			bb = canal( pp, q, h - 1 / 3 );
		}

		return '#' + [ rr, gg, bb ].map( function ( v ) {
			var n = Math.max( 0, Math.min( 255, Math.round( v * 255 ) ) );
			return ( n < 16 ? '0' : '' ) + n.toString( 16 );
		} ).join( '' );
	}

	function resolverColor( nodo, tema ) {
		if ( ! nodo ) {
			return null;
		}

		var color = null;
		if ( nodo.hasAttribute( 'rgb' ) ) {
			color = '#' + nodo.getAttribute( 'rgb' ).slice( -6 );
		} else if ( nodo.hasAttribute( 'theme' ) ) {
			color = tema[ Number( nodo.getAttribute( 'theme' ) ) ] || null;
		} else if ( nodo.hasAttribute( 'indexed' ) ) {
			var indice = PALETA_INDEXADA[ Number( nodo.getAttribute( 'indexed' ) ) ];
			color = indice ? '#' + indice : null;
		}

		if ( color && ! /^#[0-9a-f]{6}$/i.test( color ) ) {
			return null; // Nunca se pasa al canvas un color que no sea #RRGGBB.
		}

		if ( color && nodo.hasAttribute( 'tint' ) ) {
			color = aplicarTinte( color, parseFloat( nodo.getAttribute( 'tint' ) ) );
		}

		return color;
	}

	/** Estilos del libro: una entrada por <xf> de cellXfs, y la ruta del XML de cada hoja. */
	function leerEstilosLibro() {
		var tema = leerTema();
		var doc = parsearXml( textoDeFichero( 'xl/styles.xml' ) );
		if ( ! doc ) {
			return null;
		}

		var fuentes = hijos( doc, 'fonts' ).map( function ( f ) {
			var negrita = hijo( f, 'b' ), cursiva = hijo( f, 'i' );
			return {
				negrita: !! negrita && negrita.getAttribute( 'val' ) !== '0',
				cursiva: !! cursiva && cursiva.getAttribute( 'val' ) !== '0',
				color: resolverColor( hijo( f, 'color' ), tema )
			};
		} );

		var rellenos = hijos( doc, 'fills' ).map( function ( f ) {
			var patron = hijo( f, 'patternFill' );
			return patron && patron.getAttribute( 'patternType' ) === 'solid' ? resolverColor( hijo( patron, 'fgColor' ), tema ) : null;
		} );

		var xfs = hijos( doc, 'cellXfs' ).map( function ( xf ) {
			var fuente = fuentes[ Number( xf.getAttribute( 'fontId' ) || 0 ) ] || {};
			var alineacion = hijo( xf, 'alignment' );
			return {
				fondo: rellenos[ Number( xf.getAttribute( 'fillId' ) || 0 ) ] || null,
				color: fuente.color || null,
				negrita: !! fuente.negrita,
				cursiva: !! fuente.cursiva,
				horizontal: alineacion ? alineacion.getAttribute( 'horizontal' ) : null
			};
		} );

		// Hoja i (por orden en el libro) -> fichero XML, via workbook.xml + sus rels.
		var rels = {};
		hijos( parsearXml( textoDeFichero( 'xl/_rels/workbook.xml.rels' ) ), 'Relationships' ).forEach( function ( r ) {
			rels[ r.getAttribute( 'Id' ) ] = r.getAttribute( 'Target' );
		} );
		var rutasHojas = hijos( parsearXml( textoDeFichero( 'xl/workbook.xml' ) ), 'sheets' ).map( function ( sh ) {
			var destino = rels[ sh.getAttributeNS( 'http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id' ) ] || '';
			return destino.charAt( 0 ) === '/' ? destino.slice( 1 ) : 'xl/' + destino;
		} );

		return { xfs: xfs, rutasHojas: rutasHojas };
	}

	/** Indice de estilo de cada celda de una hoja: { celdas: {"fila_col": xf}, filas: {fila: xf}, columnas: {col: xf} }. */
	function leerEstilosHoja( indiceHoja ) {
		if ( ! estilosLibro || ! estilosLibro.rutasHojas[ indiceHoja ] ) {
			return null;
		}

		var xml = textoDeFichero( estilosLibro.rutasHojas[ indiceHoja ] );
		if ( ! xml ) {
			return null;
		}

		var xfs = estilosLibro.xfs;
		var utils = window.XLSX.utils;
		var resultado = { celdas: {}, filas: {}, columnas: {} };
		var m, estilo;

		var reCelda = /<c\b([^>]*)>/g;
		while ( ( m = reCelda.exec( xml ) ) !== null ) {
			var ref = /\br="([A-Z]+)(\d+)"/.exec( m[ 1 ] );
			estilo = /\bs="(\d+)"/.exec( m[ 1 ] );
			if ( ref && estilo && xfs[ Number( estilo[ 1 ] ) ] ) {
				resultado.celdas[ ( Number( ref[ 2 ] ) - 1 ) + '_' + utils.decode_col( ref[ 1 ] ) ] = xfs[ Number( estilo[ 1 ] ) ];
			}
		}

		var reFila = /<row\b([^>]*)>/g;
		while ( ( m = reFila.exec( xml ) ) !== null ) {
			var fila = /\br="(\d+)"/.exec( m[ 1 ] );
			estilo = /\bs="(\d+)"/.exec( m[ 1 ] );
			if ( fila && estilo && /customFormat="(1|true)"/.test( m[ 1 ] ) && xfs[ Number( estilo[ 1 ] ) ] ) {
				resultado.filas[ Number( fila[ 1 ] ) - 1 ] = xfs[ Number( estilo[ 1 ] ) ];
			}
		}

		var reCol = /<col\b([^>]*)>/g;
		while ( ( m = reCol.exec( xml ) ) !== null ) {
			var min = /\bmin="(\d+)"/.exec( m[ 1 ] );
			var max = /\bmax="(\d+)"/.exec( m[ 1 ] );
			estilo = /\bstyle="(\d+)"/.exec( m[ 1 ] );
			if ( min && max && estilo && xfs[ Number( estilo[ 1 ] ) ] ) {
				for ( var c = Number( min[ 1 ] ) - 1; c < Math.min( Number( max[ 1 ] ), MAX_COLUMNAS ); c++ ) {
					resultado.columnas[ c ] = xfs[ Number( estilo[ 1 ] ) ];
				}
			}
		}

		return resultado;
	}

	function estiloDeCelda( h, fila, columna ) {
		if ( ! h.estilos ) {
			return null;
		}
		return h.estilos.celdas[ fila + '_' + columna ] || h.estilos.filas[ fila ] || h.estilos.columnas[ columna ] || null;
	}

	function seleccionarHoja( nombre ) {
		var ws = libroActual.Sheets[ nombre ];
		var rango = ws && ws[ '!ref' ] ? window.XLSX.utils.decode_range( ws[ '!ref' ] ) : { s: { r: 0, c: 0 }, e: { r: 0, c: 0 } };
		var filas = Math.min( rango.e.r + 1, MAX_FILAS );
		var columnas = Math.min( rango.e.c + 1, MAX_COLUMNAS );
		var anchos = [];
		var anchoTotal = 0;

		for ( var c = 0; c < columnas; c++ ) {
			var col = ws && ws[ '!cols' ] ? ws[ '!cols' ][ c ] : null;
			var ancho = col && col.hidden ? 0 : ( col && col.wpx ? col.wpx : ( col && col.wch ? col.wch * 7 + 5 : 90 ) );
			ancho = Math.min( ancho, 400 );
			anchos.push( ancho );
			anchoTotal += ancho;
		}

		var estilosHoja = null;
		try {
			estilosHoja = leerEstilosHoja( libroActual.SheetNames.indexOf( nombre ) );
		} catch ( e ) {
			estilosHoja = null;
		}

		hojaActual = { ws: ws, filas: filas, columnas: columnas, anchos: anchos, anchoTotal: anchoTotal, estilos: estilosHoja };
		var anchoCanvas = Math.max( 800, 56 + anchoTotal );
		var filasPagina = Math.max( 40, Math.min( MAX_FILAS_POR_PAGINA, Math.floor( ( MAX_AREA_CANVAS / anchoCanvas - 24 ) / 24 ) ) );
		hojaActual.filasPagina = filasPagina;
		totalPaginas = Math.max( 1, Math.ceil( filas / filasPagina ) );
		paginaActual = 1;
		elHoja.value = nombre;

		return renderizarPaginaActual();
	}

	function renderizarHojaActual() {
		var h = hojaActual;
		var inicio = ( paginaActual - 1 ) * h.filasPagina;
		var fin = Math.min( inicio + h.filasPagina, h.filas );
		var altoCab = 24;
		var altoFila = 24;
		var anchoNum = 56;
		var utils = window.XLSX.utils;

		// Minimos para que la marca de agua (proporcional al ancho) se lea
		// entera aunque la hoja sea minuscula.
		elCanvas.width = Math.max( 800, anchoNum + h.anchoTotal );
		elCanvas.height = Math.max( 240, altoCab + ( fin - inicio ) * altoFila );

		ctx.fillStyle = '#ffffff';
		ctx.fillRect( 0, 0, elCanvas.width, elCanvas.height );
		ctx.font = '13px sans-serif';
		ctx.textBaseline = 'middle';
		ctx.strokeStyle = '#d0d3d6';
		ctx.lineWidth = 1;

		// Cabeceras de columna y numeros de fila, como en Excel.
		ctx.fillStyle = '#f1f3f4';
		ctx.fillRect( 0, 0, elCanvas.width, altoCab );
		ctx.fillRect( 0, 0, anchoNum, elCanvas.height );
		ctx.fillStyle = '#444444';
		ctx.textAlign = 'center';

		var x = anchoNum;
		var posiciones = [];
		for ( var c = 0; c < h.columnas; c++ ) {
			posiciones.push( x );
			ctx.fillText( utils.encode_col( c ), x + h.anchos[ c ] / 2, altoCab / 2 );
			x += h.anchos[ c ];
		}

		// Numeros de fila.
		ctx.fillStyle = '#444444';
		for ( var rn = inicio; rn < fin; rn++ ) {
			ctx.fillText( String( rn + 1 ), anchoNum / 2, altoCab + ( rn - inicio ) * altoFila + altoFila / 2 );
		}

		// Cuadricula primero: las celdas con fondo la tapan, como hace Excel.
		ctx.beginPath();
		for ( var i = 0; i <= fin - inicio; i++ ) {
			ctx.moveTo( 0, altoCab + i * altoFila + 0.5 );
			ctx.lineTo( elCanvas.width, altoCab + i * altoFila + 0.5 );
		}
		ctx.moveTo( 0, 0.5 );
		ctx.lineTo( elCanvas.width, 0.5 );
		ctx.moveTo( anchoNum + 0.5, 0 );
		ctx.lineTo( anchoNum + 0.5, elCanvas.height );
		for ( var k = 0; k < h.columnas; k++ ) {
			ctx.moveTo( posiciones[ k ] + h.anchos[ k ] + 0.5, 0 );
			ctx.lineTo( posiciones[ k ] + h.anchos[ k ] + 0.5, elCanvas.height );
		}
		ctx.stroke();

		// Celdas combinadas: la combinada se pinta como un unico rectangulo desde su celda superior izquierda.
		var combinadas = {};
		var cubiertas = {};
		( ( h.ws && h.ws[ '!merges' ] ) || [] ).forEach( function ( mr ) {
			combinadas[ mr.s.r + '_' + mr.s.c ] = mr;
			for ( var fr = mr.s.r; fr <= mr.e.r; fr++ ) {
				for ( var cr = mr.s.c; cr <= mr.e.c; cr++ ) {
					if ( fr !== mr.s.r || cr !== mr.s.c ) {
						cubiertas[ fr + '_' + cr ] = true;
					}
				}
			}
		} );

		for ( var r = inicio; r < fin; r++ ) {
			var y = altoCab + ( r - inicio ) * altoFila;

			for ( var cc = 0; cc < h.columnas; cc++ ) {
				if ( h.anchos[ cc ] === 0 || cubiertas[ r + '_' + cc ] ) {
					continue;
				}

				var estilo = estiloDeCelda( h, r, cc );
				var celda = h.ws ? h.ws[ utils.encode_cell( { r: r, c: cc } ) ] : null;
				var ancho = h.anchos[ cc ];
				var alto = altoFila;
				var mr = combinadas[ r + '_' + cc ];

				if ( mr ) {
					ancho = 0;
					for ( var cm = mr.s.c; cm <= Math.min( mr.e.c, h.columnas - 1 ); cm++ ) {
						ancho += h.anchos[ cm ];
					}
					alto = ( Math.min( mr.e.r, fin - 1 ) - r + 1 ) * altoFila;
				}

				if ( estilo && estilo.fondo ) {
					ctx.fillStyle = estilo.fondo;
					ctx.fillRect( posiciones[ cc ] + 1, y + 1, ancho - 1, alto - 1 );
				}

				if ( ! celda ) {
					continue;
				}

				var texto = celda.w !== undefined ? celda.w : ( celda.v !== undefined && celda.v !== null ? String( celda.v ) : '' );
				if ( texto === '' ) {
					continue;
				}

				var horizontal = estilo && estilo.horizontal && estilo.horizontal !== 'general' ? estilo.horizontal : ( celda.t === 'n' ? 'right' : 'left' );
				var posX = posiciones[ cc ] + 6;
				if ( horizontal === 'right' ) {
					posX = posiciones[ cc ] + ancho - 6;
				} else if ( horizontal === 'center' || horizontal === 'centerContinuous' ) {
					posX = posiciones[ cc ] + ancho / 2;
				}

				ctx.save();
				ctx.beginPath();
				ctx.rect( posiciones[ cc ], y, ancho, alto );
				ctx.clip();
				ctx.font = ( estilo && estilo.cursiva ? 'italic ' : '' ) + ( estilo && estilo.negrita ? 'bold ' : '' ) + '13px sans-serif';
				ctx.fillStyle = ( estilo && estilo.color ) || '#111111';
				ctx.textAlign = horizontal === 'right' ? 'right' : ( horizontal === 'center' || horizontal === 'centerContinuous' ? 'center' : 'left' );
				ctx.fillText( texto, posX, y + alto / 2 );
				ctx.restore();
			}
		}

		return Promise.resolve();
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

		var promesa;
		if ( hojaActual ) {
			promesa = renderizarHojaActual();
		} else {
			promesa = esImagenUnica ? renderizarImagenActual() : renderizarPaginaPdf( paginaActual );
		}

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
		elPagina.textContent = totalPaginas > 1 ? ( ( hojaActual ? 'Filas ' + ( ( paginaActual - 1 ) * hojaActual.filasPagina + 1 ) + '-' + Math.min( paginaActual * hojaActual.filasPagina, hojaActual.filas ) + ' (pagina ' : 'Pagina ' ) + paginaActual + ' de ' + totalPaginas + ( hojaActual ? ')' : '' ) ) : '';
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
			if ( hayContenido() ) {
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
