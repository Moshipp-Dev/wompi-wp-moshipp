/**
 * Ajustes del gateway: copiar URL del webhook y verificar credenciales.
 */
( function () {
	'use strict';

	var config = window.wompiMpAdmin || {};

	/* Copiar la URL del webhook */
	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '[data-wompi-copy]' );
		if ( ! btn ) {
			return;
		}
		event.preventDefault();
		var text = btn.getAttribute( 'data-wompi-copy' );
		navigator.clipboard.writeText( text ).then( function () {
			var done = btn.parentNode.querySelector( '.wompi-mp-copy-done' );
			if ( done ) {
				done.style.display = 'inline';
				window.setTimeout( function () {
					done.style.display = 'none';
				}, 2000 );
			}
		} );
	} );

	/* Verificar credenciales contra el API de Wompi */
	document.addEventListener( 'click', function ( event ) {
		var btn = event.target.closest( '#wompi-mp-check' );
		if ( ! btn ) {
			return;
		}
		event.preventDefault();

		var result = document.getElementById( 'wompi-mp-check-result' );
		btn.disabled = true;
		if ( result ) {
			result.textContent = config.checking || '…';
		}

		var body = new URLSearchParams();
		body.append( 'action', 'wompi_mp_check_credentials' );
		body.append( 'nonce', config.nonce || '' );

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( json ) {
				btn.disabled = false;
				if ( ! result ) {
					return;
				}
				if ( ! json || ! json.success ) {
					result.innerHTML = '<span class="fail">' + ( config.error || 'Error' ) + '</span>';
					return;
				}
				var parts = [];
				[ 'test', 'prod' ].forEach( function ( env ) {
					var info = json.data[ env ];
					if ( ! info ) {
						return;
					}
					var label = 'test' === env ? 'Sandbox' : 'Producción';
					if ( info.ok ) {
						parts.push( '<span class="ok">✓ ' + label + ':</span> ' + info.name );
					} else {
						parts.push( '<span class="fail">✗ ' + label + ':</span> ' + info.message );
					}
				} );
				result.innerHTML = parts.join( '<br>' ) || ( config.noKeys || '' );
			} )
			.catch( function () {
				btn.disabled = false;
				if ( result ) {
					result.innerHTML = '<span class="fail">' + ( config.error || 'Error' ) + '</span>';
				}
			} );
	} );
} )();
