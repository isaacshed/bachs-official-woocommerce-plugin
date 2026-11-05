/**
 * Bachs WooCommerce Blocks (checkout blocks) integration.
 */
( function () {
	const { registerPaymentMethod } = window.wc.wcBlocksRegistry;
	const { createElement } = window.wp.element;
	const { __ } = window.wp.i18n;
	const { decodeEntities } = window.wp.htmlEntities;
	const { getSetting } = window.wc.wcSettings;

	const settings = getSetting( 'bachs_data', {} );
	const defaultLabel = __( 'Bachs', 'bachs-payment-gateway-for-woocommerce' );
	const label = decodeEntities( settings.title ) || defaultLabel;

	const Content = () => {
		return decodeEntities( settings.description || '' );
	};

	const Label = () => {
		return createElement(
			'div',
			{
				style: {
					display: 'flex',
					flexDirection: 'row',
					gap: '0.5rem',
					alignItems: 'center',
				},
			},
			[
				settings.icon &&
					createElement( 'img', {
						key: 'icon',
						src: settings.icon,
						alt: label,
						style: { height: '24px', maxWidth: '100px' },
					} ),
				createElement( 'span', { key: 'label' }, label ),
			]
		);
	};

	registerPaymentMethod( {
		name: 'bachs',
		label: createElement( Label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: () => true,
		ariaLabel: label,
		supports: {
			features: settings.supports || [ 'products', 'refunds' ],
		},
	} );
} )();
