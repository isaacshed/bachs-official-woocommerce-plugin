/**
 * Bachs popup checkout: open the hosted checkout in a modal via bachs.js on the order-pay page.
 * Fulfilment still happens on the server webhook; the client events only drive the UI.
 */
( function () {
	'use strict';

	if ( typeof window.bachs_wc_popup === 'undefined' ) {
		return;
	}

	var params = window.bachs_wc_popup;

	function log() {
		if ( '1' === params.debug && window.console ) {
			// eslint-disable-next-line no-console
			console.log.apply( console, [ 'Bachs popup:' ].concat( [].slice.call( arguments ) ) );
		}
	}

	function setStatus( message ) {
		var el = document.getElementById( 'bachs-wc-status' );
		if ( el ) {
			el.textContent = message || '';
		}
	}

	// The SDK validates checkoutUrl against its baseUrl (which defaults to the live checkout
	// origin). A sandbox session's checkout_url is on a different origin, so derive baseUrl
	// from the checkout_url itself to match in every environment.
	function checkoutOrigin() {
		try {
			return new URL( params.checkout_url ).origin;
		} catch ( e ) {
			return '';
		}
	}

	function openCheckout() {
		if ( typeof window.Bachs === 'undefined' ) {
			log( 'bachs.js not loaded' );
			return;
		}
		setStatus( '' );

		// open() rejects on a bad token or a checkout URL from the wrong origin.
		var opened = window.Bachs.Checkout.open( { checkoutUrl: params.checkout_url } );
		if ( opened && typeof opened.catch === 'function' ) {
			opened.catch( function ( error ) {
				log( 'open failed', error && error.message );
				setStatus( params.i18n.error );
			} );
		}
	}

	function onEvent( event ) {
		log( event.type, event.data );

		switch ( event.type ) {
			case 'checkout.completed':
				// Success screen is the order-received page; the order itself is fulfilled by the webhook.
				window.location.href = params.return_url;
				break;
			case 'checkout.closed':
				setStatus( params.i18n.closed );
				break;
			case 'checkout.failed':
				setStatus( params.i18n.failed );
				break;
			case 'checkout.expired':
				// The stored session can't be reopened; the order-pay form creates a new one.
				setStatus( params.i18n.expired );
				window.location.href = params.retry_url;
				break;
			case 'checkout.error':
				log( 'checkout.error', event.data && event.data.message );
				setStatus( params.i18n.error );
				break;
			default:
				break;
		}
	}

	function init() {
		if ( typeof window.Bachs === 'undefined' ) {
			log( 'bachs.js not available at init' );
			setStatus( params.i18n.blocked );
			return;
		}

		var initOptions = { onEvent: onEvent };
		var origin = checkoutOrigin();
		if ( origin ) {
			initOptions.baseUrl = origin;
		}
		window.Bachs.Initialize( initOptions );

		var button = document.getElementById( 'bachs-wc-pay' );
		if ( button ) {
			button.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				openCheckout();
			} );
		}

		// Auto-open once the page is ready.
		openCheckout();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
