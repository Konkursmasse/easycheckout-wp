<?php
/**
 * EasyCheckout – WooCommerce-Bestellung aus dem aktuellen Warenkorb bauen.
 *
 * Gemeinsam genutzt vom Express-Checkout und vom Kassen-Ersatz. Übernimmt
 * Positionen inkl. Steuern, Versand, Gebühren und Gutscheine aus dem Cart und
 * berechnet die Summen. Adresse bleibt leer (bzw. wird bei eingeloggtem Kunden
 * vorbefüllt) – die endgültige Adresse erfasst die EasyCheckout-Kasse.
 *
 * @package EasyCheckout\WooCommerce
 */

namespace EasyCheckout\WooCommerce;

defined('ABSPATH') || exit;

class WC_Cart_Order {

    /**
     * @param string $created_via z.B. easycheckout_express | easycheckout
     * @return \WC_Order
     * @throws \Exception
     */
    public static function from_cart($created_via = 'easycheckout') {
        $cart = WC()->cart;

        $order = wc_create_order([
            'status'      => 'pending',
            'created_via' => $created_via,
            'customer_id' => get_current_user_id(),
        ]);
        if (is_wp_error($order)) {
            throw new \Exception($order->get_error_message());
        }

        // Positionen.
        foreach ($cart->get_cart() as $values) {
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

        // Versand aus den gewählten Methoden.
        self::add_shipping_lines($order, $cart);

        // Gebühren.
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
        $order->set_payment_method_title(__('EasyCheckout', 'easycheckout'));
        $order->set_currency(get_woocommerce_currency());

        // Bei eingeloggtem Kunden Adresse vorbefüllen (spart Tippen im Fast-Checkout).
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
     * Versandzeilen aus den gewählten Versandmethoden des Warenkorbs übernehmen.
     *
     * @param \WC_Order $order
     * @param \WC_Cart  $cart
     */
    private static function add_shipping_lines($order, $cart) {
        if (!$cart->needs_shipping() || !function_exists('WC')) {
            return;
        }

        $packages       = WC()->shipping() ? WC()->shipping()->get_packages() : [];
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
}
