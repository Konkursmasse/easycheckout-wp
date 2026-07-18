<?php
/**
 * EasyCheckout – Session-Builder für WooCommerce-Bestellungen
 *
 * Baut aus einer WC-Bestellung die payment-session-Daten für die VOLLE
 * EasyCheckout-Kasse (dynamischer Positions-Checkout): strukturierte
 * `line_items` (mit exakt aus WooCommerce abgeleitetem MwSt-Satz), `customer`
 * (Rechnungsdaten zur Vorbefüllung) und `fulfillment` (Versand/Abholung).
 *
 * Der zu belastende Betrag (`amount`) ist IMMER das WooCommerce-Bestell-Total –
 * die Positionen dienen der Anzeige + MwSt-Ausweisung. Damit gibt es NIE eine
 * Abweichung zwischen belastetem Betrag und WooCommerce-Bestellung.
 *
 * @package EasyCheckout\WooCommerce
 */

namespace EasyCheckout\WooCommerce;

defined('ABSPATH') || exit;

class WC_Session_Builder {

    /**
     * On-Domain-URL des KONFIGURIERTEN EasyCheckout-Checkouts (Produktquelle
     * „EasyCheckout-Checkout" + Slug gesetzt). Dann öffnen die WooCommerce-
     * Einstiegspunkte diesen nativ gerenderten Checkout (?ec_checkout=<slug>) mit
     * dessen hinterlegten Produkten – statt den WooCommerce-Warenkorb. Leer = nicht aktiv.
     *
     * @return string
     */
    public static function ec_checkout_url() {
        $s = get_option('woocommerce_easycheckout_settings', []);
        if (!is_array($s) || ($s['product_source'] ?? 'woo_cart') !== 'ec_checkout') {
            return '';
        }
        $slug = trim((string) ($s['checkout_slug'] ?? ''));
        return $slug !== '' ? home_url('/?ec_checkout=' . rawurlencode($slug)) : '';
    }

    /**
     * Gewählter Checkout-Modus (Gateway-Einstellung):
     *   'inline'   – Zahlseite auf der eigenen Domain eingebettet (Standard)
     *   'embedded' – dito (aktuell technisch identisch mit inline: On-Domain-Einbettung)
     *   'extern'   – Weiterleitung zur gehosteten easyCheckout-Zahlseite
     *
     * @return string
     */
    public static function checkout_mode() {
        $s = get_option('woocommerce_easycheckout_settings', []);
        $m = is_array($s) ? (string) ($s['checkout_mode'] ?? 'inline') : 'inline';
        return in_array($m, ['extern', 'embedded', 'inline'], true) ? $m : 'inline';
    }

    /**
     * Weiterleitungs-Ziel je nach Modus. Bei 'extern' die gehostete Zahlseite
     * (easycheckout.ch), sonst ein On-Domain-Wrapper `?ec_embed=<id>`, der die
     * Zahlseite auf der Händler-Domain einbettet (Kunde bleibt auf der eigenen
     * Domain, WooCommerce-Warenkorb wird verarbeitet).
     *
     * @param string $payment_url Gehostete Zahl-URL aus create_payment_session
     * @return string
     */
    public static function dispatch_redirect($payment_url) {
        $mode = self::checkout_mode();
        if (empty($payment_url) || $mode === 'extern') {
            return $payment_url;
        }
        $id = wp_generate_password(24, false);
        set_transient('ec_embed_' . $id, $payment_url, 30 * MINUTE_IN_SECONDS);
        // inline = natives DOM-Rendering (?ec_pay), embedded = iFrame (?ec_embed).
        $param = ($mode === 'inline') ? 'ec_pay' : 'ec_embed';
        return home_url('/?' . $param . '=' . $id);
    }

