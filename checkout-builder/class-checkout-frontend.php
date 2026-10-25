<?php
/**
 * EasyCheckout Frontend Rendering
 *
 * @package EasyCheckout\CheckoutBuilder
 */

namespace EasyCheckout\CheckoutBuilder;

use EasyCheckout\EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Frontend rendering for checkout pages
 */
class Checkout_Frontend {

    /**
     * Constructor
     */
    public function __construct() {
        add_filter('single_template', [$this, 'checkout_template']);
        add_action('wp_enqueue_scripts', [$this, 'add_checkout_styles'], 20);
        add_filter('the_content', [$this, 'checkout_content']);
    }

    /**
     * Use custom template for checkout CPT
     *
     * @param string $template
     * @return string
     */
    public function checkout_template($template) {
        global $post;

        if ($post && $post->post_type === Checkout_CPT::POST_TYPE) {
            // Check for theme override
            $theme_template = locate_template(['easycheckout/checkout-page.php', 'checkout-page.php']);

            if ($theme_template) {
                return $theme_template;
            }

            // Use plugin template
            $plugin_template = EASYCHECKOUT_PLUGIN_DIR . 'templates/checkout-page.php';
            if (file_exists($plugin_template)) {
                return $plugin_template;
            }
        }

        return $template;
    }

    /**
     * Add dynamic checkout styles (Markenfarbe als CSS-Variable)
     */
    public function add_checkout_styles() {
        global $post;

        if (!$post || $post->post_type !== Checkout_CPT::POST_TYPE) {
            return;
        }

        $primary_color = sanitize_hex_color(Checkout_CPT::get_meta($post->ID, 'primary_color')) ?: '#0066cc';
        // Das Stylesheet registriert EasyCheckout::enqueue_frontend_assets() (Priorität 10).
        wp_add_inline_style('easycheckout-checkout', sprintf(
            ':root{--easycheckout-primary:%s;--easycheckout-primary-hover:%s;}',
            $primary_color,
            $this->adjust_brightness($primary_color, -20)
        ));
    }

    /**
     * Filter checkout content
     *
     * @param string $content
     * @return string
     */
    public function checkout_content($content) {
        global $post;

        if (!$post || $post->post_type !== Checkout_CPT::POST_TYPE) {
            return $content;
        }

        if (!is_singular(Checkout_CPT::POST_TYPE)) {
            return $content;
        }

        wp_enqueue_style('easycheckout-checkout');

        // Render the embed (product preview + button to the hosted checkout).
        return $content . do_shortcode('[easycheckout id="' . $post->ID . '"]');
    }

    /**
     * Adjust color brightness
     *
     * @param string $hex
     * @param int $steps
     * @return string
     */
    private function adjust_brightness($hex, $steps) {
        $hex = str_replace('#', '', $hex);

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));

        return '#' . sprintf('%02x%02x%02x', $r, $g, $b);
    }
}
