/**
 * Bachs gateway settings: show sandbox keys in test mode and live keys otherwise.
 */
jQuery( function ( $ ) {
	'use strict';

	var $testmode = $( '#woocommerce_bachs_testmode' );

	function toggleKeyFields() {
		var isTest = $testmode.is( ':checked' );
		$( '.bachs-wc-test-field' ).closest( 'tr' ).toggle( isTest );
		$( '.bachs-wc-live-field' ).closest( 'tr' ).toggle( ! isTest );
	}

	if ( $testmode.length ) {
		$testmode.on( 'change', toggleKeyFields );
		toggleKeyFields();
	}
} );
