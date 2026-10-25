<?php
/**
 * EasyCheckout – zentrale Design-/Anpassungsschnittstelle für Entwickler.
 *
 * Liefert die Markenfarbe (--ec-p) und die Design-Optionen für alle
 * Kassen-Renderer (WC-Warenkorb-Kasse, /pay-Kasse, Konto-Checkout, Shortcode).
 * Die Farbe ist in den Gateway-Einstellungen setzbar. Entwickler können beides
 * per Filter überschreiben, ohne die Plugin-Dateien zu ändern:
 *   - Farbe:      apply_filters('easycheckout_checkout_color', $hex)
 *   - Zusatz-CSS: apply_filters('easycheckout_checkout_css', '')
 * Ein Eingabefeld für eigenes CSS gibt es bewusst nicht (WordPress.org-Richtlinie);
 * dafür ist der CSS-Editor von WordPress da (Customizer bzw. Website-Editor).
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

    /** Zusatz-CSS aus dem Entwickler-Filter (kein Eingabefeld). */
    public static function css() {
        return wp_strip_all_tags((string) apply_filters('easycheckout_checkout_css', ''));
    }

    /** Farbwert grob absichern (Hex/rgb/hsl/Farbname). */
    private static function safe_color($c) {
        return preg_replace('/[^#a-zA-Z0-9(),.%\s-]/', '', (string) $c);
    }

    /** Globale Design-Option (Logo, Farben, Radius, Schrift) aus dem Settings-Tab. */
    public static function design() {
        $d = get_option('easycheckout_design', []);
        return is_array($d) ? $d : [];
    }

    /**
     * Logo-URL für Kassen-Kopf + Mails.
     * Reihenfolge: eigenes Design-Logo → WordPress-Website-Logo (Customizer)
     * → '' (kein Logo). So erscheint auch ohne Konfiguration automatisch das
     * Shop-Logo in der Kasse.
     */
    public static function logo_url() {
        $d = self::design();
        $url = isset($d['logoUrl']) ? trim((string) $d['logoUrl']) : '';
        if ($url === '') {
            // WordPress-Website-Logo (Customizer → „Logo") als Fallback.
            $custom_logo_id = get_theme_mod('custom_logo');
            if ($custom_logo_id) {
                $src = wp_get_attachment_image_src($custom_logo_id, 'full');
                if ($src && !empty($src[0])) { $url = $src[0]; }
            }
        }
        return $url !== '' ? esc_url_raw($url) : '';
    }

    /**
     * „Powered by easyCheckout"-Hinweis in der Kasse anzeigen?
     * STANDARD: aus (kein externer Link auf der öffentlichen Seite —
     * WordPress.org-konform). Nur wenn der Händler es bewusst aktiviert.
     */
    public static function show_powered() {
        $d = self::design();
        return isset($d['showPowered']) && $d['showPowered'] === 'yes';
    }

    /**
     * CSS-String: setzt --ec-p auf allen Kassen-Containern, wendet die
     * Design-Optionen (Hintergrund/Text/Button/Radius/Schrift) an und hängt
     * das Zusatz-CSS aus dem Filter an. Nicht gesetzte Optionen erzeugen KEINE Regeln, damit das
     * Standard-Design unangetastet bleibt.
     */
    public static function inline_css() {
        $out = ':root,.ec-local-checkout,#ec-pay-checkout{--ec-p:' . self::safe_color(self::color()) . ';}';

        $d = self::design();
        $bg     = isset($d['bg']) ? trim((string) $d['bg']) : '';
        $text   = isset($d['text']) ? trim((string) $d['text']) : '';
        $btnTxt = isset($d['buttonText']) ? trim((string) $d['buttonText']) : '';
        $radius = isset($d['radius']) && $d['radius'] !== '' ? (int) $d['radius'] : null;
        if ($bg !== '' && strcasecmp($bg, '#F9FAFB') !== 0) {
            $out .= '.ec-local-checkout,#ec-pay-checkout{background:' . self::safe_color($bg) . ';}';
        }
        if ($text !== '' && strcasecmp($text, '#111827') !== 0) {
            $out .= '.ec-local-checkout{color:' . self::safe_color($text) . ';}'
                . '.ec-local-checkout .eclc-title,.ec-local-checkout .eclc-col-h{color:' . self::safe_color($text) . ';}';
        }
        if ($btnTxt !== '' && strcasecmp($btnTxt, '#FFFFFF') !== 0) {
            $out .= '.eclc-btn{color:' . self::safe_color($btnTxt) . ';}';
        }
        if ($radius !== null && $radius !== 8) {
            $r = max(0, min(32, $radius));
            $out .= '.eclc-btn,.eclc-input,.eclc-field input{border-radius:' . $r . 'px;}';
        }
        if (isset($d['font']) && $d['font'] === 'system') {
            // #id-Spezifität des Inline-„font-family:inherit" überstimmen.
            $out .= '#ec-pay-checkout,.ec-local-checkout,#ec-pay-checkout input,#ec-pay-checkout select,#ec-pay-checkout button'
                . '{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif !important;}';
        }

        $custom = self::css();
        if (trim($custom) !== '') {
            $out .= "\n" . $custom;
        }
        return $out;
    }

    /**
     * Design-CSS für eigenständig gerenderte Kassen-Seiten einreihen.
     * Wird vor wp_print_styles() aufgerufen und nach den übrigen Kassen-
     * Stylesheets ausgegeben, damit die Markenfarbe sie überstimmt.
     *
     * @param string[] $deps Stylesheets, nach denen das Design-CSS kommt.
     */
    public static function enqueue_brand($deps = []) {
        wp_register_style('easycheckout-brand', false, $deps, EASYCHECKOUT_VERSION);
        wp_enqueue_style('easycheckout-brand');
        wp_add_inline_style('easycheckout-brand', self::inline_css());
    }

    /** Design-CSS in ein bereits registriertes Stylesheet einhängen (Shortcode-Fall). */
    public static function enqueue_inline($handle = 'easycheckout-local-checkout') {
        if (wp_style_is($handle, 'registered') || wp_style_is($handle, 'enqueued')) {
            wp_add_inline_style($handle, self::inline_css());
        }
    }
}
