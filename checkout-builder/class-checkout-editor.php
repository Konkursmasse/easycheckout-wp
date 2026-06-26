<?php
/**
 * EasyCheckout Gutenberg Block Editor Support
 *
 * @package EasyCheckout\CheckoutBuilder
 */

namespace EasyCheckout\CheckoutBuilder;

defined('ABSPATH') || exit;

/**
 * Gutenberg block editor support for checkouts
 */
class Checkout_Editor {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'register_blocks']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_editor_assets']);
    }

    /**
     * Register Gutenberg blocks
     */
    public function register_blocks() {
        // Register the EasyCheckout block
        register_block_type('easycheckout/checkout', [
            'api_version' => 2,
            'editor_script' => 'easycheckout-block-editor',
            'editor_style' => 'easycheckout-block-editor',
            'render_callback' => [$this, 'render_checkout_block'],
            'attributes' => [
                'checkoutId' => [
                    'type' => 'number',
                    'default' => 0,
                ],
                'checkoutSlug' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'theme' => [
                    'type' => 'string',
                    'default' => 'light',
                ],
                'showHeader' => [
                    'type' => 'boolean',
                    'default' => true,
                ],
                'buttonText' => [
                    'type' => 'string',
                    'default' => '',
                ],
            ],
        ]);

        // Register the EasyCheckout Button block
        register_block_type('easycheckout/button', [
            'api_version' => 2,
            'editor_script' => 'easycheckout-block-editor',
            'editor_style' => 'easycheckout-block-editor',
            'render_callback' => [$this, 'render_button_block'],
            'attributes' => [
                'checkoutId' => [
                    'type' => 'number',
                    'default' => 0,
                ],
                'checkoutSlug' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'buttonText' => [
                    'type' => 'string',
                    'default' => 'Buy Now',
                ],
                'productId' => [
                    'type' => 'string',
                    'default' => '',
                ],
                'className' => [
                    'type' => 'string',
                    'default' => '',
                ],
            ],
        ]);
    }

    /**
     * Enqueue editor assets
     */
    public function enqueue_editor_assets() {
        wp_enqueue_script(
            'easycheckout-block-editor',
            EASYCHECKOUT_PLUGIN_URL . 'assets/js/block-editor.js',
            ['wp-blocks', 'wp-element', 'wp-editor', 'wp-components', 'wp-i18n', 'wp-block-editor'],
            EASYCHECKOUT_VERSION,
            true
        );

        wp_enqueue_style(
            'easycheckout-block-editor',
            EASYCHECKOUT_PLUGIN_URL . 'assets/css/block-editor.css',
            ['wp-edit-blocks'],
            EASYCHECKOUT_VERSION
        );

        // Get available checkouts for dropdown
        $checkouts = get_posts([
            'post_type' => Checkout_CPT::POST_TYPE,
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);

        $checkout_options = [];
        foreach ($checkouts as $checkout) {
            $checkout_options[] = [
                'value' => $checkout->ID,
                'label' => $checkout->post_title,
                'slug' => get_post_meta($checkout->ID, '_ec_checkout_slug', true),
            ];
        }

        wp_localize_script('easycheckout-block-editor', 'easycheckoutBlockData', [
            'checkouts' => $checkout_options,
            'pluginUrl' => EASYCHECKOUT_PLUGIN_URL,
            'i18n' => [
                'blockTitle' => __('EasyCheckout', 'easycheckout'),
                'blockDescription' => __('Embed an EasyCheckout payment form.', 'easycheckout'),
                'selectCheckout' => __('Select Checkout', 'easycheckout'),
                'checkoutId' => __('Checkout ID', 'easycheckout'),
                'checkoutSlug' => __('Checkout Slug', 'easycheckout'),
                'theme' => __('Theme', 'easycheckout'),
                'light' => __('Light', 'easycheckout'),
                'dark' => __('Dark', 'easycheckout'),
                'showHeader' => __('Show Header', 'easycheckout'),
                'buttonText' => __('Button Text', 'easycheckout'),
                'buttonBlockTitle' => __('EasyCheckout Button', 'easycheckout'),
                'buttonBlockDescription' => __('Add a payment button.', 'easycheckout'),
                'productId' => __('Product ID', 'easycheckout'),
                'noCheckouts' => __('No checkouts found. Create one first.', 'easycheckout'),
                'preview' => __('Preview', 'easycheckout'),
            ],
        ]);
    }

    /**
     * Render checkout block
     *
     * @param array $attributes
     * @return string
     */
    public function render_checkout_block($attributes) {
        $checkout_id = intval($attributes['checkoutId'] ?? 0);
        $checkout_slug = sanitize_text_field($attributes['checkoutSlug'] ?? '');
        $theme = sanitize_text_field($attributes['theme'] ?? 'light');
        $show_header = $attributes['showHeader'] ?? true;
        $button_text = sanitize_text_field($attributes['buttonText'] ?? '');

        if (!$checkout_id && !$checkout_slug) {
            if (current_user_can('edit_posts')) {
                return '<p class="easycheckout-error">' . __('Please select a checkout in the block settings.', 'easycheckout') . '</p>';
            }
            return '';
        }

        $shortcode_atts = [];

        if ($checkout_id) {
            $shortcode_atts[] = 'id="' . $checkout_id . '"';
        }
        if ($checkout_slug) {
            $shortcode_atts[] = 'slug="' . $checkout_slug . '"';
        }
        if ($theme !== 'light') {
            $shortcode_atts[] = 'theme="' . $theme . '"';
        }
        if (!$show_header) {
            $shortcode_atts[] = 'show_header="no"';
        }
        if ($button_text) {
            $shortcode_atts[] = 'button_text="' . $button_text . '"';
        }

        return do_shortcode('[easycheckout ' . implode(' ', $shortcode_atts) . ']');
    }

    /**
     * Render button block
     *
     * @param array $attributes
     * @return string
     */
    public function render_button_block($attributes) {
        $checkout_id = intval($attributes['checkoutId'] ?? 0);
        $checkout_slug = sanitize_text_field($attributes['checkoutSlug'] ?? '');
        $button_text = sanitize_text_field($attributes['buttonText'] ?? __('Buy Now', 'easycheckout'));
        $product_id = sanitize_text_field($attributes['productId'] ?? '');
        $class_name = sanitize_html_class($attributes['className'] ?? '');

        if (!$checkout_id && !$checkout_slug) {
            if (current_user_can('edit_posts')) {
                return '<p class="easycheckout-error">' . __('Please select a checkout in the block settings.', 'easycheckout') . '</p>';
            }
            return '';
        }

        $shortcode_atts = [];

        if ($checkout_id) {
            $shortcode_atts[] = 'id="' . $checkout_id . '"';
        }
        if ($checkout_slug) {
            $shortcode_atts[] = 'slug="' . $checkout_slug . '"';
        }
        if ($button_text) {
            $shortcode_atts[] = 'text="' . $button_text . '"';
        }
        if ($product_id) {
            $shortcode_atts[] = 'product_id="' . $product_id . '"';
        }
        if ($class_name) {
            $shortcode_atts[] = 'class="' . $class_name . '"';
        }

        return do_shortcode('[easycheckout_button ' . implode(' ', $shortcode_atts) . ']');
    }
}
