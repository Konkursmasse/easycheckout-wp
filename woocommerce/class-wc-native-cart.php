<?php
/**
 * EasyCheckout – cart-getriebene native WooCommerce-Kasse (?ec_wccart).
 *
 * Rendert eine native EasyCheckout-Kasse auf der eigenen Domain, die DIREKT den
 * LIVE-WooCommerce-Warenkorb abbildet – inkl. Mengen-Stepper. Änderungen gehen
 * über WC-AJAX in den Warenkorb (Quelle der Wahrheit), Total rechnet neu. Beim
 * Bezahlen wird aus dem finalen Warenkorb eine WooCommerce-Bestellung + eine
 * EasyCheckout-Zahlsession erzeugt und die Zahlung white-label abgewickelt.
 *
 * @package EasyCheckout\WooCommerce
 */

namespace EasyCheckout\WooCommerce;

use EasyCheckout\EasyCheckout;
use EasyCheckout\API_Client;

defined('ABSPATH') || exit;

class WC_Native_Cart {

    /** @var API_Client */
    private $api;

    public function __construct() {
        $this->api = new API_Client();
        add_action('template_redirect', [$this, 'maybe_render'], 5);
        foreach (['wc_cart_data', 'wc_cart_qty', 'wc_cart_pay'] as $a) {
            add_action('wp_ajax_easycheckout_' . $a, [$this, 'ajax_' . $a]);
            add_action('wp_ajax_nopriv_easycheckout_' . $a, [$this, 'ajax_' . $a]);
        }
    }

    /** Ist die cart-getriebene native Kasse aktiv? (Gateway an + Modus inline + Produktquelle woo_cart). */
    public static function url() {
        $s = get_option('woocommerce_easycheckout_settings', []);
        if (!is_array($s)) { return ''; }
        if (($s['enabled'] ?? 'no') !== 'yes') { return ''; }
        if (($s['product_source'] ?? 'woo_cart') !== 'woo_cart') { return ''; }
        if (WC_Session_Builder::checkout_mode() !== 'inline') { return ''; }
        return home_url('/?ec_wccart=1');
    }

    /** Native Kassen-Seite ausgeben. */
    public function maybe_render() {
        if (empty($_GET['ec_wccart'])) { return; }
        if (!function_exists('WC')) { return; }

        wp_enqueue_style('easycheckout-local-checkout', EASYCHECKOUT_PLUGIN_URL . 'assets/css/local-checkout.css', [], EASYCHECKOUT_VERSION);
        wp_register_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
        wp_register_script('easycheckout-wc-cart', EASYCHECKOUT_PLUGIN_URL . 'assets/js/wc-cart-checkout.js', ['stripe-js'], EASYCHECKOUT_VERSION, true);
        wp_localize_script('easycheckout-wc-cart', 'ecWcCart', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('easycheckout_front'),
            'apiBase'    => rtrim(get_option('easycheckout_api_url', 'https://www.easycheckout.ch'), '/'),
            'cartUrl'    => wc_get_cart_url(),
            'company'    => ($co = \EasyCheckout\Native_Dashboard::get_company()) && !empty($co['name']) ? $co['name'] : get_bloginfo('name'),
            'logo'       => \EasyCheckout\Design::logo_url(),
            'brandColor' => \EasyCheckout\Design::color(),
            'ecIcon'     => EASYCHECKOUT_PLUGIN_URL . 'assets/images/easycheckout-icon.png',
        ]);
        wp_enqueue_script('easycheckout-wc-cart');

