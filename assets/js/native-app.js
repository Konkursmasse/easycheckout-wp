/* global wp, ecNative */
( function () {
	'use strict';

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var Fragment = wp.element.Fragment;
	var render = wp.element.render;

	// --- API helpers --------------------------------------------------------

	function post( action, fields ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', ecNative.nonce );
		Object.keys( fields || {} ).forEach( function ( k ) {
			body.append( k, fields[ k ] );
		} );
		return fetch( ecNative.ajaxUrl, {
			method: 'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			credentials: 'same-origin',
			body: body.toString(),
		} ).then( function ( r ) { return r.json(); } );
	}

	// Authenticated API call through the WP proxy. Resolves with the parsed
	// body; rejects with an Error (message from the API) on HTTP >= 400.
	function api( method, path, payload ) {
		return post( 'easycheckout_native_proxy', {
			method: method,
			path: path,
			body: payload ? JSON.stringify( payload ) : '',
		} ).then( function ( j ) {
			if ( ! j.success ) {
				throw new Error( ( j.data && j.data.message ) || 'Fehler' );
			}
			var status = j.data.status;
			var b = j.data.body;
			if ( status >= 400 ) {
				throw new Error( ( b && ( b.error || b.message ) ) || ( 'Fehler ' + status ) );
			}
			return b;
		} );
	}

	// --- Login --------------------------------------------------------------

	function LoginView( props ) {
		var s = useState( { email: '', password: '', mode: 'login', busy: false, error: '', companyName: '' } );
		var st = s[ 0 ], set = s[ 1 ];
		function up( k, v ) { set( Object.assign( {}, st, { error: '' }, ( function () { var o = {}; o[ k ] = v; return o; } )() ) ); }

		function submit( e ) {
			e.preventDefault();
			set( Object.assign( {}, st, { busy: true, error: '' } ) );
			if ( st.mode === 'login' ) {
				post( 'easycheckout_native_login', { email: st.email, password: st.password } ).then( function ( j ) {
					if ( j.success ) { props.onAuthed( j.data.merchant || {} ); }
					else { set( Object.assign( {}, st, { busy: false, error: ( j.data && j.data.message ) || 'Anmeldung fehlgeschlagen' } ) ); }
				} );
			} else {
				post( 'easycheckout_native_register', {
					data: JSON.stringify( { email: st.email, password: st.password, companyName: st.companyName } ),
				} ).then( function ( j ) {
					if ( j.success ) { props.onAuthed( j.data.merchant || {} ); }
					else { set( Object.assign( {}, st, { busy: false, error: ( j.data && j.data.message ) || 'Registrierung fehlgeschlagen' } ) ); }
				} );
			}
		}

		return el( 'div', { className: 'ec-auth' },
			el( 'div', { className: 'ec-auth-card' },
				el( 'h1', { className: 'ec-auth-title' }, st.mode === 'login' ? 'Willkommen zurück' : 'Konto erstellen' ),
				el( 'p', { className: 'ec-auth-sub' }, st.mode === 'login' ? 'Melde dich bei deinem EasyCheckout-Konto an' : 'Registriere dich für EasyCheckout' ),
				st.error && el( 'div', { className: 'ec-alert ec-alert-error' }, st.error ),
				el( 'form', { onSubmit: submit },
					st.mode === 'register' && el( 'label', { className: 'ec-field' },
						el( 'span', null, 'Firma' ),
						el( 'input', { type: 'text', value: st.companyName, onChange: function ( e ) { up( 'companyName', e.target.value ); } } )
					),
					el( 'label', { className: 'ec-field' },
						el( 'span', null, 'E-Mail-Adresse' ),
						el( 'input', { type: 'email', required: true, value: st.email, onChange: function ( e ) { up( 'email', e.target.value ); } } )
					),
					el( 'label', { className: 'ec-field' },
						el( 'span', null, 'Passwort' ),
						el( 'input', { type: 'password', required: true, value: st.password, onChange: function ( e ) { up( 'password', e.target.value ); } } )
					),
					el( 'button', { type: 'submit', className: 'ec-btn ec-btn-primary ec-btn-block', disabled: st.busy },
						st.busy ? 'Bitte warten…' : ( st.mode === 'login' ? 'Anmelden' : 'Registrieren' )
					)
				),
				el( 'p', { className: 'ec-auth-switch' },
					st.mode === 'login' ? 'Noch kein Konto? ' : 'Schon ein Konto? ',
					el( 'a', { href: '#', onClick: function ( e ) { e.preventDefault(); set( Object.assign( {}, st, { mode: st.mode === 'login' ? 'register' : 'login', error: '' } ) ); } },
						st.mode === 'login' ? 'Kostenlos registrieren' : 'Anmelden'
					)
				)
			)
		);
	}

	// --- Checkouts ----------------------------------------------------------

	function CheckoutsView() {
		var s = useState( { items: null, error: '', creating: false, newName: '', newSlug: '', busy: false } );
		var st = s[ 0 ], set = s[ 1 ];

		function load() {
			api( 'GET', '/api/checkouts' ).then( function ( b ) {
				var items = Array.isArray( b ) ? b : ( b && b.checkouts ) || ( b && b.data ) || [];
				set( function ( p ) { return Object.assign( {}, p, { items: items, error: '' } ); } );
			} ).catch( function ( err ) {
				set( function ( p ) { return Object.assign( {}, p, { items: [], error: err.message } ); } );
			} );
		}
		useEffect( function () { load(); }, [] );

		function create( e ) {
			e.preventDefault();
			set( Object.assign( {}, st, { busy: true, error: '' } ) );
			api( 'POST', '/api/checkouts', { name: st.newName, slug: st.newSlug } ).then( function () {
				set( Object.assign( {}, st, { busy: false, creating: false, newName: '', newSlug: '' } ) );
				load();
			} ).catch( function ( err ) {
				set( Object.assign( {}, st, { busy: false, error: err.message } ) );
			} );
		}

		function del( c ) {
			if ( ! window.confirm( 'Checkout „' + ( c.name || c.slug ) + '" wirklich löschen?' ) ) { return; }
			api( 'DELETE', '/api/checkouts/' + c.id ).then( load ).catch( function ( err ) { window.alert( err.message ); } );
		}

		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' },
				el( 'h2', null, 'Checkouts' ),
				el( 'button', { className: 'ec-btn ec-btn-primary', onClick: function () { set( Object.assign( {}, st, { creating: true } ) ); } }, '+ Neuer Checkout' )
			),
			st.error && el( 'div', { className: 'ec-alert ec-alert-error' }, st.error ),
			st.creating && el( 'form', { className: 'ec-inline-form', onSubmit: create },
				el( 'input', { placeholder: 'Name', required: true, value: st.newName, onChange: function ( e ) { set( Object.assign( {}, st, { newName: e.target.value } ) ); } } ),
				el( 'input', { placeholder: 'Slug (z. B. mein-shop)', required: true, value: st.newSlug, onChange: function ( e ) { set( Object.assign( {}, st, { newSlug: e.target.value } ) ); } } ),
				el( 'button', { className: 'ec-btn ec-btn-primary', disabled: st.busy }, st.busy ? '…' : 'Erstellen' ),
				el( 'button', { type: 'button', className: 'ec-btn', onClick: function () { set( Object.assign( {}, st, { creating: false } ) ); } }, 'Abbrechen' )
			),
			st.items === null ? el( 'p', { className: 'ec-muted' }, 'Lädt…' ) :
				st.items.length === 0 ? el( 'p', { className: 'ec-muted' }, 'Noch keine Checkouts. Lege deinen ersten an.' ) :
				el( 'table', { className: 'ec-table' },
					el( 'thead', null, el( 'tr', null,
						el( 'th', null, 'Name' ), el( 'th', null, 'Slug' ), el( 'th', null, 'Produkte' ), el( 'th', null, 'Status' ), el( 'th', null, '' )
					) ),
					el( 'tbody', null, st.items.map( function ( c ) {
						return el( 'tr', { key: c.id },
							el( 'td', null, el( 'strong', null, c.name || '—' ) ),
							el( 'td', null, el( 'code', null, c.slug || '' ) ),
							el( 'td', null, ( c.productsCount != null ? c.productsCount : ( c._count && c._count.products ) != null ? c._count.products : ( c.products ? c.products.length : '—' ) ) ),
							el( 'td', null, ( c.isActive === false ? el( 'span', { className: 'ec-badge ec-badge-off' }, 'Inaktiv' ) : el( 'span', { className: 'ec-badge ec-badge-on' }, 'Aktiv' ) ) ),
							el( 'td', { className: 'ec-row-actions' },
								el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-danger', onClick: function () { del( c ); } }, 'Löschen' )
							)
						);
					} ) )
				)
		);
	}

	function Placeholder( props ) {
		return el( 'div', null,
			el( 'div', { className: 'ec-page-head' }, el( 'h2', null, props.title ) ),
			el( 'div', { className: 'ec-alert' }, 'Dieser Bereich wird gerade nativ gebaut und folgt in Kürze.' )
		);
	}

	// --- Shell --------------------------------------------------------------

	var NAV = [
		{ key: 'checkouts', label: 'Checkouts', icon: 'cart' },
		{ key: 'orders', label: 'Bestellungen', icon: 'list-view' },
		{ key: 'customers', label: 'Kunden', icon: 'groups' },
		{ key: 'invoices', label: 'Rechnungen', icon: 'media-document' },
		{ key: 'onboarding', label: 'Verifizierung', icon: 'id' },
		{ key: 'settings', label: 'Einstellungen', icon: 'admin-generic' },
	];

	function Shell( props ) {
		var s = useState( 'checkouts' );
		var view = s[ 0 ], setView = s[ 1 ];

		function logout() {
			post( 'easycheckout_native_logout', {} ).then( function () { props.onLogout(); } );
		}

		var content;
		if ( view === 'checkouts' ) { content = el( CheckoutsView, null ); }
		else { content = el( Placeholder, { title: ( NAV.filter( function ( n ) { return n.key === view; } )[ 0 ] || {} ).label } ); }

		return el( 'div', { className: 'ec-app' },
			el( 'aside', { className: 'ec-sidebar' },
				el( 'div', { className: 'ec-brand' }, 'EasyCheckout' ),
				el( 'nav', null, NAV.map( function ( n ) {
					return el( 'a', {
						key: n.key, href: '#', className: 'ec-nav-item' + ( view === n.key ? ' is-active' : '' ),
						onClick: function ( e ) { e.preventDefault(); setView( n.key ); },
					}, el( 'span', { className: 'dashicons dashicons-' + n.icon } ), el( 'span', null, n.label ) );
				} ) ),
				el( 'div', { className: 'ec-sidebar-foot' },
					el( 'div', { className: 'ec-merchant' }, ( props.merchant && ( props.merchant.companyName || props.merchant.email ) ) || '' ),
					el( 'button', { className: 'ec-btn ec-btn-sm ec-btn-block', onClick: logout }, 'Abmelden' )
				)
			),
			el( 'main', { className: 'ec-main' }, content )
		);
	}

	function App() {
		var s = useState( { authed: !! ecNative.authed, merchant: ecNative.merchant || {} } );
		var st = s[ 0 ], set = s[ 1 ];
		if ( ! st.authed ) {
			return el( LoginView, { onAuthed: function ( m ) { set( { authed: true, merchant: m } ); } } );
		}
		return el( Shell, { merchant: st.merchant, onLogout: function () { set( { authed: false, merchant: {} } ); } } );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var node = document.getElementById( 'ec-native-app' );
		if ( node ) { render( el( App, null ), node ); }
	} );
} )();
