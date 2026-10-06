/**
 * Kwugwo settings page: show the fields for the selected mode, copy the
 * webhook URL, and check keys with Kwugwo before saving.
 */
( function () {
	'use strict';

	var config = window.kwugwoAdmin || {};
	var i18n = config.i18n || {};
	var prefix = 'woocommerce_kwugwo_';

	function byId( id ) {
		return document.getElementById( id );
	}

	/**
	 * Show only the Sandbox or Live fields.
	 */
	function showMode( mode ) {
		document.querySelectorAll( '.kwugwo-env' ).forEach( function ( node ) {
			var display = node.classList.contains( 'kwugwo-env-' + mode ) ? '' : 'none';
			var target = node;

			if ( node.tagName === 'H3' ) {
				// Section titles: hide the heading and its description.
				if ( node.nextElementSibling && node.nextElementSibling.tagName === 'P' ) {
					node.nextElementSibling.style.display = display;
				}
			} else if ( node.tagName !== 'TR' ) {
				target = node.closest( 'tr' ) || node;
			}
			target.style.display = display;
		} );
	}

	function setupModeToggle() {
		var select = byId( prefix + 'mode' );
		if ( ! select ) {
			return;
		}
		showMode( select.value );
		select.addEventListener( 'change', function () {
			showMode( select.value );
		} );
	}

	function setupCopy() {
		document.querySelectorAll( '.kwugwo-copy__button' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var input = byId( button.getAttribute( 'data-copy-target' ) );
				var done = function () {
					button.textContent = i18n.copied;
					setTimeout( function () {
						button.textContent = i18n.copy;
					}, 2000 );
				};

				if ( ! input ) {
					return;
				}
				if ( navigator.clipboard && window.isSecureContext ) {
					navigator.clipboard.writeText( input.value ).then( done );
				} else {
					input.select();
					document.execCommand( 'copy' );
					done();
				}
			} );
		} );
	}

	/**
	 * Refill the checkout picker with the checkouts Kwugwo returned.
	 */
	function updateCheckouts( mode, checkouts ) {
		var select = byId( prefix + mode + '_checkout' );
		var current;

		if ( ! select || select.tagName !== 'SELECT' ) {
			return;
		}
		current = select.value;
		select.innerHTML = '';
		select.appendChild( new Option( i18n.automatic, '' ) );
		Object.keys( checkouts ).forEach( function ( uid ) {
			select.appendChild( new Option( checkouts[ uid ], uid, false, uid === current ) );
		} );
	}

	function setupTestButtons() {
		document.querySelectorAll( '.kwugwo-test-connection' ).forEach( function ( button ) {
			var result = button.parentNode.querySelector( '.kwugwo-test-result' );

			button.addEventListener( 'click', function () {
				var mode = button.getAttribute( 'data-mode' );
				var body = new FormData();
				var publicKey = byId( prefix + mode + '_public_key' );
				var secretKey = byId( prefix + mode + '_secret_key' );

				body.append( 'action', 'kwugwo_wc_test_connection' );
				body.append( 'nonce', config.nonce );
				body.append( 'mode', mode );
				body.append( 'public_key', publicKey ? publicKey.value : '' );
				body.append( 'secret_key', secretKey ? secretKey.value : '' );

				button.disabled = true;
				result.className = 'kwugwo-test-result';
				result.textContent = i18n.testing;

				fetch( config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
					.then( function ( response ) {
						return response.json();
					} )
					.then( function ( response ) {
						var data = response.data || {};
						result.className = 'kwugwo-test-result ' + ( response.success ? 'is-success' : 'is-error' );
						result.textContent = data.message || i18n.failed;
						if ( response.success && data.checkouts ) {
							updateCheckouts( mode, data.checkouts );
						}
					} )
					.catch( function () {
						result.className = 'kwugwo-test-result is-error';
						result.textContent = i18n.failed;
					} )
					.then( function () {
						button.disabled = false;
					} );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		setupModeToggle();
		setupCopy();
		setupTestButtons();
	} );
}() );
