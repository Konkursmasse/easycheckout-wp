<?php
/**
 * Plugin Name: EasyCheckout
 * Plugin URI: https://easycheckout.ch
 * Description: Accept payments with EasyCheckout - Credit Cards, TWINT, and Swiss QR-Bill. Works standalone or with WooCommerce.
 * Version: 1.0.69
 * Author: EasyCheckout
 * Author URI: https://easycheckout.ch
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: easycheckout
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 10.9
 */

defined('ABSPATH') || exit;

// Plugin constants
define('EASYCHECKOUT_VERSION', '1.0.69');
define('EASYCHECKOUT_PLUGIN_FILE', __FILE__);
define('EASYCHECKOUT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('EASYCHECKOUT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('EASYCHECKOUT_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Autoloader for plugin classes
 */
spl_autoload_register(function ($class) {
    $prefix = 'EasyCheckout\\';
    $base_dir = EASYCHECKOUT_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);

    // Map namespace to directory
    $class_map = [
        'WooCommerce\\' => 'woocommerce/',
        'CheckoutBuilder\\' => 'checkout-builder/',
    ];

    foreach ($class_map as $ns => $dir) {
        if (strncmp($ns, $relative_class, strlen($ns)) === 0) {
            $relative_class = substr($relative_class, strlen($ns));
            $base_dir = EASYCHECKOUT_PLUGIN_DIR . $dir;
            break;
        }
    }

    // Convert class name to file name
    $file = $base_dir . 'class-' . strtolower(str_replace(['_', '\\'], ['-', '/'], $relative_class)) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Plugin activation hook
 */
function easycheckout_activate() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'easycheckout_transactions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id BIGINT PRIMARY KEY AUTO_INCREMENT,
        order_id BIGINT,
        wc_order_id BIGINT,
        ec_order_id VARCHAR(255),
        ec_checkout_slug VARCHAR(255),
        status VARCHAR(50),
        amount DECIMAL(10,2),
        currency VARCHAR(3) DEFAULT 'CHF',
        payment_method VARCHAR(50),
        customer_email VARCHAR(255),
        customer_name VARCHAR(255),
        webhook_received TINYINT(1) DEFAULT 0,
        metadata LONGTEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_ec_order_id (ec_order_id),
        INDEX idx_wc_order_id (wc_order_id),
        INDEX idx_status (status)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    // Set default options
    $defaults = [
        'easycheckout_api_url' => 'https://www.easycheckout.ch',
        'easycheckout_test_mode' => 'yes',
        'easycheckout_payment_methods' => ['card', 'twint'],
        'easycheckout_debug_mode' => 'no',
    ];

    foreach ($defaults as $key => $value) {
        if (get_option($key) === false) {
            add_option($key, $value);
        }
    }

    // Flush rewrite rules for custom post type
    flush_rewrite_rules();
}
register_activation_hook(__FILE__, 'easycheckout_activate');

/**
 * Plugin deactivation hook
 */
function easycheckout_deactivate() {
    flush_rewrite_rules();
}
register_deactivation_hook(__FILE__, 'easycheckout_deactivate');

/**
 * Initialize the plugin
 */
function easycheckout_init() {
    // Load text domain
    load_plugin_textdomain('easycheckout', false, dirname(EASYCHECKOUT_PLUGIN_BASENAME) . '/languages');

    // Initialize core plugin
    EasyCheckout\EasyCheckout::instance();
}
add_action('plugins_loaded', 'easycheckout_init', 10);

/**
 * Rewrite-Rules nach Plugin-Updates automatisch neu schreiben: Auto-Updates
 * durchlaufen KEINEN Activation-Hook, Rewrite-Aenderungen (z.B. CPT-Slug
 * checkout -> ec-checkout) griffen sonst erst nach manuellem Speichern der
 * Permalinks. Laeuft einmal pro Versionswechsel, spaet auf init (nach der
 * CPT-/Endpoint-Registrierung).
 */
add_action('init', function() {
    if (get_option('easycheckout_rewrite_version') !== EASYCHECKOUT_VERSION) {
        flush_rewrite_rules();
        update_option('easycheckout_rewrite_version', EASYCHECKOUT_VERSION);
    }
}, 99);

/**
 * Declare WooCommerce HPOS compatibility
 */
add_action('before_woocommerce_init', function() {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Add settings link to plugins page
 */
add_filter('plugin_action_links_' . EASYCHECKOUT_PLUGIN_BASENAME, function($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=easycheckout-settings') . '">' . __('Settings', 'easycheckout') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
});