        nocache_headers();
        echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">';
        echo '<title>' . esc_html__('Checkout', 'easycheckout') . '</title>';
        // Theme laden (Schriften + Styles), damit die Kasse sich dem Shop-Design anpasst.
        wp_head();
        // Kasse erbt die Theme-Schrift; eigener Hintergrund. Nach wp_head, um zu gewinnen.
        echo '<style>html,body{margin:0;padding:0}#ec-pay-checkout{background:#f8fafc;min-height:100vh;font-family:inherit}'
            . '#ec-pay-checkout input,#ec-pay-checkout select,#ec-pay-checkout button,#ec-pay-checkout .eclc-title,'
            . '#ec-pay-checkout .eclc-col-h,#ec-pay-checkout .eclc-pname{font-family:inherit}</style>';
        // Entwickler-Design: Markenfarbe (--ec-p) + Custom CSS.
        \EasyCheckout\Design::head();
        // WICHTIG: Klasse ec-local-checkout aktiviert den CSS-Scope aus
        // local-checkout.css (box-sizing-Reset + Typo-Basis) — ohne sie ragen
        // die Eingabefelder ueber die Karte hinaus und Theme-Schriftgroessen
        // schlagen ungefiltert durch.
        echo '</head><body class="' . esc_attr(implode(' ', get_body_class('ec-native-checkout'))) . '"><div id="ec-pay-checkout" class="ec-local-checkout"></div>';
        wp_footer();
        echo '</body></html>';
        exit;
    }

    /** Aktuellen Warenkorb als Positionen zurückgeben. */
    private function cart_payload() {
        WC()->cart->calculate_totals();
        $items = [];
        foreach (WC()->cart->get_cart() as $key => $v) {
            $p = $v['data'];
            if (!$p) { continue; }
            $qty = (int) $v['quantity'];
            $net = (float) $v['line_total'];
            $tax = (float) $v['line_tax'];
            $gross = $net + $tax;
            $unit = $qty > 0 ? round($gross / $qty, 2) : $gross;
            $rate = ($net > 0 && $tax > 0) ? round($tax / $net * 100, 1) : null;
            $img = $p->get_image_id() ? wp_get_attachment_image_url($p->get_image_id(), 'thumbnail') : '';
            $items[] = [
                'key'         => $key,
                'name'        => wp_strip_all_tags($p->get_name()),
                'description' => wp_strip_all_tags(wp_trim_words($p->get_short_description(), 16)),
                'quantity'    => $qty,
                'unit_price'  => $unit,
                'total'       => round($gross, 2),
                'vat_rate'    => $rate,
                'image_url'   => $img ?: null,
            ];
        }
        return [
            'items'    => $items,
            'total'    => round((float) WC()->cart->get_total('edit'), 2),
            'vat'      => round((float) WC()->cart->get_total_tax(), 2),
            'currency' => get_woocommerce_currency(),
            'empty'    => WC()->cart->is_empty(),
        ];
    }

    public function ajax_wc_cart_data() {
        if (!check_ajax_referer('easycheckout_front', 'nonce', false)) { wp_send_json_error(['message' => 'Ungültige Anfrage.'], 400); }
        if (!WC()->cart) { wp_send_json_error(['message' => 'Kein Warenkorb.'], 400); }
        wp_send_json_success($this->cart_payload());
    }

    public function ajax_wc_cart_qty() {
        if (!check_ajax_referer('easycheckout_front', 'nonce', false)) { wp_send_json_error(['message' => 'Ungültige Anfrage.'], 400); }
        $key = isset($_POST['key']) ? sanitize_text_field(wp_unslash($_POST['key'])) : '';
        $qty = isset($_POST['qty']) ? max(0, (int) $_POST['qty']) : 0;
        if ($key === '' || !WC()->cart->get_cart_item($key)) { wp_send_json_error(['message' => 'Position nicht gefunden.'], 400); }
        if ($qty <= 0) { WC()->cart->remove_cart_item($key); }
        else { WC()->cart->set_quantity($key, $qty, true); }
        WC()->cart->calculate_totals();
        wp_send_json_success($this->cart_payload());
    }

    public function ajax_wc_cart_pay() {
        if (!check_ajax_referer('easycheckout_front', 'nonce', false)) { wp_send_json_error(['message' => 'Ungültige Anfrage.'], 400); }
        if (!WC()->cart || WC()->cart->is_empty()) { wp_send_json_error(['message' => __('Der Warenkorb ist leer.', 'easycheckout')]); }

        $c = isset($_POST['customer']) ? json_decode(wp_unslash($_POST['customer']), true) : [];
        if (!is_array($c) || empty($c['email']) || empty($c['name'])) { wp_send_json_error(['message' => __('Bitte Name und E-Mail angeben.', 'easycheckout')]); }

        WC()->cart->calculate_totals();
        try {
            $order = WC_Cart_Order::from_cart('easycheckout');
        } catch (\Exception $e) {
            wp_send_json_error(['message' => __('Bestellung konnte nicht erstellt werden.', 'easycheckout')]);
        }

        // Adresse aus der Kasse übernehmen.
        $name  = trim((string) $c['name']);
        $first = $name; $last = '';
        if (strpos($name, ' ') !== false) { $parts = explode(' ', $name); $last = array_pop($parts); $first = implode(' ', $parts); }
        $addr = is_array($c['address'] ?? null) ? $c['address'] : [];
        $billing = [
            'first_name' => $first, 'last_name' => $last,
            'email' => sanitize_email($c['email']),
            'phone' => isset($c['phone']) ? sanitize_text_field($c['phone']) : '',
            'company' => isset($c['company']) ? sanitize_text_field($c['company']) : '',
            'address_1' => sanitize_text_field($addr['street'] ?? ''),
            'postcode' => sanitize_text_field($addr['postalCode'] ?? ''),
            'city' => sanitize_text_field($addr['city'] ?? ''),
            'country' => sanitize_text_field($addr['country'] ?? 'CH'),
        ];
        $order->set_address($billing, 'billing');

        // Lieferadresse: Standard = Rechnungsadresse; bei abgewählter Checkbox
        // die separat erfasste Lieferadresse als Versandadresse übernehmen.
        $same = !isset($c['sameAddress']) || !empty($c['sameAddress']);
        if (!$same && is_array($c['delivery'] ?? null)) {
            $d = $c['delivery'];
            $shipping = [
                'first_name' => $first, 'last_name' => $last,
                'company'   => $billing['company'],
                'address_1' => sanitize_text_field($d['street'] ?? ''),
                'postcode'  => sanitize_text_field($d['postalCode'] ?? ''),
                'city'      => sanitize_text_field($d['city'] ?? ''),
                'country'   => sanitize_text_field($d['country'] ?? 'CH'),
            ];
            $order->set_address($shipping, 'shipping');
        } else {
            $order->set_address($billing, 'shipping');
        }
        $order->save();

        $order_id = $order->get_id();
        $success_url = add_query_arg(['wc-api' => 'easycheckout', 'order_id' => $order_id, 'key' => $order->get_order_key(), 'status' => 'success'], home_url('/'));
        $cancel_url  = add_query_arg(['ec_cancelled' => '1'], wc_get_checkout_url());

        $session_data = WC_Session_Builder::build($order, $success_url, $cancel_url, 'woocommerce');
        $response = $this->api->create_payment_session($session_data);
        if (is_wp_error($response)) {
            $order->update_status('failed', $response->get_error_message());
            wp_send_json_error(['message' => $response->get_error_message()]);
        }
        $data  = (isset($response['data']) && is_array($response['data'])) ? $response['data'] : $response;
        $url   = $data['payment_url'] ?? '';
        $ecid  = $data['order_id'] ?? '';
        $token = '';
        if (preg_match('#/pay/([^/?]+)#', $url, $m)) { $token = $m[1]; }
        if ($token === '') { $order->update_status('failed', 'no token'); wp_send_json_error(['message' => __('Zahlung konnte nicht gestartet werden.', 'easycheckout')]); }

        $order->update_meta_data('_easycheckout_order_id', $ecid);
        $order->save();
        EasyCheckout::instance()->log_transaction(['wc_order_id' => $order_id, 'ec_order_id' => $ecid, 'status' => 'pending', 'amount' => $order->get_total(), 'currency' => $order->get_currency()]);

        wp_send_json_success(['token' => $token, 'successUrl' => $success_url, 'order_id' => $order_id]);
    }
}
