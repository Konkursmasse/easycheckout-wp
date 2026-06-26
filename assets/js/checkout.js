/**
 * EasyCheckout Frontend Checkout Handler
 *
 * Handles the checkout form interactions and payment processing.
 * 100% white-label - all payments processed via EasyCheckout redirect.
 */
(function($) {
    'use strict';

    var EasyCheckout = {
        params: {},
        checkoutData: {},
        selectedProducts: [],

        /**
         * Initialize checkout
         */
        init: function() {
            this.params = window.easycheckoutParams || {};
            this.checkoutData = window.easycheckoutCheckout || {};

            this.bindEvents();
            this.calculateTotals();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Quantity buttons
            $(document).on('click', '.easycheckout-qty-btn, .easycheckout-qty-minus, .easycheckout-qty-plus', function(e) {
                e.preventDefault();
                var $btn = $(this);
                var $input = $btn.siblings('.easycheckout-qty-input, .easycheckout-qty');
                var current = parseInt($input.val()) || 1;
                var min = parseInt($input.attr('min')) || 1;
                var max = parseInt($input.attr('max')) || 99;

                if ($btn.hasClass('easycheckout-qty-minus') || $btn.text() === '-') {
                    current = Math.max(min, current - 1);
                } else {
                    current = Math.min(max, current + 1);
                }

                $input.val(current);
                self.calculateTotals();
            });

            // Quantity input change
            $(document).on('change', '.easycheckout-qty-input, .easycheckout-qty', function() {
                self.calculateTotals();
            });

            // Payment method selection
            $(document).on('change', 'input[name="payment_method"], input[name="easycheckout_payment_method"]', function() {
                var method = $(this).val();
                self.showPaymentInfo(method);
            });

            // Submit button
            $(document).on('click', '.easycheckout-submit-button, .easycheckout-pay-button', function(e) {
                e.preventDefault();
                self.processPayment();
            });

            // Buy button (single product)
            $(document).on('click', '.easycheckout-buy-button', function(e) {
                e.preventDefault();
                var $product = $(this).closest('.easycheckout-single-product');
                self.processSingleProductPayment($product);
            });

            // External button
            $(document).on('click', '.easycheckout-button[data-easycheckout]', function(e) {
                e.preventDefault();
                var data = $(this).data('easycheckout');
                self.redirectToCheckout(data);
            });
        },

        /**
         * Show payment method info
         */
        showPaymentInfo: function(method) {
            $('.easycheckout-payment-info-box').hide();
            $('[data-method="' + method + '"]').show();
        },

        /**
         * Calculate totals
         */
        calculateTotals: function() {
            var self = this;
            var subtotal = 0;
            var currency = this.checkoutData.currency || this.params.currency || 'CHF';
            var vatRate = parseFloat(this.checkoutData.vatRate) || 0;

            this.selectedProducts = [];

            // Calculate from product items
            $('.easycheckout-product-item, .easycheckout-product').each(function() {
                var $product = $(this);
                var productId = $product.data('product-id');
                var price = parseFloat($product.data('price')) || 0;
                var $qtyInput = $product.find('.easycheckout-qty-input, .easycheckout-qty');
                var qty = parseInt($qtyInput.val()) || 1;

                if (price > 0) {
                    subtotal += price * qty;
                    self.selectedProducts.push({
                        id: productId,
                        quantity: qty,
                        price: price
                    });
                }
            });

            var vat = vatRate > 0 ? (subtotal * vatRate / 100) : 0;
            var total = subtotal + vat;

            // Update display
            $('.easycheckout-subtotal-amount').text(self.formatPrice(subtotal, currency));
            $('.easycheckout-vat-amount').text(self.formatPrice(vat, currency));
            $('.easycheckout-total-amount').text(self.formatPrice(total, currency));
        },

        /**
         * Format price
         */
        formatPrice: function(amount, currency) {
            currency = currency || 'CHF';
            var formatted = amount.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, "'");
            return currency + ' ' + formatted;
        },

        /**
         * Process payment - redirect to EasyCheckout payment page
         */
        processPayment: function() {
            var self = this;
            var $form = $('.easycheckout-checkout-form, .easycheckout-container');
            var $submitBtn = $form.find('.easycheckout-submit-button, .easycheckout-pay-button');
            var $messages = $form.find('.easycheckout-messages, #ec-messages');

            // Get form data
            var email = $form.find('#ec-customer-email, #ec-email').val();
            var name = $form.find('#ec-customer-name, #ec-name').val();
            var paymentMethod = $form.find('input[name="payment_method"]:checked').val() || 'card';

            // Validate
            if (!email || !this.isValidEmail(email)) {
                this.showError($messages, this.params.i18n?.emailRequired || 'Please enter a valid email address');
                return;
            }

            if (!name) {
                this.showError($messages, this.params.i18n?.nameRequired || 'Please enter your name');
                return;
            }

            // Check terms if required
            var $terms = $form.find('input[name="accept_terms"]');
            if ($terms.length && !$terms.is(':checked')) {
                this.showError($messages, this.params.i18n?.termsRequired || 'Please accept the terms and conditions');
                return;
            }

            // Show loading
            this.setLoading($submitBtn, true);
            this.clearMessages($messages);

            // Prepare payment data
            var paymentData = {
                items: this.selectedProducts,
                customer: {
                    email: email,
                    name: name
                },
                paymentMethod: paymentMethod,
                successUrl: this.checkoutData.successUrl || window.location.href + '?payment=success',
                cancelUrl: this.checkoutData.cancelUrl || window.location.href
            };

            var checkoutSlug = this.checkoutData.checkoutSlug || $form.data('checkout-slug');
            var checkoutId = this.checkoutData.checkoutId || $form.data('checkout-id');

            // Create payment and redirect
            this.createPaymentAndRedirect(checkoutSlug, checkoutId, paymentData, $submitBtn, $messages);
        },

        /**
         * Create payment via API and redirect to payment page
         */
        createPaymentAndRedirect: function(checkoutSlug, checkoutId, paymentData, $submitBtn, $messages) {
            var self = this;

            $.ajax({
                url: this.params.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'easycheckout_create_payment',
                    nonce: this.params.nonce,
                    checkout_slug: checkoutSlug,
                    checkout_id: checkoutId,
                    items: JSON.stringify(paymentData.items),
                    email: paymentData.customer.email,
                    name: paymentData.customer.name,
                    payment_method: paymentData.paymentMethod,
                    success_url: paymentData.successUrl,
                    cancel_url: paymentData.cancelUrl
                },
                success: function(response) {
                    if (response.success && response.data) {
                        if (response.data.paymentUrl) {
                            // Redirect to EasyCheckout payment page
                            window.location.href = response.data.paymentUrl;
                        } else if (response.data.redirectUrl) {
                            window.location.href = response.data.redirectUrl;
                        } else {
                            self.showError($messages, self.params.i18n?.error || 'Unable to create payment');
                            self.setLoading($submitBtn, false);
                        }
                    } else {
                        self.showError($messages, response.data?.message || self.params.i18n?.error || 'Payment failed');
                        self.setLoading($submitBtn, false);
                    }
                },
                error: function(xhr) {
                    var error = xhr.responseJSON?.data?.message || self.params.i18n?.error || 'Payment failed';
                    self.showError($messages, error);
                    self.setLoading($submitBtn, false);
                }
            });
        },

        /**
         * Process single product payment
         */
        processSingleProductPayment: function($product) {
            var checkoutSlug = $product.data('checkout-slug');
            var productId = $product.data('product-id');

            this.redirectToCheckout({
                checkoutSlug: checkoutSlug,
                productId: productId
            });
        },

        /**
         * Redirect to EasyCheckout hosted checkout
         */
        redirectToCheckout: function(data) {
            var baseUrl = this.params.apiUrl || '';
            var checkoutUrl = baseUrl + '/checkout/' + data.checkoutSlug;

            if (data.productId) {
                checkoutUrl += '?product=' + data.productId;
            }

            window.location.href = checkoutUrl;
        },

        /**
         * Validate email
         */
        isValidEmail: function(email) {
            var re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        },

        /**
         * Set loading state
         */
        setLoading: function($button, loading) {
            if (loading) {
                $button.prop('disabled', true);
                $button.find('.easycheckout-button-text').hide();
                $button.find('.easycheckout-button-loading').show();
            } else {
                $button.prop('disabled', false);
                $button.find('.easycheckout-button-text').show();
                $button.find('.easycheckout-button-loading').hide();
            }
        },

        /**
         * Show error message
         */
        showError: function($container, message) {
            $container.html('<div class="easycheckout-error">' + this.escapeHtml(message) + '</div>');
        },

        /**
         * Show success message
         */
        showSuccess: function($container, message) {
            $container.html('<div class="easycheckout-success">' + this.escapeHtml(message) + '</div>');
        },

        /**
         * Clear messages
         */
        clearMessages: function($container) {
            $container.html('');
        },

        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            var div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };

    // Initialize on DOM ready
    $(document).ready(function() {
        EasyCheckout.init();
    });

    // Expose for external use
    window.EasyCheckout = EasyCheckout;

})(jQuery);
