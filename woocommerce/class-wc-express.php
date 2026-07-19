<?php
/**
 * EasyCheckout Express-Checkout for WooCommerce
 *
 * Zusaetzlich zum regulaeren Zahlungs-Gateway (das den langsamen WooCommerce-
 * Checkout durchlaeuft) bietet der Express-Checkout einen Direkt-Button im
 * Warenkorb: er erzeugt aus dem aktuellen Warenkorb sofort eine WooCommerce-
 * Bestellung und leitet zum schnellen EasyCheckout-Bezahlformular (/pay/{id})
 * weiter, wo Adresse und Zahlung in einem Schritt erfasst werden. Die auf dem
 * Fast-Checkout erfassten Kundendaten kommen per order.paid-Webhook zurueck und
 * werden auf die Bestellung geschrieben (Billing/Shipping) -> Fulfillment.
 *
 * @package EasyCheckout\WooCommerce
 */

namespace EasyCheckout\WooCommerce;

use EasyCheckout\EasyCheckout;
use EasyCheckout\API_Client;

defined('ABSPATH') || exit;

/**
 * Express-Checkout button + order creation from cart.
 */
class WC_Express {

    /**
     * @var API_Client
     */
    private $api;

    public function __construct() {
        $this->api = new API_Client();

        // Nur aktiv, wenn im Gateway aktiviert UND ein API-Key konfiguriert ist.
        if (!$this->is_enabled()) {
            return;
        }

        // Button im Warenkorb (ueber dem regulaeren "Weiter zur Kasse").
        add_action('woocommerce_proceed_to_checkout', [$this, 'render_cart_button'], 5);

        // AJAX: Express-Bestellung anlegen + Bezahl-URL liefern.
        add_action('wp_ajax_easycheckout_express', [$this, 'ajax_create_express_order']);
        add_action('wp_ajax_nopriv_easycheckout_express', [$this, 'ajax_create_express_order']);

        // Assets im Warenkorb laden.
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Express-Checkout aktiviert? (Gateway-Option + API-Key).
     *
     * @return bool
     */
    private function is_enabled() {
        $settings = get_option('woocommerce_easycheckout_settings', []);
        $gateway_on = is_array($settings) && ($settings['enabled'] ?? 'no') === 'yes';
        $express_on = is_array($settings) && ($settings['express_checkout'] ?? 'yes') === 'yes';
        return $gateway_on && $express_on && $this->api->is_configured();
    }

    /**
     * Enqueue Express-Assets auf der Warenkorb-Seite.
     */
    public function enqueue() {
        if (!function_exists('is_cart') || !is_cart()) {
            return;
        }

        wp_enqueue_script(
            'easycheckout-express',
            EASYCHECKOUT_PLUGIN_URL . 'assets/js/express-checkout.js',
            ['jquery'],
            EASYCHECKOUT_VERSION,
            true
        );

        wp_localize_script('easycheckout-express', 'easycheckoutExpress', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('easycheckout_express'),
            'i18n'    => [
                'processing' => __('Einen Moment …', 'easycheckout'),
                'error'      => __('Express-Checkout konnte nicht gestartet werden. Bitte erneut versuchen.', 'easycheckout'),
            ],
        ]);
    }

    /**
     * Express-Button im Warenkorb rendern.
     */
    public function render_cart_button() {
        $ec = WC_Session_Builder::ec_checkout_url();
        ?>
        <div class="easycheckout-express-wrap" style="margin-bottom:12px;">
            <?php if ($ec !== '') : ?>
            <a href="<?php echo esc_url($ec); ?>" class="button alt easycheckout-express-btn"
               style="width:100%;text-align:center;display:block;box-sizing:border-box;">
                <?php esc_html_e('Zum Checkout', 'easycheckout'); ?>
            </a>
            <?php else : ?>
            <button type="button"
                    id="easycheckout-express-btn"
                    class="button alt easycheckout-express-btn"
                    style="width:100%;text-align:center;">
                <?php esc_html_e('Express-Checkout', 'easycheckout'); ?>
            </button>
            <?php endif; ?>
            <p class="easycheckout-express-hint" style="margin:6px 0 0;font-size:12px;color:#666;text-align:center;">
                <?php esc_html_e('Schnell bezahlen – Adresse und Zahlung in einem Schritt.', 'easycheckout'); ?>
            </p>
        </div>
        <?php
    }

