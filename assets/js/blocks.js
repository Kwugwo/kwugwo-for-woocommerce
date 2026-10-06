/**
 * Registers Kwugwo in the WooCommerce Cart & Checkout blocks.
 *
 * Payment happens on the server (process_payment), which sends the customer
 * to the order-pay page where the Kwugwo window opens. This file only shows
 * the option at checkout.
 */
( function ( wc, wp ) {
	'use strict';

	if ( ! wc || ! wc.wcBlocksRegistry || ! wc.wcSettings || ! wp || ! wp.element ) {
		return;
	}

	var el = wp.element.createElement;
	var decode = wp.htmlEntities ? wp.htmlEntities.decodeEntities : function ( s ) {
		return s;
	};
	var data = wc.wcSettings.getSetting( 'kwugwo_data', {} );
	var title = decode( data.title || 'Kwugwo' );

	function Label() {
		return el(
			'span',
			{ className: 'kwugwo-blocks-label', style: { display: 'flex', alignItems: 'center', gap: '8px', width: '100%' } },
			el( 'span', null, title ),
			data.icon ? el( 'img', { src: data.icon, alt: '', style: { height: '24px', marginLeft: 'auto' } } ) : null
		);
	}

	function Content() {
		return el(
			'div',
			{ className: 'kwugwo-blocks-description' },
			decode( data.description || '' ),
			data.testMode ? el( 'p', { className: 'kwugwo-test-mode' }, data.testModeText ) : null
		);
	}

	wc.wcBlocksRegistry.registerPaymentMethod( {
		name: 'kwugwo',
		label: el( Label ),
		content: el( Content ),
		edit: el( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: data.supports || [ 'products' ]
		}
	} );
}( window.wc, window.wp ) );
