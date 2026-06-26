<?php
/**
 * EasyCheckout Frontend Rendering
 *
 * @package EasyCheckout\CheckoutBuilder
 */

namespace EasyCheckout\CheckoutBuilder;

use EasyCheckout\EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Frontend rendering for checkout pages
 */
class Checkout_Frontend {

    /**
     * Constructor
     */
    public function __construct() {
        add_filter('single_template', [$this, 'checkout_template']);
        add_action('wp_head', [$this, 'add_checkout_styles']);
        add_filter('the_content', [$this, 'checkout_content']);
    }

    /**
     * Use custom template for checkout CPT
     *
     * @param string $template
     * @return string
     */
    public function checkout_template($template) {
        global $post;

        if ($post && $post->post_type === Checkout_CPT::POST_TYPE) {
            // Check for theme override
            $theme_template = locate_template(['easycheckout/checkout-page.php', 'checkout-page.php']);

            if ($theme_template) {
                return $theme_template;
            }

            // Use plugin template
            $plugin_template = EASYCHECKOUT_PLUGIN_DIR . 'templates/checkout-page.php';
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }

        return $template;
    }

    /**
     * Add dynamic checkout styles
     */
    public function add_checkout_styles() {
        global $post;

        if (!$post || $post->post_type !== Checkout_CPT::POST_TYPE) {
            return;
        }

        $primary_color = get_post_meta($post->ID, '_ec_primary_color', true) ?: '#0066cc';
        ?>
        <style>
            :root {
                --easycheckout-primary: <?php echo esc_attr($primary_color); ?>;
                --easycheckout-primary-hover: <?php echo esc_attr($this->adjust_brightness($primary_color, -20)); ?>;
            }
        </style>
        <?php
    }

    /**
     * Filter checkout content
     *
     * @param string $content
     * @return string
     */
    public function checkout_content($content) {
        global $post;

        if (!$post || $post->post_type !== Checkout_CPT::POST_TYPE) {
            return $content;
        }

        if (!is_singular(Checkout_CPT::POST_TYPE)) {
            return $content;
        }

        wp_enqueue_style('easycheckout-checkout');

        // Render the embed (product preview + button to the hosted checkout).
        return $content . do_shortcode('[easycheckout id="' . $post->ID . '"]');
    }

