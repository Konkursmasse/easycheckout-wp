/* global ecPay, Stripe */
/**
 * EasyCheckout – nativer WooCommerce-Warenkorb-Checkout (KEIN iFrame).
 * Optik + Felder identisch zum nativen Konto-Checkout (account-checkout.js /
 * local-checkout.css, eclc-*): zweispaltig, Produkte links (fest aus dem
 * WooCommerce-Warenkorb, read-only), rechts Bestellübersicht + Kontaktdaten +
 * Rechnungs-/Lieferadresse + Stripe Elements (white-label). Daten via
 * Server-Proxies aus /api/pay/{token} (kein CORS).
 */
(function () {
    'use strict';

    var root = document.getElementById('ec-pay-checkout');
    if (!root || typeof ecPay === 'undefined') { return; }

    var stripe = null, elements = null, O = null, CUR = 'CHF', mode = 'pickup';

    function h(tag, attrs, children) {
        var e = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            if (k === 'text') { e.textContent = attrs[k]; }
            else if (k === 'onClick') { e.addEventListener('click', attrs[k]); }
            else if (k === 'onChange') { e.addEventListener('change', attrs[k]); }
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
        body.append('nonce', ecPay.nonce);
        Object.keys(fields || {}).forEach(function (k) { body.append(k, fields[k]); });
        return fetch(ecPay.ajaxUrl, {
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
    function errView(m) { root.innerHTML = ''; root.appendChild(h('div', { class: 'eclc-wrap' }, [h('div', { class: 'eclc-cart', style: 'max-width:520px;margin:40px auto' }, [h('div', { class: 'eclc-err', text: m })])])); }

    // Read-only Produktkarte aus einer Warenkorb-Position (keine Stepper).
    function prodCard(it) {
        var price = h('p', { class: 'eclc-pprice' }, [money(it.price)]);
        if (it.vatRate) { price.appendChild(h('span', { class: 'eclc-vat', text: 'inkl. ' + it.vatRate + '% MwSt' })); }
        return h('div', { class: 'eclc-prod' }, [
            h('div', { class: 'eclc-prod-in' }, [
                it.imageUrl ? h('img', { class: 'eclc-img', src: it.imageUrl, alt: it.name }) : h('div', { class: 'eclc-img-empty', text: '🛍' }),
                h('div', { class: 'eclc-pinfo' }, [
                    h('h3', { class: 'eclc-pname', text: it.name }),
                    it.meta ? h('p', { class: 'eclc-pdesc', text: it.meta }) : null,
                    price,
                ]),
                h('div', { class: 'eclc-qty' }, [h('span', { class: 'eclc-qnum', text: it.quantity + '×' })]),
            ]),
        ]);
    }

    function render() {
        root.innerHTML = '';
        // Primärfarbe für die eclc-*-Optik (Button/Akzente). Ohne diese Variable
        // bleibt der Button ungefärbt (unsichtbar).
        root.style.setProperty('--ec-p', (O.design && O.design.primaryColor) ? O.design.primaryColor : ((window.ecPay && ecPay.brandColor) ? ecPay.brandColor : '#4F46E5'));
        var items = O.lineItems || [];

        // --- Linke Spalte: Produkte (fest) ---
        var left = h('div', {}, [h('h2', { class: 'eclc-col-h', text: 'Produkte' })]);
        var pWrap = h('div', {});
        items.forEach(function (it) { pWrap.appendChild(prodCard(it)); });
        left.appendChild(pWrap);

        // --- Rechte Spalte: Bestellung + Formular ---
        var summaryBox = h('div', {});
        items.forEach(function (it) {
            summaryBox.appendChild(h('div', { class: 'eclc-line' }, [
                h('span', { class: 'eclc-lname' }, [h('span', { text: it.quantity + '× ' + it.name }), it.meta ? h('span', { class: 'eclc-lsub', text: it.meta }) : null]),
                h('span', { class: 'eclc-lval', text: money(it.total) }),
            ]));
        });
        if (O.fulfillmentMode) {
            summaryBox.appendChild(h('div', { class: 'eclc-line eclc-line-fee' }, [
                h('span', { class: 'eclc-lname', text: O.fulfillmentMode === 'delivery' ? 'Lieferung' : 'Abholung' }),
                h('span', { class: 'eclc-lval', text: O.deliveryFeeTotal ? money(O.deliveryFeeTotal) : 'inbegriffen' }),
            ]));
        }
        var totalEl = h('span', { text: money(O.total) });

        var emailI = h('input', { class: 'eclc-input', type: 'email', placeholder: 'E-Mail-Adresse *' });
        emailI.value = O.customerEmail || '';
        var nameI = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Vor- und Nachname *' });
        var companyI = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Firma (optional)' });
        var phoneI = h('input', { class: 'eclc-input', type: 'tel', placeholder: 'Telefonnummer' });
        var bStreet = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Strasse und Hausnummer *' });
        var bZip = h('input', { class: 'eclc-input', type: 'text', placeholder: 'PLZ *' });
        var bCity = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Ort *' });
        var bCountry = countrySel('CH');
        var sameCb = h('input', { type: 'checkbox' }); sameCb.checked = true;
        var dStreet = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Strasse und Hausnummer *' });
        var dZip = h('input', { class: 'eclc-input', type: 'text', placeholder: 'PLZ *' });
        var dCity = h('input', { class: 'eclc-input', type: 'text', placeholder: 'Ort *' });
        var dCountry = countrySel('CH');
        var deliveryWrap = h('div', { class: 'eclc-sec' }, [
            h('h3', { class: 'eclc-sec-h', text: 'Lieferadresse' }),
            h('div', { class: 'eclc-stack' }, [dStreet, h('div', { class: 'eclc-3' }, [dZip, dCity]), dCountry]),
        ]);
        var sameRow = h('label', { class: 'eclc-check' }, [sameCb, h('span', { text: 'Lieferadresse entspricht Rechnungsadresse' })]);
        function syncDeliveryUI() {
            var show = (O.fulfillmentMode === 'delivery');
            sameRow.style.display = show ? 'flex' : 'none';
            deliveryWrap.style.display = (show && !sameCb.checked) ? 'block' : 'none';
        }
        sameCb.addEventListener('change', syncDeliveryUI);

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
            var patch = {
                email: emailI.value.trim(), name: nameI.value.trim(),
                company: companyI.value.trim(), phone: phoneI.value.trim(),
                address: { street: bStreet.value.trim(), postalCode: bZip.value.trim(), city: bCity.value.trim(), country: bCountry.value },
            };
            post('pay_patch', { token: ecPay.token, payload: JSON.stringify(patch) }).then(function (d) {
                if ((d.status && d.status >= 400) || (d.body && d.body.error)) { throw new Error((d.body && d.body.error) || 'Adresse konnte nicht gespeichert werden.'); }
                return post('pay_intent', { token: ecPay.token, payload: JSON.stringify({}) });
            }).then(function (d) {
                var b = d.body || {};
                if ((d.status && d.status >= 400) || b.error) { throw new Error(b.error || 'Zahlung konnte nicht initialisiert werden.'); }
                if (!b.clientSecret || !b.publishableKey) { throw new Error('Zahlung konnte nicht initialisiert werden.'); }
                mountPayment(b, btn, errEl, payWrap);
            }).catch(function (e) { fail(e.message); });
        });

        var cart = h('div', { class: 'eclc-cart' }, [
            h('h2', { class: 'eclc-col-h', text: 'Bestellung' }),
            summaryBox,
            (Number(O.vatAmount) > 0) ? h('p', { class: 'eclc-vatnote', text: 'inkl. MwSt ' + money(O.vatAmount) }) : null,
            h('div', { class: 'eclc-divider' }, [h('div', { class: 'eclc-total' }, [h('span', { text: 'Total' }), totalEl])]),
            errEl,
            h('div', { class: 'eclc-sec' }, [h('h3', { class: 'eclc-sec-h', text: 'Kontaktdaten' }), h('div', { class: 'eclc-stack' }, [emailI, nameI, companyI, phoneI])]),
            h('div', { class: 'eclc-sec' }, [h('h3', { class: 'eclc-sec-h', text: 'Rechnungsadresse' }), h('div', { class: 'eclc-stack' }, [bStreet, h('div', { class: 'eclc-3' }, [bZip, bCity]), bCountry])]),
            sameRow,
            deliveryWrap,
            payWrap,
            h('div', { style: 'margin-top:18px;' }, [btn]),
        ]);

        var wrap = h('div', { class: 'eclc-wrap' }, [
            h('div', { class: 'eclc-header' }, [
                (O.merchant && O.merchant.logoUrl) ? h('img', { class: 'eclc-logo', src: O.merchant.logoUrl, alt: '' }) : null,
                h('h1', { class: 'eclc-title', text: (O.merchant && O.merchant.companyName) || 'Checkout' }),
            ]),
            h('div', { class: 'eclc-grid' }, [left, cart]),
            powered(),
        ]);
        root.appendChild(wrap);
        syncDeliveryUI();
    }

    function mountPayment(b, btn, errEl, payWrap) {
        root.querySelectorAll('input,select').forEach(function (f) { f.disabled = true; });
        try {
            stripe = Stripe(b.publishableKey, b.stripeAccountId ? { stripeAccount: b.stripeAccountId } : undefined);
        } catch (e) { fail2(errEl, 'Zahlungssystem nicht verfügbar.'); btn.disabled = false; return; }
        elements = stripe.elements({ clientSecret: b.clientSecret, locale: 'de' });
        var pe = elements.create('payment', { layout: { type: 'accordion', defaultCollapsed: false, radios: true, spacedAccordionItems: true }, wallets: { applePay: 'auto', googlePay: 'auto' } });
        payWrap.innerHTML = '';
        payWrap.appendChild(h('div', { class: 'eclc-sec' }, [h('h3', { class: 'eclc-sec-h', text: 'Zahlung' }), h('div', { id: 'ec-pe' })]));
        pe.mount('#ec-pe');
        btn.disabled = false; btn.textContent = 'Jetzt bezahlen · ' + money(O.total);
        var nb = btn.cloneNode(true); btn.parentNode.replaceChild(nb, btn);
        nb.addEventListener('click', function () {
            errEl.style.display = 'none'; nb.disabled = true; nb.textContent = 'Zahlung läuft…';
            stripe.confirmPayment({ elements: elements, confirmParams: { return_url: ecPay.successUrl || window.location.href }, redirect: 'if_required' })
                .then(function (res) {
                    if (res.error) { fail2(errEl, res.error.message || 'Zahlung fehlgeschlagen.'); nb.disabled = false; nb.textContent = 'Jetzt bezahlen · ' + money(O.total); return; }
                    window.location.href = ecPay.successUrl || window.location.origin;
                });
        });
    }
    function fail2(errEl, m) { errEl.textContent = m; errEl.style.display = 'block'; }

    loading('Checkout wird geladen…');
    post('pay_get', { token: ecPay.token }).then(function (d) {
        var b = d.body || {};
        if ((d.status && d.status >= 400) || !b.order) { throw new Error((b && b.error) || 'Bestellung nicht gefunden.'); }
        if (b.alreadyPaid) { window.location.href = ecPay.successUrl || window.location.origin; return; }
        O = b.order; O.needsCustomerInfo = b.needsCustomerInfo; O.merchant = b.merchant; CUR = O.currency || 'CHF';
        mode = O.fulfillmentMode || 'pickup';
        render();
    }).catch(function (e) { errView(e.message); });
})();
