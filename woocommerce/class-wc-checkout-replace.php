<?php
/**
 * EasyCheckout – WooCommerce-Kasse durch die EasyCheckout-Kasse ersetzen.
 *
 * Wenn aktiviert (Opt-in), wird beim Aufruf der WooCommerce-Kasse aus dem
 * aktuellen Warenkorb eine Bestellung erzeugt und direkt zur VOLLEN
 * EasyCheckout-Kasse (/pay mit Positionen) weitergeleitet – die EasyCheckout-
 * Seite erfasst Adresse und Zahlung. Ein Session-Cache verhindert doppelte
 * Bestellungen bei Reload/Zurück (gleicher Warenkorb -> gleiche Bezahl-URL).
 *
 * Fällt bei Fehlern still auf die normale WooCommerce-Kasse zurück.
 *
 * @package EasyCheckout\WooCommerce
 */

namespace EasyCheckout\WooCommerce;

use EasyCheckout\EasyCheckout;
use EasyCheckout\API_Client;

defined('ABSPATH') || exit;

class WC_Checkout_Replace {

    /** @var API_Client */
    private $api;

    public function __construct() {
        $this->api = new API_Client();
        if (!$this->is_enabled()) {
            return;
        }
        add_action('template_redirect', [$this, 'maybe_redirect'], 20);
    }

    /**
     * Aktiv nur wenn Gateway an + Ersatz-Option an (Opt-in, Standard AUS) + API konfiguriert.
     */
    private function is_enabled() {
        $s = get_option('woocommerce_easycheckout_settings', []);
        $gateway_on = is_array($s) && ($s['enabled'] ?? 'no') === 'yes';
        $replace_on = is_array($s) && ($s['replace_checkout'] ?? 'no') === 'yes';
        return $gateway_on && $replace_on && $this->api->is_configured();
    }

    /**
     * Auf der Kassenseite: Bestellung aus Warenkorb bauen + zur EC-Kasse leiten.
     */
    public function maybe_redirect() {
        if (!function_exists('is_checkout') || !is_checkout()) {
            return;
        }
        // Bestell-Bestätigung / Zahlungsseite bestehender Bestellungen nie abfangen.
        if (function_exists('is_wc_endpoint_url') &&
            (is_wc_endpoint_url('order-received') || is_wc_endpoint_url('order-pay'))) {
            return;
        }

        // Produktquelle „EasyCheckout-Checkout": direkt zum konfigurierten Checkout.
        $ec = WC_Session_Builder::ec_checkout_url();
        if ($ec !== '') {
            wp_redirect($ec);
            exit;
        }

        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        // Rückkehr nach Abbruch: normale Kasse zeigen, nicht sofort wieder wegleiten.
        if (isset($_GET['ec_cancelled'])) {
            return;
        }

        WC()->cart->calculate_totals();
        $hash = md5(WC()->cart->get_cart_hash() . '|' . WC()->cart->get_total('edit'));

        // Cache: gleicher Warenkorb -> bereits erstellte Bezahl-URL wiederverwenden
        // (keine Doppel-Bestellung bei Reload/Zurück).
        $cached = WC()->session ? WC()->session->get('ec_replace_session') : null;
        if (is_array($cached) && ($cached['hash'] ?? '') === $hash && !empty($cached['url'])) {
            wp_redirect($cached['url']);
            exit;
        }

        try {
            $order = WC_Cart_Order::from_cart('easycheckout');
        } catch (\Exception $e) {
            $this->log('Checkout-replace order failed: ' . $e->getMessage(), 'error');
            return; // Fallback: normale WC-Kasse.
        }

        $order_id = $order->get_id();
        $success_url = add_query_arg([
            'wc-api' => 'easycheckout', 'order_id' => $order_id, 'key' => $order->get_order_key(), 'status' => 'success',
        ], home_url('/'));
        $cancel_url = add_query_arg(['ec_cancelled' => '1'], wc_get_checkout_url());

        $session_data = WC_Session_Builder::build($order, $success_url, $cancel_url, 'woocommerce');
        $response = $this->api->create_payment_session($session_data);

        if (is_wp_error($response)) {
            $this->log('Checkout-replace session failed: ' . $response->get_error_message(), 'error');
            $order->update_status('failed', __('EasyCheckout payment session konnte nicht erstellt werden.', 'easycheckout'));
            return; // Fallback: normale WC-Kasse.
        }

        $data        = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : $response;
        $redirect    = WC_Session_Builder::dispatch_redirect($data['payment_url'] ?? '');
        $ec_order_id = $data['order_id'] ?? '';

        if (empty($redirect)) {
            $order->update_status('failed', __('Keine Bezahl-URL von EasyCheckout erhalten.', 'easycheckout'));
            return;
        }

        $order->update_meta_data('_easycheckout_order_id', $ec_order_id);
        $order->add_order_note(__('Kasse via EasyCheckout gestartet – warte auf Zahlung.', 'easycheckout'));
        $order->save();

        if (WC()->session) {
            WC()->session->set('ec_replace_session', ['hash' => $hash, 'url' => $redirect, 'order_id' => $order_id]);
        }

        EasyCheckout::instance()->log_transaction([
            'wc_order_id' => $order_id,
            'ec_order_id' => $ec_order_id,
            'status'      => 'pending',
            'amount'      => $order->get_total(),
            'currency'    => $order->get_currency(),
        ]);

        wp_redirect($redirect);
        exit;
    }

    private function log($message, $level = 'info') {
        EasyCheckout::instance()->log('[WC Checkout-Replace] ' . $message, $level);
    }
}
