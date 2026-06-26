<?php
/**
 * EasyCheckout WooCommerce Payment Gateway
 *
 * 100% white-label payment gateway - redirects to EasyCheckout hosted payment page.
 *
 * @package EasyCheckout\WooCommerce
 */

namespace EasyCheckout\WooCommerce;

use EasyCheckout\EasyCheckout;
use EasyCheckout\API_Client;

defined('ABSPATH') || exit;

/**
 * WooCommerce Payment Gateway for EasyCheckout
 */
class WC_Gateway_EasyCheckout extends \WC_Payment_Gateway {

    /**
     * API Client
     *
     * @var API_Client
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'easycheckout';
        $this->icon = '';
        $this->has_fields = true;
        $this->method_title = __('EasyCheckout', 'easycheckout');
        $this->method_description = __('Accept payments with Credit Cards, TWINT, and Swiss QR-Bill via EasyCheckout.', 'easycheckout');
        // Refunds are managed in the EasyCheckout dashboard (no public refund API).
        $this->supports = [
            'products',
        ];

        // Load settings
        $this->init_form_fields();
        $this->init_settings();

        $this->title = $this->get_option('title');
        $this->description = $this->get_option('description');
        $this->enabled = $this->get_option('enabled');
        $this->checkout_slug = $this->get_option('checkout_slug');

        // Initialize API client
        $this->api = new API_Client();

        // Hooks
        add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        add_action('woocommerce_api_easycheckout', [$this, 'handle_return']);
        add_action('woocommerce_api_easycheckout_cancel', [$this, 'handle_cancel']);
    }

    /**
     * Initialize form fields
     */
    public function init_form_fields() {
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'easycheckout'),
                'type' => 'checkbox',
                'label' => __('Enable EasyCheckout', 'easycheckout'),
                'default' => 'no',
            ],
            'title' => [
                'title' => __('Title', 'easycheckout'),
                'type' => 'text',
                'description' => __('This controls the title shown during checkout.', 'easycheckout'),
                'default' => __('Credit Card / TWINT / Bank Transfer', 'easycheckout'),
                'desc_tip' => true,
            ],
            'description' => [
                'title' => __('Description', 'easycheckout'),
                'type' => 'textarea',
                'description' => __('This controls the description shown during checkout.', 'easycheckout'),
                'default' => __('Pay securely with your preferred payment method.', 'easycheckout'),
                'desc_tip' => true,
            ],
            'checkout_slug' => [
                'title' => __('Checkout Slug', 'easycheckout'),
                'type' => 'text',
                'description' => __('The EasyCheckout checkout slug to use for WooCommerce orders.', 'easycheckout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'payment_methods' => [
                'title' => __('Payment Methods', 'easycheckout'),
                'type' => 'multiselect',
                'class' => 'wc-enhanced-select',
                'description' => __('Select which payment methods to display. Actual availability depends on EasyCheckout configuration.', 'easycheckout'),
                'options' => [
                    'card' => __('Credit/Debit Cards', 'easycheckout'),
                    'twint' => __('TWINT', 'easycheckout'),
                    'bank_transfer' => __('Bank Transfer (QR-Bill)', 'easycheckout'),
                ],
                'default' => ['card', 'twint'],
                'desc_tip' => true,
            ],
        ];
    }

    /**
     * Check if gateway is available
     *
     * @return bool
     */
    public function is_available() {
        if (!parent::is_available()) {
            return false;
        }

        // Requires a configured API key (payments:write). The payment session is
        // created ad-hoc from the cart total, so no checkout slug is needed.
        if (!$this->api->is_configured()) {
            return false;
        }

        return true;
    }

    /**
     * Render payment fields
     */
    public function payment_fields() {
        if ($this->description) {
            echo wpautop(wptexturize($this->description));
        }

        $payment_methods = $this->get_option('payment_methods', ['card', 'twint']);
        ?>
        <div id="easycheckout-payment-info">
            <p class="easycheckout-redirect-notice">
                <?php _e('You will be redirected to complete your payment securely.', 'easycheckout'); ?>
            </p>

            <?php if (count($payment_methods) > 1) : ?>
            <div class="easycheckout-payment-methods-display">
                <p class="easycheckout-methods-label"><?php _e('Available payment methods:', 'easycheckout'); ?></p>
                <ul class="easycheckout-methods-list">
                    <?php foreach ($payment_methods as $method) : ?>
                    <li class="easycheckout-method-item">
                        <?php echo esc_html($this->get_method_label($method)); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </div>

        <style>
            #easycheckout-payment-info {
                padding: 15px;
                background: #f8f9fa;
                border-radius: 4px;
                margin-top: 10px;
            }
            .easycheckout-redirect-notice {
                margin: 0 0 10px;
                color: #666;
            }
            .easycheckout-methods-label {
                margin: 0 0 8px;
                font-weight: 500;
            }
            .easycheckout-methods-list {
                margin: 0;
                padding-left: 20px;
            }
            .easycheckout-method-item {
                margin-bottom: 4px;
            }
        </style>
        <?php
    }

    /**
     * Get payment method label
     *
     * @param string $method
     * @return string
     */
    private function get_method_label($method) {
        $labels = [
            'card' => __('Credit/Debit Card', 'easycheckout'),
            'twint' => __('TWINT', 'easycheckout'),
            'bank_transfer' => __('Bank Transfer (QR-Bill)', 'easycheckout'),
        ];
        return $labels[$method] ?? $method;
    }

    /**
     * Process payment - redirect to EasyCheckout hosted payment page
     *
     * @param int $order_id
     * @return array
     */
    public function process_payment($order_id) {
        $order = wc_get_order($order_id);

        $success_url = add_query_arg([
            'wc-api' => 'easycheckout',
            'order_id' => $order_id,
            'key' => $order->get_order_key(),
        ], home_url('/'));

        $cancel_url = add_query_arg([
            'wc-api' => 'easycheckout_cancel',
            'order_id' => $order_id,
            'key' => $order->get_order_key(),
        ], home_url('/'));

        // Create an ad-hoc payment session for the cart total and get the hosted
        // payment page URL. Amount must be an integer in the smallest currency unit.
        $session_data = [
            'amount' => (int) round((float) $order->get_total() * 100),
            'currency' => $order->get_currency(),
            'success_url' => $success_url,
            'cancel_url' => $cancel_url,
            'description' => sprintf(__('Bestellung %s', 'easycheckout'), $order->get_order_number()),
            'metadata' => [
                'wc_order_id' => (string) $order_id,
                'wc_order_key' => $order->get_order_key(),
                'source' => 'woocommerce',
                'customer_email' => $order->get_billing_email(),
                'customer_name' => $order->get_formatted_billing_full_name(),
            ],
        ];

        $response = $this->api->create_payment_session($session_data);

        if (is_wp_error($response)) {
            $this->log('Payment session creation failed: ' . $response->get_error_message(), 'error');
            wc_add_notice($response->get_error_message(), 'error');
            return ['result' => 'failure'];
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;

        $redirect_url = $data['payment_url'] ?? '';
        $ec_order_id = $data['order_id'] ?? '';

        if (empty($redirect_url)) {
            $this->log('No payment_url in payment session response', 'error');
            wc_add_notice(__('Zahlung konnte nicht gestartet werden. Bitte versuche es erneut.', 'easycheckout'), 'error');
            return ['result' => 'failure'];
        }

        // Store EasyCheckout order ID for webhook matching
        $order->update_meta_data('_easycheckout_order_id', $ec_order_id);
        $order->save();

        // Log transaction
        $plugin = EasyCheckout::instance();
        $plugin->log_transaction([
            'wc_order_id' => $order_id,
            'ec_order_id' => $ec_order_id,
            'ec_checkout_slug' => '',
            'status' => 'pending',
            'amount' => $order->get_total(),
            'currency' => $order->get_currency(),
            'customer_email' => $order->get_billing_email(),
            'customer_name' => $order->get_formatted_billing_full_name(),
        ]);

        // Reduce stock and mark order as pending
        wc_reduce_stock_levels($order_id);
        $order->update_status('pending', __('Awaiting payment via EasyCheckout.', 'easycheckout'));

        return [
            'result' => 'success',
            'redirect' => $redirect_url,
        ];
    }

    /**
     * Handle return from payment (success)
     */
    public function handle_return() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';
        $ec_order_id = isset($_GET['orderId']) ? sanitize_text_field($_GET['orderId']) : '';
        $status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        if (!$order_id) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Check payment status from EasyCheckout if order ID provided
        if ($ec_order_id) {
            $order->update_meta_data('_easycheckout_order_id', $ec_order_id);
            $order->save();
        }

        // If status indicates success, mark as processing (webhook will confirm)
        if ($status === 'success' || $status === 'paid') {
            if (!$order->is_paid()) {
                $order->update_status('processing', __('Payment received via EasyCheckout, awaiting webhook confirmation.', 'easycheckout'));
            }
        }

        // Redirect to thank you page
        wp_redirect($this->get_return_url($order));
        exit;
    }

    /**
     * Handle return from payment (cancel)
     */
    public function handle_cancel() {
        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field($_GET['key']) : '';

        if (!$order_id) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_redirect(wc_get_checkout_url());
            exit;
        }

        // Restore stock
        wc_increase_stock_levels($order_id);

        // Update order status
        $order->update_status('cancelled', __('Payment cancelled by customer.', 'easycheckout'));

        // Add notice
        wc_add_notice(__('Payment was cancelled. Please try again.', 'easycheckout'), 'notice');

        // Redirect back to checkout
        wp_redirect(wc_get_checkout_url());
        exit;
    }

    /**
     * Process refund
     *
     * @param int $order_id
     * @param float|null $amount
     * @param string $reason
     * @return bool|\WP_Error
     */
    public function process_refund($order_id, $amount = null, $reason = '') {
        $order = wc_get_order($order_id);

        if (!$order) {
            return new \WP_Error('invalid_order', __('Invalid order.', 'easycheckout'));
        }

        // The EasyCheckout API does not expose a refund endpoint, so refunds must
        // be issued from the EasyCheckout dashboard. Surface a clear message
        // instead of silently failing.
        return new \WP_Error(
            'easycheckout_refund_unsupported',
            __('Rückerstattungen werden direkt im EasyCheckout-Konto durchgeführt (keine API verfügbar).', 'easycheckout')
        );
    }

    /**
     * Log message
     *
     * @param string $message
     * @param string $level
     */
    private function log($message, $level = 'info') {
        $plugin = EasyCheckout::instance();
        $plugin->log('[WC Gateway] ' . $message, $level);
    }
}
