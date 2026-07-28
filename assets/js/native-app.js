/* global wp, ecNative */
( function () {
	'use strict';
	function _t(s){try{return (window.ecNative&&ecNative.i18n&&ecNative.i18n[s])||s;}catch(e){return s;}}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var render = wp.element.render;

	// --- API helpers --------------------------------------------------------

	function post( action, fields ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', ecNative.nonce );
		Object.keys( fields || {} ).forEach( function ( k ) { body.append( k, fields[ k ] ); } );
		return fetch( ecNative.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
			body: body.toString(),
		} ).then( function ( r ) { return r.json(); } );
	}

	function api( method, path, payload ) {
		return post( 'easycheckout_native_proxy', {
			method: method, path: path, body: payload ? JSON.stringify( payload ) : '',
		} ).then( function ( j ) {
			if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || _t('Fehler') ); }
			var status = j.data.status, b = j.data.body;
			if ( status >= 400 ) { throw new Error( ( b && ( b.error || b.message ) ) || ( _t('Fehler ') + status ) ); }
			return b;
		} );
	}

	// Lokale Checkout-Entwuerfe (ohne Konto). action: 'get' | 'save' | 'delete'
	function localApi( action, fields ) {
		return post( 'easycheckout_local_' + action, fields || {} ).then( function ( j ) {
			if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || _t('Fehler') ); }
			return j.data;
		} );
	}

	// Lokaler Bild-Upload in die WP-Mediathek -> gibt { url } zurueck.
	function localUpload( file ) {
		var fd = new FormData();
		fd.append( 'action', 'easycheckout_local_upload' );
		fd.append( 'nonce', ecNative.nonce );
		fd.append( 'file', file );
		return fetch( ecNative.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || _t('Upload fehlgeschlagen') ); } return j.data; } );
	}

	// Vorschau der Einbettung auf der EIGENEN Domain (lokal ODER Konto-Checkout).
	// Fuehrt NIE auf easycheckout.ch — der Checkout wird auf der Haendler-Seite gezeigt.
	function previewUrl( slug ) { return ( ecNative.siteUrl || '/' ) + '?ec_preview=' + encodeURIComponent( slug ); }

	// Einen lokalen Checkout ins verbundene Konto veroeffentlichen (Name/Slug/
	// Produkte inkl. Bild) und danach lokal loeschen -> Konto-Checkout ersetzt den
	// lokalen (gleicher Slug/Link/Shortcode, ab dann online-zahlungsfaehig).
	function publishLocalToAccount( local ) {
		return api( 'POST', '/api/checkouts', { name: local.name, slug: local.slug } ).then( function ( b ) {
			var id = b && b.checkout && b.checkout.id;
			if ( ! id ) { throw new Error( _t('Erstellen fehlgeschlagen (Slug evtl. bereits vergeben)') ); }
			return ( local.products || [] ).reduce( function ( ch, p ) {
				return ch.then( function () {
					return api( 'POST', '/api/checkouts/' + id + '/products', { name: p.name, description: p.description || '', price: p.price || 0 } ).then( function ( pr ) {
						var prod = pr && pr.product;
						if ( p.imageUrl && prod && prod.id ) {
							return fetch( p.imageUrl ).then( function ( r ) { return r.blob(); } ).then( function ( blob ) {
								var file = new File( [ blob ], 'produkt.jpg', { type: blob.type || 'image/jpeg' } );
								return uploadFile( 'POST', '/api/products/' + prod.id + '/image', 'image', file );
							} ).catch( function () {} ); // Bild optional -> Fehler ignorieren
						}
					} );
				} );
			}, Promise.resolve() ).then( function () { return localApi( 'delete', { id: local.id } ); } );
		} );
	}

	// Alle lokalen Checkouts uebernehmen; Fehler pro Checkout werden gesammelt,
	// der Rest laeuft weiter. Gibt ein Array von Fehlermeldungen zurueck.
	function migrateLocalsToAccount() {
		var errors = [];
		return localApi( 'get' ).then( function ( locals ) {
			return ( locals || [] ).reduce( function ( chain, local ) {
				return chain.then( function () {
					return publishLocalToAccount( local ).catch( function ( e ) { errors.push( ( local.name || local.slug ) + ': ' + e.message ); } );
				} );
			}, Promise.resolve() );
		} ).then( function () { return errors; } );
	}

	// Einmalig pro Seitenaufruf: lokale Checkouts still ins verbundene Konto uebernehmen.
	var _localsMigrated = false;
	function migrateLocalsIfNeeded() { if ( _localsMigrated ) { return; } _localsMigrated = true; try { migrateLocalsToAccount(); } catch ( e ) {} }

	function uploadFile( method, path, field, file ) {
		var fd = new FormData();
		fd.append( 'action', 'easycheckout_native_upload' );
		fd.append( 'nonce', ecNative.nonce );
		fd.append( 'method', method );
		fd.append( 'path', path );
		fd.append( field, file );
		return fetch( ecNative.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) {
				if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || _t('Upload fehlgeschlagen') ); }
				var b = j.data.body;
				if ( j.data.status >= 400 ) { throw new Error( ( b && ( b.error || b.message ) ) || _t('Fehler') ); }
				return b;
			} );
	}

	// Waehrungsgerechte Formatierung statt schweizerischer Schreibweise fuer alles:
	// CHF «CHF 1'234.50», EUR «1.234,50 €».
	function fmtMoney( n, cur ) {
		if ( n == null || isNaN( n ) ) { return '—'; }
		var code = ( cur || 'CHF' ).toString().toUpperCase().slice( 0, 3 ) || 'CHF';
		var locale = code === 'EUR' ? 'de-DE' : ( code === 'USD' || code === 'GBP' ? 'en-US' : 'de-CH' );
		try {
			return new Intl.NumberFormat( locale, { style: 'currency', currency: code } ).format( Number( n ) );
		} catch ( e ) {
			return code + ' ' + Number( n ).toFixed( 2 );
		}
	}
	function fmtDate( s ) { if ( ! s ) { return '—'; } try { return new Date( s ).toLocaleDateString( 'de-CH' ); } catch ( e ) { return s; } }
	function fileToDataUrl( file ) { return new Promise( function ( res, rej ) { var r = new FileReader(); r.onload = function () { res( r.result ); }; r.onerror = rej; r.readAsDataURL( file ); } ); }

	// --- Small UI helpers ---------------------------------------------------

	function Field( label, node, hint ) {
		return el( 'label', { className: 'ec-field' }, el( 'span', null, label ), node, hint && el( 'em', { className: 'ec-hint' }, hint ) );
	}
	function Spinner() { return el( 'p', { className: 'ec-muted' }, 'Lädt…' ); }
	function ErrorBox( msg ) { return msg ? el( 'div', { className: 'ec-alert ec-alert-error' }, msg ) : null; }

	// --- Login --------------------------------------------------------------

	function LoginView( props ) {
		var s = useState( { email: '', password: '', companyName: '', mode: 'login', busy: false, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, { error: '' }, o ) ); }
		function submit( e ) {
			e.preventDefault();
			set( Object.assign( {}, st, { busy: true, error: '' } ) );
			if ( st.mode === 'login' ) {
				post( 'easycheckout_native_login', { email: st.email, password: st.password } ).then( function ( j ) {
					if ( j.success ) { props.onAuthed( j.data.merchant || {} ); }
					else { set( Object.assign( {}, st, { busy: false, error: ( j.data && j.data.message ) || _t('Anmeldung fehlgeschlagen') } ) ); }
				} );
			} else {
				post( 'easycheckout_native_register', { data: JSON.stringify( { email: st.email, password: st.password, companyName: st.companyName, plan: 'free' } ) } ).then( function ( j ) {
					if ( j.success ) {
						// Frisch registriert -> direkt ins Onboarding (SSO-Token in der URL),
						// damit die Verifizierung unmittelbar nach der Registrierung startet.
						post( 'easycheckout_onboarding_url', { 'return': window.location.href } ).then( function ( r ) {
							if ( r && r.success && r.data && r.data.url ) { window.location.href = r.data.url; }
							else { props.onAuthed( j.data.merchant || {} ); }
						} ).catch( function () { props.onAuthed( j.data.merchant || {} ); } );
					}
					else { set( Object.assign( {}, st, { busy: false, error: ( j.data && j.data.message ) || _t('Registrierung fehlgeschlagen') } ) ); }
				} );
			}
		}
		return el( 'div', { className: 'ec-auth' }, el( 'div', { className: 'ec-auth-card' },
			el( 'h1', { className: 'ec-auth-title' }, st.mode === 'login' ? 'Willkommen zurück' : _t('Konto erstellen') ),
			el( 'p', { className: 'ec-auth-sub' }, st.mode === 'login' ? 'Melde dich bei deinem EasyCheckout-Konto an' : _t('Registriere dich für EasyCheckout') ),
			ErrorBox( st.error ),
			el( 'form', { onSubmit: submit },
				st.mode === 'register' && Field( _t('Firma'), el( 'input', { type: 'text', value: st.companyName, onChange: function ( e ) { up( { companyName: e.target.value } ); } } ) ),
				Field( _t('E-Mail-Adresse'), el( 'input', { type: 'email', required: true, value: st.email, onChange: function ( e ) { up( { email: e.target.value } ); } } ) ),
				Field( _t('Passwort'), el( 'input', { type: 'password', required: true, value: st.password, onChange: function ( e ) { up( { password: e.target.value } ); } } ) ),
				el( 'button', { type: 'submit', className: 'ec-btn ec-btn-primary ec-btn-block', disabled: st.busy }, st.busy ? 'Bitte warten…' : ( st.mode === 'login' ? 'Anmelden' : _t('Registrieren') ) )
			),
			el( 'p', { className: 'ec-auth-switch' }, st.mode === 'login' ? 'Noch kein Konto? ' : _t('Schon ein Konto? '),
				el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); set( Object.assign( {}, st, { mode: st.mode === 'login' ? 'register' : 'login', error: '' } ) ); } }, st.mode === 'login' ? 'Kostenlos registrieren' : 'Anmelden' ) )
		) );
	}

	// --- Checkouts list -----------------------------------------------------

	// Produktarten (bei der Checkout-Erstellung gewaehlt) -> steuern, welche Felder
	// der Produkt-Editor zeigt.
	var PRODUCT_TYPES = [
		[ 'physical', _t('Physische Produkte (Versand)') ],
		[ 'food', _t('Gastro / Speisen') ],
		[ 'tickets', _t('Tickets / Events') ],
		[ 'digital', _t('Digital / Dienstleistung') ],
	];
	function ptFields( type ) {
		switch ( type ) {
			case 'food':    return { options: true,  optionsLabel: _t('Optionen (z. B. Extras, Beilagen)'), delivery: true,  fields: true, limits: false };
			case 'tickets': return { options: false, optionsLabel: '', delivery: false, fields: true, limits: true };
			case 'digital': return { options: false, optionsLabel: '', delivery: false, fields: false, limits: false };
			default:        return { options: true,  optionsLabel: _t('Varianten (z. B. Grösse, Farbe)'), delivery: true,  fields: true, limits: true }; // physical
		}
	}
	function ptLabel( type ) { var f = PRODUCT_TYPES.filter( function ( p ) { return p[ 0 ] === type; } )[ 0 ]; return f ? f[ 1 ] : _t('Physische Produkte (Versand)'); }

	function CheckoutsList( props ) {
		var s = useState( { items: null, error: '', creating: false, name: '', slug: '', productType: 'physical', busy: false, limit: null, atLimit: false, plan: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() {
			api( 'GET', '/api/checkouts' ).then( function ( b ) {
				set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.checkouts ) || [], error: '', limit: ( b && b.checkoutLimit != null ) ? b.checkoutLimit : null, atLimit: !! ( b && b.atCheckoutLimit ), plan: ( b && b.plan ) || '' } ); } );
			} ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } );
		}
		useEffect( function () { load(); }, [] );
		function create( e ) {
			e.preventDefault(); set( Object.assign( {}, st, { busy: true, error: '' } ) );
			api( 'POST', '/api/checkouts', { name: st.name, slug: st.slug, productType: st.productType } ).then( function ( b ) {
				set( Object.assign( {}, st, { busy: false, creating: false, name: '', slug: '', productType: 'physical' } ) );
				if ( b && b.checkout ) { props.navigate( 'checkout', { id: b.checkout.id } ); } else { load(); }
			} ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		function del( c ) {
			if ( ! window.confirm( _t('Checkout „') + ( c.name || c.slug ) + _t('" wirklich löschen?') ) ) { return; }
			api( 'DELETE', '/api/checkouts/' + c.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } );
		}
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Checkouts') ),
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.atLimit, title: st.atLimit ? 'Checkout-Limit deines Plans erreicht' : '', onClick: function () { if ( ! st.atLimit ) { set( Object.assign( {}, st, { creating: true } ) ); } } }, _t('+ Neuer Checkout') ) ),
			st.limit != null && el( 'p', { className: 'ec-muted ec-sm', style: { marginTop: '-6px', marginBottom: '10px' } },
				( st.items ? st.items.length : 0 ) + ' / ' + st.limit + _t(' Checkouts') + ( st.plan ? ' · Plan: ' + st.plan : '' ),
				st.atLimit ? el( 'span', null, _t(' — Limit erreicht. Für weitere Checkouts bitte '), el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); props.navigate( 'billing' ); } }, _t('Tarif upgraden') ), '.' ) : null ),
			ErrorBox( st.error ),
			st.creating && el( 'div', { className: 'ec-card', style: { marginBottom: '14px' } },
				el( 'form', { onSubmit: create },
					el( 'div', { className: 'ec-two' },
						Field( _t('Name'), el( 'input', { placeholder: _t('z. B. Mein Shop'), required: true, value: st.name, onChange: function ( e ) { set( Object.assign( {}, st, { name: e.target.value } ) ); } } ) ),
						Field( _t('Slug'), el( 'input', { placeholder: _t('z. B. mein-shop'), required: true, value: st.slug, onChange: function ( e ) { set( Object.assign( {}, st, { slug: e.target.value } ) ); } } ) )
					),
					Field( _t('Was verkaufst du?'), el( 'select', { value: st.productType, onChange: function ( e ) { set( Object.assign( {}, st, { productType: e.target.value } ) ); } },
						PRODUCT_TYPES.map( function ( p ) { return el( 'option', { key: p[ 0 ], value: p[ 0 ] }, p[ 1 ] ); } )
					), _t('Bestimmt, welche Felder der Produkt-Editor zeigt (z. B. Grössen nur bei physischen Produkten).') ),
					el( 'div', { className: 'ec-form-actions' },
						el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : _t('Erstellen') ),
						el( 'button', { type: 'button', className: 'ec-btn', onClick: function () { set( Object.assign( {}, st, { creating: false } ) ); } }, _t('Abbrechen') ) )
				) ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Noch keine Checkouts.') ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Name') ), el( 'th', null, _t('Slug') ), el( 'th', null, _t('Produkte') ), el( 'th', null, _t('Bestellungen') ), el( 'th', null, _t('Status') ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( c ) {
						return el( 'tr', { key: c.id },
							el( 'td', null, el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); props.navigate( 'checkout', { id: c.id } ); } }, el( 'strong', null, c.name || '—' ) ) ),
							el( 'td', null, el( 'code', null, c.slug || '' ) ),
							el( 'td', null, ( c._count && c._count.products != null ) ? c._count.products : '—' ),
							el( 'td', null, ( c._count && c._count.orders != null ) ? c._count.orders : '—' ),
							el( 'td', null, c.isActive === false ? el( 'span', { className: 'ec-badge ec-badge-off' }, _t('Inaktiv') ) : el( 'span', { className: 'ec-badge ec-badge-on' }, 'Aktiv' ) ),
							el( 'td', { className: 'ec-row-actions' },
								el( 'a', { className: 'ec-btn ec-btn-sm', href: previewUrl( c.slug ), target: '_blank', rel: 'noopener' }, _t('Ansehen') ),
								' ',
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { props.navigate( 'products', { id: c.id, name: c.name } ); } }, _t('Produkte') ),
								' ',
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { props.navigate( 'checkout', { id: c.id } ); } }, _t('Bearbeiten') ),
								' ',
								el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( c ); } }, _t('Löschen') ) )
						);
					} ) )
				)
		);
	}

	// --- Checkout editor ----------------------------------------------------

	var PAYMENT_METHODS = [ [ 'card', _t('Karte') ], [ 'twint', 'TWINT' ], [ 'klarna', 'Klarna' ], [ 'sepa_debit', 'SEPA' ], [ 'bancontact', 'Bancontact' ], [ 'eps', 'EPS' ], [ 'giropay', 'giropay' ], [ 'ideal', 'iDEAL' ], [ 'p24', 'Przelewy24' ] ];

	function CheckoutEditor( props ) {
		var s = useState( { c: null, error: '', saving: false, saved: false } );
		var st = s[ 0 ], set = s[ 1 ];
		useEffect( function () {
			api( 'GET', '/api/checkouts/' + props.id ).then( function ( b ) {
				var c = b && b.checkout ? b.checkout : b;
				c.design = c.design || {};
				c.paymentMethods = c.paymentMethods || [];
				set( function ( p ) { return Object.assign( {}, p, { c: c } ); } );
			} ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { error: err.message } ); } ); } );
		}, [ props.id ] );

		function upd( o ) { set( Object.assign( {}, st, { saved: false, c: Object.assign( {}, st.c, o ) } ) ); }
		function updDesign( o ) { upd( { design: Object.assign( {}, st.c.design, o ) } ); }
		function togglePm( m ) { var arr = st.c.paymentMethods.slice(); var i = arr.indexOf( m ); if ( i >= 0 ) { arr.splice( i, 1 ); } else { arr.push( m ); } upd( { paymentMethods: arr } ); }

		function save( e ) {
			e.preventDefault(); set( Object.assign( {}, st, { saving: true, error: '', saved: false } ) );
			var c = st.c;
			var payload = {
				name: c.name, description: c.description, slug: c.slug, isActive: c.isActive !== false,
				design: c.design, vatEnabled: !! c.vatEnabled, vatRate: parseFloat( c.vatRate ) || 0, vatInclusive: c.vatInclusive !== false,
				paymentMethods: c.paymentMethods, currency: c.currency || 'CHF', successUrl: c.successUrl || '', cancelUrl: c.cancelUrl || '', qrPaymentEnabled: !! c.qrPaymentEnabled,
					pickupEnabled: c.pickupEnabled !== false, deliveryEnabled: !! c.deliveryEnabled,
			};
			api( 'PUT', '/api/checkouts/' + props.id, payload ).then( function () { set( Object.assign( {}, st, { saving: false, saved: true } ) ); } )
				.catch( function ( err ) { set( Object.assign( {}, st, { saving: false, error: err.message } ) ); } );
		}

		if ( st.error && ! st.c ) { return el( 'div', null, backHead( props, _t('Checkout') ), ErrorBox( st.error ) ); }
		if ( ! st.c ) { return el( 'div', null, backHead( props, _t('Checkout') ), Spinner() ); }
		var c = st.c;
		return el( 'div', null,
			backHead( props, _t('Checkout bearbeiten'), el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { props.navigate( 'products', { id: props.id, name: c.name } ); } }, _t('Produkte verwalten') ) ),
			ErrorBox( st.error ), st.saved && el( 'div', { className: 'ec-alert' }, _t('Gespeichert.') ),
			el( 'form', { onSubmit: save, className: 'ec-form-grid' },
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, _t('Allgemein') ),
					Field( _t('Name'), el( 'input', { value: c.name || '', onChange: function ( e ) { upd( { name: e.target.value } ); } } ) ),
					Field( _t('Slug'), el( 'input', { value: c.slug || '', onChange: function ( e ) { upd( { slug: e.target.value } ); } } ) ),
					Field( _t('Beschreibung'), el( 'textarea', { rows: 2, value: c.description || '', onChange: function ( e ) { upd( { description: e.target.value } ); } } ) ),
					Field( _t('Währung'), el( 'select', { value: c.currency || 'CHF', onChange: function ( e ) { upd( { currency: e.target.value } ); } }, [ 'CHF', 'EUR', 'USD' ].map( function ( x ) { return el( 'option', { key: x, value: x }, x ); } ) ) ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: c.isActive !== false, onChange: function ( e ) { upd( { isActive: e.target.checked } ); } } ), _t(' Aktiv') )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, _t('MwSt') ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: !! c.vatEnabled, onChange: function ( e ) { upd( { vatEnabled: e.target.checked } ); } } ), _t(' MwSt-pflichtig') ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: c.vatInclusive !== false, onChange: function ( e ) { upd( { vatInclusive: e.target.checked } ); } } ), _t(' Preise inkl. MwSt') ),
					Field( _t('Standard-MwSt-Satz (%)'), el( 'input', { type: 'number', step: '0.1', value: c.vatRate != null ? c.vatRate : '', onChange: function ( e ) { upd( { vatRate: e.target.value } ); } } ), _t('Gilt für Produkte ohne eigenen Satz. Abweichende Sätze setzt du direkt beim Produkt (z. B. 8.1 Standard, 2.6 Lebensmittel).') )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, _t('Lieferung / Abholung') ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: c.pickupEnabled !== false, onChange: function ( e ) { upd( { pickupEnabled: e.target.checked } ); } } ), _t(' Abholung anbieten') ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: !! c.deliveryEnabled, onChange: function ( e ) { upd( { deliveryEnabled: e.target.checked } ); } } ), _t(' Lieferung anbieten') ),
					el( 'p', { className: 'ec-hint' }, _t('Bei aktiver Lieferung fragt der Checkout eine Lieferadresse ab (mit Option „gleich wie Rechnungsadresse"). Liefer-/Abholpreise setzt du je Produkt.') )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, _t('Zahlungsarten') ),
					el( 'div', { className: 'ec-checks' }, PAYMENT_METHODS.map( function ( m ) {
						return el( 'label', { key: m[ 0 ], className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: c.paymentMethods.indexOf( m[ 0 ] ) >= 0, onChange: function () { togglePm( m[ 0 ] ); } } ), ' ' + m[ 1 ] );
					} ) ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: !! c.qrPaymentEnabled, onChange: function ( e ) { upd( { qrPaymentEnabled: e.target.checked } ); } } ), _t(' QR-Rechnung') )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, _t('Design') ),
					colorField( _t('Akzentfarbe'), c.design.primaryColor || '#4F46E5', function ( v ) { updDesign( { primaryColor: v } ); } ),
					colorField( _t('Button-Farbe'), c.design.buttonColor || c.design.primaryColor || '#4F46E5', function ( v ) { updDesign( { buttonColor: v } ); } ),
					colorField( _t('Button-Text'), c.design.buttonTextColor || '#FFFFFF', function ( v ) { updDesign( { buttonTextColor: v } ); } ),
					colorField( _t('Textfarbe'), c.design.textColor || '#111827', function ( v ) { updDesign( { textColor: v } ); } ),
					colorField( _t('Hintergrund'), c.design.backgroundColor || '#F9FAFB', function ( v ) { updDesign( { backgroundColor: v } ); } ),
					Field( 'Eckenradius (px)', el( 'input', { type: 'number', min: 0, max: 40, value: c.design.borderRadius != null ? c.design.borderRadius : 12, onChange: function ( e ) { updDesign( { borderRadius: parseInt( e.target.value, 10 ) || 0 } ); } } ) )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, _t('Weiterleitungen') ),
					Field( _t('Erfolgs-URL'), el( 'input', { type: 'url', value: c.successUrl || '', onChange: function ( e ) { upd( { successUrl: e.target.value } ); } } ) ),
					Field( _t('Abbruch-URL'), el( 'input', { type: 'url', value: c.cancelUrl || '', onChange: function ( e ) { upd( { cancelUrl: e.target.value } ); } } ) )
				),
				el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.saving }, st.saving ? 'Speichert…' : _t('Speichern') ) )
			)
		);
	}

	function colorField( label, value, onChange ) {
		return el( 'label', { className: 'ec-field ec-color' }, el( 'span', null, label ),
			el( 'span', { className: 'ec-color-row' },
				el( 'input', { type: 'color', value: ( value && value[ 0 ] === '#' ) ? value : '#000000', onChange: function ( e ) { onChange( e.target.value ); } } ),
				el( 'input', { type: 'text', value: value || '', onChange: function ( e ) { onChange( e.target.value ); } } )
			) );
	}

	function backHead( props, title, extra ) {
		return el( 'div', { className: 'ec-page-head' },
			el( 'div', { className: 'ec-head-left' },
				el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { props.navigate( 'checkouts' ); } }, _t('← Zurück') ),
				el( 'h2', null, title ) ),
			extra || null );
	}

	// --- Products manager ---------------------------------------------------

	function ProductsManager( props ) {
		var s = useState( { items: null, error: '', editing: null, productType: 'physical', vatEnabled: false, vatRate: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() {
			api( 'GET', '/api/checkouts/' + props.id + '/products' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.products ) || [], error: '' } ); } ); } )
				.catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } );
		}
		// Checkout laden -> Produktart (Felder) + MwSt-Einstellungen (Standard-Satz).
		useEffect( function () {
			api( 'GET', '/api/checkouts/' + props.id ).then( function ( b ) {
				var c = ( b && b.checkout ) || {};
				set( function ( p ) { return Object.assign( {}, p, { productType: c.productType || 'physical', vatEnabled: !! c.vatEnabled, vatRate: c.vatRate != null ? c.vatRate : '' } ); } );
			} ).catch( function () {} );
		}, [ props.id ] );
		useEffect( function () { load(); }, [ props.id ] );
		function del( p ) { if ( ! window.confirm( _t('Produkt löschen?') ) ) { return; } api( 'DELETE', '/api/products/' + p.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		function emptyProduct() { return { name: '', description: '', price: '', imageUrl: '', isActive: true, maxPerCustomer: '', maxTotal: '' }; }

		return el( 'div', null,
			backHead( props, _t('Produkte') + ( props.name ? ' · ' + props.name : '' ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { editing: emptyProduct() } ) ); } }, _t('+ Neues Produkt') ) ),
			el( 'p', { className: 'ec-muted ec-sm', style: { marginTop: '-6px', marginBottom: '10px' } }, 'Produktart: ', el( 'strong', null, ptLabel( st.productType ) ) ),
			ErrorBox( st.error ),
			st.editing && el( ProductForm, { checkoutId: props.id, productType: st.productType, vatEnabled: st.vatEnabled, defaultVatRate: st.vatRate, product: st.editing, onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Noch keine Produkte.') ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, '' ), el( 'th', null, _t('Name') ), el( 'th', null, _t('Preis') ), el( 'th', null, _t('Status') ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( p ) {
						return el( 'tr', { key: p.id },
							el( 'td', null, p.imageUrl ? el( 'img', { src: p.imageUrl, className: 'ec-thumb' } ) : el( 'span', { className: 'ec-thumb ec-thumb-empty' } ) ),
							el( 'td', null, el( 'strong', null, p.name ), p.description && el( 'div', { className: 'ec-muted ec-sm' }, p.description ) ),
							el( 'td', null, fmtMoney( p.price ) ),
							el( 'td', null, p.isActive === false ? el( 'span', { className: 'ec-badge ec-badge-off' }, _t('Inaktiv') ) : el( 'span', { className: 'ec-badge ec-badge-on' }, 'Aktiv' ) ),
							el( 'td', { className: 'ec-row-actions' },
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { editing: Object.assign( {}, p ) } ) ); } }, _t('Bearbeiten') ), ' ',
								el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( p ); } }, _t('Löschen') ) )
						);
					} ) )
				)
		);
	}

	function ProductForm( props ) {
		var p0 = props.product || {};
		var f0 = ptFields( props.productType );
		function parseOpts( o ) { if ( Array.isArray( o ) ) { return o.slice(); } if ( typeof o === 'string' && o ) { try { var a = JSON.parse( o ); return Array.isArray( a ) ? a : []; } catch ( e ) { return []; } } return []; }
		var s = useState( {
			busy: false, error: '',
			id: p0.id, name: p0.name || '', description: p0.description || '', price: p0.price != null ? p0.price : '',
			imageUrl: p0.imageUrl || '', isActive: p0.isActive !== false,
			maxPerCustomer: p0.maxPerCustomer != null ? p0.maxPerCustomer : '', maxTotal: p0.maxTotal != null ? p0.maxTotal : '',
			pickupPrice: p0.pickupPrice != null ? p0.pickupPrice : '', deliveryPrice: p0.deliveryPrice != null ? p0.deliveryPrice : '', deliveryFee: p0.deliveryFee != null ? p0.deliveryFee : '',
			vatRate: p0.vatRate != null ? p0.vatRate : '',
			optionGroups: ( p0.optionGroups || [] ).map( function ( g ) { return { name: g.name || '', options: ( g.options || [] ).map( function ( o ) { return { label: o.label || '', priceModifier: o.priceModifier != null ? o.priceModifier : 0 }; } ) }; } ),
			customFields: ( p0.customFields || [] ).map( function ( f ) { return { label: f.label || '', fieldType: f.fieldType || 'text', required: !! f.required, options: parseOpts( f.options ) }; } ),
		} );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, { error: '' }, o ) ); }
		function pickImage( e ) { var f = e.target.files[ 0 ]; if ( ! f ) { return; } if ( f.size > 2 * 1024 * 1024 ) { up( { error: _t('Bild max. 2 MB') } ); return; } fileToDataUrl( f ).then( function ( d ) { up( { imageUrl: d } ); } ); }
		// Optionsgruppen
		function addGroup() { up( { optionGroups: st.optionGroups.concat( [ { name: '', options: [ { label: '', priceModifier: 0 } ] } ] ) } ); }
		function delGroup( gi ) { var g = st.optionGroups.slice(); g.splice( gi, 1 ); up( { optionGroups: g } ); }
		function setGroup( gi, k, v ) { var g = st.optionGroups.slice(); g[ gi ] = Object.assign( {}, g[ gi ] ); g[ gi ][ k ] = v; up( { optionGroups: g } ); }
		function addOpt( gi ) { var g = st.optionGroups.slice(); g[ gi ] = Object.assign( {}, g[ gi ], { options: g[ gi ].options.concat( [ { label: '', priceModifier: 0 } ] ) } ); up( { optionGroups: g } ); }
		function delOpt( gi, oi ) { var g = st.optionGroups.slice(); var os = g[ gi ].options.slice(); os.splice( oi, 1 ); g[ gi ] = Object.assign( {}, g[ gi ], { options: os } ); up( { optionGroups: g } ); }
		function setOpt( gi, oi, k, v ) { var g = st.optionGroups.slice(); var os = g[ gi ].options.slice(); os[ oi ] = Object.assign( {}, os[ oi ] ); os[ oi ][ k ] = v; g[ gi ] = Object.assign( {}, g[ gi ], { options: os } ); up( { optionGroups: g } ); }
		// Infofelder
		function addField() { up( { customFields: st.customFields.concat( [ { label: '', fieldType: 'text', required: false, options: [] } ] ) } ); }
		function delField( fi ) { var f = st.customFields.slice(); f.splice( fi, 1 ); up( { customFields: f } ); }
		function setField( fi, k, v ) { var f = st.customFields.slice(); f[ fi ] = Object.assign( {}, f[ fi ] ); f[ fi ][ k ] = v; if ( k === 'fieldType' && v === 'checkbox' && ( ! f[ fi ].options || ! f[ fi ].options.length ) ) { f[ fi ].options = [ '' ]; } up( { customFields: f } ); }
		function addChoice( fi ) { var f = st.customFields.slice(); f[ fi ] = Object.assign( {}, f[ fi ], { options: ( f[ fi ].options || [] ).concat( [ '' ] ) } ); up( { customFields: f } ); }
		function delChoice( fi, ci ) { var f = st.customFields.slice(); var os = ( f[ fi ].options || [] ).slice(); os.splice( ci, 1 ); f[ fi ] = Object.assign( {}, f[ fi ], { options: os } ); up( { customFields: f } ); }
		function setChoice( fi, ci, v ) { var f = st.customFields.slice(); var os = ( f[ fi ].options || [] ).slice(); os[ ci ] = v; f[ fi ] = Object.assign( {}, f[ fi ], { options: os } ); up( { customFields: f } ); }

		function save( e ) {
			e.preventDefault();
			if ( ! String( st.name ).trim() ) { up( { error: _t('Bitte einen Namen angeben.') } ); return; }
			set( Object.assign( {}, st, { busy: true, error: '' } ) );
			var payload = { name: st.name, description: st.description, price: parseFloat( st.price ) || 0, imageUrl: st.imageUrl || '', isActive: st.isActive !== false };
			if ( props.vatEnabled ) { payload.vatRate = st.vatRate === '' ? null : ( parseFloat( st.vatRate ) || 0 ); }
			if ( f0.limits ) {
				payload.maxPerCustomer = st.maxPerCustomer === '' ? null : parseInt( st.maxPerCustomer, 10 );
				payload.maxTotal = st.maxTotal === '' ? null : parseInt( st.maxTotal, 10 );
			}
			if ( f0.delivery ) {
				payload.pickupPrice = st.pickupPrice === '' ? null : ( parseFloat( st.pickupPrice ) || 0 );
				payload.deliveryPrice = st.deliveryPrice === '' ? null : ( parseFloat( st.deliveryPrice ) || 0 );
				payload.deliveryFee = st.deliveryFee === '' ? null : ( parseFloat( st.deliveryFee ) || 0 );
			}
			var pr = st.id ? api( 'PUT', '/api/products/' + st.id, payload ) : api( 'POST', '/api/checkouts/' + props.checkoutId + '/products', payload );
			pr.then( function ( b ) {
				var pid = st.id || ( b && b.product && b.product.id );
				if ( ! pid || ( ! f0.options && ! f0.fields ) ) { props.onSaved(); return; }
				var groups = f0.options ? st.optionGroups.map( function ( g ) {
					return { name: String( g.name ).trim(), options: ( g.options || [] ).filter( function ( o ) { return String( o.label ).trim() !== ''; } ).map( function ( o ) { return { label: o.label, priceModifier: parseFloat( o.priceModifier ) || 0 }; } ) };
				} ).filter( function ( g ) { return g.name !== '' && g.options.length; } ) : [];
				var cfs = f0.fields ? st.customFields.map( function ( f ) {
					var opts = ( f.fieldType === 'checkbox' ) ? ( f.options || [] ).map( function ( x ) { return String( x ).trim(); } ).filter( Boolean ) : [];
					return { label: String( f.label ).trim(), fieldType: f.fieldType, required: !! f.required, options: opts };
				} ).filter( function ( f ) { return f.label !== '' && ( f.fieldType !== 'checkbox' || f.options.length ); } ) : [];
				api( 'PUT', '/api/products/' + pid + '/options', { groups: groups, customFields: cfs } )
					.then( function () { props.onSaved(); } )
					.catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: 'Produkt gespeichert, aber Optionen/Felder: ' + err.message } ) ); } );
			} ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}

		return el( 'div', { className: 'ec-modal' }, el( 'form', { className: 'ec-modal-card ec-modal-lg', onSubmit: save },
			el( 'h3', null, ( st.id ? 'Produkt bearbeiten' : _t('Neues Produkt') ) + ' · ' + ptLabel( props.productType ) ),
			ErrorBox( st.error ),
			el( 'div', { className: 'ec-two' },
				Field( _t('Name'), el( 'input', { required: true, value: st.name || '', onChange: function ( e ) { up( { name: e.target.value } ); } } ) ),
				Field( _t('Preis'), el( 'input', { type: 'number', step: '0.01', required: true, value: st.price, onChange: function ( e ) { up( { price: e.target.value } ); } } ) ) ),
			Field( _t('Beschreibung'), el( 'textarea', { rows: 2, value: st.description || '', onChange: function ( e ) { up( { description: e.target.value } ); } } ) ),
			Field( _t('Bild'), el( 'div', null, st.imageUrl && el( 'img', { src: st.imageUrl, className: 'ec-thumb-lg' } ), el( 'input', { type: 'file', accept: 'image/*', onChange: pickImage } ) ) ),
			// MwSt-Satz je Produkt (nur wenn der Checkout MwSt-pflichtig ist)
			props.vatEnabled ? Field( _t('MwSt-Satz (%)'), el( 'input', { type: 'number', step: '0.1', min: 0, placeholder: ( props.defaultVatRate != null && props.defaultVatRate !== '' ? String( props.defaultVatRate ) + ' (Standard)' : _t('Standard') ), value: st.vatRate, onChange: function ( e ) { up( { vatRate: e.target.value } ); } } ), _t('Leer = Standard-Satz des Checkouts. Für Produkte mit abweichendem Satz hier setzen (z. B. 2.6).') ) : null,
			// Liefer-/Abholpreise (physisch/gastro)
			f0.delivery ? el( 'div', null,
				el( 'h4', { className: 'ec-sub-h' }, _t('Liefer-/Abholpreise (optional)') ),
				el( 'p', { className: 'ec-hint' }, _t('Leer = Standardpreis. Liefergebühr wird einmal pro Position berechnet.') ),
				el( 'div', { className: 'ec-three' },
					Field( _t('Abholpreis'), el( 'input', { type: 'number', step: '0.05', placeholder: _t('Standard'), value: st.pickupPrice, onChange: function ( e ) { up( { pickupPrice: e.target.value } ); } } ) ),
					Field( _t('Lieferpreis'), el( 'input', { type: 'number', step: '0.05', placeholder: _t('Standard'), value: st.deliveryPrice, onChange: function ( e ) { up( { deliveryPrice: e.target.value } ); } } ) ),
					Field( _t('Liefergebühr'), el( 'input', { type: 'number', step: '0.05', placeholder: '0.00', value: st.deliveryFee, onChange: function ( e ) { up( { deliveryFee: e.target.value } ); } } ) ) )
			) : null,
			// Optionen / Varianten (physisch/gastro)
			f0.options ? el( 'div', null,
				el( 'h4', { className: 'ec-sub-h' }, f0.optionsLabel ),
				st.optionGroups.map( function ( g, gi ) {
					return el( 'div', { key: gi, className: 'ec-subcard' },
						el( 'div', { className: 'ec-inline-form', style: { alignItems: 'center' } },
							el( 'input', { type: 'text', placeholder: _t('Gruppenname (z. B. Grösse)'), value: g.name, onChange: function ( e ) { setGroup( gi, 'name', e.target.value ); } } ),
							el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delGroup( gi ); } }, _t('Gruppe entfernen') ) ),
						( g.options || [] ).map( function ( o, oi ) {
							return el( 'div', { key: oi, className: 'ec-inline-form', style: { alignItems: 'center', marginTop: 6 } },
								el( 'input', { type: 'text', placeholder: _t('Option (z. B. L)'), value: o.label, onChange: function ( e ) { setOpt( gi, oi, 'label', e.target.value ); } } ),
								el( 'input', { type: 'number', step: '0.05', placeholder: _t('Aufschlag'), style: { maxWidth: '120px' }, value: o.priceModifier, onChange: function ( e ) { setOpt( gi, oi, 'priceModifier', e.target.value ); } } ),
								el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delOpt( gi, oi ); } }, '×' ) );
						} ),
						el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm', style: { marginTop: 8 }, onClick: function () { addOpt( gi ); } }, _t('+ Option') ) );
				} ),
				el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm', style: { marginTop: 8 }, onClick: addGroup }, _t('+ Optionsgruppe') )
			) : null,
			// Infofelder (gastro/tickets/physisch)
			f0.fields ? el( 'div', null,
				el( 'h4', { className: 'ec-sub-h' }, _t('Infofelder (z. B. Allergien, Teilnehmername)') ),
				st.customFields.map( function ( f, fi ) {
					return el( 'div', { key: fi, className: 'ec-subcard' },
						el( 'div', { className: 'ec-inline-form', style: { alignItems: 'center' } },
							el( 'input', { type: 'text', placeholder: _t('Feldname'), value: f.label, onChange: function ( e ) { setField( fi, 'label', e.target.value ); } } ),
							el( 'select', { value: f.fieldType, onChange: function ( e ) { setField( fi, 'fieldType', e.target.value ); } }, el( 'option', { value: 'text' }, _t('Textfeld') ), el( 'option', { value: 'checkbox' }, _t('Checkboxen') ) ),
							el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: !! f.required, onChange: function ( e ) { setField( fi, 'required', e.target.checked ); } } ), el( 'span', null, _t('Pflicht') ) ),
							el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delField( fi ); } }, '×' ) ),
						f.fieldType === 'checkbox' ? el( 'div', { style: { marginTop: 8 } },
							( f.options || [] ).map( function ( opt, ci ) {
								return el( 'div', { key: ci, className: 'ec-inline-form', style: { alignItems: 'center', marginTop: 6 } },
									el( 'input', { type: 'text', placeholder: _t('Auswahl'), value: opt, onChange: function ( e ) { setChoice( fi, ci, e.target.value ); } } ),
									el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delChoice( fi, ci ); } }, '×' ) );
							} ),
							el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm', style: { marginTop: 8 }, onClick: function () { addChoice( fi ); } }, _t('+ Auswahl') )
						) : null );
				} ),
				el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm', style: { marginTop: 8 }, onClick: addField }, _t('+ Infofeld') )
			) : null,
			// Mengenlimits (physisch/tickets)
			f0.limits ? el( 'div', { className: 'ec-two', style: { marginTop: '14px' } },
				Field( _t('Max. pro Kunde'), el( 'input', { type: 'number', min: 0, value: st.maxPerCustomer != null ? st.maxPerCustomer : '', onChange: function ( e ) { up( { maxPerCustomer: e.target.value } ); } } ), _t('leer = unbegrenzt') ),
				Field( _t('Gesamtkontingent'), el( 'input', { type: 'number', min: 0, value: st.maxTotal != null ? st.maxTotal : '', onChange: function ( e ) { up( { maxTotal: e.target.value } ); } } ), _t('leer = unbegrenzt') ) ) : null,
			el( 'label', { className: 'ec-check', style: { marginTop: '12px' } }, el( 'input', { type: 'checkbox', checked: st.isActive !== false, onChange: function ( e ) { up( { isActive: e.target.checked } ); } } ), _t(' Aktiv') ),
			el( 'div', { className: 'ec-form-actions' },
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : _t('Speichern') ),
				el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, _t('Abbrechen') ) )
		) );
	}

	// --- Orders -------------------------------------------------------------

	function OrdersView() {
		var s = useState( { data: null, error: '', busy: false } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/orders?limit=50' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { data: b, error: '' } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { data: { orders: [] }, error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function sync() { set( Object.assign( {}, st, { busy: true } ) ); api( 'POST', '/api/orders/sync' ).then( function () { set( Object.assign( {}, st, { busy: false } ) ); load(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		function refund( o ) { if ( ! window.confirm( _t('Bestellung erstatten?') ) ) { return; } api( 'POST', '/api/orders/' + o.id + '/refund', {} ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		var orders = st.data ? ( st.data.orders || [] ) : null;
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Bestellungen') ), el( 'button', { className: 'ec-btn', disabled: st.busy, onClick: sync }, st.busy ? 'Synchronisiert…' : _t('Status synchronisieren') ) ),
			ErrorBox( st.error ),
			orders === null ? Spinner() : orders.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Noch keine Bestellungen.') ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Datum') ), el( 'th', null, _t('Kunde') ), el( 'th', null, _t('Checkout') ), el( 'th', null, _t('Betrag') ), el( 'th', null, _t('Status') ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, orders.map( function ( o ) {
						return el( 'tr', { key: o.id },
							el( 'td', null, fmtDate( o.createdAt ) ),
							el( 'td', null, o.customerName || o.customerEmail || '—' ),
							el( 'td', null, o.checkoutName || '—' ),
							el( 'td', null, fmtMoney( o.total, o.currency ) ),
							el( 'td', null, statusBadge( o.paymentStatus ) ),
							el( 'td', { className: 'ec-row-actions' }, o.paymentStatus === 'paid' && el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { refund( o ); } }, _t('Erstatten') ) )
						);
					} ) )
				)
		);
	}

	function statusBadge( s ) {
		var map = { paid: [ 'ec-badge-on', _t('Bezahlt') ], pending: [ 'ec-badge-off', _t('Offen') ], pending_qr: [ 'ec-badge-off', _t('QR offen') ], failed: [ 'ec-badge-err', _t('Fehlgeschlagen') ], refunded: [ 'ec-badge-err', _t('Erstattet') ], partially_refunded: [ 'ec-badge-off', _t('Teilw. erstattet') ] };
		var m = map[ s ] || [ 'ec-badge-off', s || '—' ];
		return el( 'span', { className: 'ec-badge ' + m[ 0 ] }, m[ 1 ] );
	}

	// --- Customers ----------------------------------------------------------

	function CustomersView() {
		var s = useState( { items: null, error: '', editing: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/customers' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.customers ) || [], error: '' } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function del( c ) { if ( ! c.isManual ) { window.alert( _t('Nur manuell angelegte Kunden können gelöscht werden.') ); return; } if ( ! window.confirm( _t('Kunde löschen?') ) ) { return; } api( 'DELETE', '/api/customers/' + c.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Kunden') ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { editing: { email: '', name: '', phone: '', country: 'CH' } } ) ); } }, _t('+ Neuer Kunde') ) ),
			ErrorBox( st.error ),
			st.editing && el( CustomerForm, { customer: st.editing, onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Noch keine Kunden.') ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Name') ), el( 'th', null, _t('E-Mail') ), el( 'th', null, _t('Bestellungen') ), el( 'th', null, _t('Umsatz') ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( c ) {
						return el( 'tr', { key: c.id || c.email },
							el( 'td', null, el( 'strong', null, c.name || '—' ), c.isManual && el( 'span', { className: 'ec-badge ec-badge-off ec-ml' }, 'manuell' ) ),
							el( 'td', null, c.email ),
							el( 'td', null, c.orderCount != null ? c.orderCount : '—' ),
							el( 'td', null, fmtMoney( c.totalSpent, c.currency ) ),
							el( 'td', { className: 'ec-row-actions' },
								c.isManual && el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { editing: Object.assign( {}, c ) } ) ); } }, _t('Bearbeiten') ), ' ',
								c.isManual && el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( c ); } }, _t('Löschen') ) )
						);
					} ) )
				)
		);
	}

	function CustomerForm( props ) {
		var s = useState( Object.assign( { busy: false, error: '' }, props.customer ) );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, { error: '' }, o ) ); }
		function save( e ) {
			e.preventDefault(); set( Object.assign( {}, st, { busy: true, error: '' } ) );
			var payload = { email: st.email, name: st.name, company: st.company, phone: st.phone, street: st.street, postalCode: st.postalCode, city: st.city, country: st.country || 'CH', notes: st.notes };
			var pr = st.id ? api( 'PUT', '/api/customers/' + st.id, payload ) : api( 'POST', '/api/customers', payload );
			pr.then( function () { props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		return el( 'div', { className: 'ec-modal' }, el( 'form', { className: 'ec-modal-card', onSubmit: save },
			el( 'h3', null, st.id ? 'Kunde bearbeiten' : _t('Neuer Kunde') ), ErrorBox( st.error ),
			Field( _t('E-Mail'), el( 'input', { type: 'email', required: true, value: st.email || '', onChange: function ( e ) { up( { email: e.target.value } ); } } ) ),
			Field( _t('Name'), el( 'input', { value: st.name || '', onChange: function ( e ) { up( { name: e.target.value } ); } } ) ),
			Field( _t('Firma'), el( 'input', { value: st.company || '', onChange: function ( e ) { up( { company: e.target.value } ); } } ) ),
			Field( _t('Telefon'), el( 'input', { value: st.phone || '', onChange: function ( e ) { up( { phone: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-two' },
				Field( 'PLZ', el( 'input', { value: st.postalCode || '', onChange: function ( e ) { up( { postalCode: e.target.value } ); } } ) ),
				Field( _t('Ort'), el( 'input', { value: st.city || '', onChange: function ( e ) { up( { city: e.target.value } ); } } ) ) ),
			Field( _t('Strasse'), el( 'input', { value: st.street || '', onChange: function ( e ) { up( { street: e.target.value } ); } } ) ),
			Field( _t('Notizen'), el( 'textarea', { rows: 2, value: st.notes || '', onChange: function ( e ) { up( { notes: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-form-actions' },
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : _t('Speichern') ),
				el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, _t('Abbrechen') ) )
		) );
	}

	function Placeholder( props ) {
		return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, props.title ) ), el( 'div', { className: 'ec-alert' }, _t('Dieser Bereich wird gerade nativ gebaut und folgt in Kürze.') ) );
	}

	// --- Onboarding / KYC ---------------------------------------------------

	var MCC = [ [ '5734', _t('Software / IT') ], [ '7372', _t('Programmierung') ], [ '5999', _t('Einzelhandel (div.)') ], [ '5045', _t('Computer/Zubehör') ], [ '7299', _t('Dienstleistungen') ], [ '8999', _t('Freiberuflich') ], [ '5812', _t('Gastronomie') ], [ '5611', _t('Bekleidung') ], [ '7991', _t('Freizeit/Events') ] ];

	// Verifizierung = eingebettete easyCheckout-Onboarding-Seite (single source of
	// truth). So laufen ALLE Schritte automatisch mit – Firma, Personen, wirtschaftlich
	// Berechtigte inkl. Ausweis-/Selfie-Pruefung (Stripe Identity) und elektronischer
	// Unterschrift, Dokument-Nachforderungen, Bankverbindung – ohne Doppel-Nachbau/Drift.
	// Die native Status-Karte (via server-seitigem JWT-Proxy) gibt den Schnellstatus,
	// ohne dass man sich im Frame anmelden muss. „In neuem Tab" als Fallback, falls der
	// Kamera-Schritt im iFrame vom Browser blockiert wird.
	// Verifizierung: primaer als vollflaechige Weiterleitung auf die easyCheckout-
	// Onboarding-Seite (Branchenstandard wie WooPayments/PayPal) – dort ist der Kunde
	// top-level, Kamera/Selfie (Stripe Identity) und Login funktionieren sauber. Wir
	// haengen ?return_url=<diese Seite> an → nach Abschluss leitet die Plattform
	// automatisch WIEDER HIERHER zurueck. Alternativ laesst sich alles inline im
	// Dashboard einbetten (iFrame, ohne return_url – sonst wuerde es sich selbst laden).
	function OnboardingView() {
		var s = useState( { status: null, acct: null, error: '', embed: false } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() {
			api( 'GET', '/api/stripe/connect' ).then( function ( status ) {
				set( function ( p ) { return Object.assign( {}, p, { status: status } ); } );
				api( 'GET', '/api/stripe/account-status' ).then( function ( a ) { set( function ( p ) { return Object.assign( {}, p, { acct: a } ); } ); } ).catch( function () {} );
			} ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { error: err.message, status: {} } ); } ); } );
		}
		useEffect( function () { load(); }, [] );

		var appUrl = ( ecNative.appUrl || 'https://www.easycheckout.ch' ).replace( /\/$/, '' );
		var backHere = window.location.href;
		var startUrl = appUrl + '/onboarding?return_url=' + encodeURIComponent( backHere );
		var frameUrl = appUrl + '/onboarding?embed=1';
		var charges = st.status && ( st.status.chargesEnabled || ( st.acct && st.acct.chargesEnabled ) );
		var statusLabel = ( st.acct && st.acct.status && ( st.acct.status.summary || st.acct.status.label ) ) || _t('Verifizierung erforderlich');
		function start() {
			// SSO-Token serverseitig anhaengen, damit man auf easyCheckout nicht erneut
			// einloggen muss; Fallback = tokenlose URL (dann ggf. Login noetig).
			post( 'easycheckout_onboarding_url', { 'return': backHere } ).then( function ( r ) {
				window.location.href = ( r && r.success && r.data && r.data.url ) ? r.data.url : startUrl;
			} ).catch( function () { window.location.href = startUrl; } );
		}

		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' },
				el( 'h2', null, _t('Verifizierung') ),
				el( 'a', { className: 'ec-btn ec-btn-sm', href: startUrl, target: '_blank', rel: 'noopener' }, _t('In neuem Tab öffnen ↗') ) ),
			ErrorBox( st.error ),
			el( 'div', { className: 'ec-card', style: { marginBottom: '16px' } },
				el( 'h3', null, _t('Status') ),
				! st.status ? Spinner() : el( 'p', null, charges ? el( 'span', { className: 'ec-badge ec-badge-on' }, _t('Zahlungen aktiv') ) : el( 'span', { className: 'ec-badge ec-badge-off' }, statusLabel ) ),
				st.acct && st.acct.tasks && st.acct.tasks.length > 0 && el( 'ul', { className: 'ec-tasklist' }, st.acct.tasks.map( function ( t, i ) { return el( 'li', { key: i }, el( 'strong', null, t.title ), t.description && el( 'span', { className: 'ec-muted' }, ' — ' + t.description ) ); } ) )
			),
			charges ? el( 'div', { className: 'ec-card' },
				el( 'p', null, el( 'span', { className: 'ec-badge ec-badge-on' }, _t('✓ Verifizierung abgeschlossen') ) ),
				el( 'p', { className: 'ec-hint', style: { marginTop: 8 } }, _t('Dein Konto ist verifiziert – du kannst Zahlungen empfangen.') )
			) : el( 'div', { className: 'ec-card' },
				el( 'h3', null, _t('Verifizierung abschliessen') ),
				el( 'p', { className: 'ec-hint', style: { marginBottom: 12 } }, _t('Firma, Personen, wirtschaftlich Berechtigte (inkl. Ausweis-/Selfie-Prüfung und Unterschrift) sowie Bankverbindung – sicher über easyCheckout. Nach Abschluss wirst du automatisch hierher zurückgeleitet.') ),
				el( 'div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' } },
					el( 'button', { className: 'ec-btn ec-btn-primary', onClick: start }, _t('Verifizierung auf easyCheckout starten →') ),
					el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { embed: ! st.embed } ) ); } }, st.embed ? 'Einbettung ausblenden' : _t('Stattdessen hier einbetten') )
				),
				st.embed ? el( 'div', { style: { marginTop: 14 } }, [
					el( 'p', { key: 'h', className: 'ec-hint', style: { marginBottom: 8 } }, _t('Falls ein Login erscheint: einmalig mit deinen easyCheckout-Zugangsdaten anmelden. Klappt die Kamera hier nicht, nutze „In neuem Tab öffnen".') ),
					el( 'iframe', { key: 'f', src: frameUrl, className: 'ec-onboard-frame', allow: 'camera; microphone; clipboard-write; fullscreen', title: _t('Verifizierung') } )
				] ) : null
			)
		);
	}

	function BusinessForm( props ) {
		var s = useState( { businessType: 'company', companyName: '', taxId: '', industry: '5734', website: '', productDescription: '', phone: '', line1: '', postalCode: '', city: '', country: 'CH', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ]; function up( o ) { set( Object.assign( {}, st, { msg: '', error: '' }, o ) ); }
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true } ) );
			api( 'POST', '/api/stripe/connect/business', { businessType: st.businessType, companyName: st.companyName, taxId: st.taxId, industry: st.industry, website: st.website, productDescription: st.productDescription, phone: st.phone, address: { line1: st.line1, postalCode: st.postalCode, city: st.city, country: st.country } } )
				.then( function () { set( Object.assign( {}, st, { busy: false, msg: _t('Gespeichert.') } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		return cardForm( '1. Geschäftsangaben', el( 'div', null,
			Field( _t('Art'), el( 'select', { value: st.businessType, onChange: function ( e ) { up( { businessType: e.target.value } ); } }, el( 'option', { value: 'company' }, _t('Firma') ), el( 'option', { value: 'individual' }, _t('Einzelunternehmen') ) ) ),
			Field( _t('Firmenname'), el( 'input', { value: st.companyName, onChange: function ( e ) { up( { companyName: e.target.value } ); } } ) ),
			Field( _t('UID / Steuernr.'), el( 'input', { value: st.taxId, onChange: function ( e ) { up( { taxId: e.target.value } ); } } ) ),
			Field( _t('Branche'), el( 'select', { value: st.industry, onChange: function ( e ) { up( { industry: e.target.value } ); } }, MCC.map( function ( m ) { return el( 'option', { key: m[ 0 ], value: m[ 0 ] }, m[ 1 ] ); } ) ) ),
			Field( _t('Website'), el( 'input', { value: st.website, onChange: function ( e ) { up( { website: e.target.value } ); } }, _t('oder Beschreibung unten') ) ),
			Field( _t('Produktbeschreibung'), el( 'input', { value: st.productDescription, onChange: function ( e ) { up( { productDescription: e.target.value } ); } } ) ),
			Field( _t('Telefon'), el( 'input', { value: st.phone, onChange: function ( e ) { up( { phone: e.target.value } ); } } ) ),
			Field( _t('Strasse'), el( 'input', { value: st.line1, onChange: function ( e ) { up( { line1: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-two' }, Field( 'PLZ', el( 'input', { value: st.postalCode, onChange: function ( e ) { up( { postalCode: e.target.value } ); } } ) ), Field( _t('Ort'), el( 'input', { value: st.city, onChange: function ( e ) { up( { city: e.target.value } ); } } ) ) )
		), st, save );
	}

	function PersonForm( props ) {
		var s = useState( { firstName: '', lastName: '', email: '', phone: '', day: '', month: '', year: '', line1: '', postalCode: '', city: '', isOwner: true, percentOwnership: '', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ]; function up( o ) { set( Object.assign( {}, st, { msg: '', error: '' }, o ) ); }
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true } ) );
			api( 'POST', '/api/stripe/connect/person', { firstName: st.firstName, lastName: st.lastName, email: st.email, phone: st.phone, dob: { day: parseInt( st.day, 10 ), month: parseInt( st.month, 10 ), year: parseInt( st.year, 10 ) }, address: { line1: st.line1, postalCode: st.postalCode, city: st.city }, isOwner: st.isOwner, percentOwnership: st.percentOwnership ? parseInt( st.percentOwnership, 10 ) : undefined } )
				.then( function () { set( Object.assign( {}, st, { busy: false, msg: _t('Gespeichert.') } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		return cardForm( '2. Vertretungsberechtigte Person', el( 'div', null,
			el( 'div', { className: 'ec-two' }, Field( _t('Vorname'), el( 'input', { value: st.firstName, onChange: function ( e ) { up( { firstName: e.target.value } ); } } ) ), Field( _t('Nachname'), el( 'input', { value: st.lastName, onChange: function ( e ) { up( { lastName: e.target.value } ); } } ) ) ),
			Field( _t('E-Mail'), el( 'input', { type: 'email', value: st.email, onChange: function ( e ) { up( { email: e.target.value } ); } } ) ),
			el( 'span', { className: 'ec-field' }, el( 'span', null, _t('Geburtsdatum') ), el( 'div', { className: 'ec-dob' },
				el( 'input', { type: 'number', placeholder: 'TT', value: st.day, onChange: function ( e ) { up( { day: e.target.value } ); } } ),
				el( 'input', { type: 'number', placeholder: 'MM', value: st.month, onChange: function ( e ) { up( { month: e.target.value } ); } } ),
				el( 'input', { type: 'number', placeholder: 'JJJJ', value: st.year, onChange: function ( e ) { up( { year: e.target.value } ); } } ) ) ),
			Field( _t('Strasse'), el( 'input', { value: st.line1, onChange: function ( e ) { up( { line1: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-two' }, Field( 'PLZ', el( 'input', { value: st.postalCode, onChange: function ( e ) { up( { postalCode: e.target.value } ); } } ) ), Field( _t('Ort'), el( 'input', { value: st.city, onChange: function ( e ) { up( { city: e.target.value } ); } } ) ) ),
			el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.isOwner, onChange: function ( e ) { up( { isOwner: e.target.checked } ); } } ), _t(' Eigentümer/in') ),
			st.isOwner && Field( _t('Anteil (%)'), el( 'input', { type: 'number', value: st.percentOwnership, onChange: function ( e ) { up( { percentOwnership: e.target.value } ); } } ) )
		), st, save );
	}

	function PersonsCard() {
		var s = useState( { persons: null, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/stripe/connect/persons' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { persons: ( b && b.persons ) || [] } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { persons: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function del( id ) { if ( ! window.confirm( _t('Person entfernen?') ) ) { return; } api( 'DELETE', '/api/stripe/connect/persons?personId=' + id ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		function confirmOwners() { api( 'POST', '/api/stripe/connect/confirm-owners', { owners: true, directors: true, executives: true } ).then( function () { window.alert( _t('Bestätigt.') ); } ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', { className: 'ec-card' }, el( 'h3', null, '3. Weitere Eigentümer/Direktoren' ), ErrorBox( st.error ),
			st.persons === null ? Spinner() : st.persons.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Keine weiteren Personen.') ) :
				el( 'ul', { className: 'ec-tasklist' }, st.persons.map( function ( p ) { return el( 'li', { key: p.id }, ( p.firstName || '' ) + ' ' + ( p.lastName || '' ), ' ', el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); del( p.id ); } }, 'entfernen' ) ); } ) ),
			el( 'div', { className: 'ec-form-actions' }, el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm', onClick: confirmOwners }, _t('Alle Eigentümer angegeben') ) ) );
	}

	function BankForm( props ) {
		var s = useState( { iban: '', accountHolderName: '', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'POST', '/api/stripe/connect/bank', { iban: st.iban, accountHolderName: st.accountHolderName } ).then( function () { set( Object.assign( {}, st, { busy: false, msg: _t('Gespeichert.') } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( '4. Bankverbindung', el( 'div', null,
			Field( 'IBAN', el( 'input', { value: st.iban, onChange: function ( e ) { set( Object.assign( {}, st, { iban: e.target.value } ) ); } } ) ),
			Field( _t('Kontoinhaber'), el( 'input', { value: st.accountHolderName, onChange: function ( e ) { set( Object.assign( {}, st, { accountHolderName: e.target.value } ) ); } } ) )
		), st, save );
	}

	function DocsCard() {
		var s = useState( { busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function upId( e ) { var f = e.target.files[ 0 ]; if ( ! f ) { return; } set( { busy: true, msg: '', error: '' } ); uploadFile( 'POST', '/api/stripe/connect/document', 'front', f ).then( function () { set( { busy: false, msg: _t('Ausweis hochgeladen.') } ); } ).catch( function ( err ) { set( { busy: false, error: err.message } ); } ); }
		function upCo( e ) { var f = e.target.files[ 0 ]; if ( ! f ) { return; } set( { busy: true, msg: '', error: '' } ); uploadFile( 'POST', '/api/stripe/connect/company-document', 'document', f ).then( function () { set( { busy: false, msg: _t('Firmendokument hochgeladen.') } ); } ).catch( function ( err ) { set( { busy: false, error: err.message } ); } ); }
		return el( 'div', { className: 'ec-card' }, el( 'h3', null, '5. Dokumente' ), st.msg && el( 'div', { className: 'ec-alert' }, st.msg ), ErrorBox( st.error ),
			Field( _t('Ausweis / Pass'), el( 'input', { type: 'file', accept: 'image/*,.pdf', onChange: upId, disabled: st.busy } ) ),
			Field( _t('Handelsregisterauszug'), el( 'input', { type: 'file', accept: 'image/*,.pdf', onChange: upCo, disabled: st.busy } ) ) );
	}

	function TermsCard( props ) {
		var s = useState( { busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function accept() { set( { busy: true, msg: '', error: '' } ); api( 'POST', '/api/stripe/connect/terms', {} ).then( function ( b ) { if ( b && b.redirectUrl ) { window.open( b.redirectUrl, '_blank', 'noopener' ); } set( { busy: false, msg: _t('AGB akzeptiert.') } ); props.onSaved(); } ).catch( function ( err ) { set( { busy: false, error: err.message } ); } ); }
		return el( 'div', { className: 'ec-card' }, el( 'h3', null, '6. AGB akzeptieren' ), st.msg && el( 'div', { className: 'ec-alert' }, st.msg ), ErrorBox( st.error ),
			el( 'p', { className: 'ec-muted ec-sm' }, _t('Mit dem Akzeptieren bestätigst du die Nutzungsbedingungen für die Zahlungsabwicklung.') ),
			el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy, onClick: accept }, st.busy ? '…' : _t('AGB akzeptieren') ) );
	}

	// --- Emails -------------------------------------------------------------

	function EmailsView() {
		var s = useState( { tab: 'templates' } );
		var st = s[ 0 ], set = s[ 1 ];
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('E-Mails') ),
				el( 'div', null, el( 'button', { className: 'ec-btn ec-btn-sm' + ( st.tab === 'templates' ? ' ec-btn-primary' : '' ), onClick: function () { set( { tab: 'templates' } ); } }, _t('Vorlagen') ), ' ',
					el( 'button', { className: 'ec-btn ec-btn-sm' + ( st.tab === 'logs' ? ' ec-btn-primary' : '' ), onClick: function () { set( { tab: 'logs' } ); } }, _t('Protokoll') ) ) ),
			st.tab === 'templates' ? el( EmailTemplates, null ) : el( EmailLogs, null )
		);
	}
	// Alle Vorlagen-Typen — identisch zum WooCommerce-Tab. Gelten fuer ALLE
	// Bestellungen (WooCommerce wie Standalone). Rechnung/Mahnung nur bei Tarif
	// mit Rechnungen.
	var EC_MAIL_TYPES = [
		{ type: 'confirmation', label: _t('Bestellbestätigung an Käufer'), vars: '{{customer_name}}, {{order_number}}, {{items}}, {{total}}, {{subtotal}}, {{vat_amount}}, {{company_name}}, {{company_address}}, {{company_email}}, {{date}}' },
		{ type: 'merchant_order', label: _t('„Neue Bestellung" an dich'), vars: '{{customer_name}}, {{customer_email}}, {{order_number}}, {{items}}, {{total}}, {{company_name}}, {{date}}' },
		{ type: 'decline', label: _t('Zahlung fehlgeschlagen (an Käufer)'), vars: '{{customer_name}}, {{order_number}}, {{total}}, {{company_name}}' },
		{ type: 'refund', label: _t('Rückerstattung bestätigt (an Käufer)'), vars: '{{customer_name}}, {{order_number}}, {{total}}, {{company_name}}' },
		{ type: 'invoice', label: _t('Rechnung an Käufer'), invoiceOnly: true, vars: '{{customer_name}}, {{invoice_number}}, {{invoice_date}}, {{due_date}}, {{total}}, {{invoice_url}}, {{company_name}}, {{company_email}}' },
		{ type: 'reminder', label: _t('Mahnung / Zahlungserinnerung'), invoiceOnly: true, vars: '{{customer_name}}, {{invoice_number}}, {{due_date}}, {{total}}, {{invoice_url}}, {{company_name}}' }
	];
	function EmailTemplates() {
		var s = useState( { items: null, error: '', editing: null, canInvoice: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() {
			api( 'GET', '/api/emails' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.templates ) || [] } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } );
			api( 'GET', '/api/auth/me' ).then( function ( b ) { var m = ( b && b.merchant ) || b || {}; window.ECNative = window.ECNative || {}; window.ECNative.merchant = m; var allowed = ( m.invoicesAllowed !== undefined ) ? !! m.invoicesAllowed : !! ( m.planLimits && m.planLimits.invoices ); set( function ( p ) { return Object.assign( {}, p, { canInvoice: allowed } ); } ); } ).catch( function () { set( function ( p ) { return Object.assign( {}, p, { canInvoice: true } ); } ); } );
		}
		useEffect( function () { load(); }, [] );
		function byType( t ) { return ( st.items || [] ).filter( function ( x ) { return x.type === t; } )[ 0 ] || null; }
		var visible = EC_MAIL_TYPES.filter( function ( d ) { return ! d.invoiceOnly || st.canInvoice; } );
		return el( 'div', null, ErrorBox( st.error ),
			el( 'p', { className: 'ec-muted' }, _t('Diese Vorlagen gelten für alle Bestellungen — WooCommerce wie Standalone. Du kannst HTML oder reinen Text schreiben, beides funktioniert. Leere Vorlage = Standard.') ),
			st.editing && el( EmailTemplateForm, { def: st.editing.def, tpl: st.editing.tpl, onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			( st.items === null || st.canInvoice === null ) ? Spinner() : el( 'table', { className: 'ec-table' }, el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Vorlage') ), el( 'th', null, _t('Betreff') ), el( 'th', null, _t('Angepasst') ), el( 'th', null, '' ) ) ),
				el( 'tbody', null, visible.map( function ( d ) {
					var t = byType( d.type );
					return el( 'tr', { key: d.type }, el( 'td', null, d.label ), el( 'td', null, t ? t.subject : el( 'span', { className: 'ec-muted' }, _t('Standard') ) ), el( 'td', null, t ? 'Ja' : el( 'span', { className: 'ec-muted' }, '—' ) ), el( 'td', { className: 'ec-row-actions' }, el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { editing: { def: d, tpl: t || { type: d.type } } } ) ); } }, _t('Bearbeiten') ) ) );
				} ) ) ),
			( st.canInvoice === false ) ? el( 'p', { className: 'ec-muted' }, _t('Rechnung und Mahnung erscheinen, sobald dein Tarif Rechnungen enthält.') ) : null );
	}
	function EmailTemplateForm( props ) {
		var def = props.def || {};
		var s = useState( Object.assign( { busy: false, error: '' }, props.tpl ) );
		var st = s[ 0 ], set = s[ 1 ]; function up( o ) { set( Object.assign( {}, st, { error: '' }, o ) ); }
		function save( e ) { e.preventDefault(); if ( ! ( st.subject && st.body ) ) { up( { error: _t('Bitte Betreff und Inhalt ausfüllen.') } ); return; } set( Object.assign( {}, st, { busy: true } ) ); api( 'POST', '/api/emails', { type: st.type, subject: st.subject, body: st.body, isActive: true } ).then( function () { props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return el( 'div', { className: 'ec-modal' }, el( 'form', { className: 'ec-modal-card', onSubmit: save }, el( 'h3', null, def.label || ( 'Vorlage: ' + st.type ) ), ErrorBox( st.error ),
			Field( _t('Betreff'), el( 'input', { value: st.subject || '', onChange: function ( e ) { up( { subject: e.target.value } ); } } ) ),
			Field( _t('Inhalt (HTML oder reiner Text)'), el( 'textarea', { rows: 10, value: st.body || '', onChange: function ( e ) { up( { body: e.target.value } ); } } ) ),
			def.vars ? el( 'p', { className: 'ec-muted', style: { fontSize: '12px' } }, 'Platzhalter: ' + def.vars ) : null,
			el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, _t('Speichern') ), el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, _t('Abbrechen') ) ) ) );
	}
	function EmailLogs() {
		var s = useState( { data: null } ); var st = s[ 0 ], set = s[ 1 ];
		useEffect( function () { api( 'GET', '/api/email-logs?limit=50' ).then( set ).catch( function () { set( { emails: [] } ); } ); }, [] );
		var rows = st && st.emails;
		return rows == null ? Spinner() : el( 'table', { className: 'ec-table' }, el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Datum') ), el( 'th', null, _t('An') ), el( 'th', null, _t('Betreff') ), el( 'th', null, _t('Status') ) ) ),
			el( 'tbody', null, rows.map( function ( m ) { return el( 'tr', { key: m.id }, el( 'td', null, fmtDate( m.createdAt ) ), el( 'td', null, m.toEmail ), el( 'td', null, m.subject ), el( 'td', null, m.status ) ); } ) ) );
	}

	// --- Webhooks / Support / Billing ---------------------------------------

	function WebhooksView() {
		var s = useState( { items: null, error: '', url: '', events: 'order.paid,order.refunded' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/merchant/webhooks' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && ( b.endpoints || b.webhooks ) ) || [] } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function add( e ) { e.preventDefault(); api( 'POST', '/api/merchant/webhooks', { url: st.url, events: st.events.split( ',' ).map( function ( x ) { return x.trim(); } ).filter( Boolean ), isActive: true } ).then( function () { set( Object.assign( {}, st, { url: '' } ) ); load(); } ).catch( function ( err ) { window.alert( err.message ); } ); }
		function del( w ) { if ( ! window.confirm( _t('Webhook löschen?') ) ) { return; } api( 'DELETE', '/api/merchant/webhooks?id=' + w.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Webhooks') ) ), ErrorBox( st.error ),
			el( 'form', { className: 'ec-inline-form', onSubmit: add }, el( 'input', { type: 'url', placeholder: 'https://…', required: true, value: st.url, onChange: function ( e ) { set( Object.assign( {}, st, { url: e.target.value } ) ); }, style: { flex: '1' } } ), el( 'input', { placeholder: _t('events (kommagetrennt)'), value: st.events, onChange: function ( e ) { set( Object.assign( {}, st, { events: e.target.value } ) ); } } ), el( 'button', { className: 'ec-btn ec-btn-primary' }, _t('Hinzufügen') ) ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Keine Webhooks.') ) :
				el( 'table', { className: 'ec-table' }, el( 'thead', null, el( 'tr', null, el( 'th', null, 'URL' ), el( 'th', null, _t('Events') ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( w ) { return el( 'tr', { key: w.id }, el( 'td', null, el( 'code', null, w.url ) ), el( 'td', null, ( w.events || [] ).join( ', ' ) ), el( 'td', { className: 'ec-row-actions' }, el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( w ); } }, _t('Löschen') ) ) ); } ) ) ) );
	}

	function SupportView() {
		var s = useState( { items: null, error: '', creating: false, subject: '', message: '', category: 'general', priority: 'normal' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/support/tickets' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.tickets ) || [] } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function create( e ) { e.preventDefault(); api( 'POST', '/api/support/tickets', { subject: st.subject, message: st.message, category: st.category, priority: st.priority } ).then( function () { set( Object.assign( {}, st, { creating: false, subject: '', message: '' } ) ); load(); } ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Support') ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { creating: true } ) ); } }, _t('+ Anfrage') ) ), ErrorBox( st.error ),
			st.creating && el( 'form', { className: 'ec-card', onSubmit: create, style: { marginBottom: '14px' } },
				Field( _t('Betreff'), el( 'input', { required: true, value: st.subject, onChange: function ( e ) { set( Object.assign( {}, st, { subject: e.target.value } ) ); } } ) ),
				Field( _t('Nachricht'), el( 'textarea', { rows: 4, required: true, value: st.message, onChange: function ( e ) { set( Object.assign( {}, st, { message: e.target.value } ) ); } } ) ),
				el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary' }, _t('Senden') ), el( 'button', { type: 'button', className: 'ec-btn', onClick: function () { set( Object.assign( {}, st, { creating: false } ) ); } }, _t('Abbrechen') ) ) ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Keine Anfragen.') ) :
				el( 'table', { className: 'ec-table' }, el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Nummer') ), el( 'th', null, _t('Betreff') ), el( 'th', null, _t('Status') ), el( 'th', null, _t('Datum') ) ) ),
					el( 'tbody', null, st.items.map( function ( t ) { return el( 'tr', { key: t.id }, el( 'td', null, el( 'code', null, t.ticketNumber ) ), el( 'td', null, t.subject ), el( 'td', null, t.status ), el( 'td', null, fmtDate( t.createdAt ) ) ); } ) ) ) );
	}

	// Tarife werden von der Plattform geholt (/api/plans), nicht mehr hier gepflegt.
	// Vorher stand hier «3,5 % + CHF 0,35» — falsch in beiderlei Hinsicht: Basic
	// kostet 2,9 % + 0.35, und die Waehrung haengt am Konto des Haendlers.
	// Der Fallback greift nur, wenn die Plattform nicht erreichbar ist.
	// Welche Tarife das Plugin anzeigt. Free und Basic sind die einzigen real
	// vergebenen; die uebrigen bleiben dem Dashboard vorbehalten.
	var PLAN_KEYS = [ 'free', 'basic' ];
	function planFeeLabel( plan, currency ) {
		if ( ! plan ) { return ''; }
		var cur = ( currency || 'CHF' ).toUpperCase();
		var pct = String( plan.percentDisplay ).replace( '.', ',' );
		var fix = Number( plan.fixed ).toFixed( 2 ).replace( '.', ',' );
		return pct + ' % + ' + cur + ' ' + fix;
	}
	function BillingView() {
		var s = useState( { me: null, error: '', busy: '', plans: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/auth/me' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { me: ( b && b.merchant ) || b } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { error: err.message } ); } ); } ); }
		// Tarifsaetze von der Plattform, damit sie nicht im Plugin veralten.
		function loadPlans() { api( 'GET', '/api/plans' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { plans: ( b && b.plans ) || [] } ); } ); } ).catch( function () { set( function ( p ) { return Object.assign( {}, p, { plans: [] } ); } ); } ); }
		useEffect( function () { load(); loadPlans(); }, [] );
		function choose( plan ) {
			if ( plan === 'free' ) { set( Object.assign( {}, st, { busy: plan } ) ); api( 'POST', '/api/subscription/checkout', { plan: 'free' } ).then( function () { set( Object.assign( {}, st, { busy: '' } ) ); load(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: '', error: err.message } ) ); } ); return; }
			// Paid plans require card payment -> hosted billing in a new tab.
			window.open( ecNative.appUrl + '/dashboard/billing', '_blank', 'noopener' );
		}
		if ( ! st.me ) { return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Tarif') ) ), st.error ? ErrorBox( st.error ) : Spinner() ); }
		return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Tarif & Add-ons') ) ), ErrorBox( st.error ),
			el( 'p', null, 'Aktueller Tarif: ', el( 'strong', null, st.me.plan ) ),
			el( 'div', { className: 'ec-stat-grid' }, ( st.plans || [] ).filter( function ( pl ) { return PLAN_KEYS.indexOf( pl.key ) !== -1; } ).map( function ( pl ) {
				var current = st.me.plan === pl.key;
				return el( 'div', { key: pl.key, className: 'ec-stat' }, el( 'div', { className: 'ec-stat-val', style: { fontSize: '18px' } }, pl.label ),
					el( 'div', { className: 'ec-stat-lbl', style: { marginTop: '4px' } }, 'Kommission: ', el( 'strong', null, planFeeLabel( pl, st.me.defaultCurrency ) ) ),
					el( 'button', { className: 'ec-btn ec-btn-sm' + ( current ? '' : ' ec-btn-primary' ), disabled: current || st.busy === pl.key, onClick: function () { choose( pl.key ); }, style: { marginTop: '8px' } }, current ? 'Aktiv' : ( pl.key === 'free' ? 'Wechseln' : _t('Upgrade ↗') ) ) );
			} ) ),
			el( 'p', { className: 'ec-muted ec-sm', style: { marginTop: '12px' } }, 'Kommission pro erfolgreicher Zahlung – alle Zahlungsgebühren inklusive, keine versteckten Kosten. Kostenpflichtige Tarife: Kartenzahlung über die sichere EasyCheckout-Seite (neuer Tab).' )
		);
	}

	// --- Invoices -----------------------------------------------------------

	function InvoicesView( props ) {
		var s = useState( { items: null, error: '', editing: null, canInvoice: null, planHasInvoices: false, country: '', plan: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/invoices' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.invoices ) || [], error: '' } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		function loadPlan() { api( 'GET', '/api/auth/me' ).then( function ( b ) { var m = ( b && b.merchant ) || b || {}; var lim = m.planLimits || {}; window.ECNative = window.ECNative || {}; window.ECNative.merchant = m; var allowed = ( m.invoicesAllowed !== undefined ) ? !! m.invoicesAllowed : !! lim.invoices; set( function ( p ) { return Object.assign( {}, p, { canInvoice: allowed, planHasInvoices: !! lim.invoices, country: m.country || 'CH', plan: m.plan || '' } ); } ); } ).catch( function () { set( function ( p ) { return Object.assign( {}, p, { canInvoice: true } ); } ); } ); }
		useEffect( function () { loadPlan(); load(); }, [] );
		if ( st.canInvoice === null ) { return Spinner(); }
		if ( st.canInvoice === false ) {
			return el( 'div', null,
				el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Rechnungen') ) ),
				el( 'div', { className: 'ec-alert' },
					( st.planHasInvoices && st.country && st.country !== 'CH' )
						? el( 'p', null, el( 'strong', null, _t('Rechnungen stehen derzeit nur für Händler mit Sitz in der Schweiz zur Verfügung.') ) )
						: el( 'p', null, el( 'strong', null, _t('Rechnungen sind in deinem Tarif nicht enthalten.') ) ),
					( st.planHasInvoices && st.country && st.country !== 'CH' )
						? el( 'p', { className: 'ec-muted' }, 'Das Rechnungsmodul folgt Schweizer Vorgaben (QR-Rechnung, MwSt-Sätze, Pflichtangaben) und wäre für dein Land formal nicht korrekt.' )
						: el( 'p', { className: 'ec-muted' }, 'Verfügbar ab Tarif „Rechnungen", „Basic" oder „Pro". Aktueller Tarif: ' + ( st.plan || 'Free' ) + '.' ),
					el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { if ( props.navigate ) { props.navigate( 'billing' ); } } }, _t('Tarif ansehen') )
				)
			);
		}
		function act( inv, what ) {
			var p;
			if ( what === 'send' ) { p = api( 'POST', '/api/invoices/' + inv.id + '/send', {} ); }
			else if ( what === 'reminder' ) { p = api( 'POST', '/api/invoices/' + inv.id + '/reminder', {} ); }
			else if ( what === 'delete' ) { if ( ! window.confirm( _t('Rechnung löschen?') ) ) { return; } p = api( 'DELETE', '/api/invoices/' + inv.id ); }
			p.then( function ( b ) { if ( what === 'send' && b && b.invoiceUrl ) { window.alert( 'Rechnung gesendet. Link: ' + b.invoiceUrl ); } load(); } ).catch( function ( err ) { window.alert( err.message ); } );
		}
		// Rechnung ansehen / als PDF öffnen: erst (falls nötig) einen öffentlichen
		// Freigabe-Token erzeugen, dann die Ansicht bzw. das PDF in einem neuen Tab
		// öffnen. Läuft ohne vorheriges Versenden.
		function openInvoice( inv, mode ) {
			var appUrl = ( ecNative.appUrl || 'https://www.easycheckout.ch' ).replace( /\/$/, '' );
			function go( token ) {
				if ( ! token ) { window.alert( _t('Vorschau-Link konnte nicht erstellt werden.') ); return; }
				var url = ( mode === 'pdf' )
					? ( appUrl + '/api/public/invoice/' + token + '/pdf' )
					: ( appUrl + '/rechnung/' + token );
				window.open( url, '_blank' );
			}
			if ( inv.publicToken ) { go( inv.publicToken ); return; }
			api( 'POST', '/api/invoices/' + inv.id + '/preview', {} )
				.then( function ( b ) { go( b && b.publicToken ); } )
				.catch( function ( err ) { window.alert( err.message ); } );
		}
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Rechnungen') ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { editing: {} } ) ); } }, _t('+ Neue Rechnung') ) ),
			ErrorBox( st.error ),
			st.editing && el( InvoiceForm, { invoice: st.editing.id ? st.editing : null, onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Noch keine Rechnungen.') ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Nummer') ), el( 'th', null, _t('Kunde') ), el( 'th', null, _t('Betrag') ), el( 'th', null, _t('Fällig') ), el( 'th', null, _t('Status') ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( inv ) {
						return el( 'tr', { key: inv.id },
							el( 'td', null, el( 'code', null, inv.invoiceNumber || '—' ) ),
							el( 'td', null, inv.customerName || inv.customerEmail || '—' ),
							el( 'td', null, fmtMoney( inv.total, inv.currency ) ),
							el( 'td', null, fmtDate( inv.dueDate ) ),
							el( 'td', null, invStatus( inv.status ) ),
							el( 'td', { className: 'ec-row-actions' },
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { openInvoice( inv, 'view' ); } }, _t('Ansehen') ), ' ',
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { openInvoice( inv, 'pdf' ); } }, 'PDF' ), ' ',
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { editing: Object.assign( {}, inv ) } ) ); } }, _t('Bearbeiten') ), ' ',
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { act( inv, 'send' ); } }, _t('Senden') ), ' ',
								( inv.status === 'sent' || inv.status === 'overdue' ) && el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { act( inv, 'reminder' ); } }, _t('Mahnen') ), ' ',
								el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { act( inv, 'delete' ); } }, _t('Löschen') ) )
						);
					} ) )
				)
		);
	}

	function invStatus( s ) {
		var m = { draft: [ 'ec-badge-off', _t('Entwurf') ], sent: [ 'ec-badge-off', _t('Gesendet') ], paid: [ 'ec-badge-on', _t('Bezahlt') ], overdue: [ 'ec-badge-err', _t('Überfällig') ], cancelled: [ 'ec-badge-err', _t('Storniert') ] }[ s ] || [ 'ec-badge-off', s || '—' ];
		return el( 'span', { className: 'ec-badge ' + m[ 0 ] }, m[ 1 ] );
	}

	function InvoiceForm( props ) {
		var inv = props.invoice;
		// Vorbelegung aus dem Haendlerkonto statt fix Schweiz. Rechnungen sind zwar
		// derzeit CH-only, aber die Werte sollen aus einer Quelle kommen.
		var me = ( window.ECNative && window.ECNative.merchant ) || {};
		var init = { customerEmail: '', customerName: '', customerStreet: '', customerPostalCode: '', customerCity: '', customerCountry: me.country || 'CH', vatRate: ( me.country && me.country !== 'CH' ) ? 0 : 8.1, dueDate: '', notes: '', currency: me.defaultCurrency || 'CHF' };
		if ( inv ) { Object.keys( init ).forEach( function ( k ) { if ( inv[ k ] != null ) { init[ k ] = inv[ k ]; } } ); }
		var items0 = ( inv && inv.items && inv.items.length ) ? inv.items.map( function ( i ) { return { quantity: i.quantity || 1, price: i.price != null ? i.price : '', description: i.description || '' }; } ) : [ { quantity: 1, price: '', description: '' } ];
		var s = useState( Object.assign( { busy: false, error: '', items: items0 }, init ) );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, { error: '' }, o ) ); }
		function setItem( i, k, v ) { var it = st.items.slice(); it[ i ] = Object.assign( {}, it[ i ] ); it[ i ][ k ] = v; up( { items: it } ); }
		function addItem() { up( { items: st.items.concat( [ { quantity: 1, price: '', description: '' } ] ) } ); }
		function rmItem( i ) { var it = st.items.slice(); it.splice( i, 1 ); up( { items: it.length ? it : [ { quantity: 1, price: '', description: '' } ] } ); }
		function save( e ) {
			e.preventDefault(); set( Object.assign( {}, st, { busy: true, error: '' } ) );
			var payload = {
				customerEmail: st.customerEmail, customerName: st.customerName, customerStreet: st.customerStreet, customerPostalCode: st.customerPostalCode, customerCity: st.customerCity, customerCountry: st.customerCountry || 'CH',
				items: st.items.map( function ( i ) { return { quantity: parseInt( i.quantity, 10 ) || 1, price: parseFloat( i.price ) || 0, description: i.description }; } ),
				vatRate: parseFloat( st.vatRate ) || 0, dueDate: st.dueDate || undefined, notes: st.notes, currency: st.currency || 'CHF',
			};
			var pr = ( inv && inv.id ) ? api( 'PUT', '/api/invoices/' + inv.id, payload ) : api( 'POST', '/api/invoices', payload );
			pr.then( function () { props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		return el( 'div', { className: 'ec-modal' }, el( 'form', { className: 'ec-modal-card', onSubmit: save },
			el( 'h3', null, ( inv && inv.id ) ? 'Rechnung bearbeiten' : _t('Neue Rechnung') ), ErrorBox( st.error ),
			Field( _t('Kunden-E-Mail'), el( 'input', { type: 'email', required: true, value: st.customerEmail, onChange: function ( e ) { up( { customerEmail: e.target.value } ); } } ) ),
			Field( _t('Kundenname'), el( 'input', { required: true, value: st.customerName, onChange: function ( e ) { up( { customerName: e.target.value } ); } } ) ),
			Field( _t('Strasse'), el( 'input', { value: st.customerStreet, onChange: function ( e ) { up( { customerStreet: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-two' }, Field( 'PLZ', el( 'input', { value: st.customerPostalCode, onChange: function ( e ) { up( { customerPostalCode: e.target.value } ); } } ) ), Field( _t('Ort'), el( 'input', { value: st.customerCity, onChange: function ( e ) { up( { customerCity: e.target.value } ); } } ) ) ),
			el( 'div', { className: 'ec-items' }, el( 'div', { className: 'ec-items-head' }, el( 'span', null, _t('Positionen') ), el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm', onClick: addItem }, _t('+ Position') ) ),
				st.items.map( function ( it, i ) {
					return el( 'div', { key: i, className: 'ec-item-row' },
						el( 'input', { className: 'ec-item-desc', placeholder: _t('Beschreibung'), value: it.description, onChange: function ( e ) { setItem( i, 'description', e.target.value ); } } ),
						el( 'input', { className: 'ec-item-qty', type: 'number', min: 1, value: it.quantity, onChange: function ( e ) { setItem( i, 'quantity', e.target.value ); } } ),
						el( 'input', { className: 'ec-item-price', type: 'number', step: '0.01', placeholder: _t('Preis'), value: it.price, onChange: function ( e ) { setItem( i, 'price', e.target.value ); } } ),
						el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { rmItem( i ); } }, '×' ) );
				} ) ),
			el( 'div', { className: 'ec-two' },
				Field( _t('MwSt-Satz (%)'), el( 'input', { type: 'number', step: '0.1', value: st.vatRate, onChange: function ( e ) { up( { vatRate: e.target.value } ); } } ) ),
				Field( _t('Fällig am'), el( 'input', { type: 'date', value: ( st.dueDate || '' ).slice( 0, 10 ), onChange: function ( e ) { up( { dueDate: e.target.value } ); } } ) ) ),
			Field( _t('Notizen'), el( 'textarea', { rows: 2, value: st.notes, onChange: function ( e ) { up( { notes: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-form-actions' },
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : _t('Speichern') ),
				el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, _t('Abbrechen') ) )
		) );
	}

	// --- Overview -----------------------------------------------------------

	// WooCommerce-Gateway: self-service aktivieren (Key erzeugen + Webhook registrieren).
	function WooGatewayCard() {
		var s = useState( { st: null, busy: false, msg: '', error: '' } );
		var v = s[ 0 ], set = s[ 1 ];
		function load() {
			post( 'easycheckout_gateway_status', {} ).then( function ( j ) {
				if ( j && j.success ) { set( function ( p ) { return Object.assign( {}, p, { st: j.data } ); } ); }
			} );
		}
		useEffect( function () { load(); }, [] );

		function activate() {
			set( function ( p ) { return Object.assign( {}, p, { busy: true, msg: '', error: '' } ); } );
			post( 'easycheckout_activate_gateway', {} ).then( function ( j ) {
				if ( ! j || ! j.success ) { throw new Error( ( j && j.data && j.data.message ) || _t('Fehler') ); }
				var wh = j.data.webhook;
				var note = wh === 'registered'
					? 'Gateway aktiviert und Webhook registriert.'
					: ( 'Gateway aktiviert. Webhook: ' + wh );
				set( function ( p ) { return Object.assign( {}, p, { busy: false, msg: note } ); } );
				load();
			} ).catch( function ( err ) {
				set( function ( p ) { return Object.assign( {}, p, { busy: false, error: err.message } ); } );
			} );
		}

		var st = v.st || {};
		var ready = st.apiKeySet && st.webhookSet;
		function row( ok, label ) {
			return el( 'div', { className: 'ec-kv-row' },
				el( 'span', null, ( ok ? '✓ ' : '• ' ) + label ),
				el( 'strong', { style: { color: ok ? '#059669' : '#94a3b8' } }, ok ? 'ok' : 'offen' ) );
		}
		return el( 'div', { className: 'ec-card' },
			el( 'h3', null, _t('WooCommerce-Gateway') ),
			v.msg && el( 'div', { className: 'ec-alert' }, v.msg ),
			ErrorBox( v.error ),
			el( 'p', { style: { color: '#64748b', margin: '0 0 12px' } },
				_t('Aktiviert den EasyCheckout-Bezahlweg (Karte/TWINT) und Express-Checkout in WooCommerce – erzeugt automatisch den Zahlungs-Key und registriert den Webhook.') ),
			! st.wooActive && el( 'div', { className: 'ec-alert' }, _t('WooCommerce ist nicht aktiv – bitte zuerst WooCommerce aktivieren.') ),
			row( !! st.apiKeySet, _t('Zahlungs-Key hinterlegt') ),
			row( !! st.webhookSet, _t('Webhook registriert (Order-Sync)') ),
			el( 'div', { className: 'ec-form-actions', style: { marginTop: '12px' } },
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: v.busy || ! st.authed, onClick: activate },
					v.busy ? '…' : ( ready ? 'Neu verbinden' : _t('WooCommerce-Gateway aktivieren') ) ) )
		);
	}

	function OverviewView() {
		var s = useState( null );
		var stats = s[ 0 ], set = s[ 1 ];
		useEffect( function () { api( 'GET', '/api/dashboard/stats' ).then( set ).catch( function () { set( {} ); } ); }, [] );
		var cards = [
			[ _t('Umsatz (30 Tage)'), stats ? fmtMoney( stats.revenue ) : '…' ],
			[ _t('Bestellungen'), stats ? ( stats.ordersCount != null ? stats.ordersCount : 0 ) : '…' ],
			[ _t('Checkouts'), stats ? ( stats.checkoutsCount != null ? stats.checkoutsCount : 0 ) : '…' ],
			[ _t('Conversion'), stats ? ( ( stats.conversionRate != null ? stats.conversionRate : 0 ) + ' %' ) : '…' ],
		];
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Übersicht') ) ),
			el( 'div', { className: 'ec-stat-grid' }, cards.map( function ( c, i ) {
				return el( 'div', { key: i, className: 'ec-stat' }, el( 'div', { className: 'ec-stat-val' }, c[ 1 ] ), el( 'div', { className: 'ec-stat-lbl' }, c[ 0 ] ) );
			} ) ),
			el( 'div', { style: { marginTop: '20px', maxWidth: '520px' } }, el( WooGatewayCard, null ) )
		);
	}

	// --- Settings -----------------------------------------------------------

	function SettingsView() {
		var s = useState( { me: null, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function loadMe() { api( 'GET', '/api/auth/me' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { me: ( b && b.merchant ) || b } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { error: err.message } ); } ); } ); }
		useEffect( function () { loadMe(); }, [] );
		if ( ! st.me ) { return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Einstellungen') ) ), st.error ? ErrorBox( st.error ) : Spinner() ); }
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, _t('Einstellungen') ) ),
			el( 'div', { className: 'ec-form-grid' },
				el( ProfileCard, { me: st.me, onSaved: loadMe } ),
				el( LogoCard, { me: st.me, onSaved: loadMe } ),
				el( QrCard, { me: st.me, onSaved: loadMe } ),
				el( DescriptorCard, { me: st.me } ),
				el( PasswordCard, null )
			)
		);
	}

	function cardForm( title, body, st, onSubmit ) {
		return el( 'form', { className: 'ec-card', onSubmit: onSubmit }, el( 'h3', null, title ),
			st.msg && el( 'div', { className: 'ec-alert' }, st.msg ), ErrorBox( st.error ), body,
			el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : _t('Speichern') ) ) );
	}

	function ProfileCard( props ) {
		var s = useState( { d: { companyName: props.me.companyName || '', email: props.me.email || '', street: props.me.street || '', postalCode: props.me.postalCode || '', city: props.me.city || '', phone: props.me.phone || '', vatNumber: props.me.vatNumber || '' }, busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function up( k, v ) { var d = Object.assign( {}, st.d ); d[ k ] = v; set( Object.assign( {}, st, { d: d, msg: '', error: '' } ) ); }
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'PUT', '/api/auth/profile', st.d ).then( function () { set( Object.assign( {}, st, { busy: false, msg: _t('Gespeichert.') } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( _t('Firmenprofil'), el( 'div', null,
			Field( _t('Firma'), el( 'input', { value: st.d.companyName, onChange: function ( e ) { up( 'companyName', e.target.value ); } } ) ),
			Field( _t('E-Mail'), el( 'input', { type: 'email', value: st.d.email, onChange: function ( e ) { up( 'email', e.target.value ); } } ) ),
			Field( _t('Strasse'), el( 'input', { value: st.d.street, onChange: function ( e ) { up( 'street', e.target.value ); } } ) ),
			el( 'div', { className: 'ec-two' }, Field( 'PLZ', el( 'input', { value: st.d.postalCode, onChange: function ( e ) { up( 'postalCode', e.target.value ); } } ) ), Field( _t('Ort'), el( 'input', { value: st.d.city, onChange: function ( e ) { up( 'city', e.target.value ); } } ) ) ),
			Field( _t('Telefon'), el( 'input', { value: st.d.phone, onChange: function ( e ) { up( 'phone', e.target.value ); } } ) ),
			Field( _t('MwSt-Nr.'), el( 'input', { value: st.d.vatNumber, onChange: function ( e ) { up( 'vatNumber', e.target.value ); } } ) )
		), st, save );
	}

	function LogoCard( props ) {
		var s = useState( { url: props.me.logoUrl || '', busy: false, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function pick( e ) { var f = e.target.files[ 0 ]; if ( ! f ) { return; } set( Object.assign( {}, st, { busy: true, error: '' } ) ); uploadFile( 'POST', '/api/merchant/logo', 'logo', f ).then( function ( b ) { set( Object.assign( {}, st, { busy: false, url: b.logoUrl || '' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		function remove() { api( 'DELETE', '/api/merchant/logo' ).then( function () { set( Object.assign( {}, st, { url: '' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { error: err.message } ) ); } ); }
		return el( 'div', { className: 'ec-card' }, el( 'h3', null, _t('Logo') ), ErrorBox( st.error ),
			st.url ? el( 'img', { src: st.url, className: 'ec-thumb-lg' } ) : el( 'p', { className: 'ec-muted' }, _t('Kein Logo.') ),
			el( 'input', { type: 'file', accept: 'image/*', onChange: pick, disabled: st.busy } ),
			st.url && el( 'div', { style: { marginTop: '8px' } }, el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: remove }, _t('Entfernen') ) ) );
	}

	function QrCard( props ) {
		var s = useState( { iban: props.me.iban || '', enabled: !! props.me.qrPaymentEnabled, busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'PUT', '/api/auth/qr-settings', { iban: st.iban, qrPaymentEnabled: st.enabled } ).then( function () { set( Object.assign( {}, st, { busy: false, msg: _t('Gespeichert.') } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( _t('QR-Rechnung'), el( 'div', null,
			Field( _t('IBAN (CH)'), el( 'input', { value: st.iban, onChange: function ( e ) { set( Object.assign( {}, st, { iban: e.target.value } ) ); } } ) ),
			el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.enabled, onChange: function ( e ) { set( Object.assign( {}, st, { enabled: e.target.checked } ) ); } } ), _t(' QR-Zahlung aktiv') )
		), st, save );
	}

	function DescriptorCard( props ) {
		var s = useState( { v: props.me.statementDescriptor || '', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'PUT', '/api/auth/statement-descriptor', { statementDescriptor: st.v } ).then( function ( b ) { set( Object.assign( {}, st, { busy: false, msg: _t('Gespeichert.'), v: ( b && b.statementDescriptor ) || st.v } ) ); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( _t('Zahlungs-Referenz'), el( 'div', null,
			Field( _t('Text (5–22 Zeichen)'), el( 'input', { value: st.v, maxLength: 22, onChange: function ( e ) { set( Object.assign( {}, st, { v: e.target.value } ) ); } } ), _t('Erscheint auf der Kartenabrechnung des Kunden.') )
		), st, save );
	}

	function PasswordCard() {
		var s = useState( { cur: '', nw: '', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'PUT', '/api/auth/password', { currentPassword: st.cur, newPassword: st.nw } ).then( function () { set( { cur: '', nw: '', busy: false, msg: _t('Passwort geändert.'), error: '' } ); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( _t('Passwort ändern'), el( 'div', null,
			Field( _t('Aktuelles Passwort'), el( 'input', { type: 'password', value: st.cur, onChange: function ( e ) { set( Object.assign( {}, st, { cur: e.target.value } ) ); } } ) ),
			Field( _t('Neues Passwort'), el( 'input', { type: 'password', value: st.nw, onChange: function ( e ) { set( Object.assign( {}, st, { nw: e.target.value } ) ); } } ) )
		), st, save );
	}

	// --- Shell + router -----------------------------------------------------

	var NAV = [
		{ key: 'overview', label: _t('Übersicht'), icon: 'dashboard' },
		{ key: 'checkouts', label: _t('Checkouts'), icon: 'cart' },
		{ key: 'embed', label: _t('Einbindung'), icon: 'editor-code' },
		{ key: 'orders', label: _t('Bestellungen'), icon: 'list-view' },
		{ key: 'customers', label: _t('Kunden'), icon: 'groups' },
		{ key: 'invoices', label: _t('Rechnungen'), icon: 'media-document' },
		{ key: 'emails', label: _t('E-Mails'), icon: 'email' },
		{ key: 'onboarding', label: _t('Verifizierung'), icon: 'id' },
		{ key: 'billing', label: _t('Tarif'), icon: 'cart' },
		{ key: 'support', label: _t('Support'), icon: 'sos' },
		{ key: 'settings', label: _t('Einstellungen'), icon: 'admin-generic' },
	];

	// Views, die ein verbundenes Konto brauchen (Zahlungsempfang etc.).
	var WALL_TITLES = { orders: _t('Bestellungen'), customers: _t('Kunden'), invoices: _t('Rechnungen'), emails: _t('E-Mails'), onboarding: _t('Verifizierung'), billing: _t('Tarif'), webhooks: _t('Webhooks'), support: _t('Support'), settings: _t('Einstellungen') };

	function ConnectWall( props ) {
		return el( 'div', { className: 'ec-wall' },
			el( 'span', { className: 'dashicons dashicons-lock ec-wall-ico' } ),
			el( 'h2', { className: 'ec-wall-title' }, props.title || _t('Konto verbinden') ),
			el( 'p', { className: 'ec-wall-text' }, props.text || _t('Registriere dich kostenlos, um Zahlungen zu empfangen und diese Funktion zu nutzen.') ),
			el( 'button', { className: 'ec-btn ec-btn-primary', onClick: props.onConnect }, _t('Konto verbinden') )
		);
	}

	function LocalOverview( props ) {
		return el( 'div', { className: 'ec-hero' },
			el( 'h2', null, _t('Willkommen bei EasyCheckout') ),
			el( 'p', null, _t('Richte deinen Checkout in Ruhe ein und teste alles. Erst wenn du echte Zahlungen empfangen möchtest, verbindest du dein Konto.') ),
			el( 'div', { className: 'ec-hero-actions' },
				el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { props.navigate( 'checkouts' ); } }, _t('Checkout erstellen') ),
				el( 'button', { className: 'ec-btn', onClick: props.onConnect }, _t('Konto verbinden') )
			)
		);
	}

	function pmLabel( m ) { return { bank: _t('Banküberweisung'), card: _t('Karte'), twint: 'TWINT', qr: _t('QR-Rechnung') }[ m ] || m; }

	function DemoView( props ) {
		var cols = props.columns || [ _t('Eintrag') ];
		return el( 'div', null,
			el( 'div', { className: 'ec-banner' },
				el( 'span', { className: 'dashicons dashicons-info-outline' } ),
				el( 'span', { className: 'ec-banner-txt' }, props.hint || _t('Diese Daten erscheinen, sobald du dein Konto für den Online-Zahlungsempfang verbindest.') ),
				el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-primary', onClick: props.onConnect }, _t('Konto verbinden') )
			),
			el( 'table', { className: 'ec-table' },
				el( 'thead', null, el( 'tr', null, cols.map( function ( h, i ) { return el( 'th', { key: i }, h ); } ) ) ),
				el( 'tbody', null, el( 'tr', null, el( 'td', { colSpan: cols.length, className: 'ec-muted', style: { textAlign: 'center', padding: '28px' } }, _t('Noch keine Einträge.') ) ) )
			)
		);
	}

	function LocalCheckouts( props ) {
		var s = useState( { items: null, error: '', name: '', busy: false, editId: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, o ) ); }
		function reload( extra ) { return localApi( 'get' ).then( function ( items ) { set( Object.assign( {}, st, { items: items }, extra || {} ) ); } ).catch( function ( e ) { up( { error: e.message } ); } ); }
		useEffect( function () { localApi( 'get' ).then( function ( items ) { up( { items: items } ); } ).catch( function ( e ) { up( { error: e.message } ); } ); }, [] );
		function create( e ) {
			e.preventDefault();
			if ( ! st.name.trim() ) { return; }
			up( { busy: true, error: '' } );
			localApi( 'save', { data: JSON.stringify( { name: st.name, paymentMethods: [ 'bank' ] } ) } ).then( function ( item ) {
				reload( { name: '', busy: false, editId: item.id } );
			} ).catch( function ( e ) { up( { busy: false, error: e.message } ); } );
		}
		function del( id ) { localApi( 'delete', { id: id } ).then( function () { reload(); } ); }

		if ( st.editId ) {
			var current = ( st.items || [] ).filter( function ( c ) { return c.id === st.editId; } )[ 0 ];
			if ( current ) {
				return el( LocalCheckoutEditor, { checkout: current, onConnect: props.onConnect, onBack: function () { reload( { editId: null } ); } } );
			}
		}

		return el( 'div', null,
			el( 'div', { className: 'ec-banner' },
				el( 'span', { className: 'dashicons dashicons-info-outline' } ),
				el( 'span', { className: 'ec-banner-txt' }, _t('Checkouts mit Banküberweisung funktionieren ohne Konto. Für Karten-/TWINT-Zahlungen verbinde dein Konto.') ),
				el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-primary', onClick: props.onConnect }, _t('Verbinden') )
			),
			ErrorBox( st.error ),
			( st.items && st.items.length >= 1 ) ?
				el( 'div', { className: 'ec-card', style: { maxWidth: '640px', marginBottom: '16px' } },
					el( 'h3', null, _t('Weitere Checkouts & Online-Zahlung') ),
					el( 'p', { className: 'ec-muted', style: { marginTop: 0 } }, _t('Im kostenlosen Modus betreibst du einen Checkout mit Banküberweisung. Für weitere Checkouts sowie Karten-/TWINT-Zahlungen erstelle ein Konto auf easycheckout.ch – dein gebuchter Plan wird nach dem Verbinden hier übernommen.') ),
					el( 'div', { style: { display: 'flex', gap: '8px', flexWrap: 'wrap' } },
						el( 'a', { className: 'ec-btn ec-btn-primary', href: ( ecNative.appUrl || 'https://www.easycheckout.ch' ) + '/#preise', target: '_blank', rel: 'noopener' }, _t('Preise ansehen (easycheckout.ch)') ),
						el( 'button', { className: 'ec-btn', onClick: props.onConnect }, _t('Konto verbinden') )
					)
				) :
				el( 'form', { className: 'ec-inline-form', onSubmit: create },
					el( 'input', { type: 'text', placeholder: _t('Name des Checkouts'), value: st.name, onChange: function ( e ) { up( { name: e.target.value } ); } } ),
					el( 'button', { type: 'submit', className: 'ec-btn ec-btn-primary', disabled: st.busy }, _t('+ Checkout erstellen') )
				),
			st.items === null ? Spinner() :
				( st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Noch keine Checkouts. Erstelle deinen ersten oben.') ) :
					el( 'table', { className: 'ec-table' },
						el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Name') ), el( 'th', null, _t('Produkte') ), el( 'th', null, _t('Zahlung') ), el( 'th', null, '' ) ) ),
						el( 'tbody', null, st.items.map( function ( c ) {
							return el( 'tr', { key: c.id },
								el( 'td', null, c.name ),
								el( 'td', null, ( c.products || [] ).length ),
								el( 'td', null, ( c.paymentMethods || [] ).map( pmLabel ).join( ', ' ) || '—' ),
								el( 'td', { style: { textAlign: 'right' } },
									el( 'a', { className: 'ec-btn ec-btn-sm', href: previewUrl( c.slug ), target: '_blank', rel: 'noopener' }, _t('Ansehen') ),
									el( 'button', { className: 'ec-btn ec-btn-sm ec-ml', onClick: function () { up( { editId: c.id } ); } }, _t('Bearbeiten') ),
									el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger ec-ml', onClick: function () { del( c.id ); } }, _t('Löschen') ) ) );
						} ) )
					)
				)
		);
	}

	function CopyRow( label, value ) {
		return el( 'div', { style: { marginBottom: '12px' } },
			el( 'div', { style: { fontSize: '13px', fontWeight: 600, marginBottom: '5px' } }, label ),
			el( 'div', { style: { display: 'flex', gap: '8px' } },
				el( 'input', { type: 'text', readOnly: true, value: value, onFocus: function ( e ) { e.target.select(); }, style: { flex: 1, minWidth: 0, padding: '9px 12px', border: '1px solid #e5e7eb', borderRadius: '8px', fontFamily: 'Consolas,Monaco,monospace', fontSize: '13px' } } ),
				el( 'button', { className: 'ec-btn ec-btn-sm', type: 'button', onClick: function ( e ) {
					var inp = e.target.parentNode.querySelector( 'input' );
					if ( inp ) { inp.select(); }
					if ( navigator.clipboard && navigator.clipboard.writeText ) { navigator.clipboard.writeText( value ); }
					else if ( inp ) { try { document.execCommand( 'copy' ); } catch ( x ) {} }
					var b = e.target; b.textContent = _t('Kopiert ✓'); setTimeout( function () { b.textContent = _t('Kopieren'); }, 1500 );
				} }, _t('Kopieren') )
			)
		);
	}

	function FilePick( label, onFile ) {
		return el( 'label', { className: 'ec-btn ec-btn-sm', style: { cursor: 'pointer', marginBottom: 0 } },
			label,
			el( 'input', { type: 'file', accept: 'image/*', style: { display: 'none' }, onChange: function ( e ) { var f = e.target.files && e.target.files[ 0 ]; if ( f ) { onFile( f ); } e.target.value = ''; } } )
		);
	}

	function ecUid( prefix ) { return ( prefix || 'x_' ) + Math.random().toString( 36 ).slice( 2, 8 ); }

	// Voller Produkt-Editor (Modal) fuer den lokalen Checkout: Basis, Fulfillment-
	// Preise, Optionsgruppen (S/M/L/Farben) mit Aufschlag und Infofelder (Text/
	// Checkboxen). Gibt das bereinigte Produkt via onSave zurueck.
	function LocalProductEditor( props ) {
		var p0 = props.product || {};
		var s = useState( {
			id: p0.id || '',
			name: p0.name || '', price: p0.price != null ? p0.price : '', description: p0.description || '',
			imageUrl: p0.imageUrl || '', categoryId: p0.categoryId || '',
			pickupPrice: p0.pickupPrice != null ? p0.pickupPrice : '',
			deliveryPrice: p0.deliveryPrice != null ? p0.deliveryPrice : '',
			deliveryFee: p0.deliveryFee != null ? p0.deliveryFee : '',
			optionGroups: ( p0.optionGroups || [] ).map( function ( g ) { return { id: g.id, name: g.name, options: ( g.options || [] ).map( function ( o ) { return { id: o.id, label: o.label, priceModifier: o.priceModifier != null ? o.priceModifier : 0 }; } ) }; } ),
			customFields: ( p0.customFields || [] ).map( function ( f ) { return { id: f.id, label: f.label, fieldType: f.fieldType || 'text', required: !! f.required, options: ( f.options || [] ).slice() }; } ),
			imgBusy: false, error: ''
		} );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, o ) ); }

		// Optionsgruppen
		function addGroup() { up( { optionGroups: st.optionGroups.concat( [ { name: '', options: [ { label: '', priceModifier: 0 } ] } ] ) } ); }
		function delGroup( gi ) { var g = st.optionGroups.slice(); g.splice( gi, 1 ); up( { optionGroups: g } ); }
		function setGroup( gi, k, v ) { var g = st.optionGroups.slice(); g[ gi ] = Object.assign( {}, g[ gi ] ); g[ gi ][ k ] = v; up( { optionGroups: g } ); }
		function addOpt( gi ) { var g = st.optionGroups.slice(); g[ gi ] = Object.assign( {}, g[ gi ], { options: g[ gi ].options.concat( [ { label: '', priceModifier: 0 } ] ) } ); up( { optionGroups: g } ); }
		function delOpt( gi, oi ) { var g = st.optionGroups.slice(); var os = g[ gi ].options.slice(); os.splice( oi, 1 ); g[ gi ] = Object.assign( {}, g[ gi ], { options: os } ); up( { optionGroups: g } ); }
		function setOpt( gi, oi, k, v ) { var g = st.optionGroups.slice(); var os = g[ gi ].options.slice(); os[ oi ] = Object.assign( {}, os[ oi ] ); os[ oi ][ k ] = v; g[ gi ] = Object.assign( {}, g[ gi ], { options: os } ); up( { optionGroups: g } ); }

		// Infofelder
		function addField() { up( { customFields: st.customFields.concat( [ { label: '', fieldType: 'text', required: false, options: [] } ] ) } ); }
		function delField( fi ) { var f = st.customFields.slice(); f.splice( fi, 1 ); up( { customFields: f } ); }
		function setField( fi, k, v ) { var f = st.customFields.slice(); f[ fi ] = Object.assign( {}, f[ fi ] ); f[ fi ][ k ] = v; if ( k === 'fieldType' && v === 'checkbox' && ( ! f[ fi ].options || ! f[ fi ].options.length ) ) { f[ fi ].options = [ '' ]; } up( { customFields: f } ); }
		function addChoice( fi ) { var f = st.customFields.slice(); f[ fi ] = Object.assign( {}, f[ fi ], { options: ( f[ fi ].options || [] ).concat( [ '' ] ) } ); up( { customFields: f } ); }
		function delChoice( fi, ci ) { var f = st.customFields.slice(); var os = ( f[ fi ].options || [] ).slice(); os.splice( ci, 1 ); f[ fi ] = Object.assign( {}, f[ fi ], { options: os } ); up( { customFields: f } ); }
		function setChoice( fi, ci, v ) { var f = st.customFields.slice(); var os = ( f[ fi ].options || [] ).slice(); os[ ci ] = v; f[ fi ] = Object.assign( {}, f[ fi ], { options: os } ); up( { customFields: f } ); }

		function doSave() {
			if ( ! st.name.trim() ) { up( { error: _t('Bitte einen Produktnamen angeben.') } ); return; }
			var product = {
				id: st.id || ecUid( 'p_' ),
				name: st.name.trim(),
				price: parseFloat( st.price ) || 0,
				description: st.description,
				imageUrl: st.imageUrl,
				categoryId: st.categoryId || null,
				pickupPrice: st.pickupPrice === '' ? null : ( parseFloat( st.pickupPrice ) || 0 ),
				deliveryPrice: st.deliveryPrice === '' ? null : ( parseFloat( st.deliveryPrice ) || 0 ),
				deliveryFee: st.deliveryFee === '' ? null : ( parseFloat( st.deliveryFee ) || 0 ),
				optionGroups: st.optionGroups.map( function ( g ) {
					return { id: g.id || ecUid( 'g_' ), name: g.name, options: ( g.options || [] ).filter( function ( o ) { return String( o.label ).trim() !== ''; } ).map( function ( o ) { return { id: o.id || ecUid( 'o_' ), label: o.label, priceModifier: parseFloat( o.priceModifier ) || 0 }; } ) };
				} ).filter( function ( g ) { return String( g.name ).trim() !== '' && g.options.length; } ),
				customFields: st.customFields.map( function ( f ) {
					var opts = ( f.fieldType === 'checkbox' ) ? ( f.options || [] ).map( function ( x ) { return String( x ).trim(); } ).filter( Boolean ) : [];
					return { id: f.id || ecUid( 'f_' ), label: f.label, fieldType: f.fieldType, required: !! f.required, options: opts };
				} ).filter( function ( f ) { return String( f.label ).trim() !== '' && ( f.fieldType !== 'checkbox' || f.options.length ); } )
			};
			props.onSave( product );
		}

		var cur = props.currency || 'CHF';
		return el( 'div', { className: 'ec-modal', onClick: props.onClose },
			el( 'div', { className: 'ec-modal-card ec-modal-lg', onClick: function ( e ) { e.stopPropagation(); } },
				el( 'button', { className: 'ec-modal-x', onClick: props.onClose, 'aria-label': _t('Schliessen') }, '×' ),
				el( 'h3', null, st.id ? 'Produkt bearbeiten' : _t('Neues Produkt') ),
				ErrorBox( st.error ),
				// Basis
				el( 'div', { className: 'ec-two' },
					Field( _t('Name'), el( 'input', { type: 'text', value: st.name, onChange: function ( e ) { up( { name: e.target.value } ); } } ) ),
					Field( 'Preis (' + cur + ')', el( 'input', { type: 'number', step: '0.05', value: st.price, onChange: function ( e ) { up( { price: e.target.value } ); } } ) )
				),
				Field( _t('Beschreibung'), el( 'input', { type: 'text', value: st.description, onChange: function ( e ) { up( { description: e.target.value } ); } } ) ),
				( props.categories && props.categories.length ) ? Field( _t('Kategorie'), el( 'select', { value: st.categoryId || '', onChange: function ( e ) { up( { categoryId: e.target.value } ); } },
					el( 'option', { value: '' }, _t('— keine —') ),
					props.categories.map( function ( c ) { return el( 'option', { key: c.id, value: c.id }, c.name || _t('(ohne Name)') ); } )
				) ) : null,
				Field( _t('Bild'), el( 'div', null,
					st.imageUrl ? el( 'img', { src: st.imageUrl, className: 'ec-thumb-lg' } ) : null,
					el( 'div', { style: { display: 'flex', gap: '8px', marginTop: st.imageUrl ? '8px' : '0' } },
						FilePick( st.imgBusy ? 'Lädt…' : ( st.imageUrl ? 'Bild ändern' : _t('Bild hochladen') ), function ( f ) { up( { imgBusy: true } ); localUpload( f ).then( function ( d ) { up( { imageUrl: d.url, imgBusy: false } ); } ).catch( function ( e ) { up( { imgBusy: false, error: e.message } ); } ); } ),
						st.imageUrl ? el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { up( { imageUrl: '' } ); } }, _t('Entfernen') ) : null
					)
				) ),
				// Fulfillment-Preise
				el( 'h4', { className: 'ec-sub-h' }, _t('Liefer-/Abholpreise (optional)') ),
				el( 'p', { className: 'ec-hint' }, _t('Leer lassen = Standardpreis gilt. Liefergebühr wird einmal pro Position berechnet (nur bei Lieferung).') ),
				el( 'div', { className: 'ec-three' },
					Field( _t('Abholpreis'), el( 'input', { type: 'number', step: '0.05', placeholder: _t('Standard'), value: st.pickupPrice, onChange: function ( e ) { up( { pickupPrice: e.target.value } ); } } ) ),
					Field( _t('Lieferpreis'), el( 'input', { type: 'number', step: '0.05', placeholder: _t('Standard'), value: st.deliveryPrice, onChange: function ( e ) { up( { deliveryPrice: e.target.value } ); } } ) ),
					Field( _t('Liefergebühr'), el( 'input', { type: 'number', step: '0.05', placeholder: '0.00', value: st.deliveryFee, onChange: function ( e ) { up( { deliveryFee: e.target.value } ); } } ) )
				),
				// Optionsgruppen
				el( 'h4', { className: 'ec-sub-h' }, _t('Optionen (z. B. Grösse, Farbe)') ),
				st.optionGroups.map( function ( g, gi ) {
					return el( 'div', { key: gi, className: 'ec-subcard' },
						el( 'div', { className: 'ec-inline-form', style: { alignItems: 'center' } },
							el( 'input', { type: 'text', placeholder: _t('Gruppenname (z. B. Grösse)'), value: g.name, onChange: function ( e ) { setGroup( gi, 'name', e.target.value ); } } ),
							el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delGroup( gi ); } }, _t('Gruppe entfernen') )
						),
						( g.options || [] ).map( function ( o, oi ) {
							return el( 'div', { key: oi, className: 'ec-inline-form', style: { alignItems: 'center', marginTop: 6 } },
								el( 'input', { type: 'text', placeholder: _t('Option (z. B. L)'), value: o.label, onChange: function ( e ) { setOpt( gi, oi, 'label', e.target.value ); } } ),
								el( 'input', { type: 'number', step: '0.05', placeholder: _t('Aufschlag'), style: { maxWidth: '120px' }, value: o.priceModifier, onChange: function ( e ) { setOpt( gi, oi, 'priceModifier', e.target.value ); } } ),
								el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delOpt( gi, oi ); } }, '×' )
							);
						} ),
						el( 'button', { className: 'ec-btn ec-btn-sm', style: { marginTop: 8 }, onClick: function () { addOpt( gi ); } }, _t('+ Option') )
					);
				} ),
				el( 'button', { className: 'ec-btn ec-btn-sm', style: { marginTop: 8 }, onClick: addGroup }, _t('+ Optionsgruppe') ),
				// Infofelder
				el( 'h4', { className: 'ec-sub-h' }, _t('Infofelder (z. B. Allergien, Grösse)') ),
				st.customFields.map( function ( f, fi ) {
					return el( 'div', { key: fi, className: 'ec-subcard' },
						el( 'div', { className: 'ec-inline-form', style: { alignItems: 'center' } },
							el( 'input', { type: 'text', placeholder: _t('Feldname (z. B. Allergien)'), value: f.label, onChange: function ( e ) { setField( fi, 'label', e.target.value ); } } ),
							el( 'select', { value: f.fieldType, onChange: function ( e ) { setField( fi, 'fieldType', e.target.value ); } }, el( 'option', { value: 'text' }, _t('Textfeld') ), el( 'option', { value: 'checkbox' }, _t('Checkboxen') ) ),
							el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: !! f.required, onChange: function ( e ) { setField( fi, 'required', e.target.checked ); } } ), el( 'span', null, _t('Pflicht') ) ),
							el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delField( fi ); } }, '×' )
						),
						f.fieldType === 'checkbox' ? el( 'div', { style: { marginTop: 8 } },
							( f.options || [] ).map( function ( opt, ci ) {
								return el( 'div', { key: ci, className: 'ec-inline-form', style: { alignItems: 'center', marginTop: 6 } },
									el( 'input', { type: 'text', placeholder: _t('Auswahl (z. B. Vegetarisch)'), value: opt, onChange: function ( e ) { setChoice( fi, ci, e.target.value ); } } ),
									el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delChoice( fi, ci ); } }, '×' )
								);
							} ),
							el( 'button', { className: 'ec-btn ec-btn-sm', style: { marginTop: 8 }, onClick: function () { addChoice( fi ); } }, _t('+ Auswahl') )
						) : null
					);
				} ),
				el( 'button', { className: 'ec-btn ec-btn-sm', style: { marginTop: 8 }, onClick: addField }, _t('+ Infofeld') ),
				// Footer
				el( 'div', { style: { display: 'flex', gap: '8px', marginTop: '18px' } },
					el( 'button', { className: 'ec-btn ec-btn-primary', onClick: doSave }, _t('Übernehmen') ),
					el( 'button', { className: 'ec-btn', onClick: props.onClose }, _t('Abbrechen') )
				)
			)
		);
	}

	function LocalCheckoutEditor( props ) {
		var c0 = props.checkout;
		var s = useState( {
			name: c0.name || '', slug: c0.slug || '',
			primary: ( c0.design && c0.design.primary ) || '#4F46E5',
			logoUrl: ( c0.design && c0.design.logoUrl ) || '',
			bank: ( c0.paymentMethods || [] ).indexOf( 'bank' ) !== -1,
			vatEnabled: !! c0.vatEnabled, vatRate: c0.vatRate != null ? c0.vatRate : 8.1,
			currency: c0.currency || 'CHF',
			productsTitle: c0.productsTitle || '',
			pickupEnabled: c0.pickupEnabled !== false,
			deliveryEnabled: !! c0.deliveryEnabled,
			categorySelection: c0.categorySelection || 'multiple',
			categories: ( c0.categories || [] ).slice(),
			products: ( c0.products || [] ).slice(),
			editing: null,   // null | Index | 'new'
			busy: false, saved: false, error: ''
		} );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, o, { saved: false } ) ); }
		function delProduct( i ) { var np = st.products.slice(); np.splice( i, 1 ); up( { products: np } ); }
		function saveProduct( product ) {
			var np = st.products.slice();
			if ( st.editing === 'new' ) { np.push( product ); } else { np[ st.editing ] = product; }
			set( Object.assign( {}, st, { products: np, editing: null, saved: false } ) );
		}
		// Kategorien
		function addCategory() { up( { categories: st.categories.concat( [ { id: ecUid( 'c_' ), name: '', description: '', singleProduct: false, allowQuantity: true } ] ) } ); }
		function delCategory( i ) {
			var cats = st.categories.slice(); var removed = cats[ i ]; cats.splice( i, 1 );
			var np = st.products.map( function ( p ) { return ( p.categoryId === removed.id ) ? Object.assign( {}, p, { categoryId: null } ) : p; } );
			up( { categories: cats, products: np } );
		}
		function setCategory( i, k, v ) { var cats = st.categories.slice(); cats[ i ] = Object.assign( {}, cats[ i ] ); cats[ i ][ k ] = v; up( { categories: cats } ); }
		function catName( id ) { var c = st.categories.filter( function ( x ) { return x.id === id; } )[ 0 ]; return c ? c.name : ''; }
		function save() {
			up( { busy: true, error: '' } );
			var pm = st.bank ? [ 'bank' ] : [];
			var payload = {
				id: c0.id, name: st.name, slug: st.slug,
				design: { primary: st.primary, logoUrl: st.logoUrl },
				paymentMethods: pm.length ? pm : [ 'bank' ],
				vatEnabled: st.vatEnabled, vatRate: st.vatRate, currency: st.currency,
				productsTitle: st.productsTitle,
				pickupEnabled: st.pickupEnabled, deliveryEnabled: st.deliveryEnabled,
				categorySelection: st.categorySelection, categories: st.categories,
				products: st.products
			};
			localApi( 'save', { data: JSON.stringify( payload ) } ).then( function () {
				set( Object.assign( {}, st, { busy: false, saved: true } ) );
			} ).catch( function ( e ) { set( Object.assign( {}, st, { busy: false, error: e.message } ) ); } );
		}
		function prodBadges( p ) {
			var b = [];
			if ( ( p.optionGroups || [] ).length ) { b.push( ( p.optionGroups || [] ).length + _t(' Option(en)') ); }
			if ( ( p.customFields || [] ).length ) { b.push( ( p.customFields || [] ).length + _t(' Infofeld(er)') ); }
			if ( p.deliveryPrice != null || p.pickupPrice != null || p.deliveryFee != null ) { b.push( _t('Liefer/Abhol') ); }
			return b.length ? el( 'span', { className: 'ec-tags' }, b.join( ' · ' ) ) : null;
		}
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' },
				el( 'div', { className: 'ec-head-left' },
					el( 'button', { className: 'ec-btn ec-btn-sm', onClick: props.onBack }, _t('← Zurück') ),
					el( 'h2', null, st.name || _t('Checkout') ) ),
				el( 'div', { className: 'ec-topbar-right' },
					el( 'a', { className: 'ec-btn ec-btn-sm', href: previewUrl( st.slug ), target: '_blank', rel: 'noopener' }, _t('Ansehen') ),
					el( 'button', { className: 'ec-btn ec-btn-primary', onClick: save, disabled: st.busy }, st.busy ? 'Speichern…' : _t('Speichern') ) ) ),
			st.saved && el( 'div', { className: 'ec-alert' }, _t('Gespeichert. (Vorschau zeigt den gespeicherten Stand.)') ),
			ErrorBox( st.error ),
			el( 'div', { className: 'ec-form-grid' },
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, _t('Allgemein') ),
					Field( _t('Name'), el( 'input', { type: 'text', value: st.name, onChange: function ( e ) { up( { name: e.target.value } ); } } ) ),
					Field( _t('Slug (URL)'), el( 'input', { type: 'text', value: st.slug, onChange: function ( e ) { up( { slug: e.target.value } ); } } ) ),
					Field( _t('Titel der Produktliste'), el( 'input', { type: 'text', placeholder: _t('Produkte'), value: st.productsTitle, onChange: function ( e ) { up( { productsTitle: e.target.value } ); } } ) ),
					Field( _t('Primärfarbe'), el( 'div', { className: 'ec-color-row' }, el( 'input', { type: 'color', value: st.primary, onChange: function ( e ) { up( { primary: e.target.value } ); } } ), el( 'input', { type: 'text', value: st.primary, onChange: function ( e ) { up( { primary: e.target.value } ); } } ) ) ),
					Field( _t('Währung'), el( 'input', { type: 'text', value: st.currency, maxLength: 3, onChange: function ( e ) { up( { currency: e.target.value.toUpperCase() } ); } } ) ),
					Field( _t('Logo'), el( 'div', null,
						st.logoUrl ? el( 'img', { src: st.logoUrl, className: 'ec-thumb-lg' } ) : null,
						el( 'div', { style: { display: 'flex', gap: '8px', marginTop: st.logoUrl ? '8px' : '0' } },
							FilePick( st.logoUrl ? 'Logo ändern' : _t('Logo hochladen'), function ( f ) { localUpload( f ).then( function ( d ) { up( { logoUrl: d.url } ); } ).catch( function ( e ) { up( { error: e.message } ); } ); } ),
							st.logoUrl ? el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { up( { logoUrl: '' } ); } }, _t('Entfernen') ) : null
						)
					) )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, _t('Zahlungsart') ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.bank, onChange: function ( e ) { up( { bank: e.target.checked } ); } } ), el( 'span', null, _t('Banküberweisung (ohne Konto)') ) ),
					el( 'p', { className: 'ec-hint' }, _t('Karte & TWINT benötigen ein verbundenes Konto.') ),
					el( 'button', { className: 'ec-btn ec-btn-sm', onClick: props.onConnect, style: { marginTop: 8 } }, _t('Konto verbinden für Online-Zahlung') ),
					el( 'h3', { style: { marginTop: 18 } }, _t('Abholung & Lieferung') ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.pickupEnabled, onChange: function ( e ) { up( { pickupEnabled: e.target.checked } ); } } ), el( 'span', null, _t('Abholung anbieten') ) ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.deliveryEnabled, onChange: function ( e ) { up( { deliveryEnabled: e.target.checked } ); } } ), el( 'span', null, _t('Lieferung anbieten (mit Liefergebühr je Produkt)') ) ),
					el( 'h3', { style: { marginTop: 18 } }, _t('MwSt.') ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.vatEnabled, onChange: function ( e ) { up( { vatEnabled: e.target.checked } ); } } ), el( 'span', null, _t('MwSt. ausweisen') ) ),
					st.vatEnabled && Field( _t('MwSt-Satz (%)'), el( 'input', { type: 'number', step: '0.1', value: st.vatRate, onChange: function ( e ) { up( { vatRate: e.target.value } ); } } ) )
				)
			),
			el( 'div', { className: 'ec-card', style: { marginTop: 16 } },
				el( 'h3', null, _t('Kategorien (optional)') ),
				el( 'p', { className: 'ec-hint' }, _t('Gruppiere Produkte. „Nur ein Produkt" = Auswahl per Radio; „Menge fix 1" = kein Mengenzähler.') ),
				st.categories.length ? el( 'div', null, st.categories.map( function ( cat, i ) {
					return el( 'div', { key: cat.id || i, className: 'ec-subcard' },
						el( 'div', { className: 'ec-inline-form', style: { alignItems: 'center' } },
							el( 'input', { type: 'text', placeholder: _t('Kategoriename'), value: cat.name, onChange: function ( e ) { setCategory( i, 'name', e.target.value ); } } ),
							el( 'input', { type: 'text', placeholder: _t('Beschreibung (optional)'), value: cat.description || '', onChange: function ( e ) { setCategory( i, 'description', e.target.value ); } } ),
							el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { delCategory( i ); } }, _t('Entfernen') )
						),
						el( 'div', { style: { display: 'flex', gap: '16px', marginTop: 6, flexWrap: 'wrap' } },
							el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: !! cat.singleProduct, onChange: function ( e ) { setCategory( i, 'singleProduct', e.target.checked ); } } ), el( 'span', null, _t('Nur ein Produkt wählbar') ) ),
							el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: cat.allowQuantity !== false, onChange: function ( e ) { setCategory( i, 'allowQuantity', e.target.checked ); } } ), el( 'span', null, _t('Menge wählbar') ) )
						)
					);
				} ) ) : el( 'p', { className: 'ec-muted' }, _t('Keine Kategorien.') ),
				el( 'div', { style: { display: 'flex', gap: '8px', marginTop: 10, alignItems: 'center', flexWrap: 'wrap' } },
					el( 'button', { className: 'ec-btn ec-btn-sm', onClick: addCategory }, _t('+ Kategorie') ),
					st.categories.length ? el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.categorySelection === 'single', onChange: function ( e ) { up( { categorySelection: e.target.checked ? 'single' : 'multiple' } ); } } ), el( 'span', null, _t('Kunde darf nur aus EINER Kategorie wählen') ) ) : null
				)
			),
			el( 'div', { className: 'ec-card', style: { marginTop: 16 } },
				el( 'div', { className: 'ec-page-head' },
					el( 'h3', { style: { margin: 0 } }, _t('Produkte') ),
					el( 'button', { className: 'ec-btn ec-btn-primary ec-btn-sm', onClick: function () { up( { editing: 'new' } ); } }, _t('+ Produkt') ) ),
				st.products.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Noch keine Produkte.') ) :
					el( 'table', { className: 'ec-table' },
						el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Bild') ), el( 'th', null, _t('Produkt') ), el( 'th', null, _t('Kategorie') ), el( 'th', null, _t('Preis') ), el( 'th', null, '' ) ) ),
						el( 'tbody', null, st.products.map( function ( p, i ) {
							return el( 'tr', { key: p.id || i },
								el( 'td', null, p.imageUrl ? el( 'img', { src: p.imageUrl, className: 'ec-thumb' } ) : el( 'span', { className: 'ec-thumb ec-thumb-empty' } ) ),
								el( 'td', null, el( 'div', null, p.name || '—' ), prodBadges( p ) ),
								el( 'td', null, p.categoryId ? catName( p.categoryId ) : '—' ),
								el( 'td', null, fmtMoney( p.price, st.currency ) ),
								el( 'td', { style: { textAlign: 'right' } },
									el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { up( { editing: i } ); } }, _t('Bearbeiten') ),
									el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger ec-ml', onClick: function () { delProduct( i ); } }, _t('Entfernen') ) ) );
						} ) )
					),
				el( 'p', { className: 'ec-hint' }, _t('Danach oben rechts „Speichern" nicht vergessen.') )
			),
			st.editing !== null ? el( LocalProductEditor, {
				product: st.editing === 'new' ? {} : st.products[ st.editing ],
				currency: st.currency,
				categories: st.categories,
				onSave: saveProduct,
				onClose: function () { up( { editing: null } ); }
			} ) : null,
			el( 'div', { className: 'ec-card', style: { marginTop: 16 } },
				el( 'h3', null, _t('Einbindung') ),
				el( 'p', { className: 'ec-hint', style: { marginBottom: 12 } }, 'So bindest du diesen Checkout auf deiner Website ein (bitte zuerst speichern):' ),
				CopyRow( _t('Shortcode – in eine WordPress-Seite einfügen'), '[easycheckout slug="' + ( st.slug || '' ) + '"]' ),
				CopyRow( _t('Direkter Link – ohne Seite, teilbar'), previewUrl( st.slug || '' ) ),
				el( 'ol', { style: { margin: '10px 0 0 18px', fontSize: '13px', color: '#6b7280', lineHeight: '1.8' } },
					el( 'li', null, _t('Firma + IBAN unter „Einstellungen" hinterlegen (erscheinen auf der Rechnung).') ),
					el( 'li', null, _t('Einbetten: neue WP-Seite anlegen, Shortcode einfügen, veröffentlichen.') ),
					el( 'li', null, _t('Oder einfach den direkten Link teilen (E-Mail, Social, QR).') ),
					el( 'li', null, _t('Testen: oben rechts „Ansehen".') )
				)
			)
		);
	}

	function EmbedView( props ) {
		var s = useState( { items: null, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		var authed = !! props.authed;
		// Verbunden -> Konto-Checkouts (/api/checkouts); sonst lokale Checkouts.
		useEffect( function () {
			if ( authed ) {
				api( 'GET', '/api/checkouts' ).then( function ( b ) { set( { items: ( b && b.checkouts ) || [], error: '' } ); } ).catch( function ( e ) { set( { items: [], error: e.message } ); } );
			} else {
				localApi( 'get' ).then( function ( items ) { set( { items: items, error: '' } ); } ).catch( function ( e ) { set( { items: [], error: e.message } ); } );
			}
		}, [] );
		function linkFor( slug ) { return previewUrl( slug ); }
		return el( 'div', null,
			el( 'div', { className: 'ec-card', style: { maxWidth: '760px', marginBottom: '16px' } },
				el( 'h3', null, _t('So bindest du deine Checkouts ein') ),
				el( 'ol', { style: { margin: '4px 0 0 18px', lineHeight: '1.9', fontSize: '14px' } },
					el( 'li', null, el( 'b', null, 'Einbetten: ' ), _t('neue WordPress-Seite anlegen, den Shortcode einfügen, veröffentlichen.') ),
					el( 'li', null, el( 'b', null, 'Direkter Link: ' ), _t('die Link-URL teilen (E-Mail, Social, QR).') ),
					el( 'li', null, _t('Vorschau jederzeit über „Ansehen".') ),
					authed
						? el( 'li', null, _t('Diese Checkouts stammen aus deinem verbundenen easyCheckout-Konto – Name, Link und Shortcode gehen automatisch mit.') )
						: el( 'li', null, _t('Firmenangaben + IBAN unter „Einstellungen" hinterlegen (erscheinen auf der Rechnung).') )
				)
			),
			ErrorBox( st.error ),
			st.items === null ? Spinner() :
				( st.items.length === 0 ? el( 'div', { className: 'ec-card', style: { maxWidth: '760px' } }, el( 'p', { className: 'ec-muted' }, _t('Noch keine Checkouts. Lege zuerst unter „Checkouts" einen an.') ) ) :
					st.items.map( function ( c ) {
						return el( 'div', { key: c.id, className: 'ec-card', style: { maxWidth: '760px', marginBottom: '14px' } },
							el( 'div', { className: 'ec-page-head' },
								el( 'h3', { style: { margin: 0 } }, c.name ),
								el( 'a', { className: 'ec-btn ec-btn-sm', href: linkFor( c.slug ), target: '_blank', rel: 'noopener' }, _t('Ansehen') ) ),
							CopyRow( _t('Shortcode – in eine WordPress-Seite einfügen'), '[easycheckout slug="' + c.slug + '"]' ),
							CopyRow( _t('Direkter Link – teilbar'), linkFor( c.slug ) )
						);
					} )
				)
		);
	}

	function LocalSettings( props ) {
		var s = useState( { comp: null, bank: null, cBusy: false, cSaved: false, bBusy: false, bSaved: false, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function merge( o ) { set( Object.assign( {}, st, o ) ); }
		useEffect( function () {
			Promise.all( [ post( 'easycheckout_company_get', {} ), post( 'easycheckout_bank_get', {} ) ] ).then( function ( r ) {
				set( Object.assign( {}, st, {
					comp: ( r[ 0 ].success && r[ 0 ].data ) || {},
					bank: ( r[ 1 ].success && r[ 1 ].data ) || {}
				} ) );
			} ).catch( function () { merge( { comp: {}, bank: {} } ); } );
		}, [] );
		function setComp( k, v ) { var c = Object.assign( {}, st.comp ); c[ k ] = v; set( Object.assign( {}, st, { comp: c, cSaved: false } ) ); }
		function setBank( k, v ) { var b = Object.assign( {}, st.bank ); b[ k ] = v; set( Object.assign( {}, st, { bank: b, bSaved: false } ) ); }
		function saveComp() {
			set( Object.assign( {}, st, { cBusy: true, error: '' } ) );
			post( 'easycheckout_company_save', { data: JSON.stringify( st.comp ) } ).then( function ( j ) {
				if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || _t('Fehler') ); }
				set( Object.assign( {}, st, { cBusy: false, cSaved: true } ) );
			} ).catch( function ( e ) { set( Object.assign( {}, st, { cBusy: false, error: e.message } ) ); } );
		}
		function saveBank() {
			set( Object.assign( {}, st, { bBusy: true, error: '' } ) );
			post( 'easycheckout_bank_save', { data: JSON.stringify( st.bank ) } ).then( function ( j ) {
				if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || _t('Fehler') ); }
				set( Object.assign( {}, st, { bBusy: false, bSaved: true } ) );
			} ).catch( function ( e ) { set( Object.assign( {}, st, { bBusy: false, error: e.message } ) ); } );
		}
		if ( ! st.comp || ! st.bank ) { return Spinner(); }
		var c = st.comp, b = st.bank;
		return el( 'div', null,
			el( 'div', { className: 'ec-banner' },
				el( 'span', { className: 'dashicons dashicons-info-outline' } ),
				el( 'span', { className: 'ec-banner-txt' }, _t('Firmenangaben und Bankverbindung erscheinen auf der Rechnung/Bestätigung. Für Online-Zahlungen (Karte/TWINT) verbinde dein Konto.') ),
				el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-primary', onClick: props.onConnect }, _t('Verbinden') )
			),
			ErrorBox( st.error ),
			el( 'div', { className: 'ec-card', style: { maxWidth: '640px', marginBottom: '16px' } },
				el( 'h3', null, _t('Firmenangaben (für Rechnung)') ),
				st.cSaved && el( 'div', { className: 'ec-alert' }, _t('Gespeichert.') ),
				Field( _t('Firma'), el( 'input', { type: 'text', value: c.name || '', onChange: function ( e ) { setComp( 'name', e.target.value ); } } ) ),
				Field( _t('Strasse und Hausnummer'), el( 'input', { type: 'text', value: c.street || '', onChange: function ( e ) { setComp( 'street', e.target.value ); } } ) ),
				el( 'div', { className: 'ec-two' },
					Field( 'PLZ', el( 'input', { type: 'text', value: c.postalCode || '', onChange: function ( e ) { setComp( 'postalCode', e.target.value ); } } ) ),
					Field( _t('Ort'), el( 'input', { type: 'text', value: c.city || '', onChange: function ( e ) { setComp( 'city', e.target.value ); } } ) )
				),
				el( 'div', { className: 'ec-two' },
					Field( _t('Land'), el( 'input', { type: 'text', value: c.country || '', onChange: function ( e ) { setComp( 'country', e.target.value ); } } ) ),
					Field( _t('MwSt-Nummer'), el( 'input', { type: 'text', value: c.vatNumber || '', placeholder: 'CHE-...', onChange: function ( e ) { setComp( 'vatNumber', e.target.value ); } } ) )
				),
				el( 'div', { className: 'ec-two' },
					Field( _t('E-Mail'), el( 'input', { type: 'email', value: c.email || '', onChange: function ( e ) { setComp( 'email', e.target.value ); } } ) ),
					Field( _t('Telefon'), el( 'input', { type: 'text', value: c.phone || '', onChange: function ( e ) { setComp( 'phone', e.target.value ); } } ) )
				),
				el( 'button', { className: 'ec-btn ec-btn-primary', onClick: saveComp, disabled: st.cBusy }, st.cBusy ? 'Speichern…' : _t('Firmenangaben speichern') )
			),
			el( 'div', { className: 'ec-card', style: { maxWidth: '640px' } },
				el( 'h3', null, _t('Bankverbindung (für Überweisung)') ),
				st.bSaved && el( 'div', { className: 'ec-alert' }, _t('Gespeichert.') ),
				Field( 'IBAN', el( 'input', { type: 'text', value: b.iban || '', placeholder: 'CH00 0000 0000 0000 0000 0', onChange: function ( e ) { setBank( 'iban', e.target.value ); } } ) ),
				Field( _t('Kontoinhaber'), el( 'input', { type: 'text', value: b.holder || '', onChange: function ( e ) { setBank( 'holder', e.target.value ); } } ) ),
				Field( _t('Bank (optional)'), el( 'input', { type: 'text', value: b.bankName || '', onChange: function ( e ) { setBank( 'bankName', e.target.value ); } } ) ),
				el( 'button', { className: 'ec-btn ec-btn-primary', onClick: saveBank, disabled: st.bBusy }, st.bBusy ? 'Speichern…' : _t('Bankverbindung speichern') )
			)
		);
	}

	function LocalOrders( props ) {
		var s = useState( { items: null, error: '', detail: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, o ) ); }
		function load() { post( 'easycheckout_local_orders', {} ).then( function ( j ) { if ( j.success ) { up( { items: j.data, error: '' } ); } else { up( { items: [], error: ( j.data && j.data.message ) || _t('Fehler') } ); } } ); }
		useEffect( function () { load(); }, [] );
		function setStatus( id, status ) { post( 'easycheckout_local_order_update', { id: id, status: status } ).then( load ); }
		function del( id ) { post( 'easycheckout_local_order_delete', { id: id } ).then( function () { up( { detail: null } ); load(); } ); }
		var STAT = { awaiting_transfer: [ _t('Wartet auf Zahlung'), 'ec-badge-off' ], paid: [ _t('Bezahlt'), 'ec-badge-on' ], cancelled: [ _t('Storniert'), 'ec-badge-err' ] };
		function addr( a ) { if ( ! a ) { return '—'; } return [ a.street, ( ( a.postalCode || '' ) + ' ' + ( a.city || '' ) ).trim(), a.country ].filter( Boolean ).join( ', ' ) || '—'; }
		function detailModal( o ) {
			function row( k, v ) { return el( 'div', { className: 'ec-kv-row' }, el( 'span', null, k ), el( 'span', null, v || '—' ) ); }
			return el( 'div', { className: 'ec-modal', onClick: function () { up( { detail: null } ); } },
				el( 'div', { className: 'ec-modal-card', onClick: function ( e ) { e.stopPropagation(); } },
					el( 'h3', null, _t('Bestellung ') + o.ref ),
					row( _t('Status'), ( STAT[ o.status ] || [ o.status ] )[ 0 ] ),
					row( _t('Datum'), fmtDate( o.createdAt ) ),
					o.fulfillmentMode ? row( _t('Art'), o.fulfillmentMode === 'delivery' ? 'Lieferung' : _t('Abholung') ) : null,
					row( _t('Kunde'), o.customerName ),
					o.customerCompany ? row( _t('Firma'), o.customerCompany ) : null,
					row( _t('E-Mail'), o.customerEmail ),
					o.customerPhone ? row( _t('Telefon'), o.customerPhone ) : null,
					row( _t('Rechnungsadresse'), addr( o.billing ) ),
					( o.fulfillmentMode === 'delivery' && ! o.sameAddress ) ? row( _t('Lieferadresse'), addr( o.delivery ) ) : null,
					el( 'table', { className: 'ec-table', style: { margin: '14px 0' } },
						el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Produkt') ), el( 'th', null, _t('Menge') ), el( 'th', null, _t('Betrag') ) ) ),
						el( 'tbody', null, ( o.items || [] ).map( function ( it, i ) {
							var subs = [];
							( it.options || [] ).forEach( function ( op ) { subs.push( op.label ); } );
							( it.customFields || [] ).forEach( function ( f ) { subs.push( f.label + ': ' + ( Array.isArray( f.value ) ? f.value.join( ', ' ) : f.value ) ); } );
							if ( it.deliveryFee ) { subs.push( _t('Liefergebühr ') + fmtMoney( it.deliveryFee, o.currency ) ); }
							return el( 'tr', { key: i },
								el( 'td', null, el( 'div', null, it.name ), subs.length ? el( 'span', { className: 'ec-tags' }, subs.join( ' · ' ) ) : null ),
								el( 'td', null, it.qty ),
								el( 'td', null, fmtMoney( it.lineTotal, o.currency ) ) );
						} ) )
					),
					( o.deliveryFeeTotal ) ? row( _t('Liefergebühren'), fmtMoney( o.deliveryFeeTotal, o.currency ) ) : null,
					row( _t('Total'), fmtMoney( o.total, o.currency ) ),
					el( 'div', { style: { display: 'flex', gap: '8px', marginTop: '16px', flexWrap: 'wrap' } },
						o.status !== 'paid' ? el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { setStatus( o.id, 'paid' ); up( { detail: null } ); } }, _t('Als bezahlt markieren') ) : null,
						el( 'button', { className: 'ec-btn', onClick: function () { up( { detail: null } ); } }, _t('Schliessen') ),
						el( 'button', { className: 'ec-btn ec-btn-danger', onClick: function () { del( o.id ); } }, _t('Löschen') )
					)
				)
			);
		}
		return el( 'div', null,
			el( 'div', { className: 'ec-banner' },
				el( 'span', { className: 'dashicons dashicons-info-outline' } ),
				el( 'span', { className: 'ec-banner-txt' }, _t('Bestellungen per Banküberweisung (lokal). Für Online-Zahlungen verbinde dein Konto.') ),
				el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-primary', onClick: props.onConnect }, _t('Verbinden') )
			),
			ErrorBox( st.error ),
			st.items === null ? Spinner() :
				( st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, _t('Noch keine Bestellungen.') ) :
					el( 'table', { className: 'ec-table' },
						el( 'thead', null, el( 'tr', null, el( 'th', null, _t('Ref') ), el( 'th', null, _t('Kunde') ), el( 'th', null, _t('Betrag') ), el( 'th', null, _t('Status') ), el( 'th', null, _t('Datum') ), el( 'th', null, '' ) ) ),
						el( 'tbody', null, st.items.map( function ( o ) {
							var stat = STAT[ o.status ] || [ o.status, 'ec-badge-off' ];
							return el( 'tr', { key: o.id },
								el( 'td', null, el( 'code', null, o.ref ) ),
								el( 'td', null, ( o.customerName || '' ) + ( o.customerEmail ? ( ' · ' + o.customerEmail ) : '' ) ),
								el( 'td', null, fmtMoney( o.total, o.currency ) ),
								el( 'td', null, el( 'span', { className: 'ec-badge ' + stat[ 1 ] }, stat[ 0 ] ) ),
								el( 'td', null, fmtDate( o.createdAt ) ),
								el( 'td', { style: { textAlign: 'right' } },
									el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { up( { detail: o } ); } }, _t('Details') ),
									o.status !== 'paid' && el( 'button', { className: 'ec-btn ec-btn-sm ec-ml', onClick: function () { setStatus( o.id, 'paid' ); } }, _t('Als bezahlt') ),
									el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger ec-ml', onClick: function () { del( o.id ); } }, _t('Löschen') ) ) );
						} ) )
					)
				),
			st.detail ? detailModal( st.detail ) : null
		);
	}

	function ConnectModal( props ) {
		return el( 'div', { className: 'ec-modal', onClick: props.onClose },
			el( 'div', { className: 'ec-modal-card', onClick: function ( e ) { e.stopPropagation(); } },
				el( 'button', { className: 'ec-modal-x', onClick: props.onClose, 'aria-label': _t('Schliessen') }, '×' ),
				el( LoginView, { onAuthed: props.onAuthed } )
			)
		);
	}

	function Shell( props ) {
		var r = useState( { view: props.initialView || 'overview', params: {} } );
		var route = r[ 0 ], setRoute = r[ 1 ];
		useEffect( function () { if ( props.authed ) { migrateLocalsIfNeeded(); } }, [ props.authed ] );
		function navigate( view, params ) { setRoute( { view: view, params: params || {} } ); }
		function logout() { post( 'easycheckout_native_logout', {} ).then( function () { props.onLogout(); } ); }

		var content;
		if ( ! props.authed ) {
			// Ohne Konto: alles sichtbar/benutzbar. Aufbau (Checkouts/Produkte)
			// laeuft lokal; datengetriebene Bereiche als sichtbare Demo; nur
			// Verifizierung/Tarif verlangen echtes Verbinden.
			var DEMO_COLS = {
				orders: [ _t('Bestellung'), _t('Kunde'), _t('Betrag'), _t('Status'), _t('Datum') ],
				customers: [ _t('Kunde'), _t('E-Mail'), _t('Bestellungen'), _t('Umsatz') ],
				invoices: [ _t('Nummer'), _t('Kunde'), _t('Betrag'), _t('Status') ],
				emails: [ _t('Vorlage'), _t('Betreff'), _t('Status') ],
				webhooks: [ 'URL', _t('Events'), _t('Status') ],
				support: [ _t('Betreff'), _t('Status'), _t('Datum') ]
			};
			if ( route.view === 'checkouts' || route.view === 'checkout' || route.view === 'products' ) {
				content = el( LocalCheckouts, { onConnect: props.onOpenConnect } );
			} else if ( route.view === 'embed' ) {
				content = el( EmbedView, { authed: false } );
			} else if ( route.view === 'overview' ) {
				content = el( LocalOverview, { navigate: navigate, onConnect: props.onOpenConnect } );
			} else if ( route.view === 'settings' ) {
				content = el( LocalSettings, { onConnect: props.onOpenConnect } );
			} else if ( route.view === 'orders' ) {
				content = el( LocalOrders, { onConnect: props.onOpenConnect } );
			} else if ( DEMO_COLS[ route.view ] ) {
				content = el( DemoView, { columns: DEMO_COLS[ route.view ], onConnect: props.onOpenConnect } );
			} else {
				content = el( ConnectWall, { title: WALL_TITLES[ route.view ] || _t('Konto verbinden'), onConnect: props.onOpenConnect } );
			}
		} else {
			switch ( route.view ) {
				case 'overview': content = el( OverviewView, null ); break;
				case 'embed': content = el( EmbedView, { authed: true } ); break;
				case 'checkouts': content = el( CheckoutsList, { navigate: navigate } ); break;
				case 'checkout': content = el( CheckoutEditor, { id: route.params.id, navigate: navigate } ); break;
				case 'products': content = el( ProductsManager, { id: route.params.id, name: route.params.name, navigate: navigate } ); break;
				case 'orders': content = el( OrdersView, null ); break;
				case 'customers': content = el( CustomersView, null ); break;
				case 'settings': content = el( SettingsView, null ); break;
				case 'invoices': content = el( InvoicesView, { navigate: navigate } ); break;
				case 'onboarding': content = el( OnboardingView, null ); break;
				case 'emails': content = el( EmailsView, null ); break;
				case 'webhooks': content = el( WebhooksView, null ); break;
				case 'support': content = el( SupportView, null ); break;
				case 'billing': content = el( BillingView, null ); break;
				default: content = el( Placeholder, { title: ( NAV.filter( function ( n ) { return n.key === route.view; } )[ 0 ] || { label: route.view } ).label } );
			}
		}
		var activeTop = ( route.view === 'checkout' || route.view === 'products' ) ? 'checkouts' : route.view;
		var curNav = NAV.filter( function ( n ) { return n.key === activeTop; } )[ 0 ];
		var curLabel = curNav ? curNav.label : 'EasyCheckout';
		var merchantName = ( props.merchant && ( props.merchant.companyName || props.merchant.email ) ) || '';

		var PLAN = { free: 'Free', freeplus: 'Free+', basic: 'Basic', pro: 'Pro', invoices: _t('Rechnungen') };
		var planLabel = ( props.merchant && props.merchant.plan ) ? ( PLAN[ props.merchant.plan ] || props.merchant.plan ) : '';
		var statusEl = props.authed
			? [ el( 'span', { key: 'p', className: 'ec-conn-badge ec-conn-on' }, _t('Verbunden') + ( planLabel ? ' · ' + planLabel : '' ) ), el( 'span', { key: 'm', className: 'ec-merchant' }, merchantName ), el( 'button', { key: 'b', className: 'ec-btn ec-btn-sm', onClick: logout }, _t('Abmelden') ) ]
			: [ el( 'span', { key: 'nb', className: 'ec-conn-badge' }, _t('Nicht verbunden') ), el( 'button', { key: 'c', className: 'ec-btn ec-btn-sm ec-btn-primary', onClick: props.onOpenConnect }, _t('Konto verbinden') ) ];

		// Navigation laeuft ueber die WordPress-Untermenues; die native App
		// zeigt nur die aktuelle Sektion + eine schlanke Kopfzeile.
		return el( 'div', { className: 'ec-app' },
			el( 'main', { className: 'ec-main' },
				el( 'div', { className: 'ec-topbar' },
					el( 'div', { className: 'ec-topbar-title' },
						el( 'span', { className: 'dashicons dashicons-' + ( curNav ? curNav.icon : 'cart' ) } ),
						el( 'span', null, curLabel ) ),
					el( 'div', { className: 'ec-topbar-right' }, statusEl )
				),
				content
			),
			props.showConnect && el( ConnectModal, { onClose: props.onCloseConnect, onAuthed: props.onAuthed } )
		);
	}

	function App( props ) {
		var s = useState( { authed: !! ecNative.authed, merchant: ecNative.merchant || {}, connect: false } );
		var st = s[ 0 ], set = s[ 1 ];
		return el( Shell, {
			authed: st.authed,
			merchant: st.merchant,
			initialView: props.initialView,
			showConnect: st.connect,
			onOpenConnect: function () { set( Object.assign( {}, st, { connect: true } ) ); },
			onCloseConnect: function () { set( Object.assign( {}, st, { connect: false } ) ); },
			onAuthed: function ( m ) { set( { authed: true, merchant: m || {}, connect: false } ); },
			onLogout: function () { set( { authed: false, merchant: {}, connect: false } ); }
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var node = document.getElementById( 'ec-native-app' );
		if ( node ) {
			var iv = node.getAttribute( 'data-view' ) || 'overview';
			render( el( App, { initialView: iv } ), node );
		}
	} );
} )();
