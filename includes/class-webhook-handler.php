<?php
/**
 * EasyCheckout Webhook Handler
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Handles incoming webhooks from EasyCheckout
 */
class Webhook_Handler {

    /**
     * Webhook secret for signature verification
     *
     * @var string
     */
    private $webhook_secret;

    /**
     * Timestamp tolerance in seconds
     *
     * @var int
     */
    private const TIMESTAMP_TOLERANCE = 300;

    /**
     * Constructor
     */
    public function __construct() {
        $this->webhook_secret = get_option('easycheckout_webhook_secret', '');
    }

    /**
     * Handle incoming webhook
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle_webhook($request) {
        $payload = $request->get_body();
        $signature = $request->get_header('X-EasyCheckout-Signature');
        $timestamp = $request->get_header('X-EasyCheckout-Timestamp');

        // Log incoming webhook
        $this->log('Webhook received: ' . substr($payload, 0, 500));

        // Verify signature
        if (!$this->verify_signature($payload, $signature, $timestamp)) {
            $this->log('Webhook signature verification failed', 'error');
            return new \WP_REST_Response(['error' => 'Invalid signature'], 401);
        }

        // Verify timestamp
        if (!$this->verify_timestamp($timestamp)) {
            $this->log('Webhook timestamp expired', 'error');
            return new \WP_REST_Response(['error' => 'Request expired'], 401);
        }

        // Parse payload
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Webhook JSON parse error', 'error');
            return new \WP_REST_Response(['error' => 'Invalid JSON'], 400);
        }

        $event_type = $data['event'] ?? '';
        $event_id = $data['eventId'] ?? '';

        // Check for duplicate event
        if ($this->is_duplicate_event($event_id)) {
            $this->log('Duplicate webhook event: ' . $event_id);
            return new \WP_REST_Response(['status' => 'already_processed']);
        }

        // Process event
        $result = $this->process_event($event_type, $data);

        if (is_wp_error($result)) {
            $this->log('Webhook processing error: ' . $result->get_error_message(), 'error');
            return new \WP_REST_Response(['error' => $result->get_error_message()], 500);
        }

        // Mark event as processed
        $this->mark_event_processed($event_id);

        $this->log('Webhook processed successfully: ' . $event_type);

        return new \WP_REST_Response(['status' => 'success']);
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload
     * @param string $signature
     * @param string $timestamp
     * @return bool
     */
    private function verify_signature($payload, $signature, $timestamp) {
        if (empty($this->webhook_secret)) {
            // No secret configured, skip verification in dev mode
            if (get_option('easycheckout_test_mode', 'yes') === 'yes') {
                return true;
            }
            return false;
        }

        if (empty($signature)) {
            return false;
        }

        // Create signed payload
        $signed_payload = $timestamp . '.' . $payload;

        // Calculate expected signature
        $expected = hash_hmac('sha256', $signed_payload, $this->webhook_secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Verify timestamp is within tolerance
     *
     * @param string $timestamp
     * @return bool
     */
    private function verify_timestamp($timestamp) {
        if (empty($timestamp)) {
            return get_option('easycheckout_test_mode', 'yes') === 'yes';
        }

        $webhook_time = intval($timestamp);
        $current_time = time();

        return abs($current_time - $webhook_time) <= self::TIMESTAMP_TOLERANCE;
    }

    /**
     * Check if event has already been processed
     *
     * @param string $event_id
     * @return bool
     */
    private function is_duplicate_event($event_id) {
        if (empty($event_id)) {
            return false;
        }

        $processed = get_option('easycheckout_processed_events', []);

        return in_array($event_id, $processed);
    }

    /**
     * Mark event as processed
     *
     * @param string $event_id
     */
    private function mark_event_processed($event_id) {
        if (empty($event_id)) {
            return;
        }

        $processed = get_option('easycheckout_processed_events', []);

        // Add new event
        $processed[] = $event_id;

        // Keep only last 1000 events
        if (count($processed) > 1000) {
            $processed = array_slice($processed, -1000);
        }

        update_option('easycheckout_processed_events', $processed);
    }

    /**
     * Process webhook event
     *
     * @param string $event_type
     * @param array $data
     * @return bool|\WP_Error
     */
    private function process_event($event_type, $data) {
        $order_data = $data['data'] ?? [];

        switch ($event_type) {
            case 'order.paid':
                return $this->handle_order_paid($order_data);

            case 'order.failed':
                return $this->handle_order_failed($order_data);

            case 'order.refunded':
                return $this->handle_order_refunded($order_data);

            case 'order.created':
                return $this->handle_order_created($order_data);

            case 'checkout.completed':
                return $this->handle_checkout_completed($order_data);

            default:
                $this->log('Unknown webhook event type: ' . $event_type);
                // Return true for unknown events to acknowledge receipt
                return true;
        }
    }

    /**
     * Handle order.paid event
     *
     * @param array $data
     * @return bool|\WP_Error
     */
    private function handle_order_paid($data) {
        $ec_order_id = $data['orderId'] ?? $data['id'] ?? '';

        if (empty($ec_order_id)) {
            return new \WP_Error('invalid_data', 'Missing order ID');
        }

        // Update transaction record
        $plugin = EasyCheckout::instance();
        $plugin->update_transaction($ec_order_id, [
            'status' => 'paid',
            'webhook_received' => 1,
            'payment_method' => $data['paymentMethod'] ?? '',
        ]);

        // Handle WooCommerce order if linked
        $wc_order_id = $this->get_wc_order_id($ec_order_id);
        if ($wc_order_id) {
            $this->complete_wc_order($wc_order_id, $data);
        }

        // Fire action for other integrations
        do_action('easycheckout_order_paid', $ec_order_id, $data);

        return true;
    }

    /**
     * Handle order.failed event
     *
     * @param array $data
     * @return bool|\WP_Error
     */
    private function handle_order_failed($data) {
        $ec_order_id = $data['orderId'] ?? $data['id'] ?? '';

        if (empty($ec_order_id)) {
            return new \WP_Error('invalid_data', 'Missing order ID');
        }

        // Update transaction record
        $plugin = EasyCheckout::instance();
        $plugin->update_transaction($ec_order_id, [
            'status' => 'failed',
            'webhook_received' => 1,
        ]);

        // Handle WooCommerce order if linked
        $wc_order_id = $this->get_wc_order_id($ec_order_id);
        if ($wc_order_id) {
            $this->fail_wc_order($wc_order_id, $data);
        }

        // Fire action
        do_action('easycheckout_order_failed', $ec_order_id, $data);

        return true;
    }

    /**
     * Handle order.refunded event
     *
     * @param array $data
     * @return bool|\WP_Error
     */
    private function handle_order_refunded($data) {
        $ec_order_id = $data['orderId'] ?? $data['id'] ?? '';
        $refund_amount = $data['refundAmount'] ?? $data['amount'] ?? 0;

        if (empty($ec_order_id)) {
            return new \WP_Error('invalid_data', 'Missing order ID');
        }

        // Update transaction record
        $plugin = EasyCheckout::instance();
        $plugin->update_transaction($ec_order_id, [
            'status' => 'refunded',
            'webhook_received' => 1,
        ]);

        // Handle WooCommerce refund if linked
        $wc_order_id = $this->get_wc_order_id($ec_order_id);
        if ($wc_order_id) {
            $this->refund_wc_order($wc_order_id, $refund_amount, $data);
        }

        // Fire action
        do_action('easycheckout_order_refunded', $ec_order_id, $refund_amount, $data);

        return true;
    }

    /**
     * Handle order.created event
     *
     * @param array $data
     * @return bool
     */
    private function handle_order_created($data) {
        $ec_order_id = $data['orderId'] ?? $data['id'] ?? '';

        // Fire action
        do_action('easycheckout_order_created', $ec_order_id, $data);

        return true;
    }

    /**
     * Handle checkout.completed event
     *
     * @param array $data
     * @return bool
     */
    private function handle_checkout_completed($data) {
        $checkout_slug = $data['checkoutSlug'] ?? '';
        $ec_order_id = $data['orderId'] ?? $data['id'] ?? '';

        // Fire action
        do_action('easycheckout_checkout_completed', $checkout_slug, $ec_order_id, $data);

        return true;
    }

    /**
     * Get WooCommerce order ID from EasyCheckout order ID
     *
     * @param string $ec_order_id
     * @return int|null
     */
    private function get_wc_order_id($ec_order_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'easycheckout_transactions';

        $wc_order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_order_id FROM $table WHERE ec_order_id = %s AND wc_order_id > 0",
            $ec_order_id
        ));

        if ($wc_order_id) {
            return intval($wc_order_id);
        }

        // Also check order meta
        $orders = wc_get_orders([
            'meta_key' => '_easycheckout_order_id',
            'meta_value' => $ec_order_id,
            'limit' => 1,
        ]);

        if (!empty($orders)) {
            return $orders[0]->get_id();
        }

        return null;
    }

