<?php
/**
 * EasyCheckout – „Sofort kaufen" auf Produktseiten
 *
 * Fügt einen Direkt-Kauf-Button auf der Einzelprodukt-Seite hinzu. Klick →
 * es wird sofort eine WooCommerce-Bestellung für genau dieses Produkt (inkl.
 * gewählter Variante/Menge) angelegt und zur VOLLEN EasyCheckout-Kasse
 * (/pay mit Positionen) weitergeleitet. Nach Zahlung schliesst der
 * order.paid-Webhook die WooCommerce-Bestellung ab (Lager, Mails).
 *
 * @package EasyCheckout\WooCommerce
 */

namespace EasyCheckout\WooCommerce;

use EasyCheckout\EasyCheckout;
use EasyCheckout\API_Client;

defined('ABSPATH') || exit;

class WC_BuyNow {

    /** @var API_Client */
    private $api;

    public function __construct() {
        $this->api = new API_Client();
        if (!$this->is_enabled()) {
            return;
        }
        add_action('woocommerce_after_add_to_cart_button', [$this, 'render_button'], 20);
        add_action('wp_ajax_easycheckout_buynow', [$this, 'ajax_buynow']);
        add_action('wp_ajax_nopriv_easycheckout_buynow', [$this, 'ajax_buynow']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue']);
    }

    /**
     * Aktiv, wenn Gateway an + Sofort-Kauf-Option an + API konfiguriert.
     */
    private function is_enabled() {
        $settings = get_option('woocommerce_easycheckout_settings', []);
        $gateway_on = is_array($settings) && ($settings['enabled'] ?? 'no') === 'yes';
        $buynow_on  = is_array($settings) && ($settings['buy_now'] ?? 'yes') === 'yes';
        return $gateway_on && $buynow_on && $this->api->is_configured();
    }

    public function enqueue() {
        if (!function_exists('is_product') || !is_product()) {
            return;
        }
        wp_enqueue_script(
            'easycheckout-buynow',
            EASYCHECKOUT_PLUGIN_URL . 'assets/js/buy-now.js',
            ['jquery'],
            EASYCHECKOUT_VERSION,
            true
        );
        wp_localize_script('easycheckout-buynow', 'easycheckoutBuyNow', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('easycheckout_buynow'),
            'i18n'    => [
                'processing' => __('Einen Moment …', 'easycheckout'),
                'error'      => __('Sofort-Kauf konnte nicht gestartet werden. Bitte erneut versuchen.', 'easycheckout'),
            ],
        ]);
    }

    /**
     * Button unter „In den Warenkorb" rendern.
     */
    public function render_button() {
        global $product;
        if (!$product || !$product->is_purchasable() || !$product->is_in_stock()) {
            return;
        }
        // Produktquelle „EasyCheckout-Checkout": Button verlinkt direkt auf den
        // konfigurierten Checkout (dessen Produkte), nativ auf der eigenen Domain.
        $ec = WC_Session_Builder::ec_checkout_url();
        if ($ec !== '') {
            printf(
                '<a href="%s" class="button alt easycheckout-buynow-link" style="margin-top:10px;display:block;text-align:center;width:100%%;box-sizing:border-box;">%s</a>',
                esc_url($ec),
                esc_html__('Zum Checkout', 'easycheckout')
            );
            return;
        }
        // Klassen 1:1 vom Shop-Warenkorb-Button („In den Warenkorb") übernehmen,
        // damit der Sofort-kaufen-Button im jeweiligen Theme IDENTISCH aussieht
        // (viele Themes stylen gezielt .single_add_to_cart_button, nicht .button).
        // Bei Produktvarianten schaltet das WooCommerce-Varianten-Script beide
        // Buttons synchron frei/aus. Feinjustage über .easycheckout-buynow-btn
        // im Custom-CSS möglich. type=button -> löst kein Formular-Submit aus.
        // wp-element-button: Block-Themes (z. B. Twenty Twenty-Four) geben die
        // gefüllte Button-Optik über DIESE Klasse, nicht über .single_add_to_cart_button.
        // Für klassische Themes (Storefront etc.) ist sie wirkungslos -> beide abgedeckt.
        printf(
            '<button type="button" class="single_add_to_cart_button button alt wp-element-button easycheckout-buynow-btn" id="easycheckout-buynow-btn" data-product-id="%d" style="margin-top:10px;width:100%%;">%s</button>',
            (int) $product->get_id(),
            esc_html__('Sofort kaufen', 'easycheckout')
        );
    }

