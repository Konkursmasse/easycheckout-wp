/* global ecLocal */
/* EasyCheckout – lokaler Bankueberweisungs-Checkout (Frontend, ohne Konto).
   Layout/Design an die easycheckout.ch-Checkout-Seiten angelehnt. */
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
		function kv( k, v, cls ) { return h( 'div', { class: 'eclc-kv' }, [ h( 'b', { text: k } ), h( 'span', { class: cls || '', text: v } ) ] ); }
		var card = h( 'div', { class: 'eclc-cart eclc-ok', style: 'max-width:520px;margin:0 auto;' }, [
			h( 'div', { class: 'eclc-ok-ico', text: '✓' } ),
			h( 'h1', { class: 'eclc-title', text: 'Bestellung erhalten' } ),
			h( 'p', { class: 'eclc-sub', text: 'Vielen Dank! Bitte überweise den Betrag mit dem Verwendungszweck unten. Deine Bestellung ist reserviert, bis die Zahlung eingeht.' } ),
			h( 'div', { class: 'eclc-kvs' }, [
				kv( 'Betrag', d.currency + ' ' + Number( d.total ).toFixed( 2 ) ),
				kv( 'IBAN', ( d.bank && d.bank.iban ) || '—', 'eclc-iban' ),
				kv( 'Empfänger', ( d.bank && d.bank.holder ) || '—' ),
				( d.bank && d.bank.bankName ) ? kv( 'Bank', d.bank.bankName ) : null,
				kv( 'Verwendungszweck', d.ref )
			] )
		] );
		root.appendChild( h( 'div', { class: 'eclc-wrap' }, [ card ] ) );
	}

	function render() {
		root.innerHTML = '';
		var qnums = {};
		var summaryBox = h( 'div', {} );
		var totalEl = h( 'span', { text: money( 0 ) } );

		function updateSummary() {
			summaryBox.innerHTML = '';
			var any = false;
			( C.products || [] ).forEach( function ( p ) {
				var q = qty[ p.id ] || 0;
				if ( ! q ) { return; }
				any = true;
				summaryBox.appendChild( h( 'div', { class: 'eclc-line' }, [
					h( 'span', { class: 'eclc-lname', text: q + '× ' + p.name } ),
					h( 'span', { class: 'eclc-lval', text: money( q * p.price ) } )
				] ) );
			} );
			if ( ! any ) { summaryBox.appendChild( h( 'p', { class: 'eclc-empty', text: 'Noch keine Produkte ausgewählt' } ) ); }
			totalEl.textContent = money( total() );
		}
		function setQty( id, v ) {
			qty[ id ] = Math.max( 0, v );
			if ( qnums[ id ] ) { qnums[ id ].textContent = String( qty[ id ] ); }
			updateSummary();
		}

		// Left: products
		var left = h( 'div', {}, [ h( 'h2', { class: 'eclc-col-h', text: 'Produkte' } ) ] );
		( C.products || [] ).forEach( function ( p ) {
			var num = h( 'span', { class: 'eclc-qnum', text: '0' } );
			qnums[ p.id ] = num;
			var minus = h( 'button', { class: 'eclc-qbtn', type: 'button', text: '−', onClick: function () { setQty( p.id, ( qty[ p.id ] || 0 ) - 1 ); } } );
			var plus = h( 'button', { class: 'eclc-qbtn', type: 'button', text: '+', onClick: function () { setQty( p.id, ( qty[ p.id ] || 0 ) + 1 ); } } );
			var price = h( 'p', { class: 'eclc-pprice' }, [ money( p.price ) ] );
			if ( C.vatEnabled && C.vatRate ) { price.appendChild( h( 'span', { class: 'eclc-vat', text: 'zzgl. ' + C.vatRate + '% MwSt' } ) ); }
			left.appendChild( h( 'div', { class: 'eclc-prod' }, [
				h( 'div', { class: 'eclc-prod-in' }, [
					p.imageUrl ? h( 'img', { class: 'eclc-img', src: p.imageUrl, alt: p.name } ) : h( 'div', { class: 'eclc-img-empty', text: '🛍' } ),
					h( 'div', { class: 'eclc-pinfo' }, [
						h( 'h3', { class: 'eclc-pname', text: p.name } ),
						p.description ? h( 'p', { class: 'eclc-pdesc', text: p.description } ) : null,
						price
					] ),
					h( 'div', { class: 'eclc-qty' }, [ minus, num, plus ] )
				] )
			] ) );
		} );
		if ( ! ( C.products || [] ).length ) { left.appendChild( h( 'p', { class: 'eclc-empty', text: 'Keine Produkte verfügbar.' } ) ); }

		// Right: cart
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

		var cart = h( 'div', { class: 'eclc-cart' }, [
			h( 'h2', { class: 'eclc-col-h', text: 'Bestellung' } ),
			summaryBox,
			h( 'div', { class: 'eclc-divider' }, [ h( 'div', { class: 'eclc-total' }, [ h( 'span', { text: 'Total' } ), totalEl ] ) ] ),
			errEl,
			h( 'label', { class: 'eclc-field' }, [ h( 'span', { text: 'Name' } ), nameI ] ),
			h( 'label', { class: 'eclc-field' }, [ h( 'span', { text: 'E-Mail' } ), mailI ] ),
			btn,
			h( 'p', { class: 'eclc-pay-hint', text: 'Zahlung per Banküberweisung – du erhältst die Kontodaten nach der Bestellung.' } )
		] );

		var wrap = h( 'div', { class: 'eclc-wrap' }, [
			h( 'div', { class: 'eclc-header' }, [ h( 'h1', { class: 'eclc-title', text: C.name } ) ] ),
			h( 'div', { class: 'eclc-grid' }, [ left, cart ] )
		] );
		root.appendChild( wrap );
		updateSummary();
	}

	render();
} )();
