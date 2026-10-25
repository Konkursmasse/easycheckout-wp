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
        // EasyCheckout-Emblem neben dem Zahlungsart-Namen (Checkout + Admin-Liste).
        $this->icon = apply_filters('easycheckout_gateway_icon', EASYCHECKOUT_PLUGIN_URL . 'assets/images/easycheckout-logo.png');
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
     * Gateway-Icon (EasyCheckout-Emblem) mit fester Anzeigehoehe, damit es in
     * jedem Theme sauber neben dem Zahlungsart-Namen erscheint.
     *
     * @return string
     */
    public function get_icon() {
        if (empty($this->icon)) {
            return apply_filters('woocommerce_gateway_icon', '', $this->id);
        }
        $html = '<img src="' . esc_url($this->icon) . '" alt="' . esc_attr($this->get_title())
            . '" style="max-height:24px;width:auto;vertical-align:middle;margin-left:6px;display:inline-block;" />';
        return apply_filters('woocommerce_gateway_icon', $html, $this->id);
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
            'product_source' => [
                'title' => __('Produktquelle', 'easycheckout'),
                'type' => 'select',
                'description' => __('„WooCommerce-Warenkorb": die im Shop gewählten Artikel werden verarbeitet. „EasyCheckout-Checkout": es wird dein im EasyCheckout hinterlegter Checkout (mit dessen Produkten) geöffnet – dazu unten den Slug angeben.', 'easycheckout'),
                'default' => 'woo_cart',
                'options' => [
                    'woo_cart'    => __('WooCommerce-Warenkorb', 'easycheckout'),
                    'ec_checkout' => __('EasyCheckout-Checkout (Slug)', 'easycheckout'),
                ],
                'desc_tip' => true,
            ],
            'checkout_slug' => [
                'title' => __('Checkout Slug (optional)', 'easycheckout'),
                'type' => 'text',
                'description' => __('Nur für Standalone-Nutzung ohne WooCommerce. Im WooCommerce-Betrieb wird immer der WooCommerce-Warenkorb verarbeitet.', 'easycheckout'),
                'default' => '',
                'desc_tip' => true,
            ],
            'express_checkout' => [
                'title' => __('Express-Checkout', 'easycheckout'),
                'type' => 'checkbox',
                'label' => __('Express-Checkout-Button im Warenkorb anzeigen', 'easycheckout'),
                'description' => __('Zeigt zusätzlich zum normalen Checkout einen Direkt-Button im Warenkorb, der Adresse und Zahlung in einem schnellen Schritt erfasst.', 'easycheckout'),
                'default' => 'yes',
                'desc_tip' => true,
            ],
            'buy_now' => [
                'title' => __('Sofort kaufen', 'easycheckout'),
                'type' => 'checkbox',
                'label' => __('„Sofort kaufen"-Button auf Produktseiten anzeigen', 'easycheckout'),
                'description' => __('Zeigt auf jeder Produktseite unter „In den Warenkorb" einen Direkt-Kauf-Button, der sofort zur EasyCheckout-Kasse führt.', 'easycheckout'),
                'default' => 'yes',
                'desc_tip' => true,
            ],
            'replace_checkout' => [
                'title' => __('WooCommerce-Kasse ersetzen', 'easycheckout'),
                'type' => 'checkbox',
                'label' => __('Die WooCommerce-Kasse vollständig durch die EasyCheckout-Kasse ersetzen', 'easycheckout'),
                'description' => __('Beim Aufruf der Kasse wird der Warenkorb direkt an die EasyCheckout-Kasse übergeben (Adresse + Zahlung dort). Die Standard-WooCommerce-Kasse wird übersprungen.', 'easycheckout'),
                'default' => 'no',
                'desc_tip' => true,
            ],
            'checkout_mode' => [
                'title' => __('Darstellung der Kasse', 'easycheckout'),
                'type' => 'select',
                'description' => __('Wie die EasyCheckout-Kasse angezeigt wird, wenn sie die WooCommerce-Kasse ersetzt (oder bei „Sofort kaufen“).', 'easycheckout'),
                'default' => 'inline',
                'options' => [
                    'inline'   => __('Nativ auf Ihrer Website (empfohlen) – kein iFrame, gleiche Domain', 'easycheckout'),
                    'embedded' => __('Eingebettet (iFrame)', 'easycheckout'),
                    'extern'   => __('Weiterleitung zu easycheckout.ch', 'easycheckout'),
                ],
                'desc_tip' => true,
            ],
            'brand_color' => [
                'title' => __('Markenfarbe (Design)', 'easycheckout'),
                'type' => 'text',
                'description' => __('Primärfarbe der Kasse (Buttons/Akzente), z. B. #0891b2 oder #4F46E5. Gilt für die native WooCommerce-Kasse und /pay. Entwickler können sie auch per Filter easycheckout_checkout_color überschreiben.', 'easycheckout'),
                'default' => '#4F46E5',
                'placeholder' => '#4F46E5',
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
            echo wp_kses_post(wpautop(wptexturize($this->description)));
        }

        $payment_methods = $this->get_option('payment_methods', ['card', 'twint']);
        $labels = [];
        foreach ($payment_methods as $method) {
            $labels[] = $this->get_method_label($method);
        }
        ?>
        <div id="easycheckout-payment-info">
            <p class="easycheckout-redirect-notice">
                <?php esc_html_e('Nach dem Absenden der Bestellung wirst du sicher zu EasyCheckout weitergeleitet – dort wählst du deine Zahlungsart und bezahlst.', 'easycheckout'); ?>
            </p>

            <?php if (!empty($labels)) : ?>
            <div class="easycheckout-methods-badges" aria-hidden="true">
                <?php foreach ($labels as $lbl) : ?>
                    <span class="easycheckout-method-badge"><?php echo esc_html($lbl); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

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

        // Produktquelle „EasyCheckout-Checkout": zum konfigurierten Checkout leiten
        // (dessen Produkte), statt den WooCommerce-Warenkorb zu verarbeiten.
        $ec = WC_Session_Builder::ec_checkout_url();
        if ($ec !== '') {
            return ['result' => 'success', 'redirect' => $ec];
        }

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

        // Volle EasyCheckout-Kasse: Session mit strukturierten Positionen +
        // Kundendaten + Fulfillment aus der WC-Bestellung. So zeigt /pay die
        // komplette Bestellübersicht (Artikel, MwSt, Versand) statt nur eines
        // Betrags. Der belastete Betrag bleibt das WC-Total.
        $session_data = WC_Session_Builder::build($order, $success_url, $cancel_url, 'woocommerce');

        $response = $this->api->create_payment_session($session_data);

        if (is_wp_error($response)) {
            $this->log('Payment session creation failed: ' . $response->get_error_message(), 'error');
            wc_add_notice($response->get_error_message(), 'error');
            return ['result' => 'failure'];
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;

        $redirect_url = WC_Session_Builder::dispatch_redirect($data['payment_url'] ?? '');
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
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        $ec_order_id = isset($_GET['orderId']) ? sanitize_text_field(wp_unslash($_GET['orderId'])) : '';
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';

        if (!$order_id) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Die EasyCheckout-Bestell-ID wird beim Anlegen der Zahlung gespeichert.
        // Aus der Rückkehr-URL nur übernehmen, wenn noch keine da ist — sonst
        // könnte jemand per URL eine fremde, bezahlte Bestellung verknüpfen.
        if ($ec_order_id && !$order->get_meta('_easycheckout_order_id')) {
            $order->update_meta_data('_easycheckout_order_id', $ec_order_id);
            $order->save();
        }

        // Die Rückkehr-URL ist vom Käufer veränderbar und beweist KEINE Zahlung.
        // Bezahlt markiert nur der signierte order.paid-Webhook (payment_complete():
        // Status, Bestand, Mails); bis dahin bleibt die Bestellung „Zahlung
        // ausstehend". Kein Wechsel auf „Wartestellung", der dem Kunden eine
        // zusätzliche WooCommerce-Mail schicken würde.
        $is_express = $order->get_created_via() === 'easycheckout_express';
        if (!$is_express && ($status === 'success' || $status === 'paid') && !$order->is_paid()) {
            $order->add_order_note(__('Customer returned from EasyCheckout. Waiting for the payment confirmation (webhook).', 'easycheckout'));
        }

        // Warenkorb leeren: Beim Express umgeht der Flow den WC-Checkout, der den
        // Cart sonst selbst leert -> hier nachholen, damit die bereits als Bestellung
        // erfassten Positionen nicht doppelt bestellt werden. (Beim regulaeren
        // Gateway hat WC-Core den Cart schon geleert; erneutes Leeren ist harmlos.)
        if ($is_express && ($status === 'success' || $status === 'paid') && function_exists('WC') && WC()->cart) {
            WC()->cart->empty_cart();
        }

        // Redirect to thank you page
        wp_safe_redirect($this->get_return_url($order));
        exit;
    }

    /**
     * Handle return from payment (cancel)
     */
    public function handle_cancel() {
        $order_id = isset($_GET['order_id']) ? absint(wp_unslash($_GET['order_id'])) : 0;
        $order_key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';

        if (!$order_id) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        $order = wc_get_order($order_id);

        if (!$order || $order->get_order_key() !== $order_key) {
            wp_safe_redirect(wc_get_checkout_url());
            exit;
        }

        // Restore stock
        wc_increase_stock_levels($order_id);

        // Update order status
        $order->update_status('cancelled', __('Payment cancelled by customer.', 'easycheckout'));

        // Add notice
        wc_add_notice(__('Payment was cancelled. Please try again.', 'easycheckout'), 'notice');

        // Redirect back to checkout
        wp_safe_redirect(wc_get_checkout_url());
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
