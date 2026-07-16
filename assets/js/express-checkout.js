/**
 * EasyCheckout Express-Checkout (Warenkorb).
 *
 * Beim Klick auf den Express-Button wird serverseitig aus dem aktuellen Warenkorb
 * eine WooCommerce-Bestellung erzeugt und eine EasyCheckout-Zahlungssitzung
 * angelegt. Anschliessend Weiterleitung zum schnellen Bezahlformular.
 */
(function ($) {
    'use strict';

    function setLoading($btn, loading) {
        if (loading) {
            $btn.data('label', $btn.text());
            $btn.prop('disabled', true).text(easycheckoutExpress.i18n.processing);
        } else {
            $btn.prop('disabled', false).text($btn.data('label'));
        }
    }

    $(document).on('click', '#easycheckout-express-btn', function (e) {
        e.preventDefault();
        var $btn = $(this);

        if ($btn.prop('disabled')) {
            return;
        }
        setLoading($btn, true);

        $.ajax({
            url: easycheckoutExpress.ajaxUrl,
            method: 'POST',
            dataType: 'json',
            data: {
                action: 'easycheckout_express',
                nonce: easycheckoutExpress.nonce
            }
        }).done(function (res) {
            if (res && res.success && res.data && res.data.redirect) {
                window.location.href = res.data.redirect;
                return;
            }
            var msg = (res && res.data && res.data.message) ? res.data.message : easycheckoutExpress.i18n.error;
            window.alert(msg);
            setLoading($btn, false);
        }).fail(function () {
            window.alert(easycheckoutExpress.i18n.error);
            setLoading($btn, false);
        });
    });
})(jQuery);
