<?php
/**
 * EasyCheckout – zentrale Design-/Anpassungsschnittstelle für Entwickler.
 *
 * Liefert die Markenfarbe (--ec-p) und optionales Custom CSS für alle
 * Kassen-Renderer (WC-Warenkorb-Kasse, /pay-Kasse, Konto-Checkout, Shortcode).
 * Beides ist über die Gateway-Einstellungen setzbar UND per Filter überschreibbar:
 *   - Farbe:      apply_filters('easycheckout_checkout_color', $hex)
 *   - Custom CSS: apply_filters('easycheckout_checkout_css', $css)
 * So kann ein Entwickler das Design ohne Editieren der Plugin-Dateien anpassen.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

class Design {

    /** Gateway-Einstellungen (WooCommerce). */
    private static function settings() {
        $s = get_option('woocommerce_easycheckout_settings', []);
        return is_array($s) ? $s : [];
    }

    /** Markenfarbe (Primärfarbe --ec-p). Einstellung → Filter → Default. */
    public static function color() {
        $s = self::settings();
        $c = trim((string) ($s['brand_color'] ?? ''));
        if ($c === '') {
            $c = '#4F46E5';
        }
        return (string) apply_filters('easycheckout_checkout_color', $c);
    }

    /** Vom Händler/Entwickler hinterlegtes Custom CSS. Einstellung → Filter. */
    public static function css() {
        $s = self::settings();
        $css = (string) ($s['custom_css'] ?? '');
        $css = (string) apply_filters('easycheckout_checkout_css', $css);
        // Kein Ausbruch aus dem <style>-Tag.
        return str_ireplace(['</style', '<style'], '', $css);
    }

    /** Farbwert grob absichern (Hex/rgb/hsl/Farbname). */
    private static function safe_color($c) {
        return preg_replace('/[^#a-zA-Z0-9(),.%\s-]/', '', (string) $c);
    }

    /**
     * CSS-String: setzt --ec-p auf allen Kassen-Containern + hängt Custom CSS an.
     */
    public static function inline_css() {
        $out = ':root,.ec-local-checkout,#ec-pay-checkout{--ec-p:' . self::safe_color(self::color()) . ';}';
        $custom = self::css();
        if (trim($custom) !== '') {
            $out .= "\n" . $custom;
        }
        return $out;
    }

    /** <style>-Block für standalone gerenderte Kassen-Seiten in den <head> geben. */
    public static function head() {
        echo '<style id="easycheckout-brand">' . self::inline_css() . '</style>';
    }

    /** Custom CSS in ein bereits registriertes Stylesheet einhängen (Shortcode-Fall). */
    public static function enqueue_inline($handle = 'easycheckout-local-checkout') {
        if (wp_style_is($handle, 'registered') || wp_style_is($handle, 'enqueued')) {
            wp_add_inline_style($handle, self::inline_css());
        }
    }
}