    /**
     * AJAX: Einzelprodukt-Bestellung anlegen + Bezahl-URL liefern.
     */
    public function ajax_buynow() {
        check_ajax_referer('easycheckout_buynow', 'nonce');

        $product_id   = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
        $quantity     = isset($_POST['quantity']) ? max(1, absint($_POST['quantity'])) : 1;
        $variation    = (isset($_POST['variation']) && is_array($_POST['variation']))
            ? wc_clean(wp_unslash($_POST['variation']))
            : [];

        $product = wc_get_product($variation_id ?: $product_id);
        if (!$product || !$product->is_purchasable()) {
            wp_send_json_error(['message' => __('Produkt nicht verfügbar.', 'easycheckout')]);
        }

        try {
            $order = wc_create_order(['status' => 'pending', 'created_via' => 'easycheckout_buynow']);
            if (is_wp_error($order)) {
                throw new \Exception($order->get_error_message());
            }
            $order->add_product($product, $quantity, ['variation' => $variation]);
            $order->set_payment_method('easycheckout');
            $order->set_payment_method_title(__('EasyCheckout', 'easycheckout'));
            $order->set_currency(get_woocommerce_currency());
            $order->calculate_totals(true);
            $order->save();
        } catch (\Exception $e) {
            $this->log('Buy-now order creation failed: ' . $e->getMessage(), 'error');
            wp_send_json_error(['message' => __('Bestellung konnte nicht erstellt werden.', 'easycheckout')]);
        }

        $order_id = $order->get_id();
        $success_url = add_query_arg([
            'wc-api' => 'easycheckout', 'order_id' => $order_id, 'key' => $order->get_order_key(), 'status' => 'success',
        ], home_url('/'));
        $cancel_url = add_query_arg([
            'wc-api' => 'easycheckout_cancel', 'order_id' => $order_id, 'key' => $order->get_order_key(),
        ], home_url('/'));

        $session_data = WC_Session_Builder::build($order, $success_url, $cancel_url, 'woocommerce_buynow');
        $response = $this->api->create_payment_session($session_data);

        if (is_wp_error($response)) {
            $this->log('Buy-now payment session failed: ' . $response->get_error_message(), 'error');
            $order->update_status('failed', __('EasyCheckout payment session konnte nicht erstellt werden.', 'easycheckout'));
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $data        = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : $response;
        $redirect    = WC_Session_Builder::dispatch_redirect($data['payment_url'] ?? '');
        $ec_order_id = $data['order_id'] ?? '';

        if (empty($redirect)) {
            $order->update_status('failed', __('Keine Bezahl-URL von EasyCheckout erhalten.', 'easycheckout'));
            wp_send_json_error(['message' => __('Zahlung konnte nicht gestartet werden.', 'easycheckout')]);
        }

        $order->update_meta_data('_easycheckout_order_id', $ec_order_id);
        $order->add_order_note(__('Sofort-Kauf gestartet – warte auf Zahlung via EasyCheckout.', 'easycheckout'));
        $order->save();

        EasyCheckout::instance()->log_transaction([
            'wc_order_id' => $order_id,
            'ec_order_id' => $ec_order_id,
            'status'      => 'pending',
            'amount'      => $order->get_total(),
            'currency'    => $order->get_currency(),
        ]);

        wp_send_json_success(['redirect' => $redirect]);
    }

    private function log($message, $level = 'info') {
        EasyCheckout::instance()->log('[WC BuyNow] ' . $message, $level);
    }
}
