<?php
/**
 * EasyCheckout Webhook Handler
 *
 * Empfängt und verarbeitet Webhooks der EasyCheckout-Plattform. Das Format ist
 * exakt an lib/webhooks.js der Plattform ausgerichtet:
 *
 *   Header  X-EasyCheckout-Signature: t=<unixts>,v1=<hmac_sha256_hex>
 *           (HMAC über "<t>.<rawbody>" mit dem Endpoint-Secret)
 *   Header  X-EasyCheckout-Event:       <event-typ>   (z.B. order.paid)
 *   Body    { id: "evt_...", type: "order.paid", created, data: {...}, api_version }
 *
 *   data (order.*): { order: { id, orderNumber, amount, currency, paymentMethod,
 *                              paidAt, metadata }, customer, items, checkout? }
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
        // WICHTIG: Rohkörper verwenden (nicht das dekodierte Array). Die Signatur
        // wird über exakt diese Bytes gebildet; ein Re-Encode würde sie brechen.
        $payload = $request->get_body();
        $signature = $request->get_header('X-EasyCheckout-Signature');

        $this->log('Webhook received: ' . substr($payload, 0, 500));

        // Signatur prüfen (Format t=<ts>,v1=<hmac>). Enthält zugleich den Zeitstempel.
        if (!$this->verify_signature($payload, $signature)) {
            $this->log('Webhook signature verification failed', 'error');
            return new \WP_REST_Response(['error' => 'Invalid signature'], 401);
        }

        // Parse payload
        $data = json_decode($payload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->log('Webhook JSON parse error', 'error');
            return new \WP_REST_Response(['error' => 'Invalid JSON'], 400);
        }

        // Envelope der Plattform: { id, type, created, data, api_version }
        $event_type = $data['type'] ?? $request->get_header('X-EasyCheckout-Event') ?? '';
        $event_id = $data['id'] ?? '';

        // Check for duplicate event
        if ($this->is_duplicate_event($event_id)) {
            $this->log('Duplicate webhook event: ' . $event_id);
            return new \WP_REST_Response(['status' => 'already_processed']);
        }

        // Process event
        $result = $this->process_event($event_type, $data['data'] ?? []);

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
     * Verify webhook signature.
     *
     * Erwartetes Header-Format (Stripe-Stil, wie lib/webhooks.js sendet):
     *   t=<unix-timestamp>,v1=<hmac-sha256-hex>
     * Der HMAC wird über "<t>.<rawbody>" mit dem Endpoint-Secret gebildet.
     * Der Zeitstempel steckt im selben Header (kein separater Timestamp-Header).
     *
     * @param string $payload   Roher Request-Body
     * @param string $signature Wert des X-EasyCheckout-Signature Headers
     * @return bool
     */
    private function verify_signature($payload, $signature) {
        if (empty($this->webhook_secret)) {
            // Kein Secret hinterlegt: nur im Testmodus durchlassen (Dev), sonst ablehnen.
            return get_option('easycheckout_test_mode', 'yes') === 'yes';
        }

        if (empty($signature)) {
            return false;
        }

        // t=... und v1=... aus dem Header lösen.
        $timestamp = null;
        $provided = null;
        foreach (explode(',', $signature) as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) !== 2) {
                continue;
            }
            if ($kv[0] === 't') {
                $timestamp = $kv[1];
            } elseif ($kv[0] === 'v1') {
                $provided = $kv[1];
            }
        }

        // Rückwärtskompatibilität: falls doch eine nackte Signatur ohne t=/v1= kommt.
        if ($provided === null && strpos($signature, '=') === false) {
            $provided = $signature;
            $timestamp = $timestamp ?? (string) time();
        }

        if (empty($timestamp) || empty($provided)) {
            return false;
        }

        // Replay-Schutz: Zeitstempel innerhalb der Toleranz.
        if (abs(time() - intval($timestamp)) > self::TIMESTAMP_TOLERANCE) {
            $this->log('Webhook timestamp outside tolerance', 'error');
            return false;
        }

        $signed_payload = $timestamp . '.' . $payload;
        $expected = hash_hmac('sha256', $signed_payload, $this->webhook_secret);

        return hash_equals($expected, $provided);
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

        return in_array($event_id, $processed, true);
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
     * @param array $data Inhalt des "data"-Feldes des Envelopes
     * @return bool|\WP_Error
     */
    private function process_event($event_type, $data) {
        switch ($event_type) {
            case 'order.paid':
                return $this->handle_order_paid($data);

            case 'order.failed':
                return $this->handle_order_failed($data);

            case 'order.refunded':
            case 'order.partially_refunded':
                return $this->handle_order_refunded($data, $event_type === 'order.partially_refunded');

            case 'order.created':
                return $this->handle_order_created($data);

            case 'checkout.completed':
                return $this->handle_checkout_completed($data);

            default:
                $this->log('Unhandled webhook event type: ' . $event_type);
                // Unbekannte Events bestätigen (200), damit die Plattform nicht endlos retryt.
                return true;
        }
    }

    /**
     * Order-Objekt aus dem data-Feld holen. Plattform verschachtelt unter data.order,
     * ältere/andere Quellen evtl. flach – beides tolerieren.
     *
     * @param array $data
     * @return array
     */
    private function extract_order($data) {
        if (isset($data['order']) && is_array($data['order'])) {
            return $data['order'];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * EasyCheckout-Order-ID aus dem Order-Objekt lesen.
     *
     * @param array $order
     * @return string
     */
    private function extract_ec_order_id($order) {
        return (string) ($order['id'] ?? $order['orderId'] ?? '');
    }

    /**
     * Handle order.paid event
     *
     * @param array $data
     * @return bool|\WP_Error
     */
    private function handle_order_paid($data) {
        $order = $this->extract_order($data);
        $ec_order_id = $this->extract_ec_order_id($order);

        if (empty($ec_order_id)) {
            return new \WP_Error('invalid_data', 'Missing order ID');
        }

        // Update transaction record
        $plugin = EasyCheckout::instance();
        $plugin->update_transaction($ec_order_id, [
            'status' => 'paid',
            'webhook_received' => 1,
            'payment_method' => $order['paymentMethod'] ?? '',
        ]);

        // Handle WooCommerce order if linked
        $wc_order_id = $this->get_wc_order_id($ec_order_id, $order);
        if ($wc_order_id) {
            $this->complete_wc_order($wc_order_id, $order, $ec_order_id, $data['customer'] ?? []);
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
        $order = $this->extract_order($data);
        $ec_order_id = $this->extract_ec_order_id($order);

        if (empty($ec_order_id)) {
            return new \WP_Error('invalid_data', 'Missing order ID');
        }

        $plugin = EasyCheckout::instance();
        $plugin->update_transaction($ec_order_id, [
            'status' => 'failed',
            'webhook_received' => 1,
        ]);

        $wc_order_id = $this->get_wc_order_id($ec_order_id, $order);
        if ($wc_order_id) {
            $this->fail_wc_order($wc_order_id, $data);
        }

        do_action('easycheckout_order_failed', $ec_order_id, $data);

        return true;
    }

    /**
     * Handle order.refunded / order.partially_refunded event
     *
     * @param array $data
     * @param bool  $partial
     * @return bool|\WP_Error
     */
    private function handle_order_refunded($data, $partial = false) {
        $order = $this->extract_order($data);
        $ec_order_id = $this->extract_ec_order_id($order);
        $refund_amount = $order['refundedAmount'] ?? $order['refundAmount'] ?? $order['amount'] ?? 0;

        if (empty($ec_order_id)) {
            return new \WP_Error('invalid_data', 'Missing order ID');
        }

        $plugin = EasyCheckout::instance();
        $plugin->update_transaction($ec_order_id, [
            'status' => $partial ? 'partially_refunded' : 'refunded',
            'webhook_received' => 1,
        ]);

        $wc_order_id = $this->get_wc_order_id($ec_order_id, $order);
        if ($wc_order_id) {
            $this->refund_wc_order($wc_order_id, (float) $refund_amount, $data);
        }

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
        $order = $this->extract_order($data);
        $ec_order_id = $this->extract_ec_order_id($order);

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
        $order = $this->extract_order($data);
        $checkout_slug = $data['checkout']['slug'] ?? $data['checkoutSlug'] ?? '';
        $ec_order_id = $this->extract_ec_order_id($order);

        do_action('easycheckout_checkout_completed', $checkout_slug, $ec_order_id, $data);

        return true;
    }

    /**
     * Get WooCommerce order ID for an EasyCheckout order.
     *
     * Bevorzugt die in den Zahlungs-Metadaten mitgeführte wc_order_id (der Gateway
     * setzt sie beim Anlegen der payment-session). Fallback: Transaktionstabelle und
     * das _easycheckout_order_id Order-Meta.
     *
     * @param string $ec_order_id
     * @param array  $order Order-Objekt aus dem Webhook (enthält evtl. metadata)
     * @return int|null
     */
    private function get_wc_order_id($ec_order_id, $order = []) {
        if (!function_exists('wc_get_order')) {
            return null;
        }

        // 1) Direkter Weg: wc_order_id aus den Zahlungs-Metadaten.
        $meta = isset($order['metadata']) && is_array($order['metadata']) ? $order['metadata'] : [];
        if (!empty($meta['wc_order_id'])) {
            $candidate = intval($meta['wc_order_id']);
            if ($candidate > 0) {
                $wc_order = wc_get_order($candidate);
                // Optional gegen den Bestellschlüssel absichern, falls mitgeliefert.
                if ($wc_order) {
                    if (!empty($meta['wc_order_key']) && $wc_order->get_order_key() !== $meta['wc_order_key']) {
                        $this->log('wc_order_key mismatch for order ' . $candidate, 'error');
                    } else {
                        return $candidate;
                    }
                }
            }
        }

        // 2) Transaktionstabelle (ec_order_id -> wc_order_id).
        global $wpdb;
        $table = $wpdb->prefix . 'easycheckout_transactions';
        $wc_order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT wc_order_id FROM {$table} WHERE ec_order_id = %s AND wc_order_id > 0",
            $ec_order_id
        ));
        if ($wc_order_id) {
            return intval($wc_order_id);
        }

        // 3) Order-Meta _easycheckout_order_id.
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
     * @param int    $order_id
     * @param array  $order       Order-Objekt aus dem Webhook
     * @param string $ec_order_id
     * @param array  $customer    Kundendaten aus dem Webhook (Fast-Checkout-Adresse)
     */
    private function complete_wc_order($order_id, $order, $ec_order_id = '', $customer = []) {
        if (!function_exists('wc_get_order')) {
            return;
        }

        $wc_order = wc_get_order($order_id);
        if (!$wc_order) {
            return;
        }

        // Fehlt die Rechnungsadresse (typisch fuer Express-Bestellungen), aus den
        // im Fast-Checkout erfassten Kundendaten nachtragen -> Fulfillment + Mails.
        // Bewusst VOR dem is_paid-Guard: falls die Rueckkehr-Seite die Bestellung
        // bereits auf processing gesetzt hat, geht die Adresse sonst verloren.
        if (!$wc_order->get_billing_email() && !empty($customer)) {
            $this->apply_customer_address($wc_order, $customer);
            $wc_order->save();
        }

        // Idempotenz: bereits bezahlt -> nichts weiter tun.
        if ($wc_order->is_paid()) {
            return;
        }

        // Nur aus offenen Zuständen heraus abschließen.
        if (!in_array($wc_order->get_status(), ['pending', 'on-hold', 'failed'], true)) {
            return;
        }

        // Loyalty-Rabatt (Treuepunkte-Gutschein aus dem EasyCheckout-Checkout) als
        // negative Position nachtragen, damit das WooCommerce-Total dem tatsächlich
        // gezahlten Betrag entspricht. Einmalig (Meta-Guard gegen doppelte Webhooks).
        $discount = isset($order['discount']) ? (float) $order['discount'] : 0;
        if ($discount > 0 && !$wc_order->get_meta('_easycheckout_loyalty_discount')) {
            $code = isset($order['voucherCode']) ? sanitize_text_field($order['voucherCode']) : '';
            $fee = new \WC_Order_Item_Fee();
            $fee->set_name(trim(__('Treuepunkte-Gutschein', 'easycheckout') . ($code ? " ($code)" : '')));
            $fee->set_amount(-$discount);
            $fee->set_total(-$discount);
            $fee->set_tax_status('none');
            $wc_order->add_item($fee);
            $wc_order->update_meta_data('_easycheckout_loyalty_discount', $discount);
            $wc_order->calculate_totals(false);
            $wc_order->save();
        }

        $transaction_id = $order['paymentIntentId'] ?? $order['transactionId'] ?? $ec_order_id;

        $wc_order->add_order_note(sprintf(
            /* translators: %s: transaction/order id */
            __('Zahlung via EasyCheckout bestätigt (Referenz: %s).', 'easycheckout'),
            $transaction_id
        ));

        if (!empty($transaction_id)) {
            $wc_order->set_transaction_id($transaction_id);
        }

        // Bezahlt markieren: bucht Bestand (falls noch nicht), leert Cart, setzt
        // processing/completed nach WooCommerce-Logik.
        $wc_order->payment_complete($transaction_id);

        do_action('easycheckout_wc_order_completed', $order_id, $order);
    }

    /**
     * Kundendaten aus dem Webhook (Fast-Checkout) auf Billing/Shipping der
     * Bestellung schreiben. Der Name wird nach bestem Wissen in Vor-/Nachname
     * geteilt.
     *
     * @param \WC_Order $wc_order
     * @param array     $customer { email, name, phone, company, address:{street,postalCode,city,country} }
     */
    private function apply_customer_address($wc_order, $customer) {
        $email = isset($customer['email']) ? sanitize_email($customer['email']) : '';
        $name = isset($customer['name']) ? trim((string) $customer['name']) : '';
        $phone = isset($customer['phone']) ? (string) $customer['phone'] : '';
        $company = isset($customer['company']) ? (string) $customer['company'] : '';

        $addr = isset($customer['address']) && is_array($customer['address']) ? $customer['address'] : [];
        $street = (string) ($addr['street'] ?? '');
        $postal = (string) ($addr['postalCode'] ?? '');
        $city = (string) ($addr['city'] ?? '');
        $country = strtoupper((string) ($addr['country'] ?? 'CH'));

        // Name aufteilen (letztes Wort = Nachname).
        $first = $name;
        $last = '';
        if ($name !== '' && strpos($name, ' ') !== false) {
            $parts = preg_split('/\s+/', $name);
            $last = array_pop($parts);
            $first = implode(' ', $parts);
        }

        if ($email) {
            $wc_order->set_billing_email($email);
        }
        if ($first !== '') {
            $wc_order->set_billing_first_name($first);
            $wc_order->set_shipping_first_name($first);
        }
        if ($last !== '') {
            $wc_order->set_billing_last_name($last);
            $wc_order->set_shipping_last_name($last);
        }
        if ($company !== '') {
            $wc_order->set_billing_company($company);
            $wc_order->set_shipping_company($company);
        }
        if ($phone !== '') {
            $wc_order->set_billing_phone($phone);
        }
        if ($street !== '') {
            $wc_order->set_billing_address_1($street);
            $wc_order->set_shipping_address_1($street);
        }
        if ($postal !== '') {
            $wc_order->set_billing_postcode($postal);
            $wc_order->set_shipping_postcode($postal);
        }
        if ($city !== '') {
            $wc_order->set_billing_city($city);
            $wc_order->set_shipping_city($city);
        }
        if ($country !== '') {
            $wc_order->set_billing_country($country);
            $wc_order->set_shipping_country($country);
        }
    }

    /**
     * Mark WooCommerce order as failed
     *
     * @param int   $order_id
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

        if ($order->is_paid()) {
            // Bereits bezahlt -> ein verspätetes failed ignorieren.
            return;
        }

        $error_message = $data['error'] ?? $data['errorMessage'] ?? __('Zahlung fehlgeschlagen', 'easycheckout');

        $order->add_order_note(sprintf(
            /* translators: %s: error message */
            __('Zahlung via EasyCheckout fehlgeschlagen: %s', 'easycheckout'),
            $error_message
        ));

        $order->update_status('failed', __('Zahlung fehlgeschlagen.', 'easycheckout'));

        do_action('easycheckout_wc_order_failed', $order_id, $data);
    }

    /**
     * Process WooCommerce refund
     *
     * @param int   $order_id
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

        // Bereits (voll) erstattet? Nicht doppelt erstatten.
        $already_refunded = (float) $order->get_total_refunded();
        $order_total = (float) $order->get_total();
        if ($already_refunded >= $order_total && $order_total > 0) {
            return;
        }

        // Betrag begrenzen auf den noch nicht erstatteten Rest.
        $remaining = max(0, $order_total - $already_refunded);
        if ($amount <= 0 || $amount > $remaining) {
            $amount = $remaining;
        }
        if ($amount <= 0) {
            return;
        }

        $refund = wc_create_refund([
            'amount' => $amount,
            'reason' => $data['refundReason'] ?? __('Rückerstattung via EasyCheckout', 'easycheckout'),
            'order_id' => $order_id,
            'refund_payment' => false, // Erstattung erfolgte bereits über EasyCheckout/Stripe.
        ]);

        if (is_wp_error($refund)) {
            $this->log('Failed to create WC refund: ' . $refund->get_error_message(), 'error');
            return;
        }

        $order->add_order_note(sprintf(
            /* translators: %s: refunded amount */
            __('Rückerstattung von %s via EasyCheckout verbucht.', 'easycheckout'),
            wc_price($amount, ['currency' => $order->get_currency()])
        ));

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
