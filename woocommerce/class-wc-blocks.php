<?php
/**
 * EasyCheckout WooCommerce Blocks Support
 *
 * @package EasyCheckout\WooCommerce
 */

namespace EasyCheckout\WooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

/**
 * WooCommerce Blocks payment method support
 */
class WC_Blocks_Support {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('woocommerce_blocks_loaded', [$this, 'register_payment_method']);
    }

    /**
     * Register payment method with WooCommerce Blocks
     */
    public function register_payment_method() {
        if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
            return;
        }

        add_action(
            'woocommerce_blocks_payment_method_type_registration',
            function($payment_method_registry) {
                $payment_method_registry->register(new WC_Blocks_Payment_Method());
            }
        );
    }
}

/**
 * WooCommerce Blocks Payment Method
 */
class WC_Blocks_Payment_Method extends AbstractPaymentMethodType {

    /**
     * Payment method name
     *
     * @var string
     */
    protected $name = 'easycheckout';

    /**
     * Settings from WC payment gateway
     *
     * @var array
     */
    private $gateway_settings;

    /**
     * Initialize
     */
    public function initialize() {
        $this->settings = get_option('woocommerce_easycheckout_settings', []);
        $gateways = WC()->payment_gateways->payment_gateways();
        $this->gateway = isset($gateways['easycheckout']) ? $gateways['easycheckout'] : null;
    }

    /**
     * Check if payment method is active
     *
     * @return bool
     */
    public function is_active() {
        return !empty($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    /**
     * Register scripts
     *
     * @return array
     */
    public function get_payment_method_script_handles() {
        $asset_path = EASYCHECKOUT_PLUGIN_DIR . 'assets/js/blocks/index.asset.php';
        $version = EASYCHECKOUT_VERSION;
        $dependencies = ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'];

        if (file_exists($asset_path)) {
            $asset = require $asset_path;
            $version = isset($asset['version']) ? $asset['version'] : $version;
            $dependencies = isset($asset['dependencies']) ? $asset['dependencies'] : $dependencies;
        }

        wp_register_script(
            'easycheckout-blocks',
            EASYCHECKOUT_PLUGIN_URL . 'assets/js/blocks/index.js',
            $dependencies,
            $version,
            true
        );

        wp_register_script(
            'stripe-js',
            'https://js.stripe.com/v3/',
            [],
            null,
            true
        );

        if (function_exists('wp_set_script_translations')) {
            wp_set_script_translations('easycheckout-blocks', 'easycheckout');
        }

        return ['easycheckout-blocks'];
    }

    /**
     * Get payment method data
     *
     * @return array
     */
    public function get_payment_method_data() {
        $api = new \EasyCheckout\API_Client();

        // Payment is fully redirect-based (buyer pays on the hosted EasyCheckout
        // page), so no Stripe publishable key is needed client-side. We avoid an
        // API round-trip on every Blocks checkout render.
        return [
            'title' => $this->get_setting('title'),
            'description' => $this->get_setting('description'),
            'supports' => $this->get_supported_features(),
            'checkoutSlug' => $this->get_setting('checkout_slug'),
            'paymentMethods' => $this->get_setting('payment_methods', ['card', 'twint']),
            'isTestMode' => $api->is_test_mode(),
            'icons' => $this->get_icons(),
            'logo' => apply_filters('easycheckout_gateway_icon', EASYCHECKOUT_PLUGIN_URL . 'assets/images/easycheckout-logo.png'),
        ];
    }

    /**
     * Get supported features
     *
     * @return array
     */
    public function get_supported_features() {
        return ['products', 'refunds'];
    }

    /**
     * Get payment method icons
     *
     * @return array
     */
    private function get_icons() {
        return [
            'card' => [
                'src' => EASYCHECKOUT_PLUGIN_URL . 'assets/images/card-icon.svg',
                'alt' => __('Credit Card', 'easycheckout'),
            ],
            'twint' => [
                'src' => EASYCHECKOUT_PLUGIN_URL . 'assets/images/twint-icon.svg',
                'alt' => __('TWINT', 'easycheckout'),
            ],
        ];
    }
}
