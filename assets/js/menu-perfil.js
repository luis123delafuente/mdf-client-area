( function () {
	var menu = document.querySelector( '[data-mdf-ca-menu-perfil]' );
	if ( ! menu ) {
		return;
	}

	var toggle = menu.querySelector( '.mdf-ca-menu-perfil__toggle' );
	var panel = menu.querySelector( '.mdf-ca-menu-perfil__panel' );

	function setOpen( open ) {
		toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		panel.hidden = ! open;
	}

	toggle.addEventListener( 'click', function () {
		setOpen( panel.hidden );
	} );

	document.addEventListener( 'click', function ( e ) {
		if ( ! menu.contains( e.target ) ) {
			setOpen( false );
		}
	} );

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key && ! panel.hidden ) {
			setOpen( false );
			toggle.focus();
		}
	} );
} )();
