<?php
/**
 * Core EasyCheckout Plugin Class
 *
 * 100% white-label - no third-party payment processor branding visible.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Main plugin class - singleton pattern
 */
class EasyCheckout {

    /**
     * Plugin instance
     *
     * @var EasyCheckout
     */
    private static $instance = null;

    /**
     * API Client instance
     *
     * @var API_Client
     */
    public $api;

    /**
     * Admin instance
     *
     * @var Admin
     */
    public $admin;

    /**
     * Webhook Handler instance
     *
     * @var Webhook_Handler
     */
    public $webhooks;

    /**
     * Shortcodes instance
     *
     * @var Shortcodes
     */
    public $shortcodes;

    /**
     * Get singleton instance
     *
     * @return EasyCheckout
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->init_components();
        $this->init_hooks();
    }

    /**
     * Initialize plugin components
     */
    private function init_components() {
        // Core components
        $this->api = new API_Client();
        $this->webhooks = new Webhook_Handler();
        $this->shortcodes = new Shortcodes();

        // Admin components
        if (is_admin()) {
            $this->admin = new Admin();
            new Dashboard_Page();
            new Checkouts_Page();
        }

        // Checkout Builder
        new CheckoutBuilder\Checkout_CPT();
        new CheckoutBuilder\Checkout_Frontend();

        if (is_admin()) {
            new CheckoutBuilder\Checkout_Editor();
        }

        // WooCommerce integration.
        // WC fires `woocommerce_loaded` on plugins_loaded at priority -1, which
        // is BEFORE this component bootstraps (plugins_loaded default priority),
        // so the action has usually already fired by now — call init directly in
        // that case, otherwise hook it for when it fires.
        if ($this->is_woocommerce_active()) {
            if (did_action('woocommerce_loaded')) {
                $this->init_woocommerce();
            } else {
                add_action('woocommerce_loaded', [$this, 'init_woocommerce']);
            }
        }
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Enqueue frontend assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);

        // Register REST API endpoints (inbound webhook receiver)
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Initialize WooCommerce integration
     */
    public function init_woocommerce() {
        require_once EASYCHECKOUT_PLUGIN_DIR . 'woocommerce/class-wc-gateway.php';
        require_once EASYCHECKOUT_PLUGIN_DIR . 'woocommerce/class-wc-blocks.php';

        // Register payment gateway
        add_filter('woocommerce_payment_gateways', function($gateways) {
            $gateways[] = 'EasyCheckout\\WooCommerce\\WC_Gateway_EasyCheckout';
            return $gateways;
        });

        // Initialize blocks support
        new WooCommerce\WC_Blocks_Support();
    }

    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    public function is_woocommerce_active() {
        return class_exists('WooCommerce') || in_array(
            'woocommerce/woocommerce.php',
            apply_filters('active_plugins', get_option('active_plugins', []))
        );
    }

    /**
     * Enqueue frontend assets
     */
    public function enqueue_frontend_assets() {
        // Only load on pages with checkout
        if (!$this->should_load_assets()) {
            return;
        }

        // Main checkout script
        wp_register_script(
            'easycheckout-checkout',
            EASYCHECKOUT_PLUGIN_URL . 'assets/js/checkout.js',
            ['jquery'],
            EASYCHECKOUT_VERSION,
            true
        );

        // Localize script
        wp_localize_script('easycheckout-checkout', 'easycheckoutParams', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'restUrl' => rest_url('easycheckout/v1/'),
            'apiUrl' => $this->api->get_api_url(),
            'nonce' => wp_create_nonce('easycheckout-nonce'),
            'currency' => get_option('easycheckout_currency', 'CHF'),
            'locale' => get_locale(),
            'isTestMode' => get_option('easycheckout_test_mode', 'yes') === 'yes',
            'i18n' => [
                'processing' => __('Processing...', 'easycheckout'),
                'error' => __('An error occurred. Please try again.', 'easycheckout'),
                'success' => __('Payment successful!', 'easycheckout'),
                'redirecting' => __('Redirecting to payment...', 'easycheckout'),
                'emailRequired' => __('Please enter a valid email address.', 'easycheckout'),
                'nameRequired' => __('Please enter your name.', 'easycheckout'),
                'termsRequired' => __('Please accept the terms and conditions.', 'easycheckout'),
            ],
        ]);

        // Frontend styles
        wp_register_style(
            'easycheckout-checkout',
            EASYCHECKOUT_PLUGIN_URL . 'assets/css/checkout.css',
            [],
            EASYCHECKOUT_VERSION
        );

        wp_enqueue_script('easycheckout-checkout');
        wp_enqueue_style('easycheckout-checkout');
    }

    /**
     * Check if we should load checkout assets
     *
     * @return bool
     */
    private function should_load_assets() {
        global $post;

        // Always load on WooCommerce checkout
        if (function_exists('is_checkout') && is_checkout()) {
            return true;
        }

        // Check for shortcode in content
        if ($post && has_shortcode($post->post_content, 'easycheckout')) {
            return true;
        }

        // Check for checkout CPT
        if ($post && $post->post_type === 'ec_checkout') {
            return true;
        }

        return apply_filters('easycheckout_should_load_assets', false);
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        // Inbound webhook endpoint (EasyCheckout -> WordPress).
        register_rest_route('easycheckout/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => [$this->webhooks, 'handle_webhook'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Log transaction to database
     *
     * @param array $data Transaction data
     * @return int|false Insert ID or false on failure
     */
    public function log_transaction($data) {
        global $wpdb;

        $table = $wpdb->prefix . 'easycheckout_transactions';

        $defaults = [
            'order_id' => 0,
            'wc_order_id' => 0,
            'ec_order_id' => '',
            'ec_checkout_slug' => '',
            'status' => 'pending',
            'amount' => 0,
            'currency' => 'CHF',
            'payment_method' => '',
            'customer_email' => '',
            'customer_name' => '',
            'webhook_received' => 0,
            'metadata' => '',
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        if (is_array($data['metadata'])) {
            $data['metadata'] = wp_json_encode($data['metadata']);
        }

        $result = $wpdb->insert($table, $data);

        if ($result) {
            return $wpdb->insert_id;
        }

        return false;
    }

    /**
     * Update transaction in database
     *
     * @param string $ec_order_id EasyCheckout order ID
     * @param array $data Data to update
     * @return bool
     */
    public function update_transaction($ec_order_id, $data) {
        global $wpdb;

        $table = $wpdb->prefix . 'easycheckout_transactions';

        $data['updated_at'] = current_time('mysql');

        if (isset($data['metadata']) && is_array($data['metadata'])) {
            $data['metadata'] = wp_json_encode($data['metadata']);
        }

        return $wpdb->update(
            $table,
            $data,
            ['ec_order_id' => $ec_order_id]
        ) !== false;
    }

    /**
     * Get transaction by EasyCheckout order ID
     *
     * @param string $ec_order_id
     * @return object|null
     */
    public function get_transaction($ec_order_id) {
        global $wpdb;

        $table = $wpdb->prefix . 'easycheckout_transactions';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE ec_order_id = %s",
            $ec_order_id
        ));
    }

    /**
     * Debug logging
     *
     * @param string $message
     * @param string $level
     */
    public function log($message, $level = 'info') {
        if (get_option('easycheckout_debug_mode', 'no') !== 'yes') {
            return;
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'easycheckout']);
        } else {
            error_log('[EasyCheckout] ' . $message);
        }
    }
}