    /**
     * Baut das komplette session_data-Array für /api/v1/payment-sessions.
     *
     * @param \WC_Order $order
     * @param string    $success_url
     * @param string    $cancel_url
     * @param string    $source      Herkunft (woocommerce | woocommerce_express | woocommerce_buynow)
     * @return array
     */
    public static function build($order, $success_url, $cancel_url, $source = 'woocommerce') {
        $line_items = [];

        // Produktpositionen. WC speichert Zeilen-Totals NETTO + Steuer separat,
        // unabhängig von der Anzeige-Einstellung. Brutto = Netto + Steuer =
        // tatsächlich zu zahlender Betrag der Zeile.
        foreach ($order->get_items() as $item) {
            $qty = max(1, (int) $item->get_quantity());
            $net = (float) $item->get_total();
            $tax = (float) $item->get_total_tax();
            $gross_line = $net + $tax;
            $unit_price = round($gross_line / $qty, 2);
            // MwSt-Satz exakt aus der Zeile ableiten (Steuer/Netto). Bei
            // steuerfrei -> null. Auf 1 Nachkommastelle gerundet (7.7 / 8.1 …).
            $rate = ($net > 0 && $tax > 0) ? round($tax / $net * 100, 1) : null;

            $product = $item->get_product();
            $img = '';
            if ($product) {
                $img_id = $product->get_image_id();
                if ($img_id) {
                    $img = wp_get_attachment_image_url($img_id, 'thumbnail');
                }
            }

            $line_items[] = [
                'name'       => wp_strip_all_tags($item->get_name()),
                'quantity'   => $qty,
                'unit_price' => $unit_price,
                'vat_rate'   => $rate,
                'image_url'  => $img ?: null,
                'meta'       => self::item_meta_text($item) ?: null,
            ];
        }

        // Gebühren als eigene Positionen (Brutto), damit die Übersicht mit dem
        // Total aufgeht.
        foreach ($order->get_fees() as $fee) {
            $gross = (float) $fee->get_total() + (float) $fee->get_total_tax();
            if (abs($gross) < 0.005) {
                continue;
            }
            $line_items[] = [
                'name'       => wp_strip_all_tags($fee->get_name()),
                'quantity'   => 1,
                'unit_price' => round($gross, 2),
                'vat_rate'   => null,
                'image_url'  => null,
                'meta'       => null,
            ];
        }

        // Versand -> Fulfillment (Anzeige separat; Betrag steckt bereits im Total).
        $ship_gross = (float) $order->get_shipping_total() + (float) $order->get_shipping_tax();
        $fulfillment = null;
        if ($order->has_shipping_method('local_pickup')) {
            $fulfillment = ['mode' => 'pickup', 'fee' => 0];
        } elseif ($order->needs_shipping_address() || $ship_gross > 0) {
            $fulfillment = ['mode' => 'delivery', 'fee' => round($ship_gross, 2)];
        }

        // Kundendaten zur Vorbefüllung (bei „Sofort kaufen" leer -> /pay fragt ab).
        $street = trim($order->get_billing_address_1() . ' ' . $order->get_billing_address_2());
        $customer = [
            'email'       => $order->get_billing_email() ?: null,
            'name'        => trim($order->get_formatted_billing_full_name()) ?: null,
            'company'     => $order->get_billing_company() ?: null,
            'phone'       => $order->get_billing_phone() ?: null,
            'street'      => $street ?: null,
            'postal_code' => $order->get_billing_postcode() ?: null,
            'city'        => $order->get_billing_city() ?: null,
            'country'     => $order->get_billing_country() ?: null,
        ];

        $data = [
            'amount'        => (int) round((float) $order->get_total() * 100),
            'currency'      => $order->get_currency(),
            'success_url'   => $success_url,
            'cancel_url'    => $cancel_url,
            'description'   => sprintf(__('Bestellung %s', 'easycheckout'), $order->get_order_number()),
            'vat_inclusive' => true,
            'line_items'    => $line_items,
            'customer'      => $customer,
            'metadata'      => array_merge([
                'wc_order_id'    => (string) $order->get_id(),
                'wc_order_key'   => $order->get_order_key(),
                'source'         => $source,
                'customer_email' => $order->get_billing_email(),
                'customer_name'  => $order->get_formatted_billing_full_name(),
            ],
                // Kassen-Ersatz: immer mind. Name + E-Mail auf der EC-Kasse abfragen
                // (auch wenn WC-Billing uebernommen wurde -> dann vorbefuellt). „Sofort
                // kaufen" (woocommerce_buynow) bleibt unveraendert.
                $source === 'woocommerce' ? ['collect_customer' => '1'] : []
            ),
        ];

        if ($fulfillment !== null) {
            $data['fulfillment'] = $fulfillment;
        }

        return $data;
    }

    /**
     * Varianten-/Zusatz-Meta einer Position als kurzer Text (z.B. "Größe: L").
     *
     * @param \WC_Order_Item $item
     * @return string
     */
    private static function item_meta_text($item) {
        if (!method_exists($item, 'get_formatted_meta_data')) {
            return '';
        }
        $parts = [];
        foreach ($item->get_formatted_meta_data('_', true) as $meta) {
            $key = wp_strip_all_tags($meta->display_key);
            $val = wp_strip_all_tags($meta->display_value);
            if ($key !== '' && $val !== '') {
                $parts[] = $key . ': ' . $val;
            }
        }
        return implode(', ', $parts);
    }
}
