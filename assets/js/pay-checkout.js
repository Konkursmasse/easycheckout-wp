/* global ecPay, Stripe */
/**
 * EasyCheckout – nativer WooCommerce-Warenkorb-Checkout (KEIN iFrame).
 * Rendert die Positionen/MwSt/Adresse aus /api/pay/{token} direkt im DOM auf der
 * Händler-Domain und wickelt die Zahlung white-label über Stripe Elements ab
 * (Server-Proxies -> kein CORS). Optik gespiegelt von app/pay/[id]/page.js.
 */
(function () {
    'use strict';

    var root = document.getElementById('ec-pay-checkout');
    if (!root || typeof ecPay === 'undefined') { return; }

    var stripe = null, elements = null, O = null, CUR = 'CHF';
    var form = { email: '', name: '', street: '', postalCode: '', city: '', country: 'CH' };

    var C = {
        primary: '#0891b2',
        bg: '#f8fafc',
        card: 'background:#fff;border-radius:16px;box-shadow:0 4px 24px rgba(0,0,0,0.08);overflow:hidden;',
        input: 'width:100%;padding:11px 14px;border:1px solid #cbd5e1;border-radius:8px;font-size:14px;box-sizing:border-box;',
    };

    function h(tag, attrs, children) {
        var e = document.createElement(tag);
        attrs = attrs || {};
        Object.keys(attrs).forEach(function (k) {
            if (k === 'text') { e.textContent = attrs[k]; }
            else if (k === 'html') { e.innerHTML = attrs[k]; }
            else { e.setAttribute(k, attrs[k]); }
        });
        (children || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
        return e;
    }
    function money(n) { return CUR + ' ' + Number(n || 0).toLocaleString('de-CH', { minimumFractionDigits: 2 }); }

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

    function shell(inner) {
        root.innerHTML = '';
        var wrap = h('div', { style: 'min-height:100vh;background:' + C.bg + ';padding:40px 16px;' }, [
            h('div', { style: 'max-width:480px;margin:0 auto;' }, inner),
        ]);
        root.appendChild(wrap);
    }
    function loading(msg) {
        shell([h('div', { style: C.card + 'padding:40px;text-align:center;color:#64748b;' }, [msg || 'Lädt…'])]);
    }
    function errView(msg) {
        shell([h('div', { style: C.card + 'padding:40px;text-align:center;' }, [
            h('div', { style: 'font-size:40px;margin-bottom:12px;', text: '✗' }),
            h('p', { style: 'color:#64748b;margin:0;', text: msg }),
        ])]);
    }

    function inp(ph, key, type) {
        var i = h('input', { style: C.input, type: type || 'text', placeholder: ph });
        i.value = form[key] || '';
        i.addEventListener('input', function () { form[key] = i.value; });
        return i;
    }

    function summaryNode() {
        var lines = (O.lineItems || []).map(function (it) {
            var img = it.imageUrl
                ? h('img', { src: it.imageUrl, alt: '', style: 'width:44px;height:44px;object-fit:cover;border-radius:8px;flex-shrink:0;border:1px solid #f1f5f9;' })
                : h('div', { style: 'width:44px;height:44px;border-radius:8px;background:#f1f5f9;flex-shrink:0;' });
            return h('div', { style: 'display:flex;align-items:center;gap:12px;' }, [
                img,
                h('div', { style: 'flex:1;min-width:0;' }, [
                    h('p', { style: 'margin:0;font-size:14px;color:#0f172a;font-weight:500;', text: it.name }),
                    it.meta ? h('p', { style: 'margin:2px 0 0;font-size:12px;color:#94a3b8;', text: it.meta }) : null,
                    h('p', { style: 'margin:2px 0 0;font-size:12px;color:#94a3b8;', text: it.quantity + ' × ' + money(it.price) }),
                ]),
                h('p', { style: 'margin:0;font-size:14px;color:#0f172a;font-weight:600;white-space:nowrap;', text: money(it.total) }),
            ]);
        });
        var rows = [];
        if (O.fulfillmentMode) {
            rows.push(h('div', { style: 'display:flex;justify-content:space-between;font-size:13px;color:#64748b;' }, [
                h('span', { text: O.fulfillmentMode === 'delivery' ? 'Lieferung' : 'Abholung' }),
                h('span', { text: O.deliveryFeeTotal ? money(O.deliveryFeeTotal) : 'inbegriffen' }),
            ]));
        }
        if (Number(O.vatAmount) > 0) {
            rows.push(h('div', { style: 'display:flex;justify-content:space-between;font-size:13px;color:#64748b;' }, [
                h('span', { text: 'inkl. MwSt.' }), h('span', { text: money(O.vatAmount) }),
            ]));
        }
        rows.push(h('div', { style: 'display:flex;justify-content:space-between;align-items:baseline;margin-top:6px;' }, [
            h('span', { style: 'font-size:15px;font-weight:700;color:#0f172a;', text: 'Total' }),
            h('span', { style: 'font-size:22px;font-weight:700;color:#0f172a;', text: money(O.total) }),
        ]));
        return h('div', { style: 'padding:24px 28px;border-bottom:1px solid #f1f5f9;' }, [
            h('p', { style: 'font-size:13px;font-weight:600;color:#334155;margin:0 0 14px;', text: 'Ihre Bestellung' }),
            h('div', { style: 'display:flex;flex-direction:column;gap:12px;' }, lines),
            h('div', { style: 'margin-top:16px;padding-top:14px;border-top:1px solid #f1f5f9;display:flex;flex-direction:column;gap:6px;' }, rows),
            h('p', { style: 'color:#94a3b8;font-size:12px;margin:10px 0 0;', text: 'Bestellung ' + O.orderNumber }),
        ]);
    }

    function render() {
        var header = h('div', { style: 'text-align:center;margin-bottom:32px;' }, [
            (O.merchant && O.merchant.logoUrl) ? h('img', { src: O.merchant.logoUrl, alt: '', style: 'height:48px;margin-bottom:12px;' }) : null,
            (O.merchant && O.merchant.companyName) ? h('p', { style: 'color:#64748b;font-size:14px;margin:0;', text: O.merchant.companyName }) : null,
        ]);

        var errEl = h('div', { style: 'color:#dc2626;background:#fef2f2;padding:12px;border-radius:8px;font-size:14px;display:none;margin-bottom:12px;' });
        var payWrap = h('div', {});
        var btn = h('button', { type: 'button', style: 'width:100%;margin-top:18px;padding:14px 16px;background:' + C.primary + ';color:#fff;border:0;border-radius:10px;font-size:16px;font-weight:600;cursor:pointer;' }, ['Zur Zahlung · ' + money(O.total)]);

        var body = [errEl];
        if (O.needsCustomerInfo) {
            form.email = form.email || O.customerEmail || '';
            body.push(h('p', { style: 'font-size:13px;font-weight:600;color:#334155;margin:0 0 12px;', text: 'Ihre Angaben' }));
            body.push(h('div', { style: 'display:flex;flex-direction:column;gap:10px;' }, [
                inp('E-Mail-Adresse *', 'email', 'email'), inp('Vor- und Nachname *', 'name'), inp('Strasse und Hausnummer *', 'street'),
                h('div', { style: 'display:grid;grid-template-columns:1fr 2fr;gap:10px;' }, [inp('PLZ *', 'postalCode'), inp('Ort *', 'city')]),
            ]));
        }
        body.push(payWrap);
        body.push(btn);

        btn.addEventListener('click', function () { startPayment(btn, errEl, payWrap); });

        shell([
            header,
            h('div', { style: C.card }, [
                summaryNode(),
                h('div', { style: 'padding:32px;' }, body),
            ]),
            h('p', { style: 'text-align:center;margin-top:24px;color:#94a3b8;font-size:12px;', html: 'Sichere Zahlung über <b>easyCheckout</b>' }),
        ]);
    }

    function startPayment(btn, errEl, payWrap) {
        errEl.style.display = 'none'; btn.disabled = true; btn.textContent = 'Bitte warten…';
        var chain = Promise.resolve();
        if (O.needsCustomerInfo) {
            if (!form.email || !form.name || !form.street || !form.postalCode || !form.city) {
                errEl.textContent = 'Bitte alle Pflichtfelder ausfüllen.'; errEl.style.display = 'block';
                btn.disabled = false; btn.textContent = 'Zur Zahlung · ' + money(O.total); return;
            }
            chain = post('pay_patch', { token: ecPay.token, payload: JSON.stringify({
                email: form.email, name: form.name,
                address: { street: form.street, postalCode: form.postalCode, city: form.city, country: form.country },
            }) }).then(function (d) {
                if ((d.status && d.status >= 400) || (d.body && d.body.error)) { throw new Error((d.body && d.body.error) || 'Adresse konnte nicht gespeichert werden.'); }
            });
        }
        chain.then(function () { return post('pay_intent', { token: ecPay.token, payload: JSON.stringify({}) }); })
            .then(function (d) {
                var b = d.body || {};
                if ((d.status && d.status >= 400) || b.error) { throw new Error(b.error || 'Zahlung konnte nicht initialisiert werden.'); }
                if (!b.clientSecret || !b.publishableKey) { throw new Error('Zahlung konnte nicht initialisiert werden.'); }
                mountPayment(b, btn, errEl, payWrap);
            }).catch(function (e) {
                errEl.textContent = e.message; errEl.style.display = 'block';
                btn.disabled = false; btn.textContent = 'Zur Zahlung · ' + money(O.total);
            });
    }

    function mountPayment(b, btn, errEl, payWrap) {
        root.querySelectorAll('input').forEach(function (f) { f.disabled = true; });
        try {
            stripe = Stripe(b.publishableKey, b.stripeAccountId ? { stripeAccount: b.stripeAccountId } : undefined);
        } catch (e) {
            errEl.textContent = 'Zahlungssystem nicht verfügbar.'; errEl.style.display = 'block';
            btn.disabled = false; return;
        }
        elements = stripe.elements({ clientSecret: b.clientSecret, locale: 'de', appearance: { theme: 'stripe', variables: { colorPrimary: C.primary, borderRadius: '8px' } } });
        var pe = elements.create('payment', { wallets: { applePay: 'auto', googlePay: 'auto' } });
        payWrap.innerHTML = '';
        payWrap.appendChild(h('div', { id: 'ec-pe' }));
        pe.mount('#ec-pe');
        btn.disabled = false; btn.textContent = 'Jetzt bezahlen · ' + money(O.total);
        var nb = btn.cloneNode(true); btn.parentNode.replaceChild(nb, btn);
        nb.addEventListener('click', function () {
            errEl.style.display = 'none'; nb.disabled = true; nb.textContent = 'Verarbeitung…';
            var ret = ecPay.successUrl || window.location.href;
            stripe.confirmPayment({ elements: elements, confirmParams: { return_url: ret }, redirect: 'if_required' })
                .then(function (res) {
                    if (res.error) {
                        errEl.textContent = res.error.message || 'Zahlung fehlgeschlagen.'; errEl.style.display = 'block';
                        nb.disabled = false; nb.textContent = 'Jetzt bezahlen · ' + money(O.total); return;
                    }
                    window.location.href = ecPay.successUrl || window.location.origin;
                });
        });
    }

    loading('Checkout wird geladen…');
    post('pay_get', { token: ecPay.token }).then(function (d) {
        var b = d.body || {};
        if ((d.status && d.status >= 400) || !b.order) { throw new Error((b && b.error) || 'Bestellung nicht gefunden.'); }
        if (b.alreadyPaid) { window.location.href = ecPay.successUrl || window.location.origin; return; }
        O = b.order; O.needsCustomerInfo = b.needsCustomerInfo; O.merchant = b.merchant; CUR = O.currency || 'CHF';
        render();
    }).catch(function (e) { errView(e.message); });
})();
