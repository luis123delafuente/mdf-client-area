/**
 * Zona para arrastrar y soltar archivos en las subidas del backoffice (#339).
 * Mejora cada <input type="file" data-mdf-ca-zona-archivos> de la pantalla
 * (atributos en Admin_Zona_Archivos::atributos()): uno o varios archivos
 * segun el atributo multiple del input.
 *
 * - El input sigue en el formulario, oculto a la vista: lo que se suelta pasa
 *   a el con DataTransfer y el envio es el de siempre (mismo nonce, misma
 *   capacidad, misma validacion en el servidor).
 * - Rechaza en el navegador, con un mensaje, los tipos que no admite el accept
 *   del input, lo que supera el tamano maximo, varios archivos en un input de
 *   uno y las carpetas. Es solo comodidad: el servidor valida igual.
 * - Soltar un archivo fuera de la zona no hace que el navegador lo abra.
 * - Teclado: la zona es un boton (Enter o espacio abren el selector); el
 *   resultado se anuncia en una region de estado.
 * - Sin JS, o sin DataTransfer construible (Safari anterior a 14.1), el input
 *   se queda como siempre.
 */
( function () {
	'use strict';

	var entradas = document.querySelectorAll( 'input[type="file"][data-mdf-ca-zona-archivos]' );

	if ( ! entradas.length || ! puedeAsignar() ) {
		return;
	}

	var zonas = [];

	entradas.forEach( function ( input, n ) {
		zonas.push( crearZona( input, n ) );
	} );

	// Un archivo soltado fuera de una zona: sin esto, el navegador lo abriria
	// y se saldria de la pantalla (perdiendo lo escrito en el formulario).
	document.addEventListener( 'dragover', function ( e ) {
		if ( llevaArchivos( e ) && ! dentroDeZona( e.target ) ) {
			e.preventDefault();
			e.dataTransfer.dropEffect = 'none';
		}
	} );

	document.addEventListener( 'drop', function ( e ) {
		if ( llevaArchivos( e ) && ! dentroDeZona( e.target ) ) {
			e.preventDefault();

			if ( 1 === zonas.length ) {
				zonas[ 0 ].error( 'Para subirlo, suelta el archivo dentro del recuadro.' );
			}
		}
	} );

	function puedeAsignar() {
		try {
			return 'function' === typeof window.DataTransfer && !! new window.DataTransfer().items;
		} catch ( e ) {
			return false;
		}
	}

	function llevaArchivos( e ) {
		var tipos = e.dataTransfer ? e.dataTransfer.types : null;

		return !! tipos && Array.prototype.indexOf.call( tipos, 'Files' ) !== -1;
	}

	function dentroDeZona( nodo ) {
		return !! ( nodo && nodo.closest && nodo.closest( '.mdf-ca-zona-archivos__zona' ) );
	}

	function elemento( etiqueta, clase ) {
		var el = document.createElement( etiqueta );

		el.className = clase;

		return el;
	}

	function formatearTamano( bytes ) {
		var unidades = [ 'bytes', 'KB', 'MB', 'GB' ];
		var i = 0;
		var valor = bytes;

		while ( valor >= 1024 && i < unidades.length - 1 ) {
			valor /= 1024;
			i++;
		}

		return ( 0 === i ? String( valor ) : valor.toLocaleString( 'es-ES', { maximumFractionDigits: 1 } ) ) + ' ' + unidades[ i ];
	}

	function crearZona( input, n ) {
		var multiple = input.multiple;
		var tipos = input.getAttribute( 'data-mdf-ca-zona-tipos' ) || '';
		var maxBytes = parseInt( input.getAttribute( 'data-mdf-ca-zona-max-bytes' ), 10 ) || 0;
		var aceptados = ( input.getAttribute( 'accept' ) || '' ).split( ',' ).map( function ( t ) {
			return t.trim().toLowerCase();
		} ).filter( Boolean );
		var base = input.id || 'mdf-ca-zona-archivos-' + n;
		var etiqueta = input.id ? document.querySelector( 'label[for="' + input.id + '"]' ) : null;
		var actuales = [];
		var asignando = false;

		var contenedor = elemento( 'div', 'mdf-ca-zona-archivos' );
		var zona = elemento( 'div', 'mdf-ca-zona-archivos__zona' );
		var icono = elemento( 'span', 'mdf-ca-zona-archivos__icono dashicons dashicons-upload' );
		var texto = elemento( 'span', 'mdf-ca-zona-archivos__texto' );
		var ayuda = elemento( 'span', 'mdf-ca-zona-archivos__ayuda' );
		var lista = elemento( 'ul', 'mdf-ca-zona-archivos__lista' );
		var mensaje = elemento( 'p', 'mdf-ca-zona-archivos__mensaje' );

		texto.id = base + '-texto';
		ayuda.id = base + '-ayuda';
		mensaje.id = base + '-mensaje';
		icono.setAttribute( 'aria-hidden', 'true' );
		ayuda.textContent = [
			tipos ? 'Admite ' + tipos : '',
			maxBytes ? 'hasta ' + formatearTamano( maxBytes ) + ( multiple ? ' cada uno' : '' ) : '',
		].filter( Boolean ).join( ', ' ) + '.';

		if ( etiqueta && ! etiqueta.id ) {
			etiqueta.id = base + '-etiqueta';
		}

		zona.setAttribute( 'role', 'button' );
		zona.tabIndex = 0;
		zona.setAttribute( 'aria-labelledby', ( etiqueta ? etiqueta.id + ' ' : '' ) + texto.id );
		zona.setAttribute( 'aria-describedby', ayuda.id + ' ' + mensaje.id );
		mensaje.setAttribute( 'role', 'status' );
		lista.hidden = true;

		zona.appendChild( icono );
		zona.appendChild( texto );
		zona.appendChild( ayuda );

		input.parentNode.insertBefore( contenedor, input );
		contenedor.appendChild( zona );
		contenedor.appendChild( lista );
		contenedor.appendChild( mensaje );
		contenedor.appendChild( input );

		// El input se queda en el formulario (es el que se envia), fuera de la
		// vista y del orden de tabulacion: la zona hace de control.
		input.classList.add( 'mdf-ca-zona-archivos__input' );
		input.tabIndex = -1;
		input.setAttribute( 'aria-hidden', 'true' );

		pintar();

		zona.addEventListener( 'click', function () {
			input.click();
		} );

		zona.addEventListener( 'keydown', function ( e ) {
			if ( 'Enter' === e.key || ' ' === e.key ) {
				e.preventDefault();
				input.click();
			}
		} );

		zona.addEventListener( 'dragenter', encima );
		zona.addEventListener( 'dragover', encima );

		zona.addEventListener( 'dragleave', function ( e ) {
			if ( ! e.relatedTarget || ! zona.contains( e.relatedTarget ) ) {
				zona.classList.remove( 'mdf-ca-zona-archivos__zona--encima' );
			}
		} );

		zona.addEventListener( 'drop', function ( e ) {
			if ( ! llevaArchivos( e ) ) {
				return;
			}

			e.preventDefault();
			zona.classList.remove( 'mdf-ca-zona-archivos__zona--encima' );
			recibir( Array.prototype.slice.call( e.dataTransfer.files ), hayCarpeta( e.dataTransfer ) );
		} );

		// Lo elegido con el selector pasa por las mismas comprobaciones (el
		// selector deja elegir "Todos los archivos").
		input.addEventListener( 'change', function () {
			if ( ! asignando ) {
				recibir( Array.prototype.slice.call( input.files ), false, true );
			}
		} );

		// Enviar sin archivo: el input oculto no puede mostrar el aviso del
		// navegador, asi que lo dice la zona.
		input.addEventListener( 'invalid', function ( e ) {
			e.preventDefault();
			error( multiple ? 'Elige al menos un archivo antes de enviar.' : 'Elige un archivo antes de enviar.' );
			zona.focus();
		} );

		function encima( e ) {
			if ( llevaArchivos( e ) ) {
				e.preventDefault();
				e.dataTransfer.dropEffect = 'copy';
				zona.classList.add( 'mdf-ca-zona-archivos__zona--encima' );
			}
		}

		// Con la API de entradas (Chrome, Safari, Firefox) una carpeta soltada
		// se distingue de un archivo; hay que mirarlo durante el propio drop.
		function hayCarpeta( dt ) {
			return Array.prototype.some.call( dt.items || [], function ( item ) {
				var entrada = 'file' === item.kind && item.webkitGetAsEntry ? item.webkitGetAsEntry() : null;

				return !! ( entrada && entrada.isDirectory );
			} );
		}

		function motivoRechazo( archivo ) {
			var nombre = archivo.name.toLowerCase();
			var mime = ( archivo.type || '' ).toLowerCase();
			var tipoValido = ! aceptados.length || aceptados.some( function ( a ) {
				if ( '.' === a.charAt( 0 ) ) {
					return nombre.slice( -a.length ) === a;
				}

				return '/*' === a.slice( -2 ) ? 0 === mime.indexOf( a.slice( 0, -1 ) ) : mime === a;
			} );

			// Sin la API de entradas, una carpeta llega como un archivo de 0 bytes.
			if ( 0 === archivo.size ) {
				return '«' + archivo.name + '» está vacío o es una carpeta.';
			}

			if ( ! tipoValido ) {
				return '«' + archivo.name + '» no es de un tipo admitido.' + ( tipos ? ' Se admite: ' + tipos + '.' : '' );
			}

			if ( maxBytes && archivo.size > maxBytes ) {
				return '«' + archivo.name + '» supera el tamaño máximo de ' + formatearTamano( maxBytes ) + '.';
			}

			return '';
		}

		function recibir( archivos, carpeta, desdeSelector ) {
			if ( carpeta ) {
				rechazar( 'No se pueden subir carpetas: arrastra ' + ( multiple ? 'los archivos' : 'el archivo' ) + ', no la carpeta que ' + ( multiple ? 'los' : 'lo' ) + ' contiene.', desdeSelector );
				return;
			}

			if ( ! archivos.length ) {
				// Cancelar el selector deja el input vacio: se respeta lo que hubiera.
				if ( desdeSelector ) {
					asignar( actuales );
				} else {
					error( 'No se ha soltado ningún archivo.' );
				}
				return;
			}

			if ( ! multiple && archivos.length > 1 ) {
				rechazar( 'Aquí se sube un solo archivo cada vez y has soltado ' + archivos.length + '.', desdeSelector );
				return;
			}

			var validos = [];
			var motivos = [];

			archivos.forEach( function ( archivo ) {
				var motivo = motivoRechazo( archivo );

				if ( motivo ) {
					motivos.push( motivo );
				} else {
					validos.push( archivo );
				}
			} );

			if ( ! multiple && motivos.length ) {
				rechazar( motivos[ 0 ], desdeSelector );
				return;
			}

			// Con varios, lo nuevo (soltado o elegido) se suma a lo que ya habia.
			var nuevos = multiple ? sinRepetidos( actuales.concat( validos ) ) : validos;

			asignar( nuevos );

			var texto = validos.length ? describir( validos, multiple ? 'Añadido' : 'Elegido' ) : '';

			if ( motivos.length ) {
				error( ( texto ? texto + ' ' : '' ) + 'No se ' + ( 1 === motivos.length ? 'ha añadido' : 'han añadido' ) + ': ' + motivos.join( ' ' ) );
			} else {
				aviso( texto );
			}
		}

		// Lo rechazado nunca se queda en el input: vuelve lo que habia.
		function rechazar( motivo, desdeSelector ) {
			if ( desdeSelector ) {
				asignar( actuales );
			}

			error( motivo );
		}

		function sinRepetidos( archivos ) {
			var vistos = {};

			return archivos.filter( function ( a ) {
				var clave = a.name + '|' + a.size + '|' + a.lastModified;

				if ( vistos[ clave ] ) {
					return false;
				}

				vistos[ clave ] = true;
				return true;
			} );
		}

		function describir( archivos, verbo ) {
			if ( 1 === archivos.length ) {
				return verbo + ': ' + archivos[ 0 ].name + ' (' + formatearTamano( archivos[ 0 ].size ) + ').';
			}

			return verbo + 's ' + archivos.length + ' archivos.';
		}

		function asignar( archivos ) {
			var dt = new window.DataTransfer();

			archivos.forEach( function ( a ) {
				dt.items.add( a );
			} );

			asignando = true;
			input.files = dt.files;
			// Para quien escuche el input (hoy nadie); la zona ya lo ha comprobado.
			input.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			asignando = false;

			actuales = archivos.slice();
			pintar();
		}

		function quitar( indice ) {
			var quitado = actuales[ indice ];

			asignar( actuales.filter( function ( a, i ) {
				return i !== indice;
			} ) );
			aviso( 'Quitado: ' + quitado.name + '.' + ( actuales.length ? '' : ' No hay ningún archivo elegido.' ) );
			zona.focus();
		}

		function pintar() {
			texto.textContent = multiple
				? 'Arrastra aquí los archivos o haz clic para elegirlos'
				: ( actuales.length ? 'Arrastra aquí otro archivo para cambiarlo o haz clic para elegirlo' : 'Arrastra aquí el archivo o haz clic para elegirlo' );

			zona.classList.toggle( 'mdf-ca-zona-archivos__zona--con-archivo', actuales.length > 0 );
			lista.textContent = '';
			lista.hidden = ! actuales.length;

			actuales.forEach( function ( archivo, i ) {
				var item = elemento( 'li', 'mdf-ca-zona-archivos__archivo' );
				var nombre = elemento( 'span', 'mdf-ca-zona-archivos__nombre' );
				var tamano = elemento( 'span', 'mdf-ca-zona-archivos__tamano' );
				var boton = elemento( 'button', 'button-link mdf-ca-zona-archivos__quitar' );

				nombre.textContent = archivo.name;
				tamano.textContent = formatearTamano( archivo.size );
				boton.type = 'button';
				boton.textContent = 'Quitar';
				boton.setAttribute( 'aria-label', 'Quitar ' + archivo.name );
				boton.addEventListener( 'click', function () {
					quitar( i );
				} );

				item.appendChild( elemento( 'span', 'dashicons dashicons-media-default' ) ).setAttribute( 'aria-hidden', 'true' );
				item.appendChild( nombre );
				item.appendChild( tamano );
				item.appendChild( boton );
				lista.appendChild( item );
			} );
		}

		// La region de estado solo anuncia cambios: se vacia antes por si el
		// mensaje es el mismo que el anterior.
		function anunciar( textoMensaje, esError ) {
			mensaje.textContent = '';
			mensaje.classList.toggle( 'mdf-ca-zona-archivos__mensaje--error', !! esError );
			window.setTimeout( function () {
				mensaje.textContent = textoMensaje;
			}, 50 );
		}

		function aviso( textoMensaje ) {
			anunciar( textoMensaje, false );
		}

		function error( textoMensaje ) {
			anunciar( textoMensaje, true );
		}

		return { error: error };
	}
} )();
