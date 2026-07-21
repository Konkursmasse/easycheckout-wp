/**
 * EasyCheckout – Integration in den WooCommerce Block-Checkout (Cart/Checkout-Blocks).
 *
 * Registriert EasyCheckout als Zahlungsart im Block-basierten Checkout. Die Zahlung
 * selbst ist redirect-basiert: Beim „Bestellung aufgeben" ruft die Store-API
 * serverseitig process_payment() des Gateways auf, das mit {result:'success',
 * redirect:<url>} antwortet – die Blocks-Kasse leitet dann automatisch zur
 * EasyCheckout-Bezahlseite (/pay/{id}) weiter. Hier wird deshalb nur die Anzeige
 * (Logo, Titel, Beschreibung, akzeptierte Methoden) registriert.
 *
 * Kein Build-Step: nutzt die von WordPress/WooCommerce bereitgestellten Globals
 * (wc-blocks-registry, wc-settings, wp-element, wp-html-entities, wp-i18n).
 */
( function () {
	var registry = window.wc && window.wc.wcBlocksRegistry;
	var settingsApi = window.wc && window.wc.wcSettings;
	var element = window.wp && window.wp.element;
	var el = element && element.createElement;
	var decodeEntities = ( window.wp && window.wp.htmlEntities && window.wp.htmlEntities.decodeEntities ) || function ( s ) { return s; };
	var __ = ( window.wp && window.wp.i18n && window.wp.i18n.__ ) || function ( s ) { return s; };

	if ( ! registry || ! registry.registerPaymentMethod || ! settingsApi || ! el ) {
		return;
	}

	var data = settingsApi.getSetting( 'easycheckout_data', {} );
	var title = decodeEntities( data.title || __( 'EasyCheckout', 'easycheckout' ) );
	var description = decodeEntities( data.description || '' );
	var logo = data.logo || '';
	var icons = data.icons || {};
	var enabledMethods = data.paymentMethods || [];

	// Bilder der akzeptierten Zahlungsarten (nur die aktivierten anzeigen).
	function methodIcons() {
		var imgs = [];
		Object.keys( icons ).forEach( function ( key ) {
			if ( enabledMethods.length && enabledMethods.indexOf( key ) === -1 ) {
				return;
			}
			var ic = icons[ key ];
			if ( ic && ic.src ) {
				imgs.push( el( 'img', {
					key: key,
					src: ic.src,
					alt: ic.alt || key,
					style: { height: '22px', width: 'auto', marginLeft: '6px', verticalAlign: 'middle' }
				} ) );
			}
		} );
		return imgs;
	}

	// Label neben dem Auswahl-Radio: Logo + Titel links, akzeptierte Methoden rechts.
	function Label() {
		var left = [];
		if ( logo ) {
			left.push( el( 'img', {
				key: 'logo',
				src: logo,
				alt: title,
				style: { height: '22px', width: 'auto', verticalAlign: 'middle' }
			} ) );
		}
		left.push( el( 'span', {
			key: 'title',
			style: { marginLeft: logo ? '8px' : 0, verticalAlign: 'middle', fontWeight: 600 }
		}, title ) );

		return el(
			'span',
			{ className: 'ec-blocks-label', style: { display: 'flex', alignItems: 'center', width: '100%' } },
			el( 'span', { key: 'l', style: { flex: '1', display: 'flex', alignItems: 'center' } }, left ),
			el( 'span', { key: 'r', style: { display: 'flex', alignItems: 'center' } }, methodIcons() )
		);
	}

	// Inhalt, wenn die Zahlungsart ausgewählt ist: Beschreibung + Hinweis.
	function Content() {
		var lines = [];
		if ( description ) {
			lines.push( el( 'p', { key: 'desc', style: { margin: '0 0 6px' } }, description ) );
		}
		if ( data.isTestMode ) {
			lines.push( el( 'p', {
				key: 'test',
				style: { margin: '4px 0 0', fontSize: '12px', color: '#9a6700' }
			}, __( 'Testmodus aktiv – es werden keine echten Zahlungen ausgeführt.', 'easycheckout' ) ) );
		}
		if ( ! lines.length ) {
			return null;
		}
		return el( 'div', { className: 'ec-blocks-content' }, lines );
	}

	registry.registerPaymentMethod( {
		name: 'easycheckout',
		label: el( Label, null ),
		content: el( Content, null ),
		edit: el( Content, null ),
		ariaLabel: title,
		placeOrderButtonLabel: __( 'Weiter zur Zahlung', 'easycheckout' ),
		// Redirect-basiert: der Server (process_payment) entscheidet über die Zahlung.
		canMakePayment: function () { return true; },
		supports: {
			features: Array.isArray( data.supports ) ? data.supports : [ 'products' ]
		}
	} );
} )();
