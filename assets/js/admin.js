/**
 * EasyCheckout Admin JavaScript
 *
 * Handles admin settings page interactions.
 */
(function($) {
    'use strict';

    var EasyCheckoutAdmin = {
        params: {},

        /**
         * Initialize admin scripts
         */
        init: function() {
            this.params = window.easycheckoutAdmin || {};
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            var self = this;

            // Test connection button
            $('#easycheckout-test-connection').on('click', function(e) {
                e.preventDefault();
                self.testConnection($(this));
            });

            // Clear cache button
            $('#easycheckout-clear-cache').on('click', function(e) {
                e.preventDefault();
                self.clearCache($(this));
            });

            // Copy webhook URL
            $(document).on('click', '[data-copy]', function(e) {
                e.preventDefault();
                var text = $(this).data('copy') || $(this).prev('code').text();
                self.copyToClipboard(text);
            });

            // Toggle API key visibility
            $(document).on('click', '.toggle-password', function(e) {
                e.preventDefault();
                var $input = $(this).siblings('input');
                var type = $input.attr('type') === 'password' ? 'text' : 'password';
                $input.attr('type', type);
                $(this).text(type === 'password' ? 'Show' : 'Hide');
            });
        },

        /**
         * Test API connection
         */
        testConnection: function($button) {
            var self = this;
            var $result = $('#easycheckout-connection-result');
            var originalText = $button.text();

            $button.prop('disabled', true).text(this.params.i18n?.testing || 'Testing...');
            $result.html('').removeClass('success error');

            $.ajax({
                url: this.params.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'easycheckout_test_connection',
                    nonce: this.params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span class="success">' + (self.params.i18n?.success || 'Connection successful!') + '</span>');
                    } else {
                        $result.html('<span class="error">' + (self.params.i18n?.error || 'Connection failed') + ': ' + (response.data?.message || 'Unknown error') + '</span>');
                    }
                },
                error: function(xhr) {
                    var errorMsg = xhr.responseJSON?.data?.message || 'Connection failed';
                    $result.html('<span class="error">' + (self.params.i18n?.error || 'Error') + ': ' + errorMsg + '</span>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Clear cache
         */
        clearCache: function($button) {
            var self = this;
            var $result = $('#easycheckout-cache-result');
            var originalText = $button.text();

            $button.prop('disabled', true).text(this.params.i18n?.clearing || 'Clearing...');
            $result.html('');

            $.ajax({
                url: this.params.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'easycheckout_clear_cache',
                    nonce: this.params.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $result.html('<span class="success">' + (self.params.i18n?.cleared || 'Cache cleared!') + '</span>');
                    } else {
                        $result.html('<span class="error">Failed to clear cache</span>');
                    }
                },
                error: function() {
                    $result.html('<span class="error">Failed to clear cache</span>');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        /**
         * Copy text to clipboard
         */
        copyToClipboard: function(text) {
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(function() {
                    // Show brief success feedback
                    var $notice = $('<span class="copied-notice">Copied!</span>');
                    $('body').append($notice);
                    setTimeout(function() {
                        $notice.fadeOut(function() {
                            $(this).remove();
                        });
                    }, 1500);
                });
            } else {
                // Fallback for older browsers
                var $temp = $('<textarea>');
                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();
            }
        }
    };

    // Initialize on DOM ready
    $(document).ready(function() {
        EasyCheckoutAdmin.init();
    });

})(jQuery);