    /**
     * Complete WooCommerce order
     *
     * @param int $order_id
     * @param array $data
     */
    private function complete_wc_order($order_id, $data) {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Only process if not already completed
        if (!in_array($order->get_status(), ['pending', 'on-hold', 'processing'])) {
            return;
        }

        // Add order note
        $order->add_order_note(sprintf(
            __('Payment completed via EasyCheckout. Transaction ID: %s', 'easycheckout'),
            $data['transactionId'] ?? $data['orderId'] ?? ''
        ));

        // Update order meta
        if (!empty($data['transactionId'])) {
            $order->set_transaction_id($data['transactionId']);
        }

        // Complete the order
        $order->payment_complete();

        // Fire action
        do_action('easycheckout_wc_order_completed', $order_id, $data);
    }

    /**
     * Mark WooCommerce order as failed
     *
     * @param int $order_id
     * @param array $data
     */
    private function fail_wc_order($order_id, $data) {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $error_message = $data['errorMessage'] ?? $data['error'] ?? __('Payment failed', 'easycheckout');

        $order->add_order_note(sprintf(
            __('Payment failed via EasyCheckout: %s', 'easycheckout'),
            $error_message
        ));

        $order->update_status('failed', __('Payment failed.', 'easycheckout'));

        // Fire action
        do_action('easycheckout_wc_order_failed', $order_id, $data);
    }

