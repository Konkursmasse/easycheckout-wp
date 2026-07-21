<?php
/**
 * EasyCheckout – Deinstallation.
 *
 * Wird von WordPress ausgefuehrt, wenn der Nutzer das Plugin ueber „Loeschen"
 * entfernt (nicht bei blossem Deaktivieren). Raeumt alle vom Plugin angelegten
 * Optionen, Transients und die Transaktionstabelle restlos auf.
 *
 * @package EasyCheckout
 */

// Nur im echten Uninstall-Kontext von WordPress laufen.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

global $wpdb;

// 1) Bekannte Einzel-Optionen.
$options = [
    'easycheckout_api_url',
    'easycheckout_api_key',
    'easycheckout_webhook_secret',
    'easycheckout_test_mode',
    'easycheckout_debug_mode',
    'easycheckout_payment_methods',
    'easycheckout_currency',
    'easycheckout_config',
    'easycheckout_design',
    'easycheckout_gateway_heal_lock',
    'easycheckout_processed_events',
    'woocommerce_easycheckout_settings',
];
foreach ($options as $option) {
    delete_option($option);
}

// 2) Alle uebrigen Plugin-Optionen und Transients per Praefix (z. B. ec_embed_*,
//    easycheckout_*-Transients wie der Release-Cache).
$like_sets = [
    $wpdb->esc_like('easycheckout_') . '%',
    $wpdb->esc_like('ec_embed_') . '%',
    $wpdb->esc_like('_transient_easycheckout_') . '%',
    $wpdb->esc_like('_transient_timeout_easycheckout_') . '%',
    $wpdb->esc_like('_site_transient_easycheckout_') . '%',
    $wpdb->esc_like('_site_transient_timeout_easycheckout_') . '%',
];
foreach ($like_sets as $like) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $like));
}

// 3) Transaktionstabelle entfernen.
$table = $wpdb->prefix . 'easycheckout_transactions';
$wpdb->query("DROP TABLE IF EXISTS {$table}");

// 4) Aufgeraeumte Rewrite-Regeln erzwingen (CPT-Endpunkte).
if (function_exists('flush_rewrite_rules')) {
    flush_rewrite_rules();
}
