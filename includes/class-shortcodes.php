<?php
/**
 * EasyCheckout Shortcodes
 *
 * 100% white-label embed: shows a product preview and sends the buyer to the
 * hosted EasyCheckout checkout page ({APP_URL}/c/{slug}) to pay. No card data
 * or payment logic ever touches the WordPress site.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Shortcodes class for embedding checkouts
 */
class Shortcodes {

    /**
     * Cached API client
     *
     * @var API_Client|null
     */
    private $api = null;

    /**
     * Constructor
     */
    public function __construct() {
        add_shortcode('easycheckout', [$this, 'render_checkout']);
        add_shortcode('easycheckout_button', [$this, 'render_button']);
        add_shortcode('easycheckout_product', [$this, 'render_product']);
    }

    /**
     * Lazy API client
     *
     * @return API_Client
     */
    private function api() {
        if ($this->api === null) {
            $this->api = new API_Client();
        }
        return $this->api;
    }

    /**
     * Render full checkout shortcode (product preview + buy button).
     *
     * Usage: [easycheckout slug="my-checkout"]
     *        [easycheckout id="123"]            (a local "ec_checkout" post that
     *                                            references a remote slug)
     *
     * @param array $atts Shortcode attributes
     * @return string
     */
    public function render_checkout($atts) {
        $atts = shortcode_atts([
            'id' => '',
            'slug' => '',
            'theme' => 'light',
            'show_header' => 'yes',
            'show_products' => 'yes',
            'button_text' => __('Jetzt kaufen', 'easycheckout'),
            'new_tab' => 'no',
        ], $atts, 'easycheckout');

        $checkout = $this->get_checkout_data($atts['id'], $atts['slug']);

        if (is_wp_error($checkout)) {
            return $this->error_for_admin($checkout);
        }

        wp_enqueue_style('easycheckout-checkout');

        $url = $this->hosted_checkout_url($checkout['slug']);
        $target = $atts['new_tab'] === 'yes' ? ' target="_blank" rel="noopener"' : '';

        ob_start();
        ?>
        <div class="easycheckout-container easycheckout-theme-<?php echo esc_attr($atts['theme']); ?>">

            <?php if ($atts['show_header'] === 'yes') : ?>
            <div class="easycheckout-header">
                <?php if (!empty($checkout['logo'])) : ?>
                    <img src="<?php echo esc_url($checkout['logo']); ?>" alt="" class="easycheckout-logo">
                <?php endif; ?>
                <?php if (!empty($checkout['name'])) : ?>
                    <h2 class="easycheckout-title"><?php echo esc_html($checkout['name']); ?></h2>
                <?php endif; ?>
                <?php if (!empty($checkout['description'])) : ?>
                    <p class="easycheckout-description"><?php echo esc_html($checkout['description']); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($atts['show_products'] === 'yes' && !empty($checkout['products'])) : ?>
            <div class="easycheckout-products">
                <?php foreach ($checkout['products'] as $product) : ?>
                <div class="easycheckout-product">
                    <?php if (!empty($product['image'])) : ?>
                        <img src="<?php echo $this->img_src($product['image']); ?>" alt="" class="easycheckout-product-image">
                    <?php endif; ?>
                    <div class="easycheckout-product-info">
                        <h3 class="easycheckout-product-name"><?php echo esc_html($product['name']); ?></h3>
                        <?php if (!empty($product['description'])) : ?>
                            <p class="easycheckout-product-description"><?php echo esc_html($product['description']); ?></p>
                        <?php endif; ?>
                        <span class="easycheckout-product-price">
                            <?php echo esc_html($this->format_price($product['price'], $checkout['currency'])); ?>
                        </span>
                        <?php if (!empty($product['sold_out'])) : ?>
                            <span class="easycheckout-soldout"><?php esc_html_e('Ausverkauft', 'easycheckout'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="easycheckout-actions">
                <a class="easycheckout-button easycheckout-pay-button" href="<?php echo esc_url($url); ?>"<?php echo $target; ?>>
                    <?php echo esc_html($atts['button_text']); ?>
                </a>
            </div>

            <p class="easycheckout-redirect-info">
                <?php esc_html_e('Sichere Zahlung über EasyCheckout (Karte, TWINT, QR-Rechnung).', 'easycheckout'); ?>
            </p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single buy button linking to the hosted checkout.
     *
     * Usage: [easycheckout_button slug="my-checkout" text="Jetzt kaufen"]
     *
     * @param array $atts
     * @return string
     */
    public function render_button($atts) {
        $atts = shortcode_atts([
            'id' => '',
            'slug' => '',
            'text' => __('Jetzt kaufen', 'easycheckout'),
            'class' => '',
            'new_tab' => 'no',
        ], $atts, 'easycheckout_button');

        $checkout = $this->get_checkout_data($atts['id'], $atts['slug']);

        if (is_wp_error($checkout)) {
            return $this->error_for_admin($checkout);
        }

        wp_enqueue_style('easycheckout-checkout');

        $url = $this->hosted_checkout_url($checkout['slug']);
        $target = $atts['new_tab'] === 'yes' ? ' target="_blank" rel="noopener"' : '';

        return sprintf(
            '<a class="easycheckout-button %s" href="%s"%s>%s</a>',
            esc_attr($atts['class']),
            esc_url($url),
            $target,
            esc_html($atts['text'])
        );
    }

    /**
     * Render a single product card with a buy button.
     *
     * Usage: [easycheckout_product slug="my-checkout" product="<product-id>"]
     *
     * @param array $atts
     * @return string
     */
    public function render_product($atts) {
        $atts = shortcode_atts([
            'id' => '',
            'slug' => '',
            'product' => '',
            'show_description' => 'yes',
            'show_image' => 'yes',
            'button_text' => __('Jetzt kaufen', 'easycheckout'),
            'new_tab' => 'no',
        ], $atts, 'easycheckout_product');

        $checkout = $this->get_checkout_data($atts['id'], $atts['slug']);

        if (is_wp_error($checkout)) {
            return $this->error_for_admin($checkout);
        }

        // Find the requested product (by id); fall back to first product.
        $product = null;
        if (!empty($checkout['products'])) {
            foreach ($checkout['products'] as $p) {
                if ((string) $p['id'] === (string) $atts['product']) {
                    $product = $p;
                    break;
                }
            }
            if (!$product && empty($atts['product'])) {
                $product = $checkout['products'][0];
            }
        }

        if (!$product) {
            return $this->error_for_admin(new \WP_Error('ec_product_not_found', __('Produkt nicht gefunden.', 'easycheckout')));
        }

        wp_enqueue_style('easycheckout-checkout');

        $url = $this->hosted_checkout_url($checkout['slug']);
        $target = $atts['new_tab'] === 'yes' ? ' target="_blank" rel="noopener"' : '';

        ob_start();
        ?>
        <div class="easycheckout-single-product">
            <?php if ($atts['show_image'] === 'yes' && !empty($product['image'])) : ?>
                <img src="<?php echo $this->img_src($product['image']); ?>" alt="" class="easycheckout-product-image">
            <?php endif; ?>

            <div class="easycheckout-product-details">
                <h3 class="easycheckout-product-name"><?php echo esc_html($product['name']); ?></h3>

                <?php if ($atts['show_description'] === 'yes' && !empty($product['description'])) : ?>
                    <p class="easycheckout-product-description"><?php echo esc_html($product['description']); ?></p>
                <?php endif; ?>

                <span class="easycheckout-product-price">
                    <?php echo esc_html($this->format_price($product['price'], $checkout['currency'])); ?>
                </span>
            </div>

            <a class="easycheckout-button easycheckout-buy-button" href="<?php echo esc_url($url); ?>"<?php echo $target; ?>>
                <?php echo esc_html($atts['button_text']); ?>
            </a>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Resolve checkout data (normalized) from a slug or a local ec_checkout post.
     *
     * @param int|string $id   Local ec_checkout post ID (optional)
     * @param string     $slug Remote EasyCheckout checkout slug (optional)
     * @return array|\WP_Error Normalized checkout array or error
     */
    private function get_checkout_data($id, $slug) {
        // Remote slug takes priority.
        if (!empty($slug)) {
            return $this->fetch_remote_checkout($slug);
        }

        // A local post just needs to reference a remote slug.
        if (!empty($id)) {
            $post = get_post($id);
            if ($post && $post->post_type === 'ec_checkout') {
                $remote_slug = get_post_meta($post->ID, '_ec_checkout_slug', true);
                if (empty($remote_slug)) {
                    return new \WP_Error(
                        'ec_no_slug',
                        __('Diesem Checkout ist kein EasyCheckout-Slug zugeordnet. Trage den Slug aus deinem EasyCheckout-Konto ein.', 'easycheckout')
                    );
                }
                return $this->fetch_remote_checkout($remote_slug);
            }
        }

        return new \WP_Error('ec_missing_checkout', __('Bitte gib einen Checkout-Slug an: [easycheckout slug="dein-slug"].', 'easycheckout'));
    }

    /**
     * Fetch a checkout from the public API and normalize it.
     *
     * @param string $slug
     * @return array|\WP_Error
     */
    private function fetch_remote_checkout($slug) {
        $response = $this->api()->get_checkout_by_slug($slug);

        if (is_wp_error($response)) {
            return $response;
        }

        // Public endpoint returns { checkout: {...} }; be tolerant of a flat shape.
        $c = isset($response['checkout']) && is_array($response['checkout']) ? $response['checkout'] : $response;

        if (empty($c) || empty($c['slug'])) {
            return new \WP_Error('ec_invalid_checkout', __('Checkout nicht gefunden.', 'easycheckout'));
        }

        $products = [];
        if (!empty($c['products']) && is_array($c['products'])) {
            foreach ($c['products'] as $p) {
                $products[] = [
                    'id' => $p['id'] ?? '',
                    'name' => $p['name'] ?? '',
                    'description' => $p['description'] ?? '',
                    'price' => isset($p['price']) ? (float) $p['price'] : 0,
                    'image' => $p['imageUrl'] ?? ($p['image_url'] ?? ($p['image'] ?? '')),
                    'sold_out' => !empty($p['soldOut']) || !empty($p['sold_out']),
                ];
            }
        }

        return [
            'slug' => $c['slug'],
            'name' => $c['name'] ?? '',
            'description' => $c['description'] ?? '',
            'logo' => $c['merchantLogoUrl'] ?? ($c['merchant_logo_url'] ?? ($c['logo'] ?? '')),
            'currency' => $c['currency'] ?? 'CHF',
            'products' => $products,
        ];
    }

    /**
     * Build the hosted checkout URL for a slug ({APP_URL}/c/{slug}).
     *
     * @param string $slug
     * @return string
     */
    private function hosted_checkout_url($slug) {
        $base = rtrim(get_option('easycheckout_api_url', 'https://www.easycheckout.ch'), '/');
        $url = $base . '/c/' . rawurlencode($slug);

        /**
         * Filter the hosted checkout URL (e.g. to use a custom checkout domain).
         *
         * @param string $url
         * @param string $slug
         */
        return apply_filters('easycheckout_hosted_checkout_url', $url, $slug);
    }

    /**
     * Show a helpful error to admins only; nothing to visitors.
     *
     * @param \WP_Error $error
     * @return string
     */
    private function error_for_admin($error) {
        if (current_user_can('manage_options')) {
            return '<div class="easycheckout-error" style="padding:10px;border:1px solid #d63638;background:#fcf0f1;color:#d63638;border-radius:4px;">'
                . 'EasyCheckout: ' . esc_html($error->get_error_message())
                . '</div>';
        }
        return '';
    }

    /**
     * Escape an image src. EasyCheckout product images may be data: URIs
     * (base64), which esc_url() strips, so allow those explicitly.
     *
     * @param string $src
     * @return string
     */
    private function img_src($src) {
        if (is_string($src) && strncmp($src, 'data:image/', 11) === 0) {
            return esc_attr($src);
        }
        return esc_url($src);
    }

    /**
     * Format price.
     *
     * @param float  $price
     * @param string $currency
     * @return string
     */
    private function format_price($price, $currency = 'CHF') {
        return $currency . ' ' . number_format((float) $price, 2, '.', "'");
    }
}