    /**
     * Render the checkout form
     *
     * @param \WP_Post $post
     */
    public function render_checkout_form($post) {
        $checkout_slug = get_post_meta($post->ID, '_ec_checkout_slug', true);
        $description = get_post_meta($post->ID, '_ec_description', true);
        $currency = get_post_meta($post->ID, '_ec_currency', true) ?: 'CHF';
        $vat_rate = get_post_meta($post->ID, '_ec_vat_rate', true);
        $products = get_post_meta($post->ID, '_ec_products', true) ?: [];
        $payment_methods = get_post_meta($post->ID, '_ec_payment_methods', true) ?: ['card'];
        $logo = get_post_meta($post->ID, '_ec_logo', true);
        $success_url = get_post_meta($post->ID, '_ec_success_url', true);
        $terms_url = get_post_meta($post->ID, '_ec_terms_url', true);

        // Get enabled global payment methods
        $global_methods = get_option('easycheckout_payment_methods', ['card', 'twint']);
        $enabled_methods = array_intersect($payment_methods, $global_methods);

        if (empty($enabled_methods)) {
            $enabled_methods = ['card'];
        }

        // Localize script with checkout data
        wp_localize_script('easycheckout-checkout', 'easycheckoutCheckout', [
            'checkoutId' => $post->ID,
            'checkoutSlug' => $checkout_slug,
            'currency' => $currency,
            'vatRate' => floatval($vat_rate),
            'products' => $products,
            'paymentMethods' => $enabled_methods,
            'successUrl' => $success_url ?: add_query_arg('payment', 'success', get_permalink($post->ID)),
            'cancelUrl' => get_permalink($post->ID),
        ]);
        ?>
        <div class="easycheckout-checkout-form" data-checkout-id="<?php echo esc_attr($post->ID); ?>">
            <?php if ($logo) : ?>
            <div class="easycheckout-logo-wrapper">
                <img src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($post->post_title); ?>" class="easycheckout-logo">
            </div>
            <?php endif; ?>

            <?php if ($description) : ?>
            <div class="easycheckout-description">
                <?php echo wp_kses_post(wpautop($description)); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($products)) : ?>
            <div class="easycheckout-products-section">
                <h3><?php _e('Products', 'easycheckout'); ?></h3>
                <div class="easycheckout-products-list">
                    <?php foreach ($products as $product) : ?>
                    <div class="easycheckout-product-item" data-product-id="<?php echo esc_attr($product['id']); ?>" data-price="<?php echo esc_attr($product['price']); ?>">
                        <?php if (!empty($product['image'])) : ?>
                        <div class="easycheckout-product-image">
                            <img src="<?php echo esc_url($product['image']); ?>" alt="<?php echo esc_attr($product['name']); ?>">
                        </div>
                        <?php endif; ?>
                        <div class="easycheckout-product-details">
                            <h4 class="easycheckout-product-name"><?php echo esc_html($product['name']); ?></h4>
                            <?php if (!empty($product['description'])) : ?>
                            <p class="easycheckout-product-desc"><?php echo esc_html($product['description']); ?></p>
                            <?php endif; ?>
                            <span class="easycheckout-product-price">
                                <?php echo esc_html($currency . ' ' . number_format($product['price'], 2, '.', "'")); ?>
                            </span>
                        </div>
                        <div class="easycheckout-product-qty">
                            <button type="button" class="easycheckout-qty-btn easycheckout-qty-minus" aria-label="<?php esc_attr_e('Decrease quantity', 'easycheckout'); ?>">-</button>
                            <input type="number" class="easycheckout-qty-input" value="1" min="1" max="<?php echo esc_attr($product['maxQuantity'] ?? 99); ?>" aria-label="<?php esc_attr_e('Quantity', 'easycheckout'); ?>">
                            <button type="button" class="easycheckout-qty-btn easycheckout-qty-plus" aria-label="<?php esc_attr_e('Increase quantity', 'easycheckout'); ?>">+</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="easycheckout-customer-section">
                <h3><?php _e('Your Information', 'easycheckout'); ?></h3>
                <div class="easycheckout-form-group">
                    <label for="ec-customer-email"><?php _e('Email', 'easycheckout'); ?> <span class="required">*</span></label>
                    <input type="email" id="ec-customer-email" name="email" required autocomplete="email">
                </div>
                <div class="easycheckout-form-group">
                    <label for="ec-customer-name"><?php _e('Full Name', 'easycheckout'); ?> <span class="required">*</span></label>
                    <input type="text" id="ec-customer-name" name="name" required autocomplete="name">
                </div>
            </div>

            <div class="easycheckout-payment-section">
                <h3><?php _e('Payment Method', 'easycheckout'); ?></h3>

                <?php if (count($enabled_methods) > 1) : ?>
                <div class="easycheckout-payment-methods">
                    <?php foreach ($enabled_methods as $method) : ?>
                    <label class="easycheckout-payment-method">
                        <input type="radio" name="payment_method" value="<?php echo esc_attr($method); ?>" <?php checked($method, $enabled_methods[0]); ?>>
                        <span class="easycheckout-method-label">
                            <?php echo $this->get_payment_method_icon($method); ?>
                            <?php echo esc_html($this->get_payment_method_name($method)); ?>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <?php else : ?>
                <input type="hidden" name="payment_method" value="<?php echo esc_attr($enabled_methods[0]); ?>">
                <?php endif; ?>

                <!-- Card payment form -->
                <div class="easycheckout-payment-form easycheckout-card-form" data-method="card" style="<?php echo $enabled_methods[0] !== 'card' ? 'display:none;' : ''; ?>">
                    <div id="ec-card-element"></div>
                    <div id="ec-card-errors" class="easycheckout-error-message" role="alert"></div>
                </div>

                <!-- TWINT info -->
                <div class="easycheckout-payment-form easycheckout-twint-form" data-method="twint" style="<?php echo $enabled_methods[0] !== 'twint' ? 'display:none;' : ''; ?>">
                    <p class="easycheckout-payment-info">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                        <?php _e('You will be redirected to the TWINT app to complete your payment.', 'easycheckout'); ?>
                    </p>
                </div>