    /**
     * Process WooCommerce refund
     *
     * @param int $order_id
     * @param float $amount
     * @param array $data
     */
    private function refund_wc_order($order_id, $amount, $data) {
        if (!function_exists('wc_get_order') || !function_exists('wc_create_refund')) {
            return;
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Create refund
        $refund = wc_create_refund([
            'amount' => $amount,
            'reason' => $data['refundReason'] ?? __('Refund via EasyCheckout', 'easycheckout'),
            'order_id' => $order_id,
            'refund_payment' => false, // Already refunded via EasyCheckout
        ]);

        if (is_wp_error($refund)) {
            $this->log('Failed to create WC refund: ' . $refund->get_error_message(), 'error');
            return;
        }

        $order->add_order_note(sprintf(
            __('Refund of %s processed via EasyCheckout.', 'easycheckout'),
            wc_price($amount, ['currency' => $order->get_currency()])
        ));

        // Fire action
        do_action('easycheckout_wc_order_refunded', $order_id, $amount, $data);
    }

    /**
     * Get webhook URL
     *
     * @return string
     */
    public static function get_webhook_url() {
        return rest_url('easycheckout/v1/webhook');
    }

    /**
     * Log message
     *
     * @param string $message
     * @param string $level
     */
    private function log($message, $level = 'info') {
        $plugin = EasyCheckout::instance();
        $plugin->log('[Webhook] ' . $message, $level);
    }
}
