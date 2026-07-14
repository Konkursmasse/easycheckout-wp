/* global ecAccount, Stripe */
/* EasyCheckout – nativer KONTO-Checkout auf der Haendler-Domain.
   Design = local-checkout.css (anpassbar). Zahlung = Stripe Elements (white-label,
   der Kunde sieht nur easyCheckout). Daten/Bezahlung laufen ueber die
   easyCheckout-Public-API (server-seitiger WP-Proxy -> kein CORS).

   Vollstaendige Parität zur gehosteten Checkout-Seite: Kategorien + Auswahlregeln,
   Produkt-Optionen (S/M/L, Farben…) mit Aufschlag, Varianten-Bestand, Infofelder
   (Text/Checkboxen), Liefer-/Abholmodus mit modusabhaengigen Preisen + Liefergebuehr
   und dynamischem Produkt-Obertitel. Preise werden clientseitig NUR angezeigt — der
   Server (/pay) ist autoritativ und rechnet identisch. */
( function () {
	'use strict';
	if ( typeof ecAccount === 'undefined' ) { return; }
	var root = document.querySelector( '.ec-account-checkout[data-ec-account]' );
	if ( ! root ) { return; }

	var C = null;         // checkout data
	var mode = 'pickup';  // 'pickup' | 'delivery'
	var st = {};          // pro Produkt: { qty, opts:{groupId:optionId}, fields:{fieldId:value} }
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
	function round( n ) { return Math.round( ( Number( n ) || 0 ) * 100 ) / 100; }
	function money( n ) { return ( C && C.currency ? C.currency : 'CHF' ) + ' ' + Number( n || 0 ).toFixed( 2 ); }

	// --- Zustand pro Produkt ---
	function state( p ) {
		if ( ! st[ p.id ] ) {
			var opts = {};
			( p.optionGroups || [] ).forEach( function ( g ) { if ( ( g.options || [] ).length ) { opts[ g.id ] = g.options[ 0 ].id; } } );
			var fields = {};
			( p.customFields || [] ).forEach( function ( f ) { fields[ f.id ] = f.fieldType === 'checkbox' ? [] : ''; } );
			st[ p.id ] = { qty: 0, opts: opts, fields: fields };
		}
		return st[ p.id ];
	}

	// --- Preislogik (identisch zu lib/product-pricing.js auf dem Server) ---
	function baseUnit( p ) {
		if ( mode === 'delivery' ) { return ( p.deliveryPrice != null ? p.deliveryPrice : p.price ); }
		return ( p.pickupPrice != null ? p.pickupPrice : p.price );
	}
	function selectedOptions( p ) {
		var out = [];
		( p.optionGroups || [] ).forEach( function ( g ) {
			var oid = state( p ).opts[ g.id ];
			var o = ( g.options || [] ).find( function ( x ) { return String( x.id ) === String( oid ); } );
			if ( o ) { out.push( o ); }
		} );
		return out;
	}
	function surcharge( p ) { return selectedOptions( p ).reduce( function ( s, o ) { return s + ( Number( o.priceModifier ) || 0 ); }, 0 ); }
	function unit( p ) { return round( baseUnit( p ) + surcharge( p ) ); }
	function lineFee( p ) { return mode === 'delivery' ? round( p.deliveryFee || 0 ) : 0; }
	function variantKey( p ) {
		var ids = selectedOptions( p ).map( function ( o ) { return Number( o.id ); } ).filter( function ( n ) { return ! isNaN( n ); } );
		if ( ! ids.length ) { return null; }
		return ids.sort( function ( a, b ) { return a - b; } ).join( '-' );
	}
	function currentVariant( p ) {
		var vk = variantKey( p );
		if ( ! vk ) { return null; }
		return ( p.variants || [] ).find( function ( v ) { return v.optionKey === vk; } ) || null;
	}
	function variantSoldOut( p ) { var v = currentVariant( p ); return !! ( v && v.soldOut ); }
	function productSoldOut( p ) { return !! p.soldOut || variantSoldOut( p ); }

	function subtotal() {
		var t = 0;
		( C.products || [] ).forEach( function ( p ) { var s = state( p ); if ( s.qty > 0 ) { t += unit( p ) * s.qty + lineFee( p ); } } );
		return round( t );
	}
	function grandTotal() {
		var sub = subtotal();
		if ( C.vatEnabled && C.vatRate && ! C.vatInclusive ) { return round( sub * ( 1 + Number( C.vatRate ) / 100 ) ); }
		return sub;
	}

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
		var summaryBox = h( 'div', {} );
		var totalEl = h( 'span', { text: money( 0 ) } );

		// --- Kategorie-Helfer (Auswahlregeln wie im lokalen Checkout) ---
		function catById( id ) { return ( C.categories || [] ).find( function ( c ) { return c.id === id; } ) || null; }
		function pMax( p ) { var cat = catById( p.categoryId ); return ( cat && cat.allowQuantity === false ) ? 1 : Infinity; }
		function activeGroup() {
			var ids = Object.keys( st ).filter( function ( id ) { return st[ id ] && st[ id ].qty > 0; } );
			if ( ! ids.length ) { return null; }
			var p = ( C.products || [] ).find( function ( x ) { return String( x.id ) === String( ids[ 0 ] ); } );
			return p ? ( p.categoryId != null ? p.categoryId : 'none' ) : null;
		}

		function updateSummary() {
			summaryBox.innerHTML = '';
			var any = false;
			( C.products || [] ).forEach( function ( p ) {
				var s = state( p ); if ( ! s.qty ) { return; }
				any = true;
				var subs = selectedOptions( p ).map( function ( o ) { return o.label; } );
				summaryBox.appendChild( h( 'div', { class: 'eclc-line' }, [
					h( 'span', { class: 'eclc-lname' }, [
						h( 'span', { text: s.qty + '× ' + p.name } ),
						subs.length ? h( 'span', { class: 'eclc-lsub', text: subs.join( ', ' ) } ) : null
					] ),
					h( 'span', { class: 'eclc-lval', text: money( unit( p ) * s.qty ) } )
				] ) );
				if ( lineFee( p ) ) {
					summaryBox.appendChild( h( 'div', { class: 'eclc-line eclc-line-fee' }, [
						h( 'span', { class: 'eclc-lname', text: 'Liefergebühr – ' + p.name } ),
						h( 'span', { class: 'eclc-lval', text: money( lineFee( p ) ) } )
					] ) );
				}
			} );
			if ( ! any ) { summaryBox.appendChild( h( 'p', { class: 'eclc-empty', text: 'Noch keine Produkte ausgewählt' } ) ); }
			totalEl.textContent = money( grandTotal() );
		}

		var productsWrap = h( 'div', {} );

		function setQty( p, v ) {
			var s = state( p );
			var cat = catById( p.categoryId );
			var nv = Math.max( 0, v );
			if ( nv > 0 ) {
				if ( productSoldOut( p ) ) { return; }
				nv = Math.min( nv, pMax( p ) );
				if ( C.categorySelection === 'single' ) {
					var ag = activeGroup();
					var tg = ( p.categoryId != null ) ? p.categoryId : 'none';
					if ( ag !== null && ag !== tg ) { return; }
				}
				if ( cat && cat.singleProduct ) {
					( C.products || [] ).forEach( function ( o ) { if ( o.categoryId === p.categoryId && String( o.id ) !== String( p.id ) ) { state( o ).qty = 0; } } );
				}
			}
			s.qty = nv;
			renderProducts();
			updateSummary();
		}

		function optionGroupEl( p, g ) {
			var s = state( p );
			var sel = h( 'select', { class: 'eclc-input eclc-opt-sel', onChange: function ( e ) { s.opts[ g.id ] = e.target.value; renderProducts(); updateSummary(); } },
				( g.options || [] ).map( function ( o ) {
					var extra = Number( o.priceModifier ) ? ( ' (' + ( o.priceModifier > 0 ? '+' : '' ) + money( o.priceModifier ) + ')' ) : '';
					return h( 'option', { value: String( o.id ) }, [ o.label + extra ] );
				} )
			);
			sel.value = String( s.opts[ g.id ] );
			return h( 'label', { class: 'eclc-optg' }, [ h( 'span', { class: 'eclc-optg-l', text: g.name }, [] ), sel ] );
		}

		function customFieldEl( p, f ) {
			var s = state( p );
			if ( f.fieldType === 'checkbox' ) {
				var boxes = ( f.options || [] ).map( function ( opt ) {
					var cb = h( 'input', { type: 'checkbox', value: opt } );
					cb.addEventListener( 'change', function () {
						var arr = s.fields[ f.id ] || [];
						if ( cb.checked ) { if ( arr.indexOf( opt ) === -1 ) { arr.push( opt ); } }
						else { arr = arr.filter( function ( x ) { return x !== opt; } ); }
						s.fields[ f.id ] = arr;
					} );
					if ( ( s.fields[ f.id ] || [] ).indexOf( opt ) !== -1 ) { cb.checked = true; }
					return h( 'label', { class: 'eclc-cf-box' }, [ cb, h( 'span', { text: opt } ) ] );
				} );
				return h( 'div', { class: 'eclc-cf' }, [ h( 'span', { class: 'eclc-cf-l', text: f.label + ( f.required ? ' *' : '' ) } ), h( 'div', { class: 'eclc-cf-boxes' }, boxes ) ] );
			}
			var inp = h( 'input', { class: 'eclc-input', type: 'text', placeholder: f.label + ( f.required ? ' *' : '' ) } );
			inp.value = s.fields[ f.id ] || '';
			inp.addEventListener( 'input', function () { s.fields[ f.id ] = inp.value; } );
			return h( 'div', { class: 'eclc-cf' }, [ h( 'span', { class: 'eclc-cf-l', text: f.label + ( f.required ? ' *' : '' ) } ), inp ] );
		}

		function prodCard( p ) {
			var s = state( p );
			var cat = catById( p.categoryId );
			var q = s.qty || 0;
			var selected = q > 0;
			var asToggle = !! ( cat && cat.allowQuantity === false );
			var ag = activeGroup();
			var locked = C.categorySelection === 'single' && ! selected && ag !== null && ag !== ( p.categoryId != null ? p.categoryId : 'none' );
			var soldOut = productSoldOut( p );

			var price = h( 'p', { class: 'eclc-pprice' }, [ money( unit( p ) ) ] );
			if ( C.vatEnabled && C.vatRate ) { price.appendChild( h( 'span', { class: 'eclc-vat', text: ( C.vatInclusive ? 'inkl. ' : 'zzgl. ' ) + C.vatRate + '% MwSt' } ) ); }
			if ( mode === 'delivery' && p.deliveryFee ) { price.appendChild( h( 'span', { class: 'eclc-vat', text: '+ ' + money( p.deliveryFee ) + ' Liefergebühr' } ) ); }

			var ctrl;
			if ( soldOut ) {
				ctrl = h( 'div', { class: 'eclc-qty' }, [ h( 'span', { class: 'eclc-soldout', text: 'Ausverkauft' } ) ] );
			} else if ( asToggle ) {
				var battrs = { class: 'eclc-qbtn' + ( selected ? ' eclc-selected' : '' ), type: 'button', text: selected ? '✓' : ( cat && cat.singleProduct ? 'Wählen' : '+' ), onClick: function () { setQty( p, selected ? 0 : 1 ); } };
				if ( locked ) { battrs.disabled = 'disabled'; }
				ctrl = h( 'div', { class: 'eclc-qty' }, [ h( 'button', battrs ) ] );
			} else {
				var minus = h( 'button', { class: 'eclc-qbtn', type: 'button', text: '−', onClick: function () { setQty( p, q - 1 ); } } );
				var num = h( 'span', { class: 'eclc-qnum', text: String( q ) } );
				var plusAttrs = { class: 'eclc-qbtn', type: 'button', text: '+', onClick: function () { setQty( p, q + 1 ); } };
				if ( locked ) { plusAttrs.disabled = 'disabled'; }
				ctrl = h( 'div', { class: 'eclc-qty' }, [ minus, num, h( 'button', plusAttrs ) ] );
			}

			var config = [];
			( p.optionGroups || [] ).forEach( function ( g ) { if ( ( g.options || [] ).length ) { config.push( optionGroupEl( p, g ) ); } } );
			( p.customFields || [] ).forEach( function ( f ) { config.push( customFieldEl( p, f ) ); } );
			var v = currentVariant( p );
			if ( v && ! v.soldOut && v.remaining != null && v.remaining <= 5 ) { config.push( h( 'p', { class: 'eclc-stock', text: 'Nur noch ' + v.remaining + ' verfügbar' } ) ); }

			return h( 'div', { class: 'eclc-prod' + ( locked ? ' eclc-locked' : '' ) + ( soldOut ? ' eclc-prod-so' : '' ) }, [
				h( 'div', { class: 'eclc-prod-in' }, [
					p.imageUrl ? h( 'img', { class: 'eclc-img', src: p.imageUrl, alt: p.name } ) : h( 'div', { class: 'eclc-img-empty', text: '🛍' } ),
					h( 'div', { class: 'eclc-pinfo' }, [
						h( 'h3', { class: 'eclc-pname', text: p.name } ),
						p.description ? h( 'p', { class: 'eclc-pdesc', text: p.description } ) : null,
						price,
						locked ? h( 'p', { class: 'eclc-pdesc', text: 'Nur aus einer Kategorie wählbar' } ) : null
					] ),
					ctrl
				] ),
				config.length ? h( 'div', { class: 'eclc-config' }, config ) : null
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

		// --- Linke Spalte: Fulfillment-Umschalter + Produkte ---
		var title = ( C.productsTitle && String( C.productsTitle ).trim() ) ? C.productsTitle : 'Produkte';
		var left = h( 'div', {}, [ h( 'h2', { class: 'eclc-col-h', text: title } ) ] );

		if ( C.deliveryEnabled && C.pickupEnabled ) {
			function modeBtn( m, label ) {
				return h( 'button', { class: 'eclc-fmode' + ( mode === m ? ' eclc-fmode-on' : '' ), type: 'button', text: label, onClick: function () {
					if ( mode === m ) { return; }
					mode = m; renderFmode(); renderProducts(); updateSummary(); syncDeliveryUI();
				} } );
			}
			var fmodeWrap = h( 'div', { class: 'eclc-fmodes' } );
			function renderFmode() { fmodeWrap.innerHTML = ''; fmodeWrap.appendChild( modeBtn( 'pickup', 'Abholung' ) ); fmodeWrap.appendChild( modeBtn( 'delivery', 'Lieferung' ) ); }
			renderFmode();
			left.appendChild( fmodeWrap );
		}
		if ( C.categorySelection === 'single' && ( C.categories || [] ).length ) {
			left.appendChild( h( 'p', { class: 'eclc-cat-hint', text: 'Bitte nur Produkte aus einer Kategorie wählen.' } ) );
		}
		left.appendChild( productsWrap );
		renderProducts();

		// --- Kundenformular ---
		var emailI = h( 'input', { class: 'eclc-input', type: 'email', placeholder: 'E-Mail-Adresse *' } );
		var nameI = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Vor- und Nachname *' } );
		var companyI = h( 'input', { class: 'eclc-input', type: 'text', placeholder: 'Firma (optional)' } );
		var phoneI = h( 'input', { class: 'eclc-input', type: 'tel', placeholder: 'Telefonnummer' } );
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
		var sameRow = h( 'label', { class: 'eclc-check' }, [ sameCb, h( 'span', { text: 'Lieferadresse entspricht Rechnungsadresse' } ) ] );
		function syncDeliveryUI() {
			// Liefer-Sektion nur zeigen, wenn Liefermodus aktiv ist bzw. abweichende Adresse.
			var showDeliverySection = ( mode === 'delivery' );
			sameRow.style.display = showDeliverySection ? 'flex' : 'none';
			deliveryWrap.style.display = ( showDeliverySection && ! sameCb.checked ) ? 'block' : 'none';
		}
		sameCb.addEventListener( 'change', syncDeliveryUI );

		var errEl = h( 'div', { class: 'eclc-err' } ); errEl.style.display = 'none';
		var payWrap = h( 'div', {} );
		var btn = h( 'button', { class: 'eclc-btn', type: 'button', text: 'Weiter zur Zahlung' } );
		function fail( m ) { errEl.textContent = m; errEl.style.display = 'block'; }

		btn.addEventListener( 'click', function () {
			errEl.style.display = 'none';
			// Positionen + Options-/Feld-Validierung.
			var items = [];
			var chosen = ( C.products || [] ).filter( function ( p ) { return state( p ).qty > 0; } );
			if ( ! chosen.length ) { return fail( 'Bitte mindestens ein Produkt wählen.' ); }
			for ( var i = 0; i < chosen.length; i++ ) {
				var p = chosen[ i ], s = state( p );
				if ( productSoldOut( p ) ) { return fail( '„' + p.name + '" ist leider ausverkauft.' ); }
				// Pflicht-Infofelder pruefen.
				var fv = {};
				for ( var fi = 0; fi < ( p.customFields || [] ).length; fi++ ) {
					var f = p.customFields[ fi ];
					var val = s.fields[ f.id ];
					var empty = f.fieldType === 'checkbox' ? ! ( val && val.length ) : ! ( val && String( val ).trim() );
					if ( f.required && empty ) { return fail( 'Bitte „' + f.label + '" bei „' + p.name + '" ausfüllen.' ); }
					if ( ! empty ) { fv[ f.id ] = val; }
				}
				items.push( { productId: p.id, quantity: s.qty, optionIds: selectedOptions( p ).map( function ( o ) { return o.id; } ), fieldValues: fv } );
			}
			if ( ! emailI.value.trim() ) { return fail( 'Bitte E-Mail-Adresse eingeben.' ); }
			if ( ! nameI.value.trim() ) { return fail( 'Bitte Namen eingeben.' ); }
			if ( ! bStreet.value.trim() || ! bZip.value.trim() || ! bCity.value.trim() ) { return fail( 'Bitte vollständige Rechnungsadresse eingeben.' ); }
			if ( mode === 'delivery' && ! sameCb.checked && ( ! dStreet.value.trim() || ! dZip.value.trim() || ! dCity.value.trim() ) ) { return fail( 'Bitte vollständige Lieferadresse eingeben.' ); }

			btn.disabled = true; btn.textContent = 'Zahlung wird vorbereitet…';
			var billing = { street: bStreet.value, postalCode: bZip.value, city: bCity.value, country: bCountry.value };
			var delivery = ( mode === 'delivery' && ! sameCb.checked ) ? { street: dStreet.value, postalCode: dZip.value, city: dCity.value, country: dCountry.value } : billing;
			var payload = {
				items: items,
				fulfillmentMode: mode,
				customerEmail: emailI.value, customerName: nameI.value,
				customerCompany: companyI.value, customerPhone: phoneI.value,
				customerAddress: billing,
				deliveryAddress: delivery,
				sameAddress: sameCb.checked,
				newsletterOptIn: false
			};
			post( 'easycheckout_pub_pay', { slug: ecAccount.slug, payload: JSON.stringify( payload ) } ).then( function ( d ) {
				var body = d.body || {};
				if ( ( d.status && d.status >= 400 ) || body.error ) { throw new Error( body.error || ( 'Fehler ' + d.status ) ); }
				if ( ! body.clientSecret || ! body.publishableKey ) { throw new Error( 'Zahlung konnte nicht initialisiert werden.' ); }
				mountPayment( body, btn, errEl, payWrap );
			} ).catch( function ( e ) { fail( e.message ); btn.disabled = false; btn.textContent = 'Weiter zur Zahlung'; } );
		} );

		var cart = h( 'div', { class: 'eclc-cart' }, [
			h( 'h2', { class: 'eclc-col-h', text: 'Bestellung' } ),
			summaryBox,
			( C.vatEnabled && C.vatRate && C.vatInclusive ) ? h( 'p', { class: 'eclc-vatnote', text: 'inkl. ' + C.vatRate + '% MwSt' } ) : null,
			h( 'div', { class: 'eclc-divider' }, [ h( 'div', { class: 'eclc-total' }, [ h( 'span', { text: 'Total' } ), totalEl ] ) ] ),
			errEl,
			h( 'div', { class: 'eclc-sec' }, [ h( 'h3', { class: 'eclc-sec-h', text: 'Kontaktdaten' } ), h( 'div', { class: 'eclc-stack' }, [ emailI, nameI, companyI, phoneI ] ) ] ),
			h( 'div', { class: 'eclc-sec' }, [ h( 'h3', { class: 'eclc-sec-h', text: 'Rechnungsadresse' } ), h( 'div', { class: 'eclc-stack' }, [ bStreet, h( 'div', { class: 'eclc-3' }, [ bZip, bCity ] ), bCountry ] ) ] ),
			sameRow,
			deliveryWrap,
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
		syncDeliveryUI();
		updateSummary();
	}

	function mountPayment( body, btn, errEl, payWrap ) {
		root.querySelectorAll( 'input,select,button' ).forEach( function ( f ) { if ( f !== btn ) { f.disabled = true; } } );
		try {
			stripe = Stripe( body.publishableKey, body.stripeAccountId ? { stripeAccount: body.stripeAccountId } : undefined );
		} catch ( e ) { errEl.textContent = 'Zahlungssystem nicht verfügbar.'; errEl.style.display = 'block'; btn.disabled = false; btn.textContent = 'Weiter zur Zahlung'; return; }
		elements = stripe.elements( { clientSecret: body.clientSecret } );
		var pe = elements.create( 'payment' );
		payWrap.innerHTML = '';
		payWrap.appendChild( h( 'div', { class: 'eclc-sec' }, [ h( 'h3', { class: 'eclc-sec-h', text: 'Zahlung' } ), h( 'div', { id: 'eca-pe' } ) ] ) );
		pe.mount( '#eca-pe' );
		btn.disabled = false;
		btn.textContent = 'Jetzt bezahlen ' + money( body.total != null ? body.total : grandTotal() );
		var newBtn = btn.cloneNode( true );
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
		// Standard-Fulfillment: Lieferung nur, wenn Abholung nicht angeboten wird.
		mode = ( C.deliveryEnabled && C.pickupEnabled === false ) ? 'delivery' : 'pickup';
		if ( C.design && C.design.primaryColor ) { root.style.setProperty( '--ec-p', C.design.primaryColor ); }
		render();
	} ).catch( function ( e ) { errorView( e.message ); } );
} )();
