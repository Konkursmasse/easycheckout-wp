/* global ecAccount, Stripe */
/* EasyCheckout – nativer KONTO-Checkout auf der Haendler-Domain.
   Design = local-checkout.css (anpassbar). Zahlung = Stripe Elements (white-label,
   der Kunde sieht nur easyCheckout). Daten/Bezahlung laufen ueber die
   easyCheckout-Public-API (server-seitiger WP-Proxy -> kein CORS). */
( function () {
	'use strict';
	if ( typeof ecAccount === 'undefined' ) { return; }
	var root = document.querySelector( '.ec-account-checkout[data-ec-account]' );
	if ( ! root ) { return; }

	var C = null;                 // checkout data (products, currency, name...)
	var qty = {};
	var stripe = null, elements = null;

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
	function money( n ) { return ( C && C.currency ? C.currency : 'CHF' ) + ' ' + Number( n || 0 ).toFixed( 2 ); }
	function total() { var t = 0; ( C.products || [] ).forEach( function ( p ) { t += ( qty[ p.id ] || 0 ) * Number( p.price ); } ); return Math.round( t * 100 ) / 100; }

	function post( action, fields ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', ecAccount.nonce );
		Object.keys( fields || {} ).forEach( function ( k ) { body.append( k, fields[ k ] ); } );
		return fetch( ecAccount.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() } )
			.then( function ( r ) { return r.json(); } )
			.then( function ( j ) { if ( ! j.success ) { throw new Error( ( j.data && j.data.message ) || 'Fehler' ); } return j.data; } );
	}

	function countrySel( val ) {
		var opts = [ [ 'CH', 'Schweiz' ], [ 'DE', 'Deutschland' ], [ 'AT', 'Österreich' ], [ 'LI', 'Liechtenstein' ], [ 'FR', 'Frankreich' ], [ 'IT', 'Italien' ] ];
		var sel = h( 'select', { class: 'eclc-input' }, opts.map( function ( o ) { return h( 'option', { value: o[ 0 ] }, [ o[ 1 ] ] ); } ) );
		sel.value = val || 'CH'; return sel;
	}

	function powered() {
		return h( 'div', { class: 'eclc-powered' }, [ h( 'a', { href: 'https://easycheckout.ch', target: '_blank', rel: 'noopener', text: 'Powered by easyCheckout' } ) ] );
	}

	function loadingView( msg ) { root.innerHTML = ''; root.appendChild( h( 'div', { class: 'eclc-wrap' }, [ h( 'div', { class: 'eclc-cart', style: 'max-width:520px;margin:0 auto;text-align:center' }, [ h( 'p', { class: 'eclc-empty', text: msg || 'Lädt…' } ) ] ) ] ) ); }
	function errorView( msg ) { root.innerHTML = ''; root.appendChild( h( 'div', { class: 'eclc-wrap' }, [ h( 'div', { class: 'eclc-cart', style: 'max-width:520px;margin:0 auto' }, [ h( 'div', { class: 'eclc-err', text: msg } ) ] ) ] ) ); }

	function render() {
		root.innerHTML = '';
		var totalEl = h( 'span', { text: money( 0 ) } );
		var qnums = {};
		function updateTotal() { totalEl.textContent = money( total() ); }
		function setQty( id, v ) { qty[ id ] = Math.max( 0, v ); if ( qnums[ id ] ) { qnums[ id ].textContent = String( qty[ id ] ); } updateTotal(); }

		var left = h( 'div', {}, [ h( 'h2', { class: 'eclc-col-h', text: 'Produkte' } ) ] );
		( C.products || [] ).forEach( function ( p ) {
			var num = h( 'span', { class: 'eclc-qnum', text: '0' } ); qnums[ p.id ] = num;
			var minus = h( 'button', { class: 'eclc-qbtn', type: 'button', text: '−', onClick: function () { setQty( p.id, ( qty[ p.id ] || 0 ) - 1 ); } } );
			var plus = h( 'button', { class: 'eclc-qbtn', type: 'button', text: '+', onClick: function () { setQty( p.id, ( qty[ p.id ] || 0 ) + 1 ); } } );
			var price = h( 'p', { class: 'eclc-pprice' }, [ money( p.price ) ] );
			if ( C.vatEnabled && C.vatRate ) { price.appendChild( h( 'span', { class: 'eclc-vat', text: ( C.vatInclusive ? 'inkl. ' : 'zzgl. ' ) + C.vatRate + '% MwSt' } ) ); }
			left.appendChild( h( 'div', { class: 'eclc-prod' }, [ h( 'div', { class: 'eclc-prod-in' }, [
				p.imageUrl ? h( 'img', { class: 'eclc-img', src: p.imageUrl, alt: p.name } ) : h( 'div', { class: 'eclc-img-empty', text: '🛍' } ),
				h( 'div', { class: 'eclc-pinfo' }, [ h( 'h3', { class: 'eclc-pname', text: p.name } ), p.description ? h( 'p', { class: 'eclc-pdesc', text: p.description } ) : null, price ] ),
				h( 'div', { class: 'eclc-qty' }, [ minus, num, plus ] )
			] ) ] ) );
		} );
		if ( ! ( C.products || [] ).length ) { left.appendChild( h( 'p', { class: 'eclc-empty', text: 'Keine Produkte verfügbar.' } ) ); }

		// Kundenformular (wie lokaler Checkout)
		var emailI = h( 'input', { class: 'eclc-input', type: 'email', placeholder: 'E-Mail-Adresse *' } );
		var nameI = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Vor- und Nachname *' } );
		var companyI = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Firma (optional)' } );
		var phoneI = h( 'input', { class: 'eclc-input', type: 'tel', placeholder: 'Telefonnummer' } );
		var bStreet = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Strasse und Hausnummer *' } );
		var bZip = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'PLZ *' } );
		var bCity = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Ort *' } );
		var bCountry = countrySel( 'CH' );
		var errEl = h( 'div', { class: 'eclc-err' } ); errEl.style.display = 'none';
		var payWrap = h( 'div', {} ); // hier kommt spaeter das Stripe Payment Element rein
		var btn = h( 'button', { class: 'eclc-btn', type: 'button', text: 'Weiter zur Zahlung' } );

		function fail( m ) { errEl.textContent = m; errEl.style.display = 'block'; }

		btn.addEventListener( 'click', function () {
			errEl.style.display = 'none';
			var items = ( C.products || [] ).filter( function ( p ) { return ( qty[ p.id ] || 0 ) > 0; } ).map( function ( p ) { return { productId: p.id, quantity: qty[ p.id ] }; } );
			if ( ! items.length ) { return fail( 'Bitte mindestens ein Produkt wählen.' ); }
			if ( ! emailI.value.trim() ) { return fail( 'Bitte E-Mail-Adresse eingeben.' ); }
			if ( ! nameI.value.trim() ) { return fail( 'Bitte Namen eingeben.' ); }
			if ( ! bStreet.value.trim() || ! bZip.value.trim() || ! bCity.value.trim() ) { return fail( 'Bitte vollständige Rechnungsadresse eingeben.' ); }
			btn.disabled = true; btn.textContent = 'Zahlung wird vorbereitet…';
			var payload = {
				items: items,
				customerEmail: emailI.value, customerName: nameI.value,
				customerCompany: companyI.value, customerPhone: phoneI.value,
				customerAddress: { street: bStreet.value, postalCode: bZip.value, city: bCity.value, country: bCountry.value },
				newsletterOptIn: false
			};
			post( 'easycheckout_pub_pay', { slug: ecAccount.slug, payload: JSON.stringify( payload ) } ).then( function ( d ) {
				var body = d.body || {};
				if ( ( d.status && d.status >= 400 ) || body.error ) { throw new Error( body.error || ( 'Fehler ' + d.status ) ); }
				if ( ! body.clientSecret || ! body.publishableKey ) { throw new Error( 'Zahlung konnte nicht initialisiert werden.' ); }
				mountPayment( body, btn, errEl, payWrap, [ emailI, nameI, companyI, phoneI, bStreet, bZip, bCity, bCountry ] );
			} ).catch( function ( e ) { fail( e.message ); btn.disabled = false; btn.textContent = 'Weiter zur Zahlung'; } );
		} );

		var cart = h( 'div', { class: 'eclc-cart' }, [
			h( 'h2', { class: 'eclc-col-h', text: 'Bestellung' } ),
			h( 'div', { class: 'eclc-divider' }, [ h( 'div', { class: 'eclc-total' }, [ h( 'span', { text: 'Total' } ), totalEl ] ) ] ),
			errEl,
			h( 'div', { class: 'eclc-sec' }, [ h( 'h3', { class: 'eclc-sec-h', text: 'Kontaktdaten' } ), h( 'div', { class: 'eclc-stack' }, [ emailI, nameI, companyI, phoneI ] ) ] ),
			h( 'div', { class: 'eclc-sec' }, [ h( 'h3', { class: 'eclc-sec-h', text: 'Rechnungsadresse' } ), h( 'div', { class: 'eclc-stack' }, [ bStreet, h( 'div', { class: 'eclc-3' }, [ bZip, bCity ] ), bCountry ] ) ] ),
			payWrap,
			h( 'div', { style: 'margin-top:18px;' }, [ btn ] )
		] );

		var wrap = h( 'div', { class: 'eclc-wrap' }, [
			h( 'div', { class: 'eclc-header' }, [
				( C.merchantLogoUrl || ( C.design && C.design.logoUrl ) ) ? h( 'img', { class: 'eclc-logo', src: C.merchantLogoUrl || C.design.logoUrl, alt: C.name } ) : null,
				h( 'h1', { class: 'eclc-title', text: C.name || 'Checkout' } )
			] ),
			h( 'div', { class: 'eclc-grid' }, [ left, cart ] ),
			powered()
		] );
		root.appendChild( wrap );
		updateTotal();
	}

	// Stripe Payment Element mounten und den Button zu "Jetzt bezahlen" umbauen.
	function mountPayment( body, btn, errEl, payWrap, lockFields ) {
		lockFields.forEach( function ( f ) { f.disabled = true; } );
		try {
			stripe = Stripe( body.publishableKey, body.stripeAccountId ? { stripeAccount: body.stripeAccountId } : undefined );
		} catch ( e ) { errEl.textContent = 'Zahlungssystem nicht verfügbar.'; errEl.style.display = 'block'; btn.disabled = false; btn.textContent = 'Weiter zur Zahlung'; return; }
		elements = stripe.elements( { clientSecret: body.clientSecret } );
		var pe = elements.create( 'payment' );
		payWrap.innerHTML = '';
		payWrap.appendChild( h( 'div', { class: 'eclc-sec' }, [ h( 'h3', { class: 'eclc-sec-h', text: 'Zahlung' } ), h( 'div', { id: 'eca-pe' } ) ] ) );
		pe.mount( '#eca-pe' );
		btn.disabled = false;
		btn.textContent = 'Jetzt bezahlen ' + money( body.total != null ? body.total : total() );
		var newBtn = btn.cloneNode( true ); // alten Click-Handler entfernen
		btn.parentNode.replaceChild( newBtn, btn );
		newBtn.addEventListener( 'click', function () {
			errEl.style.display = 'none';
			newBtn.disabled = true; newBtn.textContent = 'Zahlung läuft…';
			stripe.confirmPayment( { elements: elements, confirmParams: { return_url: window.location.href }, redirect: 'if_required' } )
				.then( function ( result ) {
					if ( result.error ) { errEl.textContent = result.error.message || 'Zahlung fehlgeschlagen.'; errEl.style.display = 'block'; newBtn.disabled = false; newBtn.textContent = 'Jetzt bezahlen'; return; }
					successView( body );
				} );
		} );
	}

	function successView( body ) {
		root.innerHTML = '';
		root.appendChild( h( 'div', { class: 'eclc-wrap' }, [ h( 'div', { class: 'eclc-cart eclc-ok', style: 'max-width:520px;margin:0 auto;' }, [
			h( 'div', { class: 'eclc-ok-ico', text: '✓' } ),
			h( 'h1', { class: 'eclc-title', text: 'Zahlung erfolgreich' } ),
			h( 'p', { class: 'eclc-sub', text: 'Vielen Dank für deine Bestellung! Du erhältst eine Bestätigung per E-Mail.' } )
		] ), powered() ] ) );
		if ( window.scrollTo ) { window.scrollTo( 0, 0 ); }
	}

	// Start: Checkout-Daten laden
	loadingView( 'Checkout wird geladen…' );
	post( 'easycheckout_pub_checkout', { slug: ecAccount.slug } ).then( function ( d ) {
		var body = d.body || {};
		if ( ( d.status && d.status >= 400 ) || ! body.checkout ) { throw new Error( ( body && body.error ) || 'Checkout nicht gefunden.' ); }
		C = body.checkout;
		// Design des Konto-Checkouts uebernehmen (Primaerfarbe) -> stylable.
		if ( C.design && C.design.primaryColor ) { root.style.setProperty( '--ec-p', C.design.primaryColor ); }
		( C.products || [] ).forEach( function ( p ) { qty[ p.id ] = 0; } );
		render();
	} ).catch( function ( e ) { errorView( e.message ); } );
} )();
