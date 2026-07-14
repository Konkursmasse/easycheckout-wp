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

	// Swiss-QR-Payload (SPC) im offiziellen QR-Rechnung-Format.
	function swissSpc( q ) {
		var cr = q.creditor || {}, db = q.debtor || {};
		return [
			'SPC', '0200', '1',
			( q.iban || '' ).replace( /\s/g, '' ),
			'S', cr.name || '', cr.street || '', '', cr.postalCode || '', cr.city || '', 'CH',
			'', '', '', '', '', '', '',
			q.amount || '', q.currency || 'CHF',
			db.name ? 'S' : '', db.name || '', db.street || '', '', db.postalCode || '', db.city || '', db.name ? 'CH' : '',
			'NON', '', q.message || '',
			'EPD', ''
		].join( '\n' );
	}
	function makeQrImg( text ) {
		try {
			if ( typeof qrcode === 'undefined' ) { return null; }
			if ( qrcode.stringToBytesFuncs && qrcode.stringToBytesFuncs[ 'UTF-8' ] ) { qrcode.stringToBytes = qrcode.stringToBytesFuncs[ 'UTF-8' ]; }
			var qr = qrcode( 0, 'M' );
			qr.addData( text );
			qr.make();
			var img = new Image();
			img.src = qr.createDataURL( 4, 8 );
			img.className = 'eclc-qr-img';
			img.alt = 'QR-Rechnung';
			return img;
		} catch ( e ) { return null; }
	}

	function powered() {
		return h( 'div', { class: 'eclc-powered' }, [ h( 'a', { href: 'https://easycheckout.ch', target: '_blank', rel: 'noopener', text: 'Powered by easyCheckout' } ) ] );
	}

	function confirmation( d ) {
		root.innerHTML = '';
		function kv( k, v, cls ) { return h( 'div', { class: 'eclc-kv' }, [ h( 'b', { text: k } ), h( 'span', { class: cls || '', text: v } ) ] ); }
		var children = [
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
		];
		if ( d.qr && d.qr.iban ) {
			var img = makeQrImg( swissSpc( d.qr ) );
			if ( img ) {
				children.push( h( 'div', { class: 'eclc-qr' }, [
					h( 'div', { class: 'eclc-qr-h', text: 'QR-Rechnung' } ),
					img,
					h( 'div', { class: 'eclc-qr-hint', text: 'Mit deiner Banking-App scannen und bezahlen.' } )
				] ) );
			}
		}
		var card = h( 'div', { class: 'eclc-cart eclc-ok', style: 'max-width:520px;margin:0 auto;' }, children );
		root.appendChild( h( 'div', { class: 'eclc-wrap' }, [ card, powered() ] ) );
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
		// --- Kategorien-Helfer ---
		function catById( id ) { return ( C.categories || [] ).find( function ( c ) { return c.id === id; } ) || null; }
		function pMax( p ) { var cat = catById( p.categoryId ); return ( cat && cat.allowQuantity === false ) ? 1 : Infinity; }
		function activeGroup() {
			var ids = Object.keys( qty ).filter( function ( id ) { return qty[ id ] > 0; } );
			if ( ! ids.length ) { return null; }
			var p = ( C.products || [] ).find( function ( x ) { return String( x.id ) === String( ids[ 0 ] ); } );
			return p ? ( p.categoryId != null ? p.categoryId : 'none' ) : null;
		}

		function setQty( id, v ) {
			var p = ( C.products || [] ).find( function ( x ) { return String( x.id ) === String( id ); } );
			var cat = p ? catById( p.categoryId ) : null;
			var nv = Math.max( 0, v );
			if ( nv > 0 ) {
				nv = Math.min( nv, pMax( p ) );
				// Regel 1: nur eine Kategorie gleichzeitig
				if ( C.categorySelection === 'single' ) {
					var ag = activeGroup();
					var tg = ( p && p.categoryId != null ) ? p.categoryId : 'none';
					if ( ag !== null && ag !== tg ) { return; }
				}
				// Regel 2: Kategorie erlaubt nur EIN Produkt (Radio)
				if ( cat && cat.singleProduct ) {
					( C.products || [] ).forEach( function ( o ) { if ( o.categoryId === p.categoryId && String( o.id ) !== String( id ) ) { qty[ o.id ] = 0; } } );
				}
			}
			qty[ id ] = nv;
			renderProducts();
			updateSummary();
		}

		var productsWrap = h( 'div', {} );
		function prodCard( p ) {
			var cat = catById( p.categoryId );
			var q = qty[ p.id ] || 0;
			var selected = q > 0;
			var asToggle = !! ( cat && cat.allowQuantity === false );
			var ag = activeGroup();
			var locked = C.categorySelection === 'single' && ! selected && ag !== null && ag !== ( p.categoryId != null ? p.categoryId : 'none' );
			var price = h( 'p', { class: 'eclc-pprice' }, [ money( p.price ) ] );
			if ( C.vatEnabled && C.vatRate ) { price.appendChild( h( 'span', { class: 'eclc-vat', text: 'zzgl. ' + C.vatRate + '% MwSt' } ) ); }
			var ctrl;
			if ( asToggle ) {
				var battrs = { class: 'eclc-qbtn' + ( selected ? ' eclc-selected' : '' ), type: 'button', text: selected ? '✓' : ( cat && cat.singleProduct ? 'Wählen' : '+' ), onClick: function () { setQty( p.id, selected ? 0 : 1 ); } };
				if ( locked ) { battrs.disabled = 'disabled'; }
				ctrl = h( 'div', { class: 'eclc-qty' }, [ h( 'button', battrs ) ] );
			} else {
				var minus = h( 'button', { class: 'eclc-qbtn', type: 'button', text: '−', onClick: function () { setQty( p.id, ( qty[ p.id ] || 0 ) - 1 ); } } );
				var num = h( 'span', { class: 'eclc-qnum', text: String( q ) } );
				var plusAttrs = { class: 'eclc-qbtn', type: 'button', text: '+', onClick: function () { setQty( p.id, ( qty[ p.id ] || 0 ) + 1 ); } };
				if ( locked ) { plusAttrs.disabled = 'disabled'; }
				ctrl = h( 'div', { class: 'eclc-qty' }, [ minus, num, h( 'button', plusAttrs ) ] );
			}
			return h( 'div', { class: 'eclc-prod' + ( locked ? ' eclc-locked' : '' ) }, [
				h( 'div', { class: 'eclc-prod-in' }, [
					p.imageUrl ? h( 'img', { class: 'eclc-img', src: p.imageUrl, alt: p.name } ) : h( 'div', { class: 'eclc-img-empty', text: '🛍' } ),
					h( 'div', { class: 'eclc-pinfo' }, [
						h( 'h3', { class: 'eclc-pname', text: p.name } ),
						p.description ? h( 'p', { class: 'eclc-pdesc', text: p.description } ) : null,
						price,
						locked ? h( 'p', { class: 'eclc-pdesc', text: 'Nur aus einer Kategorie wählbar' } ) : null
					] ),
					ctrl
				] )
			] );
		}
		function renderProducts() {
			productsWrap.innerHTML = '';
			var prods = C.products || [];
			if ( ! prods.length ) { productsWrap.appendChild( h( 'p', { class: 'eclc-empty', text: 'Keine Produkte verfügbar.' } ) ); return; }
			var cats = C.categories || [];
			if ( ! cats.length ) { prods.forEach( function ( p ) { productsWrap.appendChild( prodCard( p ) ); } ); return; }
			cats.forEach( function ( cat ) {
				var items = prods.filter( function ( p ) { return p.categoryId === cat.id; } );
				if ( ! items.length ) { return; }
				productsWrap.appendChild( h( 'div', { class: 'eclc-cat-h' }, [
					h( 'h3', { class: 'eclc-cat-name', text: cat.name } ),
					cat.description ? h( 'p', { class: 'eclc-cat-desc', text: cat.description } ) : null,
					cat.singleProduct ? h( 'p', { class: 'eclc-cat-hint', text: 'Nur ein Produkt wählbar' } ) : null
				] ) );
				items.forEach( function ( p ) { productsWrap.appendChild( prodCard( p ) ); } );
			} );
			var catIds = cats.map( function ( c ) { return c.id; } );
			var unc = prods.filter( function ( p ) { return ! p.categoryId || catIds.indexOf( p.categoryId ) === -1; } );
			if ( unc.length ) {
				if ( cats.some( function ( c ) { return prods.some( function ( p ) { return p.categoryId === c.id; } ); } ) ) {
					productsWrap.appendChild( h( 'div', { class: 'eclc-cat-h' }, [ h( 'h3', { class: 'eclc-cat-name', text: 'Weitere' } ) ] ) );
				}
				unc.forEach( function ( p ) { productsWrap.appendChild( prodCard( p ) ); } );
			}
		}

		// Left: products (gruppiert nach Kategorie). Obertitel dynamisch (z.B. „Tickets").
		var prodTitle = ( C.productsTitle && String( C.productsTitle ).trim() ) ? C.productsTitle : 'Produkte';
		var left = h( 'div', {}, [ h( 'h2', { class: 'eclc-col-h', text: prodTitle } ) ] );
		if ( C.categorySelection === 'single' && ( C.categories || [] ).length ) {
			left.appendChild( h( 'p', { class: 'eclc-cat-hint', text: 'Bitte nur Produkte aus einer Kategorie wählen.' } ) );
		}
		left.appendChild( productsWrap );
		renderProducts();

		// Right: cart + Kundenformular (Felder wie easycheckout.ch)
		function countrySel( val ) {
			var opts = [ [ 'CH', 'Schweiz' ], [ 'DE', 'Deutschland' ], [ 'AT', 'Österreich' ], [ 'LI', 'Liechtenstein' ], [ 'FR', 'Frankreich' ], [ 'IT', 'Italien' ] ];
			var sel = h( 'select', { class: 'eclc-input' }, opts.map( function ( o ) { return h( 'option', { value: o[ 0 ] }, [ o[ 1 ] ] ); } ) );
			sel.value = val;
			return sel;
		}
		var emailI = h( 'input', { class: 'eclc-input', type: 'email', placeholder: 'E-Mail-Adresse *' } );
		var nameI = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Vor- und Nachname *' } );
		var companyI = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Firma (optional)' } );
		var phoneI = h( 'input', { class: 'eclc-input', type: 'tel', placeholder: 'Telefonnummer' } );
		var newsCb = h( 'input', { type: 'checkbox' } );
		var bStreet = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Strasse und Hausnummer *' } );
		var bZip = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'PLZ *' } );
		var bCity = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Ort *' } );
		var bCountry = countrySel( 'CH' );
		var sameCb = h( 'input', { type: 'checkbox' } ); sameCb.checked = true;
		var dStreet = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Strasse und Hausnummer *' } );
		var dZip = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'PLZ *' } );
		var dCity = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Ort *' } );
		var dCountry = countrySel( 'CH' );
		var deliveryWrap = h( 'div', { class: 'eclc-sec' }, [
			h( 'h3', { class: 'eclc-sec-h', text: 'Lieferadresse' } ),
			h( 'div', { class: 'eclc-stack' }, [ dStreet, h( 'div', { class: 'eclc-3' }, [ dZip, dCity ] ), dCountry ] )
		] );
		deliveryWrap.style.display = 'none';
		sameCb.addEventListener( 'change', function () { deliveryWrap.style.display = sameCb.checked ? 'none' : 'block'; } );

		var errEl = h( 'div', { class: 'eclc-err' } );
		errEl.style.display = 'none';
		var btn = h( 'button', { class: 'eclc-btn', type: 'button', text: 'Jetzt bestellen (Banküberweisung)' } );
		btn.addEventListener( 'click', function () {
			errEl.style.display = 'none';
			function fail( m ) { errEl.textContent = m; errEl.style.display = 'block'; }
			var items = ( C.products || [] ).filter( function ( p ) { return ( qty[ p.id ] || 0 ) > 0; } ).map( function ( p ) { return { id: p.id, qty: qty[ p.id ] }; } );
			if ( ! items.length ) { return fail( 'Bitte mindestens ein Produkt wählen.' ); }
			if ( ! emailI.value.trim() ) { return fail( 'Bitte geben Sie Ihre E-Mail-Adresse ein.' ); }
			if ( ! nameI.value.trim() ) { return fail( 'Bitte geben Sie Ihren Namen ein.' ); }
			if ( ! bStreet.value.trim() || ! bZip.value.trim() || ! bCity.value.trim() ) { return fail( 'Bitte geben Sie Ihre vollständige Rechnungsadresse ein.' ); }
			if ( ! sameCb.checked && ( ! dStreet.value.trim() || ! dZip.value.trim() || ! dCity.value.trim() ) ) { return fail( 'Bitte geben Sie Ihre vollständige Lieferadresse ein.' ); }
			btn.disabled = true; btn.textContent = 'Wird gesendet…';
			var billing = { street: bStreet.value, postalCode: bZip.value, city: bCity.value, country: bCountry.value };
			var delivery = sameCb.checked ? billing : { street: dStreet.value, postalCode: dZip.value, city: dCity.value, country: dCountry.value };
			var body = new URLSearchParams();
			body.append( 'action', 'easycheckout_place_order' );
			body.append( 'nonce', ecLocal.nonce );
			body.append( 'slug', C.slug );
			body.append( 'items', JSON.stringify( items ) );
			body.append( 'name', nameI.value );
			body.append( 'email', emailI.value );
			body.append( 'company', companyI.value );
			body.append( 'phone', phoneI.value );
			body.append( 'newsletter', newsCb.checked ? '1' : '0' );
			body.append( 'sameAddress', sameCb.checked ? '1' : '0' );
			body.append( 'billing', JSON.stringify( billing ) );
			body.append( 'delivery', JSON.stringify( delivery ) );
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
			( C.vatEnabled && C.vatRate ) ? h( 'p', { class: 'eclc-vatnote', text: 'inkl. ' + C.vatRate + '% MwSt' } ) : null,
			h( 'div', { class: 'eclc-divider' }, [ h( 'div', { class: 'eclc-total' }, [ h( 'span', { text: 'Total' } ), totalEl ] ) ] ),
			errEl,
			h( 'div', { class: 'eclc-sec' }, [
				h( 'h3', { class: 'eclc-sec-h', text: 'Kontaktdaten' } ),
				h( 'div', { class: 'eclc-stack' }, [ emailI, nameI, companyI, phoneI ] ),
				h( 'label', { class: 'eclc-check' }, [ newsCb, h( 'span', { text: 'Ja, ich möchte über Neuigkeiten und Angebote informiert werden' } ) ] )
			] ),
			h( 'div', { class: 'eclc-sec' }, [
				h( 'h3', { class: 'eclc-sec-h', text: 'Rechnungsadresse' } ),
				h( 'div', { class: 'eclc-stack' }, [ bStreet, h( 'div', { class: 'eclc-3' }, [ bZip, bCity ] ), bCountry ] )
			] ),
			h( 'label', { class: 'eclc-check' }, [ sameCb, h( 'span', { text: 'Lieferadresse entspricht Rechnungsadresse' } ) ] ),
			deliveryWrap,
			h( 'div', { style: 'margin-top:18px;' }, [ btn ] ),
			h( 'p', { class: 'eclc-pay-hint', text: 'Zahlung per Banküberweisung – du erhältst die Kontodaten nach der Bestellung.' } )
		] );

		var wrap = h( 'div', { class: 'eclc-wrap' }, [
			h( 'div', { class: 'eclc-header' }, [
				C.logo ? h( 'img', { class: 'eclc-logo', src: C.logo, alt: C.name } ) : null,
				h( 'h1', { class: 'eclc-title', text: C.name } )
			] ),
			h( 'div', { class: 'eclc-grid' }, [ left, cart ] ),
			powered()
		] );
		root.appendChild( wrap );
		updateSummary();
	}

	render();
} )();
