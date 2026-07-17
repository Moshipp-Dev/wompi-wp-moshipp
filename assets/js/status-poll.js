/**
 * Polling del estado del pago en la página de gracias.
 * Consulta el estado vía admin-ajax hasta APPROVED/FAILED o hasta agotar el tiempo.
 */
( function () {
	'use strict';

	var config = window.wompiMpPoll;
	var box = document.getElementById( 'wompi-mp-wait' );
	if ( ! config || ! box ) {
		return;
	}

	var startedAt = Date.now();
	var stopped = false;

	function showExpired() {
		var msg = box.querySelector( '.wompi-mp-wait-msg' );
		var sub = box.querySelector( '.wompi-mp-wait-sub' );
		var spinner = box.querySelector( '.wompi-mp-spinner' );
		if ( spinner ) {
			spinner.style.display = 'none';
		}
		if ( msg ) {
			msg.textContent = config.expiredMsg;
		}
		if ( sub && config.retryUrl ) {
			sub.innerHTML = '';
			var link = document.createElement( 'a' );
			link.className = 'button';
			link.href = config.retryUrl;
			link.textContent = 'Reintentar el pago';
			sub.appendChild( link );
		}
	}

	function poll() {
		if ( stopped ) {
			return;
		}
		if ( Date.now() - startedAt > config.timeout ) {
			stopped = true;
			showExpired();
			return;
		}

		var body = new URLSearchParams();
		body.append( 'action', 'wompi_mp_check_status' );
		body.append( 'nonce', config.nonce );
		body.append( 'order_id', config.orderId );
		body.append( 'order_key', config.orderKey );

		window
			.fetch( config.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString(),
			} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( json ) {
				if ( json && json.success && json.data ) {
					if ( 'PENDING' !== json.data.status && json.data.redirect ) {
						stopped = true;
						window.location.href = json.data.redirect;
						return;
					}
				}
				window.setTimeout( poll, config.interval );
			} )
			.catch( function () {
				window.setTimeout( poll, config.interval );
			} );
	}

	window.setTimeout( poll, config.interval );
} )();
