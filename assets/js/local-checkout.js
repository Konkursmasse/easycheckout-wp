/* global ecLocal */
/* EasyCheckout – lokaler Bankueberweisungs-Checkout (Frontend, ohne Konto). */
( function () {
	'use strict';
	if ( typeof ecLocal === 'undefined' || ! ecLocal.checkout ) { return; }
	var C = ecLocal.checkout;
	var root = document.querySelector( '.ec-local-checkout[data-ec-local]' );
	if ( ! root ) { return; }

	var qty = {};
	( C.products || [] ).forEach( function ( p ) { qty[ p.id ] = 0; } );

	function money( n ) { return C.currency + ' ' + Number( n || 0 ).toFixed( 2 ); }
	function total() {
		var t = 0;
		( C.products || [] ).forEach( function ( p ) { t += ( qty[ p.id ] || 0 ) * p.price; } );
		return Math.round( t * 100 ) / 100;
	}

	function h( tag, attrs, children ) {
		var e = document.createElement( tag );
		attrs = attrs || {};
		Object.keys( attrs ).forEach( function ( k ) {
			if ( k === 'class' ) { e.className = attrs[ k ]; }
			else if ( k === 'text' ) { e.textContent = attrs[ k ]; }
			else if ( k.indexOf( 'on' ) === 0 ) { e.addEventListener( k.slice( 2 ).toLowerCase(), attrs[ k ] ); }
			else { e.setAttribute( k, attrs[ k ] ); }
		} );
		( children || [] ).forEach( function ( c ) { if ( c ) { e.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c ); } } );
		return e;
	}

	function confirmation( d ) {
		root.innerHTML = '';
		function kv( k, v, cls ) { return h( 'div', { class: 'eclc-kv' }, [ h( 'strong', { text: k + ': ' } ), h( 'span', { class: cls || '', text: v } ) ] ); }
		root.appendChild( h( 'div', { class: 'eclc-card eclc-ok' }, [
			h( 'h2', { class: 'eclc-h', text: 'Bestellung erhalten – bitte überweisen' } ),
			h( 'p', { text: 'Vielen Dank! Bitte überweise den Betrag mit dem Verwendungszweck unten. Deine Bestellung ist reserviert, bis die Zahlung eingeht.' } ),
			kv( 'Betrag', d.currency + ' ' + Number( d.total ).toFixed( 2 ) ),
			kv( 'IBAN', ( d.bank && d.bank.iban ) || '—', 'eclc-iban' ),
			kv( 'Empfänger', ( d.bank && d.bank.holder ) || '—' ),
			( d.bank && d.bank.bankName ) ? kv( 'Bank', d.bank.bankName ) : null,
			kv( 'Verwendungszweck', d.ref )
		] ) );
	}

	function render() {
		root.innerHTML = '';
		var totalEl = h( 'span', { text: money( total() ) } );

		var productRows = ( C.products || [] ).map( function ( p ) {
			var q = h( 'input', { class: 'eclc-qty', type: 'number', min: '0', value: '0', onInput: function ( e ) {
				qty[ p.id ] = Math.max( 0, parseInt( e.target.value, 10 ) || 0 );
				totalEl.textContent = money( total() );
			} } );
			return h( 'div', { class: 'eclc-row' }, [
				h( 'div', {}, [ h( 'div', { class: 'eclc-pname', text: p.name } ), p.description ? h( 'div', { class: 'eclc-pdesc', text: p.description } ) : null ] ),
				h( 'span', { class: 'eclc-badge', text: money( p.price ) } ),
				q
			] );
		} );
		if ( ! ( C.products || [] ).length ) { productRows = [ h( 'p', { text: 'Keine Produkte verfügbar.' } ) ]; }

		var nameI = h( 'input', { type: 'text' } );
		var mailI = h( 'input', { type: 'email' } );
		var errEl = h( 'div', { class: 'eclc-err' } );
		errEl.style.display = 'none';
		var btn = h( 'button', { class: 'eclc-btn', type: 'button', text: 'Jetzt bestellen (Banküberweisung)' } );

		btn.addEventListener( 'click', function () {
			errEl.style.display = 'none';
			var items = ( C.products || [] ).filter( function ( p ) { return ( qty[ p.id ] || 0 ) > 0; } ).map( function ( p ) { return { id: p.id, qty: qty[ p.id ] }; } );
			if ( ! items.length ) { errEl.textContent = 'Bitte mindestens ein Produkt wählen.'; errEl.style.display = 'block'; return; }
			if ( ! nameI.value.trim() || ! mailI.value.trim() ) { errEl.textContent = 'Bitte Name und E-Mail angeben.'; errEl.style.display = 'block'; return; }
			btn.disabled = true; btn.textContent = 'Wird gesendet…';
			var body = new URLSearchParams();
			body.append( 'action', 'easycheckout_place_order' );
			body.append( 'nonce', ecLocal.nonce );
			body.append( 'slug', C.slug );
			body.append( 'items', JSON.stringify( items ) );
			body.append( 'name', nameI.value );
			body.append( 'email', mailI.value );
			fetch( ecLocal.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || 'Fehler' ); }
					confirmation( j.data );
					if ( window.scrollTo ) { window.scrollTo( 0, 0 ); }
				} )
				.catch( function ( e ) {
					errEl.textContent = e.message; errEl.style.display = 'block';
					btn.disabled = false; btn.textContent = 'Jetzt bestellen (Banküberweisung)';
				} );
		} );

		root.appendChild( h( 'div', { class: 'eclc-card' },
			[ h( 'h2', { class: 'eclc-h', text: C.name } ) ].concat( productRows ).concat( [ h( 'div', { class: 'eclc-total' }, [ h( 'span', { text: 'Total' } ), totalEl ] ) ] )
		) );
		root.appendChild( h( 'div', { class: 'eclc-card' }, [
			h( 'h2', { class: 'eclc-h', text: 'Deine Angaben' } ),
			errEl,
			h( 'label', { class: 'eclc-field' }, [ h( 'span', { text: 'Name' } ), nameI ] ),
			h( 'label', { class: 'eclc-field' }, [ h( 'span', { text: 'E-Mail' } ), mailI ] ),
			btn
		] ) );
	}

	render();
} )();
