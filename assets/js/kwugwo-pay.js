/**
 * Opens the Kwugwo checkout window on the WooCommerce order-pay page.
 *
 * Config comes from `kwugwoPay` (see Kwugwo_Gateway::enqueue_checkout_assets).
 * This only drives what the customer sees; the order is marked paid by the
 * server after it confirms the payment with Kwugwo.
 */
( function () {
	'use strict';

	var config = window.kwugwoPay || {};
	var i18n = config.i18n || {};

	function start() {
		var button = document.getElementById( 'kwugwo-pay-button' );
		var statusEl = document.getElementById( 'kwugwo-pay-status' );
		var checkout;
		var busy = false;

		function setStatus( message ) {
			if ( statusEl ) {
				statusEl.textContent = message || '';
			}
		}

		function setBusy( value ) {
			busy = value;
			if ( button ) {
				button.disabled = value;
			}
		}

		if ( ! window.KwugwoCheckout || ! config.publicKey || ! config.ugwoUid ) {
			setStatus( i18n.error );
			return;
		}

		try {
			checkout = window.KwugwoCheckout.init( {
				publicKey: config.publicKey,
				baseUrl: config.baseUrl || undefined
			} );
		} catch ( e ) {
			setStatus( i18n.error );
			return;
		}

		function open() {
			if ( busy ) {
				return;
			}
			setBusy( true );
			setStatus( i18n.opening );

			checkout
				.open( {
					ugwoUid: config.ugwoUid,
					returnUrl: config.returnUrl,
					onSuccess: function () {
						setStatus( i18n.success );
					}
				} )
				.then( function ( result ) {
					if ( result && result.type === 'success' ) {
						return;
					}
					setBusy( false );
					setStatus( result && result.type === 'error' ? i18n.error : i18n.closed );
				} );
		}

		if ( button ) {
			button.addEventListener( 'click', open );
		}

		open();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
}() );