                <!-- Bank transfer info -->
                <div class="easycheckout-payment-form easycheckout-bank-form" data-method="bank_transfer" style="<?php echo $enabled_methods[0] !== 'bank_transfer' ? 'display:none;' : ''; ?>">
                    <p class="easycheckout-payment-info">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                        <?php _e('A QR-Bill will be generated for you to complete payment via bank transfer.', 'easycheckout'); ?>
                    </p>
                </div>
            </div>

            <div class="easycheckout-summary-section">
                <div class="easycheckout-summary-row easycheckout-subtotal">
                    <span><?php _e('Subtotal', 'easycheckout'); ?></span>
                    <span class="easycheckout-subtotal-amount"><?php echo esc_html($currency); ?> 0.00</span>
                </div>
                <?php if ($vat_rate > 0) : ?>
                <div class="easycheckout-summary-row easycheckout-vat">
                    <span><?php printf(__('VAT (%s%%)', 'easycheckout'), number_format($vat_rate, 1)); ?></span>
                    <span class="easycheckout-vat-amount"><?php echo esc_html($currency); ?> 0.00</span>
                </div>
                <?php endif; ?>
                <div class="easycheckout-summary-row easycheckout-total">
                    <span><?php _e('Total', 'easycheckout'); ?></span>
                    <span class="easycheckout-total-amount"><?php echo esc_html($currency); ?> 0.00</span>
                </div>
            </div>

            <?php if ($terms_url) : ?>
            <div class="easycheckout-terms-section">
                <label class="easycheckout-terms-checkbox">
                    <input type="checkbox" name="accept_terms" required>
                    <span><?php printf(
                        __('I agree to the %s', 'easycheckout'),
                        '<a href="' . esc_url($terms_url) . '" target="_blank">' . __('Terms and Conditions', 'easycheckout') . '</a>'
                    ); ?></span>
                </label>
            </div>
            <?php endif; ?>

            <div class="easycheckout-submit-section">
                <button type="submit" class="easycheckout-submit-button" id="ec-submit-button">
                    <span class="easycheckout-button-text"><?php _e('Pay Now', 'easycheckout'); ?></span>
                    <span class="easycheckout-button-loading" style="display:none;">
                        <svg class="easycheckout-spinner" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" fill="none" stroke-dasharray="60" stroke-linecap="round"/></svg>
                        <?php _e('Processing...', 'easycheckout'); ?>
                    </span>
                </button>
            </div>

            <div class="easycheckout-messages" id="ec-messages" aria-live="polite"></div>

            <div class="easycheckout-powered-by">
                <span><?php _e('Secured by', 'easycheckout'); ?></span>
                <a href="https://easycheckout.ch" target="_blank" rel="noopener">EasyCheckout</a>
            </div>
        </div>
        <?php
    }

    /**
     * Get payment method name
     *
     * @param string $method
     * @return string
     */
    private function get_payment_method_name($method) {
        $names = [
            'card' => __('Credit/Debit Card', 'easycheckout'),
            'twint' => __('TWINT', 'easycheckout'),
            'bank_transfer' => __('Bank Transfer', 'easycheckout'),
        ];
        return $names[$method] ?? $method;
    }

    /**
     * Get payment method icon
     *
     * @param string $method
     * @return string
     */
    private function get_payment_method_icon($method) {
        $icons = [
            'card' => '<svg class="easycheckout-method-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>',
            'twint' => '<svg class="easycheckout-method-icon" width="24" height="24" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="12" r="10"/><text x="12" y="16" text-anchor="middle" font-size="12" fill="white" font-weight="bold">T</text></svg>',
            'bank_transfer' => '<svg class="easycheckout-method-icon" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><rect x="7" y="7" width="4" height="4"/><rect x="13" y="7" width="4" height="4"/><rect x="7" y="13" width="4" height="4"/></svg>',
        ];
        return $icons[$method] ?? '';
    }

    /**
     * Adjust color brightness
     *
     * @param string $hex
     * @param int $steps
     * @return string
     */
    private function adjust_brightness($hex, $steps) {
        $hex = str_replace('#', '', $hex);

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));

        return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
    }
}
