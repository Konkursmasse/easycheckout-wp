/* global wp, ecNative */
( function () {
	'use strict';

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
			if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || 'Fehler' ); }
			var status = j.data.status, b = j.data.body;
			if ( status >= 400 ) { throw new Error( ( b && ( b.error || b.message ) ) || ( 'Fehler ' + status ) ); }
			return b;
		} );
	}

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
				if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || 'Upload fehlgeschlagen' ); }
				var b = j.data.body;
				if ( j.data.status >= 400 ) { throw new Error( ( b && ( b.error || b.message ) ) || 'Fehler' ); }
				return b;
			} );
	}

	function fmtMoney( n, cur ) {
		if ( n == null || isNaN( n ) ) { return '—'; }
		return ( cur || 'CHF' ) + ' ' + Number( n ).toFixed( 2 );
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
					else { set( Object.assign( {}, st, { busy: false, error: ( j.data && j.data.message ) || 'Anmeldung fehlgeschlagen' } ) ); }
				} );
			} else {
				post( 'easycheckout_native_register', { data: JSON.stringify( { email: st.email, password: st.password, companyName: st.companyName, plan: 'free' } ) } ).then( function ( j ) {
					if ( j.success ) { props.onAuthed( j.data.merchant || {} ); }
					else { set( Object.assign( {}, st, { busy: false, error: ( j.data && j.data.message ) || 'Registrierung fehlgeschlagen' } ) ); }
				} );
			}
		}
		return el( 'div', { className: 'ec-auth' }, el( 'div', { className: 'ec-auth-card' },
			el( 'h1', { className: 'ec-auth-title' }, st.mode === 'login' ? 'Willkommen zurück' : 'Konto erstellen' ),
			el( 'p', { className: 'ec-auth-sub' }, st.mode === 'login' ? 'Melde dich bei deinem EasyCheckout-Konto an' : 'Registriere dich für EasyCheckout' ),
			ErrorBox( st.error ),
			el( 'form', { onSubmit: submit },
				st.mode === 'register' && Field( 'Firma', el( 'input', { type: 'text', value: st.companyName, onChange: function ( e ) { up( { companyName: e.target.value } ); } } ) ),
				Field( 'E-Mail-Adresse', el( 'input', { type: 'email', required: true, value: st.email, onChange: function ( e ) { up( { email: e.target.value } ); } } ) ),
				Field( 'Passwort', el( 'input', { type: 'password', required: true, value: st.password, onChange: function ( e ) { up( { password: e.target.value } ); } } ) ),
				el( 'button', { type: 'submit', className: 'ec-btn ec-btn-primary ec-btn-block', disabled: st.busy }, st.busy ? 'Bitte warten…' : ( st.mode === 'login' ? 'Anmelden' : 'Registrieren' ) )
			),
			el( 'p', { className: 'ec-auth-switch' }, st.mode === 'login' ? 'Noch kein Konto? ' : 'Schon ein Konto? ',
				el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); set( Object.assign( {}, st, { mode: st.mode === 'login' ? 'register' : 'login', error: '' } ) ); } }, st.mode === 'login' ? 'Kostenlos registrieren' : 'Anmelden' ) )
		) );
	}

	// --- Checkouts list -----------------------------------------------------

	function CheckoutsList( props ) {
		var s = useState( { items: null, error: '', creating: false, name: '', slug: '', busy: false } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() {
			api( 'GET', '/api/checkouts' ).then( function ( b ) {
				set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.checkouts ) || [], error: '' } ); } );
			} ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } );
		}
		useEffect( function () { load(); }, [] );
		function create( e ) {
			e.preventDefault(); set( Object.assign( {}, st, { busy: true, error: '' } ) );
			api( 'POST', '/api/checkouts', { name: st.name, slug: st.slug } ).then( function ( b ) {
				set( Object.assign( {}, st, { busy: false, creating: false, name: '', slug: '' } ) );
				if ( b && b.checkout ) { props.navigate( 'checkout', { id: b.checkout.id } ); } else { load(); }
			} ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		function del( c ) {
			if ( ! window.confirm( 'Checkout „' + ( c.name || c.slug ) + '" wirklich löschen?' ) ) { return; }
			api( 'DELETE', '/api/checkouts/' + c.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } );
		}
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Checkouts' ),
				el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { creating: true } ) ); } }, '+ Neuer Checkout' ) ),
			ErrorBox( st.error ),
			st.creating && el( 'form', { className: 'ec-inline-form', onSubmit: create },
				el( 'input', { placeholder: 'Name', required: true, value: st.name, onChange: function ( e ) { set( Object.assign( {}, st, { name: e.target.value } ) ); } } ),
				el( 'input', { placeholder: 'Slug (z. B. mein-shop)', required: true, value: st.slug, onChange: function ( e ) { set( Object.assign( {}, st, { slug: e.target.value } ) ); } } ),
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : 'Erstellen' ),
				el( 'button', { type: 'button', className: 'ec-btn', onClick: function () { set( Object.assign( {}, st, { creating: false } ) ); } }, 'Abbrechen' ) ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Noch keine Checkouts.' ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, 'Name' ), el( 'th', null, 'Slug' ), el( 'th', null, 'Produkte' ), el( 'th', null, 'Bestellungen' ), el( 'th', null, 'Status' ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( c ) {
						return el( 'tr', { key: c.id },
							el( 'td', null, el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); props.navigate( 'checkout', { id: c.id } ); } }, el( 'strong', null, c.name || '—' ) ) ),
							el( 'td', null, el( 'code', null, c.slug || '' ) ),
							el( 'td', null, ( c._count && c._count.products != null ) ? c._count.products : '—' ),
							el( 'td', null, ( c._count && c._count.orders != null ) ? c._count.orders : '—' ),
							el( 'td', null, c.isActive === false ? el( 'span', { className: 'ec-badge ec-badge-off' }, 'Inaktiv' ) : el( 'span', { className: 'ec-badge ec-badge-on' }, 'Aktiv' ) ),
							el( 'td', { className: 'ec-row-actions' },
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { props.navigate( 'products', { id: c.id, name: c.name } ); } }, 'Produkte' ),
								' ',
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { props.navigate( 'checkout', { id: c.id } ); } }, 'Bearbeiten' ),
								' ',
								el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( c ); } }, 'Löschen' ) )
						);
					} ) )
				)
		);
	}

	// --- Checkout editor ----------------------------------------------------

	var PAYMENT_METHODS = [ [ 'card', 'Karte' ], [ 'twint', 'TWINT' ], [ 'klarna', 'Klarna' ], [ 'sepa_debit', 'SEPA' ], [ 'bancontact', 'Bancontact' ], [ 'eps', 'EPS' ], [ 'giropay', 'giropay' ], [ 'ideal', 'iDEAL' ], [ 'p24', 'Przelewy24' ] ];

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
			};
			api( 'PUT', '/api/checkouts/' + props.id, payload ).then( function () { set( Object.assign( {}, st, { saving: false, saved: true } ) ); } )
				.catch( function ( err ) { set( Object.assign( {}, st, { saving: false, error: err.message } ) ); } );
		}

		if ( st.error && ! st.c ) { return el( 'div', null, backHead( props, 'Checkout' ), ErrorBox( st.error ) ); }
		if ( ! st.c ) { return el( 'div', null, backHead( props, 'Checkout' ), Spinner() ); }
		var c = st.c;
		return el( 'div', null,
			backHead( props, 'Checkout bearbeiten', el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { props.navigate( 'products', { id: props.id, name: c.name } ); } }, 'Produkte verwalten' ) ),
			ErrorBox( st.error ), st.saved && el( 'div', { className: 'ec-alert' }, 'Gespeichert.' ),
			el( 'form', { onSubmit: save, className: 'ec-form-grid' },
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, 'Allgemein' ),
					Field( 'Name', el( 'input', { value: c.name || '', onChange: function ( e ) { upd( { name: e.target.value } ); } } ) ),
					Field( 'Slug', el( 'input', { value: c.slug || '', onChange: function ( e ) { upd( { slug: e.target.value } ); } } ) ),
					Field( 'Beschreibung', el( 'textarea', { rows: 2, value: c.description || '', onChange: function ( e ) { upd( { description: e.target.value } ); } } ) ),
					Field( 'Währung', el( 'select', { value: c.currency || 'CHF', onChange: function ( e ) { upd( { currency: e.target.value } ); } }, [ 'CHF', 'EUR', 'USD' ].map( function ( x ) { return el( 'option', { key: x, value: x }, x ); } ) ) ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: c.isActive !== false, onChange: function ( e ) { upd( { isActive: e.target.checked } ); } } ), ' Aktiv' )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, 'MwSt' ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: !! c.vatEnabled, onChange: function ( e ) { upd( { vatEnabled: e.target.checked } ); } } ), ' MwSt aktiv' ),
					Field( 'MwSt-Satz (%)', el( 'input', { type: 'number', step: '0.1', value: c.vatRate != null ? c.vatRate : '', onChange: function ( e ) { upd( { vatRate: e.target.value } ); } } ) ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: c.vatInclusive !== false, onChange: function ( e ) { upd( { vatInclusive: e.target.checked } ); } } ), ' Preise inkl. MwSt' )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, 'Zahlungsarten' ),
					el( 'div', { className: 'ec-checks' }, PAYMENT_METHODS.map( function ( m ) {
						return el( 'label', { key: m[ 0 ], className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: c.paymentMethods.indexOf( m[ 0 ] ) >= 0, onChange: function () { togglePm( m[ 0 ] ); } } ), ' ' + m[ 1 ] );
					} ) ),
					el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: !! c.qrPaymentEnabled, onChange: function ( e ) { upd( { qrPaymentEnabled: e.target.checked } ); } } ), ' QR-Rechnung' )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, 'Design' ),
					colorField( 'Akzentfarbe', c.design.primaryColor || '#4F46E5', function ( v ) { updDesign( { primaryColor: v } ); } ),
					colorField( 'Button-Farbe', c.design.buttonColor || c.design.primaryColor || '#4F46E5', function ( v ) { updDesign( { buttonColor: v } ); } ),
					colorField( 'Button-Text', c.design.buttonTextColor || '#FFFFFF', function ( v ) { updDesign( { buttonTextColor: v } ); } ),
					colorField( 'Textfarbe', c.design.textColor || '#111827', function ( v ) { updDesign( { textColor: v } ); } ),
					colorField( 'Hintergrund', c.design.backgroundColor || '#F9FAFB', function ( v ) { updDesign( { backgroundColor: v } ); } ),
					Field( 'Eckenradius (px)', el( 'input', { type: 'number', min: 0, max: 40, value: c.design.borderRadius != null ? c.design.borderRadius : 12, onChange: function ( e ) { updDesign( { borderRadius: parseInt( e.target.value, 10 ) || 0 } ); } } ) )
				),
				el( 'div', { className: 'ec-card' },
					el( 'h3', null, 'Weiterleitungen' ),
					Field( 'Erfolgs-URL', el( 'input', { type: 'url', value: c.successUrl || '', onChange: function ( e ) { upd( { successUrl: e.target.value } ); } } ) ),
					Field( 'Abbruch-URL', el( 'input', { type: 'url', value: c.cancelUrl || '', onChange: function ( e ) { upd( { cancelUrl: e.target.value } ); } } ) )
				),
				el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.saving }, st.saving ? 'Speichert…' : 'Speichern' ) )
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
				el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { props.navigate( 'checkouts' ); } }, '← Zurück' ),
				el( 'h2', null, title ) ),
			extra || null );
	}

	// --- Products manager ---------------------------------------------------

	function ProductsManager( props ) {
		var s = useState( { items: null, error: '', editing: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() {
			api( 'GET', '/api/checkouts/' + props.id + '/products' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.products ) || [], error: '' } ); } ); } )
				.catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } );
		}
		useEffect( function () { load(); }, [ props.id ] );
		function del( p ) { if ( ! window.confirm( 'Produkt löschen?' ) ) { return; } api( 'DELETE', '/api/products/' + p.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		function emptyProduct() { return { name: '', description: '', price: '', imageUrl: '', isActive: true, maxPerCustomer: '', maxTotal: '' }; }

		return el( 'div', null,
			backHead( props, 'Produkte' + ( props.name ? ' · ' + props.name : '' ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { editing: emptyProduct() } ) ); } }, '+ Neues Produkt' ) ),
			ErrorBox( st.error ),
			st.editing && el( ProductForm, { checkoutId: props.id, product: st.editing, onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Noch keine Produkte.' ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, '' ), el( 'th', null, 'Name' ), el( 'th', null, 'Preis' ), el( 'th', null, 'Status' ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( p ) {
						return el( 'tr', { key: p.id },
							el( 'td', null, p.imageUrl ? el( 'img', { src: p.imageUrl, className: 'ec-thumb' } ) : el( 'span', { className: 'ec-thumb ec-thumb-empty' } ) ),
							el( 'td', null, el( 'strong', null, p.name ), p.description && el( 'div', { className: 'ec-muted ec-sm' }, p.description ) ),
							el( 'td', null, fmtMoney( p.price ) ),
							el( 'td', null, p.isActive === false ? el( 'span', { className: 'ec-badge ec-badge-off' }, 'Inaktiv' ) : el( 'span', { className: 'ec-badge ec-badge-on' }, 'Aktiv' ) ),
							el( 'td', { className: 'ec-row-actions' },
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { editing: Object.assign( {}, p ) } ) ); } }, 'Bearbeiten' ), ' ',
								el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( p ); } }, 'Löschen' ) )
						);
					} ) )
				)
		);
	}

	function ProductForm( props ) {
		var s = useState( Object.assign( { busy: false, error: '' }, props.product ) );
		var st = s[ 0 ], set = s[ 1 ];
		function up( o ) { set( Object.assign( {}, st, { error: '' }, o ) ); }
		function pickImage( e ) { var f = e.target.files[ 0 ]; if ( ! f ) { return; } if ( f.size > 2 * 1024 * 1024 ) { up( { error: 'Bild max. 2 MB' } ); return; } fileToDataUrl( f ).then( function ( d ) { up( { imageUrl: d } ); } ); }
		function save( e ) {
			e.preventDefault(); set( Object.assign( {}, st, { busy: true, error: '' } ) );
			var payload = {
				name: st.name, description: st.description, price: parseFloat( st.price ) || 0, imageUrl: st.imageUrl || '', isActive: st.isActive !== false,
				maxPerCustomer: st.maxPerCustomer === '' ? null : parseInt( st.maxPerCustomer, 10 ), maxTotal: st.maxTotal === '' ? null : parseInt( st.maxTotal, 10 ),
			};
			var pr = st.id ? api( 'PUT', '/api/products/' + st.id, payload ) : api( 'POST', '/api/checkouts/' + props.checkoutId + '/products', payload );
			pr.then( function () { props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		return el( 'div', { className: 'ec-modal' }, el( 'form', { className: 'ec-modal-card', onSubmit: save },
			el( 'h3', null, st.id ? 'Produkt bearbeiten' : 'Neues Produkt' ),
			ErrorBox( st.error ),
			Field( 'Name', el( 'input', { required: true, value: st.name || '', onChange: function ( e ) { up( { name: e.target.value } ); } } ) ),
			Field( 'Beschreibung', el( 'textarea', { rows: 2, value: st.description || '', onChange: function ( e ) { up( { description: e.target.value } ); } } ) ),
			Field( 'Preis', el( 'input', { type: 'number', step: '0.01', required: true, value: st.price, onChange: function ( e ) { up( { price: e.target.value } ); } } ) ),
			Field( 'Bild', el( 'div', null, st.imageUrl && el( 'img', { src: st.imageUrl, className: 'ec-thumb-lg' } ), el( 'input', { type: 'file', accept: 'image/*', onChange: pickImage } ) ) ),
			el( 'div', { className: 'ec-two' },
				Field( 'Max. pro Kunde', el( 'input', { type: 'number', min: 0, value: st.maxPerCustomer != null ? st.maxPerCustomer : '', onChange: function ( e ) { up( { maxPerCustomer: e.target.value } ); } } ), 'leer = unbegrenzt' ),
				Field( 'Gesamtkontingent', el( 'input', { type: 'number', min: 0, value: st.maxTotal != null ? st.maxTotal : '', onChange: function ( e ) { up( { maxTotal: e.target.value } ); } } ), 'leer = unbegrenzt' ) ),
			el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.isActive !== false, onChange: function ( e ) { up( { isActive: e.target.checked } ); } } ), ' Aktiv' ),
			el( 'div', { className: 'ec-form-actions' },
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : 'Speichern' ),
				el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, 'Abbrechen' ) )
		) );
	}

	// --- Orders -------------------------------------------------------------

	function OrdersView() {
		var s = useState( { data: null, error: '', busy: false } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/orders?limit=50' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { data: b, error: '' } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { data: { orders: [] }, error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function sync() { set( Object.assign( {}, st, { busy: true } ) ); api( 'POST', '/api/orders/sync' ).then( function () { set( Object.assign( {}, st, { busy: false } ) ); load(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		function refund( o ) { if ( ! window.confirm( 'Bestellung erstatten?' ) ) { return; } api( 'POST', '/api/orders/' + o.id + '/refund', {} ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		var orders = st.data ? ( st.data.orders || [] ) : null;
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Bestellungen' ), el( 'button', { className: 'ec-btn', disabled: st.busy, onClick: sync }, st.busy ? 'Synchronisiert…' : 'Status synchronisieren' ) ),
			ErrorBox( st.error ),
			orders === null ? Spinner() : orders.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Noch keine Bestellungen.' ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, 'Datum' ), el( 'th', null, 'Kunde' ), el( 'th', null, 'Checkout' ), el( 'th', null, 'Betrag' ), el( 'th', null, 'Status' ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, orders.map( function ( o ) {
						return el( 'tr', { key: o.id },
							el( 'td', null, fmtDate( o.createdAt ) ),
							el( 'td', null, o.customerName || o.customerEmail || '—' ),
							el( 'td', null, o.checkoutName || '—' ),
							el( 'td', null, fmtMoney( o.total, o.currency ) ),
							el( 'td', null, statusBadge( o.paymentStatus ) ),
							el( 'td', { className: 'ec-row-actions' }, o.paymentStatus === 'paid' && el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { refund( o ); } }, 'Erstatten' ) )
						);
					} ) )
				)
		);
	}

	function statusBadge( s ) {
		var map = { paid: [ 'ec-badge-on', 'Bezahlt' ], pending: [ 'ec-badge-off', 'Offen' ], pending_qr: [ 'ec-badge-off', 'QR offen' ], failed: [ 'ec-badge-err', 'Fehlgeschlagen' ], refunded: [ 'ec-badge-err', 'Erstattet' ], partially_refunded: [ 'ec-badge-off', 'Teilw. erstattet' ] };
		var m = map[ s ] || [ 'ec-badge-off', s || '—' ];
		return el( 'span', { className: 'ec-badge ' + m[ 0 ] }, m[ 1 ] );
	}

	// --- Customers ----------------------------------------------------------

	function CustomersView() {
		var s = useState( { items: null, error: '', editing: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/customers' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.customers ) || [], error: '' } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function del( c ) { if ( ! c.isManual ) { window.alert( 'Nur manuell angelegte Kunden können gelöscht werden.' ); return; } if ( ! window.confirm( 'Kunde löschen?' ) ) { return; } api( 'DELETE', '/api/customers/' + c.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Kunden' ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { editing: { email: '', name: '', phone: '', country: 'CH' } } ) ); } }, '+ Neuer Kunde' ) ),
			ErrorBox( st.error ),
			st.editing && el( CustomerForm, { customer: st.editing, onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Noch keine Kunden.' ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, 'Name' ), el( 'th', null, 'E-Mail' ), el( 'th', null, 'Bestellungen' ), el( 'th', null, 'Umsatz' ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( c ) {
						return el( 'tr', { key: c.id || c.email },
							el( 'td', null, el( 'strong', null, c.name || '—' ), c.isManual && el( 'span', { className: 'ec-badge ec-badge-off ec-ml' }, 'manuell' ) ),
							el( 'td', null, c.email ),
							el( 'td', null, c.orderCount != null ? c.orderCount : '—' ),
							el( 'td', null, fmtMoney( c.totalSpent, c.currency ) ),
							el( 'td', { className: 'ec-row-actions' },
								c.isManual && el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { editing: Object.assign( {}, c ) } ) ); } }, 'Bearbeiten' ), ' ',
								c.isManual && el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( c ); } }, 'Löschen' ) )
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
			var payload = { email: st.email, name: st.name, phone: st.phone, street: st.street, postalCode: st.postalCode, city: st.city, country: st.country || 'CH', notes: st.notes };
			var pr = st.id ? api( 'PUT', '/api/customers/' + st.id, payload ) : api( 'POST', '/api/customers', payload );
			pr.then( function () { props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		return el( 'div', { className: 'ec-modal' }, el( 'form', { className: 'ec-modal-card', onSubmit: save },
			el( 'h3', null, st.id ? 'Kunde bearbeiten' : 'Neuer Kunde' ), ErrorBox( st.error ),
			Field( 'E-Mail', el( 'input', { type: 'email', required: true, value: st.email || '', onChange: function ( e ) { up( { email: e.target.value } ); } } ) ),
			Field( 'Name', el( 'input', { value: st.name || '', onChange: function ( e ) { up( { name: e.target.value } ); } } ) ),
			Field( 'Telefon', el( 'input', { value: st.phone || '', onChange: function ( e ) { up( { phone: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-two' },
				Field( 'PLZ', el( 'input', { value: st.postalCode || '', onChange: function ( e ) { up( { postalCode: e.target.value } ); } } ) ),
				Field( 'Ort', el( 'input', { value: st.city || '', onChange: function ( e ) { up( { city: e.target.value } ); } } ) ) ),
			Field( 'Strasse', el( 'input', { value: st.street || '', onChange: function ( e ) { up( { street: e.target.value } ); } } ) ),
			Field( 'Notizen', el( 'textarea', { rows: 2, value: st.notes || '', onChange: function ( e ) { up( { notes: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-form-actions' },
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : 'Speichern' ),
				el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, 'Abbrechen' ) )
		) );
	}

	function Placeholder( props ) {
		return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, props.title ) ), el( 'div', { className: 'ec-alert' }, 'Dieser Bereich wird gerade nativ gebaut und folgt in Kürze.' ) );
	}

	// --- Onboarding / KYC ---------------------------------------------------

	var MCC = [ [ '5734', 'Software / IT' ], [ '7372', 'Programmierung' ], [ '5999', 'Einzelhandel (div.)' ], [ '5045', 'Computer/Zubehör' ], [ '7299', 'Dienstleistungen' ], [ '8999', 'Freiberuflich' ], [ '5812', 'Gastronomie' ], [ '5611', 'Bekleidung' ], [ '7991', 'Freizeit/Events' ] ];

	function OnboardingView() {
		var s = useState( { status: null, acct: null, error: '', msg: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() {
			api( 'GET', '/api/stripe/connect' ).then( function ( status ) {
				set( function ( p ) { return Object.assign( {}, p, { status: status } ); } );
				api( 'GET', '/api/stripe/account-status' ).then( function ( a ) { set( function ( p ) { return Object.assign( {}, p, { acct: a } ); } ); } ).catch( function () {} );
			} ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { error: err.message, status: {} } ); } ); } );
		}
		useEffect( function () { load(); }, [] );
		function start() { api( 'POST', '/api/stripe/connect', { origin: window.location.origin } ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		function hosted() { api( 'POST', '/api/stripe/connect/onboarding-link', { origin: window.location.origin } ).then( function ( b ) { if ( b && b.url ) { window.open( b.url, '_blank', 'noopener' ); } else if ( b && b.redirectUrl ) { window.open( b.redirectUrl, '_blank', 'noopener' ); } else { window.alert( 'Onboarding bereits abgeschlossen.' ); } } ).catch( function ( err ) { window.alert( err.message ); } ); }

		if ( ! st.status ) { return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Verifizierung' ) ), st.error ? ErrorBox( st.error ) : Spinner() ); }
		var hasAccount = !! ( st.status.stripeAccountId || ( st.acct && st.acct.hasAccount ) );
		var charges = st.status.chargesEnabled || ( st.acct && st.acct.chargesEnabled );

		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Verifizierung (Stripe)' ),
				el( 'button', { className: 'ec-btn', onClick: hosted }, 'Bei Stripe abschließen ↗' ) ),
			ErrorBox( st.error ),
			el( 'div', { className: 'ec-card', style: { marginBottom: '16px' } },
				el( 'h3', null, 'Status' ),
				el( 'p', null, charges ? el( 'span', { className: 'ec-badge ec-badge-on' }, 'Zahlungen aktiv' ) : el( 'span', { className: 'ec-badge ec-badge-off' }, ( st.acct && st.acct.status && ( st.acct.status.summary || st.acct.status.label ) ) || 'Verifizierung erforderlich' ) ),
				st.acct && st.acct.tasks && st.acct.tasks.length > 0 && el( 'ul', { className: 'ec-tasklist' }, st.acct.tasks.map( function ( t, i ) { return el( 'li', { key: i }, el( 'strong', null, t.title ), t.description && el( 'span', { className: 'ec-muted' }, ' — ' + t.description ) ); } ) ),
				! hasAccount && el( 'button', { className: 'ec-btn ec-btn-primary', onClick: start }, 'Verifizierung starten' )
			),
			hasAccount && el( 'div', { className: 'ec-form-grid' },
				el( BusinessForm, { onSaved: load } ),
				el( PersonForm, { onSaved: load } ),
				el( PersonsCard, null ),
				el( BankForm, { onSaved: load } ),
				el( DocsCard, null ),
				el( TermsCard, { onSaved: load } )
			)
		);
	}

	function BusinessForm( props ) {
		var s = useState( { businessType: 'company', companyName: '', taxId: '', industry: '5734', website: '', productDescription: '', phone: '', line1: '', postalCode: '', city: '', country: 'CH', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ]; function up( o ) { set( Object.assign( {}, st, { msg: '', error: '' }, o ) ); }
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true } ) );
			api( 'POST', '/api/stripe/connect/business', { businessType: st.businessType, companyName: st.companyName, taxId: st.taxId, industry: st.industry, website: st.website, productDescription: st.productDescription, phone: st.phone, address: { line1: st.line1, postalCode: st.postalCode, city: st.city, country: st.country } } )
				.then( function () { set( Object.assign( {}, st, { busy: false, msg: 'Gespeichert.' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		return cardForm( '1. Geschäftsangaben', el( 'div', null,
			Field( 'Art', el( 'select', { value: st.businessType, onChange: function ( e ) { up( { businessType: e.target.value } ); } }, el( 'option', { value: 'company' }, 'Firma' ), el( 'option', { value: 'individual' }, 'Einzelunternehmen' ) ) ),
			Field( 'Firmenname', el( 'input', { value: st.companyName, onChange: function ( e ) { up( { companyName: e.target.value } ); } } ) ),
			Field( 'UID / Steuernr.', el( 'input', { value: st.taxId, onChange: function ( e ) { up( { taxId: e.target.value } ); } } ) ),
			Field( 'Branche', el( 'select', { value: st.industry, onChange: function ( e ) { up( { industry: e.target.value } ); } }, MCC.map( function ( m ) { return el( 'option', { key: m[ 0 ], value: m[ 0 ] }, m[ 1 ] ); } ) ) ),
			Field( 'Website', el( 'input', { value: st.website, onChange: function ( e ) { up( { website: e.target.value } ); } }, 'oder Beschreibung unten' ) ),
			Field( 'Produktbeschreibung', el( 'input', { value: st.productDescription, onChange: function ( e ) { up( { productDescription: e.target.value } ); } } ) ),
			Field( 'Telefon', el( 'input', { value: st.phone, onChange: function ( e ) { up( { phone: e.target.value } ); } } ) ),
			Field( 'Strasse', el( 'input', { value: st.line1, onChange: function ( e ) { up( { line1: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-two' }, Field( 'PLZ', el( 'input', { value: st.postalCode, onChange: function ( e ) { up( { postalCode: e.target.value } ); } } ) ), Field( 'Ort', el( 'input', { value: st.city, onChange: function ( e ) { up( { city: e.target.value } ); } } ) ) )
		), st, save );
	}

	function PersonForm( props ) {
		var s = useState( { firstName: '', lastName: '', email: '', phone: '', day: '', month: '', year: '', line1: '', postalCode: '', city: '', isOwner: true, percentOwnership: '', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ]; function up( o ) { set( Object.assign( {}, st, { msg: '', error: '' }, o ) ); }
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true } ) );
			api( 'POST', '/api/stripe/connect/person', { firstName: st.firstName, lastName: st.lastName, email: st.email, phone: st.phone, dob: { day: parseInt( st.day, 10 ), month: parseInt( st.month, 10 ), year: parseInt( st.year, 10 ) }, address: { line1: st.line1, postalCode: st.postalCode, city: st.city }, isOwner: st.isOwner, percentOwnership: st.percentOwnership ? parseInt( st.percentOwnership, 10 ) : undefined } )
				.then( function () { set( Object.assign( {}, st, { busy: false, msg: 'Gespeichert.' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } );
		}
		return cardForm( '2. Vertretungsberechtigte Person', el( 'div', null,
			el( 'div', { className: 'ec-two' }, Field( 'Vorname', el( 'input', { value: st.firstName, onChange: function ( e ) { up( { firstName: e.target.value } ); } } ) ), Field( 'Nachname', el( 'input', { value: st.lastName, onChange: function ( e ) { up( { lastName: e.target.value } ); } } ) ) ),
			Field( 'E-Mail', el( 'input', { type: 'email', value: st.email, onChange: function ( e ) { up( { email: e.target.value } ); } } ) ),
			el( 'span', { className: 'ec-field' }, el( 'span', null, 'Geburtsdatum' ), el( 'div', { className: 'ec-dob' },
				el( 'input', { type: 'number', placeholder: 'TT', value: st.day, onChange: function ( e ) { up( { day: e.target.value } ); } } ),
				el( 'input', { type: 'number', placeholder: 'MM', value: st.month, onChange: function ( e ) { up( { month: e.target.value } ); } } ),
				el( 'input', { type: 'number', placeholder: 'JJJJ', value: st.year, onChange: function ( e ) { up( { year: e.target.value } ); } } ) ) ),
			Field( 'Strasse', el( 'input', { value: st.line1, onChange: function ( e ) { up( { line1: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-two' }, Field( 'PLZ', el( 'input', { value: st.postalCode, onChange: function ( e ) { up( { postalCode: e.target.value } ); } } ) ), Field( 'Ort', el( 'input', { value: st.city, onChange: function ( e ) { up( { city: e.target.value } ); } } ) ) ),
			el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.isOwner, onChange: function ( e ) { up( { isOwner: e.target.checked } ); } } ), ' Eigentümer/in' ),
			st.isOwner && Field( 'Anteil (%)', el( 'input', { type: 'number', value: st.percentOwnership, onChange: function ( e ) { up( { percentOwnership: e.target.value } ); } } ) )
		), st, save );
	}

	function PersonsCard() {
		var s = useState( { persons: null, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/stripe/connect/persons' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { persons: ( b && b.persons ) || [] } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { persons: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function del( id ) { if ( ! window.confirm( 'Person entfernen?' ) ) { return; } api( 'DELETE', '/api/stripe/connect/persons?personId=' + id ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		function confirmOwners() { api( 'POST', '/api/stripe/connect/confirm-owners', { owners: true, directors: true, executives: true } ).then( function () { window.alert( 'Bestätigt.' ); } ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', { className: 'ec-card' }, el( 'h3', null, '3. Weitere Eigentümer/Direktoren' ), ErrorBox( st.error ),
			st.persons === null ? Spinner() : st.persons.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Keine weiteren Personen.' ) :
				el( 'ul', { className: 'ec-tasklist' }, st.persons.map( function ( p ) { return el( 'li', { key: p.id }, ( p.firstName || '' ) + ' ' + ( p.lastName || '' ), ' ', el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); del( p.id ); } }, 'entfernen' ) ); } ) ),
			el( 'div', { className: 'ec-form-actions' }, el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm', onClick: confirmOwners }, 'Alle Eigentümer angegeben' ) ) );
	}

	function BankForm( props ) {
		var s = useState( { iban: '', accountHolderName: '', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'POST', '/api/stripe/connect/bank', { iban: st.iban, accountHolderName: st.accountHolderName } ).then( function () { set( Object.assign( {}, st, { busy: false, msg: 'Gespeichert.' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( '4. Bankverbindung', el( 'div', null,
			Field( 'IBAN', el( 'input', { value: st.iban, onChange: function ( e ) { set( Object.assign( {}, st, { iban: e.target.value } ) ); } } ) ),
			Field( 'Kontoinhaber', el( 'input', { value: st.accountHolderName, onChange: function ( e ) { set( Object.assign( {}, st, { accountHolderName: e.target.value } ) ); } } ) )
		), st, save );
	}

	function DocsCard() {
		var s = useState( { busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function upId( e ) { var f = e.target.files[ 0 ]; if ( ! f ) { return; } set( { busy: true, msg: '', error: '' } ); uploadFile( 'POST', '/api/stripe/connect/document', 'front', f ).then( function () { set( { busy: false, msg: 'Ausweis hochgeladen.' } ); } ).catch( function ( err ) { set( { busy: false, error: err.message } ); } ); }
		function upCo( e ) { var f = e.target.files[ 0 ]; if ( ! f ) { return; } set( { busy: true, msg: '', error: '' } ); uploadFile( 'POST', '/api/stripe/connect/company-document', 'document', f ).then( function () { set( { busy: false, msg: 'Firmendokument hochgeladen.' } ); } ).catch( function ( err ) { set( { busy: false, error: err.message } ); } ); }
		return el( 'div', { className: 'ec-card' }, el( 'h3', null, '5. Dokumente' ), st.msg && el( 'div', { className: 'ec-alert' }, st.msg ), ErrorBox( st.error ),
			Field( 'Ausweis / Pass', el( 'input', { type: 'file', accept: 'image/*,.pdf', onChange: upId, disabled: st.busy } ) ),
			Field( 'Handelsregisterauszug', el( 'input', { type: 'file', accept: 'image/*,.pdf', onChange: upCo, disabled: st.busy } ) ) );
	}

	function TermsCard( props ) {
		var s = useState( { busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function accept() { set( { busy: true, msg: '', error: '' } ); api( 'POST', '/api/stripe/connect/terms', {} ).then( function ( b ) { if ( b && b.redirectUrl ) { window.open( b.redirectUrl, '_blank', 'noopener' ); } set( { busy: false, msg: 'AGB akzeptiert.' } ); props.onSaved(); } ).catch( function ( err ) { set( { busy: false, error: err.message } ); } ); }
		return el( 'div', { className: 'ec-card' }, el( 'h3', null, '6. AGB akzeptieren' ), st.msg && el( 'div', { className: 'ec-alert' }, st.msg ), ErrorBox( st.error ),
			el( 'p', { className: 'ec-muted ec-sm' }, 'Mit dem Akzeptieren bestätigst du die Stripe-Nutzungsbedingungen.' ),
			el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy, onClick: accept }, st.busy ? '…' : 'AGB akzeptieren' ) );
	}

	// --- Emails -------------------------------------------------------------

	function EmailsView() {
		var s = useState( { tab: 'templates' } );
		var st = s[ 0 ], set = s[ 1 ];
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'E-Mails' ),
				el( 'div', null, el( 'button', { className: 'ec-btn ec-btn-sm' + ( st.tab === 'templates' ? ' ec-btn-primary' : '' ), onClick: function () { set( { tab: 'templates' } ); } }, 'Vorlagen' ), ' ',
					el( 'button', { className: 'ec-btn ec-btn-sm' + ( st.tab === 'logs' ? ' ec-btn-primary' : '' ), onClick: function () { set( { tab: 'logs' } ); } }, 'Protokoll' ) ) ),
			st.tab === 'templates' ? el( EmailTemplates, null ) : el( EmailLogs, null )
		);
	}
	function EmailTemplates() {
		var s = useState( { items: null, error: '', editing: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/emails' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.templates ) || [] } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		return el( 'div', null, ErrorBox( st.error ),
			st.editing && el( EmailTemplateForm, { tpl: st.editing, onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			st.items === null ? Spinner() : el( 'table', { className: 'ec-table' }, el( 'thead', null, el( 'tr', null, el( 'th', null, 'Typ' ), el( 'th', null, 'Betreff' ), el( 'th', null, 'Aktiv' ), el( 'th', null, '' ) ) ),
				el( 'tbody', null, st.items.map( function ( t ) { return el( 'tr', { key: t.id }, el( 'td', null, el( 'code', null, t.type ) ), el( 'td', null, t.subject ), el( 'td', null, t.isActive === false ? 'Nein' : 'Ja' ), el( 'td', { className: 'ec-row-actions' }, el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { editing: Object.assign( {}, t ) } ) ); } }, 'Bearbeiten' ) ) ); } ) ) ) );
	}
	function EmailTemplateForm( props ) {
		var s = useState( Object.assign( { busy: false, error: '' }, props.tpl ) );
		var st = s[ 0 ], set = s[ 1 ]; function up( o ) { set( Object.assign( {}, st, { error: '' }, o ) ); }
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true } ) ); api( 'POST', '/api/emails', { type: st.type, name: st.name, subject: st.subject, body: st.body, isActive: st.isActive !== false } ).then( function () { props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return el( 'div', { className: 'ec-modal' }, el( 'form', { className: 'ec-modal-card', onSubmit: save }, el( 'h3', null, 'Vorlage: ' + st.type ), ErrorBox( st.error ),
			Field( 'Betreff', el( 'input', { value: st.subject || '', onChange: function ( e ) { up( { subject: e.target.value } ); } } ) ),
			Field( 'Inhalt (HTML)', el( 'textarea', { rows: 10, value: st.body || '', onChange: function ( e ) { up( { body: e.target.value } ); } } ) ),
			el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.isActive !== false, onChange: function ( e ) { up( { isActive: e.target.checked } ); } } ), ' Aktiv' ),
			el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, 'Speichern' ), el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, 'Abbrechen' ) ) ) );
	}
	function EmailLogs() {
		var s = useState( { data: null } ); var st = s[ 0 ], set = s[ 1 ];
		useEffect( function () { api( 'GET', '/api/email-logs?limit=50' ).then( set ).catch( function () { set( { emails: [] } ); } ); }, [] );
		var rows = st && st.emails;
		return rows == null ? Spinner() : el( 'table', { className: 'ec-table' }, el( 'thead', null, el( 'tr', null, el( 'th', null, 'Datum' ), el( 'th', null, 'An' ), el( 'th', null, 'Betreff' ), el( 'th', null, 'Status' ) ) ),
			el( 'tbody', null, rows.map( function ( m ) { return el( 'tr', { key: m.id }, el( 'td', null, fmtDate( m.createdAt ) ), el( 'td', null, m.toEmail ), el( 'td', null, m.subject ), el( 'td', null, m.status ) ); } ) ) );
	}

	// --- Marketing ----------------------------------------------------------

	function MarketingView() {
		var s = useState( { subs: null, camps: null, error: '', newSub: '', editing: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() {
			api( 'GET', '/api/subscribers?limit=100' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { subs: ( b && b.subscribers ) || [] } ); } ); } ).catch( function () {} );
			api( 'GET', '/api/marketing' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { camps: ( b && b.campaigns ) || [] } ); } ); } ).catch( function () {} );
		}
		useEffect( function () { load(); }, [] );
		function addSub( e ) { e.preventDefault(); if ( ! st.newSub ) { return; } api( 'POST', '/api/subscribers', { email: st.newSub } ).then( function () { set( Object.assign( {}, st, { newSub: '' } ) ); load(); } ).catch( function ( err ) { window.alert( err.message ); } ); }
		function sendCamp( c ) { if ( ! window.confirm( 'Kampagne „' + c.name + '" jetzt senden?' ) ) { return; } api( 'POST', '/api/marketing/' + c.id + '/send', {} ).then( function ( b ) { window.alert( 'Gesendet: ' + ( b && b.sent != null ? b.sent : '?' ) ); load(); } ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Marketing' ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { editing: {} } ) ); } }, '+ Kampagne' ) ),
			st.editing && el( CampaignForm, { onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			el( 'div', { className: 'ec-form-grid' },
				el( 'div', { className: 'ec-card' }, el( 'h3', null, 'Abonnenten (' + ( st.subs ? st.subs.length : '…' ) + ')' ),
					el( 'form', { className: 'ec-inline-form', onSubmit: addSub }, el( 'input', { type: 'email', placeholder: 'E-Mail', value: st.newSub, onChange: function ( e ) { set( Object.assign( {}, st, { newSub: e.target.value } ) ); } } ), el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-primary' }, '+' ) ),
					st.subs === null ? Spinner() : el( 'ul', { className: 'ec-tasklist' }, st.subs.slice( 0, 30 ).map( function ( su ) { return el( 'li', { key: su.id }, su.email, su.name && el( 'span', { className: 'ec-muted' }, ' · ' + su.name ) ); } ) ) ),
				el( 'div', { className: 'ec-card' }, el( 'h3', null, 'Kampagnen' ),
					st.camps === null ? Spinner() : st.camps.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Keine Kampagnen.' ) :
						el( 'ul', { className: 'ec-tasklist' }, st.camps.map( function ( c ) { return el( 'li', { key: c.id }, el( 'strong', null, c.name ), ' (' + c.status + ') ', c.status !== 'sent' && el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); sendCamp( c ); } }, 'senden' ) ); } ) ) )
			)
		);
	}
	function CampaignForm( props ) {
		var s = useState( { name: '', subject: '', body: '', busy: false, error: '' } );
		var st = s[ 0 ], set = s[ 1 ]; function up( o ) { set( Object.assign( {}, st, { error: '' }, o ) ); }
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true } ) ); api( 'POST', '/api/marketing', { name: st.name, subject: st.subject, body: st.body } ).then( function () { props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return el( 'div', { className: 'ec-modal' }, el( 'form', { className: 'ec-modal-card', onSubmit: save }, el( 'h3', null, 'Neue Kampagne' ), ErrorBox( st.error ),
			Field( 'Name', el( 'input', { required: true, value: st.name, onChange: function ( e ) { up( { name: e.target.value } ); } } ) ),
			Field( 'Betreff', el( 'input', { required: true, value: st.subject, onChange: function ( e ) { up( { subject: e.target.value } ); } } ) ),
			Field( 'Inhalt (HTML)', el( 'textarea', { rows: 8, value: st.body, onChange: function ( e ) { up( { body: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, 'Speichern' ), el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, 'Abbrechen' ) ) ) );
	}

	// --- Webhooks / Support / Billing ---------------------------------------

	function WebhooksView() {
		var s = useState( { items: null, error: '', url: '', events: 'order.paid,order.refunded' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/merchant/webhooks' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && ( b.endpoints || b.webhooks ) ) || [] } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function add( e ) { e.preventDefault(); api( 'POST', '/api/merchant/webhooks', { url: st.url, events: st.events.split( ',' ).map( function ( x ) { return x.trim(); } ).filter( Boolean ), isActive: true } ).then( function () { set( Object.assign( {}, st, { url: '' } ) ); load(); } ).catch( function ( err ) { window.alert( err.message ); } ); }
		function del( w ) { if ( ! window.confirm( 'Webhook löschen?' ) ) { return; } api( 'DELETE', '/api/merchant/webhooks?id=' + w.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Webhooks' ) ), ErrorBox( st.error ),
			el( 'form', { className: 'ec-inline-form', onSubmit: add }, el( 'input', { type: 'url', placeholder: 'https://…', required: true, value: st.url, onChange: function ( e ) { set( Object.assign( {}, st, { url: e.target.value } ) ); }, style: { flex: '1' } } ), el( 'input', { placeholder: 'events (kommagetrennt)', value: st.events, onChange: function ( e ) { set( Object.assign( {}, st, { events: e.target.value } ) ); } } ), el( 'button', { className: 'ec-btn ec-btn-primary' }, 'Hinzufügen' ) ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Keine Webhooks.' ) :
				el( 'table', { className: 'ec-table' }, el( 'thead', null, el( 'tr', null, el( 'th', null, 'URL' ), el( 'th', null, 'Events' ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( w ) { return el( 'tr', { key: w.id }, el( 'td', null, el( 'code', null, w.url ) ), el( 'td', null, ( w.events || [] ).join( ', ' ) ), el( 'td', { className: 'ec-row-actions' }, el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( w ); } }, 'Löschen' ) ) ); } ) ) ) );
	}

	function SupportView() {
		var s = useState( { items: null, error: '', creating: false, subject: '', message: '', category: 'general', priority: 'normal' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/support/tickets' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.tickets ) || [] } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function create( e ) { e.preventDefault(); api( 'POST', '/api/support/tickets', { subject: st.subject, message: st.message, category: st.category, priority: st.priority } ).then( function () { set( Object.assign( {}, st, { creating: false, subject: '', message: '' } ) ); load(); } ).catch( function ( err ) { window.alert( err.message ); } ); }
		return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Support' ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { creating: true } ) ); } }, '+ Anfrage' ) ), ErrorBox( st.error ),
			st.creating && el( 'form', { className: 'ec-card', onSubmit: create, style: { marginBottom: '14px' } },
				Field( 'Betreff', el( 'input', { required: true, value: st.subject, onChange: function ( e ) { set( Object.assign( {}, st, { subject: e.target.value } ) ); } } ) ),
				Field( 'Nachricht', el( 'textarea', { rows: 4, required: true, value: st.message, onChange: function ( e ) { set( Object.assign( {}, st, { message: e.target.value } ) ); } } ) ),
				el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary' }, 'Senden' ), el( 'button', { type: 'button', className: 'ec-btn', onClick: function () { set( Object.assign( {}, st, { creating: false } ) ); } }, 'Abbrechen' ) ) ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Keine Anfragen.' ) :
				el( 'table', { className: 'ec-table' }, el( 'thead', null, el( 'tr', null, el( 'th', null, 'Nummer' ), el( 'th', null, 'Betreff' ), el( 'th', null, 'Status' ), el( 'th', null, 'Datum' ) ) ),
					el( 'tbody', null, st.items.map( function ( t ) { return el( 'tr', { key: t.id }, el( 'td', null, el( 'code', null, t.ticketNumber ) ), el( 'td', null, t.subject ), el( 'td', null, t.status ), el( 'td', null, fmtDate( t.createdAt ) ) ); } ) ) ) );
	}

	var PLANS = [ [ 'free', 'Free' ], [ 'free_plus', 'Free+' ], [ 'basic', 'Basic' ], [ 'pro', 'Pro' ], [ 'rechnungen_only', 'Rechnungen' ] ];
	function BillingView() {
		var s = useState( { me: null, error: '', busy: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/auth/me' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { me: ( b && b.merchant ) || b } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function choose( plan ) {
			if ( plan === 'free' ) { set( Object.assign( {}, st, { busy: plan } ) ); api( 'POST', '/api/subscription/checkout', { plan: 'free' } ).then( function () { set( Object.assign( {}, st, { busy: '' } ) ); load(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: '', error: err.message } ) ); } ); return; }
			// Paid plans require card payment -> hosted billing in a new tab.
			window.open( ecNative.appUrl + '/dashboard/billing', '_blank', 'noopener' );
		}
		if ( ! st.me ) { return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Tarif' ) ), st.error ? ErrorBox( st.error ) : Spinner() ); }
		return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Tarif & Add-ons' ) ), ErrorBox( st.error ),
			el( 'p', null, 'Aktueller Tarif: ', el( 'strong', null, st.me.plan ) ),
			el( 'div', { className: 'ec-stat-grid' }, PLANS.map( function ( pl ) {
				var current = st.me.plan === pl[ 0 ];
				return el( 'div', { key: pl[ 0 ], className: 'ec-stat' }, el( 'div', { className: 'ec-stat-val', style: { fontSize: '18px' } }, pl[ 1 ] ),
					el( 'button', { className: 'ec-btn ec-btn-sm' + ( current ? '' : ' ec-btn-primary' ), disabled: current || st.busy === pl[ 0 ], onClick: function () { choose( pl[ 0 ] ); }, style: { marginTop: '8px' } }, current ? 'Aktiv' : ( pl[ 0 ] === 'free' ? 'Wechseln' : 'Upgrade ↗' ) ) );
			} ) ),
			el( 'p', { className: 'ec-muted ec-sm', style: { marginTop: '12px' } }, 'Kostenpflichtige Tarife: Kartenzahlung über die sichere EasyCheckout-Seite (neuer Tab).' )
		);
	}

	// --- Invoices -----------------------------------------------------------

	function InvoicesView() {
		var s = useState( { items: null, error: '', editing: null } );
		var st = s[ 0 ], set = s[ 1 ];
		function load() { api( 'GET', '/api/invoices' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { items: ( b && b.invoices ) || [], error: '' } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } ); } ); }
		useEffect( function () { load(); }, [] );
		function act( inv, what ) {
			var p;
			if ( what === 'send' ) { p = api( 'POST', '/api/invoices/' + inv.id + '/send', {} ); }
			else if ( what === 'reminder' ) { p = api( 'POST', '/api/invoices/' + inv.id + '/reminder', {} ); }
			else if ( what === 'delete' ) { if ( ! window.confirm( 'Rechnung löschen?' ) ) { return; } p = api( 'DELETE', '/api/invoices/' + inv.id ); }
			p.then( function ( b ) { if ( what === 'send' && b && b.invoiceUrl ) { window.alert( 'Rechnung gesendet. Link: ' + b.invoiceUrl ); } load(); } ).catch( function ( err ) { window.alert( err.message ); } );
		}
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Rechnungen' ), el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { editing: {} } ) ); } }, '+ Neue Rechnung' ) ),
			ErrorBox( st.error ),
			st.editing && el( InvoiceForm, { invoice: st.editing.id ? st.editing : null, onClose: function () { set( Object.assign( {}, st, { editing: null } ) ); }, onSaved: function () { set( Object.assign( {}, st, { editing: null } ) ); load(); } } ),
			st.items === null ? Spinner() : st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Noch keine Rechnungen.' ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null, el( 'th', null, 'Nummer' ), el( 'th', null, 'Kunde' ), el( 'th', null, 'Betrag' ), el( 'th', null, 'Fällig' ), el( 'th', null, 'Status' ), el( 'th', null, '' ) ) ),
					el( 'tbody', null, st.items.map( function ( inv ) {
						return el( 'tr', { key: inv.id },
							el( 'td', null, el( 'code', null, inv.invoiceNumber || '—' ) ),
							el( 'td', null, inv.customerName || inv.customerEmail || '—' ),
							el( 'td', null, fmtMoney( inv.total, inv.currency ) ),
							el( 'td', null, fmtDate( inv.dueDate ) ),
							el( 'td', null, invStatus( inv.status ) ),
							el( 'td', { className: 'ec-row-actions' },
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { set( Object.assign( {}, st, { editing: Object.assign( {}, inv ) } ) ); } }, 'Bearbeiten' ), ' ',
								el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { act( inv, 'send' ); } }, 'Senden' ), ' ',
								( inv.status === 'sent' || inv.status === 'overdue' ) && el( 'button', { className: 'ec-btn ec-btn-sm', onClick: function () { act( inv, 'reminder' ); } }, 'Mahnen' ), ' ',
								el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { act( inv, 'delete' ); } }, 'Löschen' ) )
						);
					} ) )
				)
		);
	}

	function invStatus( s ) {
		var m = { draft: [ 'ec-badge-off', 'Entwurf' ], sent: [ 'ec-badge-off', 'Gesendet' ], paid: [ 'ec-badge-on', 'Bezahlt' ], overdue: [ 'ec-badge-err', 'Überfällig' ], cancelled: [ 'ec-badge-err', 'Storniert' ] }[ s ] || [ 'ec-badge-off', s || '—' ];
		return el( 'span', { className: 'ec-badge ' + m[ 0 ] }, m[ 1 ] );
	}

	function InvoiceForm( props ) {
		var inv = props.invoice;
		var init = { customerEmail: '', customerName: '', customerStreet: '', customerPostalCode: '', customerCity: '', customerCountry: 'CH', vatRate: 8.1, dueDate: '', notes: '', currency: 'CHF' };
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
			el( 'h3', null, ( inv && inv.id ) ? 'Rechnung bearbeiten' : 'Neue Rechnung' ), ErrorBox( st.error ),
			Field( 'Kunden-E-Mail', el( 'input', { type: 'email', required: true, value: st.customerEmail, onChange: function ( e ) { up( { customerEmail: e.target.value } ); } } ) ),
			Field( 'Kundenname', el( 'input', { required: true, value: st.customerName, onChange: function ( e ) { up( { customerName: e.target.value } ); } } ) ),
			Field( 'Strasse', el( 'input', { value: st.customerStreet, onChange: function ( e ) { up( { customerStreet: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-two' }, Field( 'PLZ', el( 'input', { value: st.customerPostalCode, onChange: function ( e ) { up( { customerPostalCode: e.target.value } ); } } ) ), Field( 'Ort', el( 'input', { value: st.customerCity, onChange: function ( e ) { up( { customerCity: e.target.value } ); } } ) ) ),
			el( 'div', { className: 'ec-items' }, el( 'div', { className: 'ec-items-head' }, el( 'span', null, 'Positionen' ), el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm', onClick: addItem }, '+ Position' ) ),
				st.items.map( function ( it, i ) {
					return el( 'div', { key: i, className: 'ec-item-row' },
						el( 'input', { className: 'ec-item-desc', placeholder: 'Beschreibung', value: it.description, onChange: function ( e ) { setItem( i, 'description', e.target.value ); } } ),
						el( 'input', { className: 'ec-item-qty', type: 'number', min: 1, value: it.quantity, onChange: function ( e ) { setItem( i, 'quantity', e.target.value ); } } ),
						el( 'input', { className: 'ec-item-price', type: 'number', step: '0.01', placeholder: 'Preis', value: it.price, onChange: function ( e ) { setItem( i, 'price', e.target.value ); } } ),
						el( 'button', { type: 'button', className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { rmItem( i ); } }, '×' ) );
				} ) ),
			el( 'div', { className: 'ec-two' },
				Field( 'MwSt-Satz (%)', el( 'input', { type: 'number', step: '0.1', value: st.vatRate, onChange: function ( e ) { up( { vatRate: e.target.value } ); } } ) ),
				Field( 'Fällig am', el( 'input', { type: 'date', value: ( st.dueDate || '' ).slice( 0, 10 ), onChange: function ( e ) { up( { dueDate: e.target.value } ); } } ) ) ),
			Field( 'Notizen', el( 'textarea', { rows: 2, value: st.notes, onChange: function ( e ) { up( { notes: e.target.value } ); } } ) ),
			el( 'div', { className: 'ec-form-actions' },
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : 'Speichern' ),
				el( 'button', { type: 'button', className: 'ec-btn', onClick: props.onClose }, 'Abbrechen' ) )
		) );
	}

	// --- Overview -----------------------------------------------------------

	function OverviewView() {
		var s = useState( null );
		var stats = s[ 0 ], set = s[ 1 ];
		useEffect( function () { api( 'GET', '/api/dashboard/stats' ).then( set ).catch( function () { set( {} ); } ); }, [] );
		var cards = [
			[ 'Umsatz (30 Tage)', stats ? fmtMoney( stats.revenue ) : '…' ],
			[ 'Bestellungen', stats ? ( stats.ordersCount != null ? stats.ordersCount : 0 ) : '…' ],
			[ 'Checkouts', stats ? ( stats.checkoutsCount != null ? stats.checkoutsCount : 0 ) : '…' ],
			[ 'Conversion', stats ? ( ( stats.conversionRate != null ? stats.conversionRate : 0 ) + ' %' ) : '…' ],
		];
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Übersicht' ) ),
			el( 'div', { className: 'ec-stat-grid' }, cards.map( function ( c, i ) {
				return el( 'div', { key: i, className: 'ec-stat' }, el( 'div', { className: 'ec-stat-val' }, c[ 1 ] ), el( 'div', { className: 'ec-stat-lbl' }, c[ 0 ] ) );
			} ) )
		);
	}

	// --- Settings -----------------------------------------------------------

	function SettingsView() {
		var s = useState( { me: null, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function loadMe() { api( 'GET', '/api/auth/me' ).then( function ( b ) { set( function ( p ) { return Object.assign( {}, p, { me: ( b && b.merchant ) || b } ); } ); } ).catch( function ( err ) { set( function ( p ) { return Object.assign( {}, p, { error: err.message } ); } ); } ); }
		useEffect( function () { loadMe(); }, [] );
		if ( ! st.me ) { return el( 'div', null, el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Einstellungen' ) ), st.error ? ErrorBox( st.error ) : Spinner() ); }
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, 'Einstellungen' ) ),
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
			el( 'div', { className: 'ec-form-actions' }, el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : 'Speichern' ) ) );
	}

	function ProfileCard( props ) {
		var s = useState( { d: { companyName: props.me.companyName || '', email: props.me.email || '', street: props.me.street || '', postalCode: props.me.postalCode || '', city: props.me.city || '', phone: props.me.phone || '', vatNumber: props.me.vatNumber || '' }, busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function up( k, v ) { var d = Object.assign( {}, st.d ); d[ k ] = v; set( Object.assign( {}, st, { d: d, msg: '', error: '' } ) ); }
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'PUT', '/api/auth/profile', st.d ).then( function () { set( Object.assign( {}, st, { busy: false, msg: 'Gespeichert.' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( 'Firmenprofil', el( 'div', null,
			Field( 'Firma', el( 'input', { value: st.d.companyName, onChange: function ( e ) { up( 'companyName', e.target.value ); } } ) ),
			Field( 'E-Mail', el( 'input', { type: 'email', value: st.d.email, onChange: function ( e ) { up( 'email', e.target.value ); } } ) ),
			Field( 'Strasse', el( 'input', { value: st.d.street, onChange: function ( e ) { up( 'street', e.target.value ); } } ) ),
			el( 'div', { className: 'ec-two' }, Field( 'PLZ', el( 'input', { value: st.d.postalCode, onChange: function ( e ) { up( 'postalCode', e.target.value ); } } ) ), Field( 'Ort', el( 'input', { value: st.d.city, onChange: function ( e ) { up( 'city', e.target.value ); } } ) ) ),
			Field( 'Telefon', el( 'input', { value: st.d.phone, onChange: function ( e ) { up( 'phone', e.target.value ); } } ) ),
			Field( 'MwSt-Nr.', el( 'input', { value: st.d.vatNumber, onChange: function ( e ) { up( 'vatNumber', e.target.value ); } } ) )
		), st, save );
	}

	function LogoCard( props ) {
		var s = useState( { url: props.me.logoUrl || '', busy: false, error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function pick( e ) { var f = e.target.files[ 0 ]; if ( ! f ) { return; } set( Object.assign( {}, st, { busy: true, error: '' } ) ); uploadFile( 'POST', '/api/merchant/logo', 'logo', f ).then( function ( b ) { set( Object.assign( {}, st, { busy: false, url: b.logoUrl || '' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		function remove() { api( 'DELETE', '/api/merchant/logo' ).then( function () { set( Object.assign( {}, st, { url: '' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { error: err.message } ) ); } ); }
		return el( 'div', { className: 'ec-card' }, el( 'h3', null, 'Logo' ), ErrorBox( st.error ),
			st.url ? el( 'img', { src: st.url, className: 'ec-thumb-lg' } ) : el( 'p', { className: 'ec-muted' }, 'Kein Logo.' ),
			el( 'input', { type: 'file', accept: 'image/*', onChange: pick, disabled: st.busy } ),
			st.url && el( 'div', { style: { marginTop: '8px' } }, el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: remove }, 'Entfernen' ) ) );
	}

	function QrCard( props ) {
		var s = useState( { iban: props.me.iban || '', enabled: !! props.me.qrPaymentEnabled, busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'PUT', '/api/auth/qr-settings', { iban: st.iban, qrPaymentEnabled: st.enabled } ).then( function () { set( Object.assign( {}, st, { busy: false, msg: 'Gespeichert.' } ) ); props.onSaved(); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( 'QR-Rechnung', el( 'div', null,
			Field( 'IBAN (CH)', el( 'input', { value: st.iban, onChange: function ( e ) { set( Object.assign( {}, st, { iban: e.target.value } ) ); } } ) ),
			el( 'label', { className: 'ec-check' }, el( 'input', { type: 'checkbox', checked: st.enabled, onChange: function ( e ) { set( Object.assign( {}, st, { enabled: e.target.checked } ) ); } } ), ' QR-Zahlung aktiv' )
		), st, save );
	}

	function DescriptorCard( props ) {
		var s = useState( { v: props.me.statementDescriptor || '', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'PUT', '/api/auth/statement-descriptor', { statementDescriptor: st.v } ).then( function ( b ) { set( Object.assign( {}, st, { busy: false, msg: 'Gespeichert.', v: ( b && b.statementDescriptor ) || st.v } ) ); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( 'Zahlungs-Referenz', el( 'div', null,
			Field( 'Text (5–22 Zeichen)', el( 'input', { value: st.v, maxLength: 22, onChange: function ( e ) { set( Object.assign( {}, st, { v: e.target.value } ) ); } } ), 'Erscheint auf der Kartenabrechnung des Kunden.' )
		), st, save );
	}

	function PasswordCard() {
		var s = useState( { cur: '', nw: '', busy: false, msg: '', error: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function save( e ) { e.preventDefault(); set( Object.assign( {}, st, { busy: true, msg: '', error: '' } ) ); api( 'PUT', '/api/auth/password', { currentPassword: st.cur, newPassword: st.nw } ).then( function () { set( { cur: '', nw: '', busy: false, msg: 'Passwort geändert.', error: '' } ); } ).catch( function ( err ) { set( Object.assign( {}, st, { busy: false, error: err.message } ) ); } ); }
		return cardForm( 'Passwort ändern', el( 'div', null,
			Field( 'Aktuelles Passwort', el( 'input', { type: 'password', value: st.cur, onChange: function ( e ) { set( Object.assign( {}, st, { cur: e.target.value } ) ); } } ) ),
			Field( 'Neues Passwort', el( 'input', { type: 'password', value: st.nw, onChange: function ( e ) { set( Object.assign( {}, st, { nw: e.target.value } ) ); } } ) )
		), st, save );
	}

	// --- Shell + router -----------------------------------------------------

	var NAV = [
		{ key: 'overview', label: 'Übersicht', icon: 'dashboard' },
		{ key: 'checkouts', label: 'Checkouts', icon: 'cart' },
		{ key: 'orders', label: 'Bestellungen', icon: 'list-view' },
		{ key: 'customers', label: 'Kunden', icon: 'groups' },
		{ key: 'invoices', label: 'Rechnungen', icon: 'media-document' },
		{ key: 'emails', label: 'E-Mails', icon: 'email' },
		{ key: 'marketing', label: 'Marketing', icon: 'megaphone' },
		{ key: 'onboarding', label: 'Verifizierung', icon: 'id' },
		{ key: 'billing', label: 'Tarif', icon: 'cart' },
		{ key: 'webhooks', label: 'Webhooks', icon: 'admin-links' },
		{ key: 'support', label: 'Support', icon: 'sos' },
		{ key: 'settings', label: 'Einstellungen', icon: 'admin-generic' },
	];

	function Shell( props ) {
		var r = useState( { view: 'overview', params: {} } );
		var route = r[ 0 ], setRoute = r[ 1 ];
		function navigate( view, params ) { setRoute( { view: view, params: params || {} } ); }
		function logout() { post( 'easycheckout_native_logout', {} ).then( function () { props.onLogout(); } ); }

		var content;
		switch ( route.view ) {
			case 'overview': content = el( OverviewView, null ); break;
			case 'checkouts': content = el( CheckoutsList, { navigate: navigate } ); break;
			case 'checkout': content = el( CheckoutEditor, { id: route.params.id, navigate: navigate } ); break;
			case 'products': content = el( ProductsManager, { id: route.params.id, name: route.params.name, navigate: navigate } ); break;
			case 'orders': content = el( OrdersView, null ); break;
			case 'customers': content = el( CustomersView, null ); break;
			case 'settings': content = el( SettingsView, null ); break;
			case 'invoices': content = el( InvoicesView, null ); break;
			case 'onboarding': content = el( OnboardingView, null ); break;
			case 'emails': content = el( EmailsView, null ); break;
			case 'marketing': content = el( MarketingView, null ); break;
			case 'webhooks': content = el( WebhooksView, null ); break;
			case 'support': content = el( SupportView, null ); break;
			case 'billing': content = el( BillingView, null ); break;
			default: content = el( Placeholder, { title: ( NAV.filter( function ( n ) { return n.key === route.view; } )[ 0 ] || { label: route.view } ).label } );
		}
		var activeTop = ( route.view === 'checkout' || route.view === 'products' ) ? 'checkouts' : route.view;

		return el( 'div', { className: 'ec-app' },
			el( 'aside', { className: 'ec-sidebar' },
				el( 'div', { className: 'ec-brand' }, 'EasyCheckout' ),
				el( 'nav', null, NAV.map( function ( n ) {
					return el( 'a', { key: n.key, href: '#', className: 'ec-nav-item' + ( activeTop === n.key ? ' is-active' : '' ), onClick: function ( e ) { e.preventDefault(); navigate( n.key ); } },
						el( 'span', { className: 'dashicons dashicons-' + n.icon } ), el( 'span', null, n.label ) );
				} ) ),
				el( 'div', { className: 'ec-sidebar-foot' },
					el( 'div', { className: 'ec-merchant' }, ( props.merchant && ( props.merchant.companyName || props.merchant.email ) ) || '' ),
					el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-block', onClick: logout }, 'Abmelden' ) )
			),
			el( 'main', { className: 'ec-main' }, content )
		);
	}

	function App() {
		var s = useState( { authed: !! ecNative.authed, merchant: ecNative.merchant || {} } );
		var st = s[ 0 ], set = s[ 1 ];
		if ( ! st.authed ) { return el( LoginView, { onAuthed: function ( m ) { set( { authed: true, merchant: m } ); } } ); }
		return el( Shell, { merchant: st.merchant, onLogout: function () { set( { authed: false, merchant: {} } ); } } );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var node = document.getElementById( 'ec-native-app' );
		if ( node ) { render( el( App, null ), node ); }
	} );
} )();
