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
        ?>
        <div class="easycheckout-express-wrap" style="margin-bottom:12px;">
            <button type="button"
                    id="easycheckout-express-btn"
                    class="button alt easycheckout-express-btn"
                    style="width:100%;text-align:center;">
                <?php esc_html_e('Express-Checkout', 'easycheckout'); ?>
            </button>
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
            $order = $this->create_order_from_cart();
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

        $session_data = [
            'amount'      => (int) round((float) $order->get_total() * 100),
            'currency'    => $order->get_currency(),
            'success_url' => $success_url,
            'cancel_url'  => $cancel_url,
            'description' => sprintf(__('Bestellung %s', 'easycheckout'), $order->get_order_number()),
            'metadata'    => [
                'wc_order_id'  => (string) $order_id,
                'wc_order_key' => $order->get_order_key(),
                'source'       => 'woocommerce_express',
            ],
        ];

        $response = $this->api->create_payment_session($session_data);

        if (is_wp_error($response)) {
            $this->log('Express payment session failed: ' . $response->get_error_message(), 'error');
            // Bestellung nicht als Leiche zuruecklassen.
            $order->update_status('failed', __('EasyCheckout payment session konnte nicht erstellt werden.', 'easycheckout'));
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $data = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;
        $redirect_url = $data['payment_url'] ?? '';
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
     * WooCommerce-Bestellung aus dem aktuellen Warenkorb aufbauen.
     *
     * Uebernimmt Positionen inkl. Steuern, Versand, Gebuehren und Gutscheine aus
     * dem Cart und berechnet die Summen. Adresse bleibt zunaechst leer und wird
     * nach der Zahlung aus dem Fast-Checkout per Webhook nachgetragen.
     *
     * @return \WC_Order|null
     * @throws \Exception
     */
    private function create_order_from_cart() {
        $cart = WC()->cart;

        $order = wc_create_order([
            'status'      => 'pending',
            'created_via' => 'easycheckout_express',
            'customer_id' => get_current_user_id(),
        ]);

        if (is_wp_error($order)) {
            throw new \Exception($order->get_error_message());
        }

        // Positionen.
        foreach ($cart->get_cart() as $cart_item_key => $values) {
            $product = $values['data'];
            if (!$product) {
                continue;
            }
            $item_id = $order->add_product($product, $values['quantity'], [
                'subtotal' => $values['line_subtotal'],
                'total'    => $values['line_total'],
                'taxes'    => [
                    'subtotal' => $values['line_tax_data']['subtotal'] ?? [],
                    'total'    => $values['line_tax_data']['total'] ?? [],
                ],
            ]);
            if (!$item_id) {
                throw new \Exception('add_product failed');
            }
        }

        // Versand aus den gewaehlten Methoden.
        $this->add_shipping_lines($order, $cart);

        // Gebuehren.
        foreach ($cart->get_fees() as $fee) {
            $item = new \WC_Order_Item_Fee();
            $item->set_name($fee->name);
            $item->set_amount($fee->amount);
            $item->set_total($fee->total);
            $item->set_tax_class($fee->tax_class ?? '');
            if (isset($fee->tax_data)) {
                $item->set_taxes(['total' => $fee->tax_data]);
            }
            $order->add_item($item);
        }

        // Gutscheine.
        if (method_exists($cart, 'get_applied_coupons')) {
            foreach ($cart->get_applied_coupons() as $code) {
                $order->apply_coupon($code);
            }
        }

        $order->set_payment_method('easycheckout');
        $order->set_payment_method_title(__('EasyCheckout (Express)', 'easycheckout'));
        $order->set_currency(get_woocommerce_currency());

        // Bei eingeloggtem Kunden Adresse vorbefuellen (spart Tippen im Fast-Checkout).
        if (is_user_logged_in()) {
            $customer = new \WC_Customer(get_current_user_id());
            $order->set_address($customer->get_billing(), 'billing');
            $order->set_address($customer->get_shipping(), 'shipping');
        }

        $order->calculate_totals(false); // Steuern aus den Positionen behalten.
        $order->save();

        return $order;
    }

    /**
     * Versandzeilen aus den gewaehlten Versandmethoden des Warenkorbs uebernehmen.
     *
     * @param \WC_Order $order
     * @param \WC_Cart  $cart
     */
    private function add_shipping_lines($order, $cart) {
        if (!$cart->needs_shipping() || !function_exists('WC')) {
            return;
        }

        $packages = WC()->shipping() ? WC()->shipping()->get_packages() : [];
        $chosen_methods = WC()->session ? WC()->session->get('chosen_shipping_methods', []) : [];

        foreach ($packages as $package_key => $package) {
            $chosen_id = $chosen_methods[$package_key] ?? '';
            if (!$chosen_id || empty($package['rates'][$chosen_id])) {
                continue;
            }
            $rate = $package['rates'][$chosen_id];

            $item = new \WC_Order_Item_Shipping();
            $item->set_method_title($rate->get_label());
            $item->set_method_id($rate->get_method_id());
            $item->set_instance_id($rate->get_instance_id());
            $item->set_total($rate->get_cost());
            $item->set_taxes(['total' => $rate->get_taxes()]);
            $order->add_item($item);
        }
    }

    /**
     * @param string $message
     * @param string $level
     */
    private function log($message, $level = 'info') {
        EasyCheckout::instance()->log('[WC Express] ' . $message, $level);
    }
}
