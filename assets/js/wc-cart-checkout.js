/* global ecWcCart, Stripe */
/**
 * EasyCheckout – cart-getriebene native WooCommerce-Kasse (Option C).
 * Bildet den LIVE-WooCommerce-Warenkorb ab, inkl. Mengen-Stepper (ändert den
 * Warenkorb via WC-AJAX, Total rechnet neu). Optik = local-checkout.css (eclc-*),
 * identisch zum nativen Konto-Checkout. Zahlung white-label über Stripe Elements.
 */
(function () {
    'use strict';

    var root = document.getElementById('ec-pay-checkout');
    if (!root || typeof ecWcCart === 'undefined') { return; }

    var stripe = null, elements = null, CART = null, CUR = 'CHF', busy = false;
    var form = { email: '', name: '', company: '', phone: '', street: '', postalCode: '', city: '', country: 'CH' };

    function h(tag, attrs, children) {
        var e = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            if (k === 'text') { e.textContent = attrs[k]; }
            else if (k === 'onClick') { e.addEventListener('click', attrs[k]); }
            else if (k === 'html') { e.innerHTML = attrs[k]; }
            else { e.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return e;
    }
    function money(n) { return CUR + ' ' + Number(n || 0).toFixed(2); }

    function post(action, fields) {
        var body = new URLSearchParams();
        body.append('action', 'easycheckout_' + action);
        body.append('nonce', ecWcCart.nonce);
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(ecWcCart.ajaxUrl, {
            method: 'POST', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString(),
        }).then(function (r) { return r.json(); })
          .then(function (j) { if (!j.success) { throw new Error((j.data && j.data.message) || 'Fehler'); } return j.data; });
    }

    function countrySel(val) {
        var opts = [['CH', 'Schweiz'], ['DE', 'Deutschland'], ['AT', 'Österreich'], ['LI', 'Liechtenstein'], ['FR', 'Frankreich'], ['IT', 'Italien']];
        var sel = h('select', { class: 'eclc-input' }, opts.map(function (o) { return h('option', { value: o[0] }, [o[1]]); }));
        sel.value = val || 'CH';
        return sel;
    }
    function powered() { return h('p', { class: 'eclc-powered', html: 'Sichere Zahlung über <b>easyCheckout</b>' }); }
    function loading(m) { root.innerHTML = ''; root.appendChild(h('div', { class: 'eclc-wrap' }, [h('div', { class: 'eclc-cart', style: 'max-width:520px;margin:40px auto;text-align:center' }, [h('p', { class: 'eclc-empty', text: m || 'Lädt…' })])])); }
    function errView(m) { root.innerHTML = ''; root.appendChild(h('div', { class: 'eclc-wrap' }, [h('div', { class: 'eclc-cart', style: 'max-width:520px;margin:40px auto' }, [h('div', { class: 'eclc-err', text: m }), h('p', { style: 'text-align:center;margin-top:12px' }, [h('a', { href: ecWcCart.cartUrl, text: 'Zurück zum Warenkorb' })])])])); }

    function setQty(key, qty) {
        if (busy) { return; }
        busy = true;
        post('wc_cart_qty', { key: key, qty: qty }).then(function (d) {
            CART = d; busy = false;
            if (d.empty) { errView('Der Warenkorb ist leer.'); return; }
            render();
        }).catch(function () { busy = false; });
    }

    function prodCard(it) {
        var price = h('p', { class: 'eclc-pprice' }, [money(it.unit_price)]);
        if (it.vat_rate) { price.appendChild(h('span', { class: 'eclc-vat', text: 'inkl. ' + it.vat_rate + '% MwSt' })); }
        var minus = h('button', { class: 'eclc-qbtn', type: 'button', text: '−', onClick: function () { setQty(it.key, it.quantity - 1); } });
        var num = h('span', { class: 'eclc-qnum', text: String(it.quantity) });
        var plus = h('button', { class: 'eclc-qbtn', type: 'button', text: '+', onClick: function () { setQty(it.key, it.quantity + 1); } });
        return h('div', { class: 'eclc-prod' }, [
            h('div', { class: 'eclc-prod-in' }, [
                it.image_url ? h('img', { class: 'eclc-img', src: it.image_url, alt: it.name }) : h('div', { class: 'eclc-img-empty', text: '🛍' }),
                h('div', { class: 'eclc-pinfo' }, [
                    h('h3', { class: 'eclc-pname', text: it.name }),
                    it.description ? h('p', { class: 'eclc-pdesc', text: it.description }) : null,
                    price,
                ]),
                h('div', { class: 'eclc-qty' }, [minus, num, plus]),
            ]),
        ]);
    }

    function render() {
        root.innerHTML = '';
        root.style.setProperty('--ec-p', (window.ecWcCart && ecWcCart.brandColor) ? ecWcCart.brandColor : '#4F46E5');
        var items = CART.items || [];

        var left = h('div', {}, [h('h2', { class: 'eclc-col-h', text: 'Produkte' })]);
        var pw = h('div', {});
        items.forEach(function (it) { pw.appendChild(prodCard(it)); });
        left.appendChild(pw);

        var summaryBox = h('div', {});
        items.forEach(function (it) {
            summaryBox.appendChild(h('div', { class: 'eclc-line' }, [
                h('span', { class: 'eclc-lname' }, [h('span', { text: it.quantity + '× ' + it.name })]),
                h('span', { class: 'eclc-lval', text: money(it.total) }),
            ]));
        });
        var totalEl = h('span', { text: money(CART.total) });

        var emailI = h('input', { class: 'eclc-input', type: 'email', placeholder: 'E-Mail-Adresse *' }); emailI.value = form.email;
        var nameI = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Vor- und Nachname *' }); nameI.value = form.name;
        var companyI = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Firma (optional)' }); companyI.value = form.company;
        var phoneI = h('input', { class: 'eclc-input', type: 'tel', placeholder: 'Telefonnummer' }); phoneI.value = form.phone;
        var bStreet = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Strasse und Hausnummer *' }); bStreet.value = form.street;
        var bZip = h('input', { class: 'eclc-input', type: 'text', placeholder: 'PLZ *' }); bZip.value = form.postalCode;
        var bCity = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Ort *' }); bCity.value = form.city;
        var bCountry = countrySel(form.country);
        [[emailI, 'email'], [nameI, 'name'], [companyI, 'company'], [phoneI, 'phone'], [bStreet, 'street'], [bZip, 'postalCode'], [bCity, 'city']].forEach(function (p) {
            p[0].addEventListener('input', function () { form[p[1]] = p[0].value; });
        });
        bCountry.addEventListener('change', function () { form.country = bCountry.value; });

        var errEl = h('div', { class: 'eclc-err' }); errEl.style.display = 'none';
        var payWrap = h('div', {});
        var btn = h('button', { class: 'eclc-btn', type: 'button', text: 'Weiter zur Zahlung' });
        function fail(m) { errEl.textContent = m; errEl.style.display = 'block'; btn.disabled = false; btn.textContent = 'Weiter zur Zahlung'; }

        btn.addEventListener('click', function () {
            errEl.style.display = 'none';
            if (!emailI.value.trim()) { return fail('Bitte E-Mail-Adresse eingeben.'); }
            if (!nameI.value.trim()) { return fail('Bitte Namen eingeben.'); }
            if (!bStreet.value.trim() || !bZip.value.trim() || !bCity.value.trim()) { return fail('Bitte vollständige Rechnungsadresse eingeben.'); }
            btn.disabled = true; btn.textContent = 'Zahlung wird vorbereitet…';
            var customer = { email: emailI.value.trim(), name: nameI.value.trim(), company: companyI.value.trim(), phone: phoneI.value.trim(), address: { street: bStreet.value.trim(), postalCode: bZip.value.trim(), city: bCity.value.trim(), country: bCountry.value } };
            var TOKEN = null, SUCCESS = null;
            post('wc_cart_pay', { customer: JSON.stringify(customer) }).then(function (d) {
                TOKEN = d.token; SUCCESS = d.successUrl;
                return post('pay_intent', { token: TOKEN, payload: JSON.stringify({}) });
            }).then(function (d) {
                var b = d.body || {};
                if ((d.status && d.status >= 400) || b.error) { throw new Error(b.error || 'Zahlung konnte nicht initialisiert werden.'); }
                if (!b.clientSecret || !b.publishableKey) { throw new Error('Zahlung konnte nicht initialisiert werden.'); }
                mountPayment(b, btn, errEl, payWrap, SUCCESS);
            }).catch(function (e) { fail(e.message); });
        });

        var cart = h('div', { class: 'eclc-cart' }, [
            h('h2', { class: 'eclc-col-h', text: 'Bestellung' }),
            summaryBox,
            (Number(CART.vat) > 0) ? h('p', { class: 'eclc-vatnote', text: 'inkl. MwSt ' + money(CART.vat) }) : null,
            h('div', { class: 'eclc-divider' }, [h('div', { class: 'eclc-total' }, [h('span', { text: 'Total' }), totalEl])]),
            errEl,
            h('div', { class: 'eclc-sec' }, [h('h3', { class: 'eclc-sec-h', text: 'Kontaktdaten' }), h('div', { class: 'eclc-stack' }, [emailI, nameI, companyI, phoneI])]),
            h('div', { class: 'eclc-sec' }, [h('h3', { class: 'eclc-sec-h', text: 'Rechnungsadresse' }), h('div', { class: 'eclc-stack' }, [bStreet, h('div', { class: 'eclc-3' }, [bZip, bCity]), bCountry])]),
            payWrap,
            h('div', { style: 'margin-top:18px;' }, [btn]),
        ]);

        var back = h('a', {
            href: ecWcCart.cartUrl,
            style: 'display:inline-block;margin-bottom:12px;color:#64748b;text-decoration:none;font-size:14px;',
            text: '← Zurück zum Warenkorb',
        });
        var wrap = h('div', { class: 'eclc-wrap' }, [
            back,
            h('div', { class: 'eclc-header' }, [
                ecWcCart.logo ? h('img', { class: 'eclc-logo', src: ecWcCart.logo, alt: ecWcCart.company || '' }) : null,
                h('h1', { class: 'eclc-title', text: ecWcCart.company || 'Checkout' }),
            ]),
            h('div', { class: 'eclc-grid' }, [left, cart]),
            powered(),
        ]);
        root.appendChild(wrap);
    }

    function mountPayment(b, btn, errEl, payWrap, successUrl) {
        root.querySelectorAll('input,select,.eclc-qbtn').forEach(function (f) { f.disabled = true; });
        try {
            stripe = Stripe(b.publishableKey, b.stripeAccountId ? { stripeAccount: b.stripeAccountId } : undefined);
        } catch (e) { errEl.textContent = 'Zahlungssystem nicht verfügbar.'; errEl.style.display = 'block'; btn.disabled = false; return; }
        elements = stripe.elements({ clientSecret: b.clientSecret, locale: 'de' });
        var pe = elements.create('payment', { layout: { type: 'accordion', defaultCollapsed: false, radios: true, spacedAccordionItems: true }, wallets: { applePay: 'auto', googlePay: 'auto' } });
        payWrap.innerHTML = '';
        payWrap.appendChild(h('div', { class: 'eclc-sec' }, [h('h3', { class: 'eclc-sec-h', text: 'Zahlung' }), h('div', { id: 'ec-pe' })]));
        pe.mount('#ec-pe');
        btn.disabled = false; btn.textContent = 'Jetzt bezahlen · ' + money(CART.total);
        var nb = btn.cloneNode(true); btn.parentNode.replaceChild(nb, btn);
        nb.addEventListener('click', function () {
            errEl.style.display = 'none'; nb.disabled = true; nb.textContent = 'Zahlung läuft…';
            stripe.confirmPayment({ elements: elements, confirmParams: { return_url: successUrl || window.location.href }, redirect: 'if_required' })
                .then(function (res) {
                    if (res.error) { errEl.textContent = res.error.message || 'Zahlung fehlgeschlagen.'; errEl.style.display = 'block'; nb.disabled = false; nb.textContent = 'Jetzt bezahlen · ' + money(CART.total); return; }
                    window.location.href = successUrl || window.location.origin;
                });
        });
    }

    loading('Checkout wird geladen…');
    post('wc_cart_data', {}).then(function (d) {
        CART = d; CUR = d.currency || 'CHF';
        if (d.empty) { errView('Der Warenkorb ist leer.'); return; }
        render();
    }).catch(function (e) { errView(e.message); });
})();