    /**
     * AJAX: Aus dem aktuellen Warenkorb eine WooCommerce-Bestellung erstellen,
     * eine EasyCheckout payment-session anlegen und die Bezahl-URL zurueckgeben.
     */
    public function ajax_create_express_order() {
        check_ajax_referer('easycheckout_express', 'nonce');

        if (!function_exists('WC') || WC()->cart === null || WC()->cart->is_empty()) {
            wp_send_json_error(['message' => __('Der Warenkorb ist leer.', 'easycheckout')]);
        }

        // Totals frisch berechnen, damit Positionen/Versand/Gutscheine stimmen.
        WC()->cart->calculate_totals();

        try {
            $order = WC_Cart_Order::from_cart('easycheckout_express');
        } catch (\Exception $e) {
            $this->log('Express order creation failed: ' . $e->getMessage(), 'error');
            wp_send_json_error(['message' => __('Bestellung konnte nicht erstellt werden.', 'easycheckout')]);
        }

        if (!$order) {
            wp_send_json_error(['message' => __('Bestellung konnte nicht erstellt werden.', 'easycheckout')]);
        }

        $order_id = $order->get_id();

        $success_url = add_query_arg([
            'wc-api'   => 'easycheckout',
            'order_id' => $order_id,
            'key'      => $order->get_order_key(),
            'status'   => 'success',
        ], home_url('/'));

        $cancel_url = add_query_arg([
            'wc-api'   => 'easycheckout_cancel',
            'order_id' => $order_id,
            'key'      => $order->get_order_key(),
        ], home_url('/'));

        // Volle EasyCheckout-Kasse mit Positionen + Fulfillment aus dem Warenkorb.
        $session_data = WC_Session_Builder::build($order, $success_url, $cancel_url, 'woocommerce_express');

        $response = $this->api->create_payment_session($session_data);

        if (is_wp_error($response)) {
            $this->log('Express payment session failed: ' . $response->get_error_message(), 'error');
            // Bestellung nicht als Leiche zuruecklassen.
            $order->update_status('failed', __('EasyCheckout payment session konnte nicht erstellt werden.', 'easycheckout'));
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
        $redirect_url = WC_Session_Builder::dispatch_redirect($data['payment_url'] ?? '');
        $ec_order_id  = $data['order_id'] ?? '';

        if (empty($redirect_url)) {
            $order->update_status('failed', __('Keine Bezahl-URL von EasyCheckout erhalten.', 'easycheckout'));
            wp_send_json_error(['message' => __('Zahlung konnte nicht gestartet werden.', 'easycheckout')]);
        }

        // EC-Order-ID fuer Webhook-Matching hinterlegen.
        $order->update_meta_data('_easycheckout_order_id', $ec_order_id);
        $order->add_order_note(__('Express-Checkout gestartet – warte auf Zahlung via EasyCheckout.', 'easycheckout'));
        $order->save();

        // Transaktion protokollieren (wie im Gateway).
        $plugin = EasyCheckout::instance();
        $plugin->log_transaction([
            'wc_order_id'    => $order_id,
            'ec_order_id'    => $ec_order_id,
            'status'         => 'pending',
            'amount'         => $order->get_total(),
            'currency'       => $order->get_currency(),
        ]);

        wp_send_json_success(['redirect' => $redirect_url]);
    }

    /**
     * @param string $message
     * @param string $level
     */
    private function log($message, $level = 'info') {
        EasyCheckout::instance()->log('[WC Express] ' . $message, $level);
    }
}
