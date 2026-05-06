( function () {
	const { registerPaymentMethod } = wc.wcBlocksRegistry;
	const { createElement } = window.wp.element;
	const { decodeEntities } = window.wp.htmlEntities;

	const settings = wc.wcSettings.getSetting( 'mutopay_data', {} );
	const title = decodeEntities( settings.title || 'Pay with Crypto' );
	const description = decodeEntities( settings.description || '' );
	const iconUrl = settings.icon || '';

	const Content = function () {
		return createElement( 'div', null, description );
	};

	const Label = function () {
		if ( iconUrl ) {
			return createElement(
				'span',
				{ style: { display: 'flex', alignItems: 'center', gap: '8px' } },
				createElement( 'img', {
					src: iconUrl,
					alt: title,
					style: { width: '20px', height: '20px' },
				} ),
				title
			);
		}
		return createElement( 'span', null, title );
	};

	registerPaymentMethod( {
		name: 'mutopay',
		label: createElement( Label ),
		content: createElement( Content ),
		edit: createElement( Content ),
		canMakePayment: function () {
			return true;
		},
		ariaLabel: title,
		supports: {
			features: settings.supports || [ 'products' ],
		},
	} );
} )();
