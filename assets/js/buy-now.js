/**
 * EasyCheckout – „Sofort kaufen" auf der Produktseite.
 * Erstellt per AJAX eine Einzelprodukt-Bestellung und leitet zur EasyCheckout-Kasse weiter.
 */
(function ($) {
    'use strict';

    $(function () {
        var $btn = $('#easycheckout-buynow-btn');
        if (!$btn.length) {
            return;
        }

        $btn.on('click', function (e) {
            e.preventDefault();

            var $form = $btn.closest('form.cart');
            var productId = $btn.data('product-id');
            var variationId = 0;
            var quantity = 1;
            var variation = {};

            if ($form.length) {
                var $var = $form.find('input[name="variation_id"]');
                if ($var.length) {
                    variationId = parseInt($var.val(), 10) || 0;
                }
                var $qty = $form.find('input.qty, input[name="quantity"]');
                if ($qty.length) {
                    quantity = parseInt($qty.val(), 10) || 1;
                }
                $form.find('[name^="attribute_"]').each(function () {
                    variation[this.name] = $(this).val();
                });

                // Variables Produkt ohne gewählte Variante -> abbrechen mit Hinweis.
                if ($form.hasClass('variations_form') && !variationId) {
                    alert(easycheckoutBuyNow.i18n.error);
                    return;
                }
            }

            if (!productId) {
                return;
            }

            var original = $btn.text();
            $btn.prop('disabled', true).text(easycheckoutBuyNow.i18n.processing);

            $.post(easycheckoutBuyNow.ajaxUrl, {
                action: 'easycheckout_buynow',
                nonce: easycheckoutBuyNow.nonce,
                product_id: productId,
                variation_id: variationId,
                quantity: quantity,
                variation: variation
            }).done(function (res) {
                if (res && res.success && res.data && res.data.redirect) {
                    window.location.href = res.data.redirect;
                } else {
                    alert((res && res.data && res.data.message) || easycheckoutBuyNow.i18n.error);
                    $btn.prop('disabled', false).text(original);
                }
            }).fail(function () {
                alert(easycheckoutBuyNow.i18n.error);
                $btn.prop('disabled', false).text(original);
            });
        });
    });
})(jQuery);
