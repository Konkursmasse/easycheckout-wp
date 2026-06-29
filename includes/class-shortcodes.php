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
            'mode' => 'iframe',   // 'iframe' = inline checkout (identical to easycheckout.ch) | 'button' = redirect
            'height' => '',       // fixed px height for the iframe; empty = responsive default
            'max_width' => '1100',
            'button_text' => __('Jetzt kaufen', 'easycheckout'),
            'new_tab' => 'no',
            // Design overrides (each falls back to the global Design settings).
            'primary' => '',
            'text' => '',
            'bg' => '',
            'button' => '',
            'button_text_color' => '',
            'radius' => '',
            'font' => '',         // '' | 'default' | 'site' (match this site) | a font name e.g. "Poppins"
        ], $atts, 'easycheckout');

        $slug = $this->resolve_slug($atts['id'], $atts['slug']);
        if (is_wp_error($slug)) {
            return $this->error_for_admin($slug);
        }

        $base = $this->hosted_checkout_url($slug);

        // Redirect-button mode (optional).
        if ($atts['mode'] === 'button') {
            wp_enqueue_style('easycheckout-checkout');
            $target = $atts['new_tab'] === 'yes' ? ' target="_blank" rel="noopener"' : '';
            return '<div class="easycheckout-actions"><a class="easycheckout-button easycheckout-pay-button" href="'
                . esc_url($base) . '"' . $target . '>' . esc_html($atts['button_text']) . '</a></div>';
        }

        // Inline iframe of the hosted checkout — visually identical to
        // easycheckout.ch and fully functional (payment happens inside).
        list($src, $match_site_font) = $this->build_embed_url($base, $atts);
        $mw = max(320, intval($atts['max_width']));
        $fixed_h = intval($atts['height']);
        $uid = 'ecf-' . substr(md5($slug . wp_rand()), 0, 8);

        ob_start();
        ?>
        <div class="easycheckout-embed" style="width:100vw;max-width:100vw;margin-left:calc(50% - 50vw);">
            <div style="max-width:<?php echo esc_attr($mw); ?>px;margin:0 auto;padding:0 16px;box-sizing:border-box;">
                <iframe id="<?php echo esc_attr($uid); ?>" class="easycheckout-frame"
                        title="<?php esc_attr_e('Checkout', 'easycheckout'); ?>"
                        <?php if ($match_site_font) : ?>data-ecsrc="<?php echo esc_url($src); ?>"<?php else : ?>src="<?php echo esc_url($src); ?>"<?php endif; ?>
                        style="width:100%;border:0;display:block;background:transparent;<?php echo $fixed_h ? 'height:' . esc_attr($fixed_h) . 'px;' : ''; ?>"
                        loading="lazy" allow="payment *" referrerpolicy="origin"></iframe>
            </div>
        </div>
        <?php if (!$fixed_h) : ?>
        <style>
            #<?php echo $uid; ?>{height:1250px;}
            @media (max-width:980px){#<?php echo $uid; ?>{height:1700px;}}
            @media (max-width:600px){#<?php echo $uid; ?>{height:1980px;}}
        </style>
        <?php endif; ?>
        <script>
        (function(){
            var f = document.getElementById('<?php echo $uid; ?>');
            if (!f) { return; }
            <?php if ($match_site_font) : ?>
            // Match the surrounding site's font: detect it and pass it to the checkout.
            try {
                var fam = (getComputedStyle(document.body).fontFamily || '').trim();
                var base = f.getAttribute('data-ecsrc') || '';
                var sep = base.indexOf('?') === -1 ? '?' : '&';
                f.src = (fam && base) ? (base + sep + 'ec_font=' + encodeURIComponent(fam)) : base;
            } catch (err) {
                var b = f.getAttribute('data-ecsrc'); if (b) { f.src = b; }
            }
            <?php endif; ?>
            // Auto-size when the hosted page reports its height (graceful no-op otherwise).
            window.addEventListener('message', function(e){
                if (typeof e.origin !== 'string' || e.origin.indexOf('easycheckout.ch') === -1) { return; }
                var d = e.data;
                if (d && d.type === 'easycheckout:height' && d.height) {
                    f.style.height = (parseInt(d.height, 10) + 24) + 'px';
                }
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Build the hosted-checkout embed URL with opt-in ec_* design params
     * (shortcode attributes override the global Design settings).
     *
     * @param string $base
     * @param array  $atts
     * @return array [string $url, bool $match_site_font]
     */
    private function build_embed_url($base, $atts) {
        $design = get_option('easycheckout_design', []);
        if (!is_array($design)) {
            $design = [];
        }

        $pick = function ($att_key, $opt_key) use ($atts, $design) {
            if (isset($atts[$att_key]) && $atts[$att_key] !== '') {
                return $atts[$att_key];
            }
            return isset($design[$opt_key]) ? $design[$opt_key] : '';
        };

        $params = ['ec_embed' => '1'];

        $colors = [
            'primary'           => ['primary', 'ec_primary'],
            'text'              => ['text', 'ec_text'],
            'bg'                => ['bg', 'ec_bg'],
            'button'            => ['button', 'ec_button'],
            'button_text_color' => ['buttontext', 'ec_buttontext'],
        ];
        foreach ($colors as $att_key => $info) {
            $val = $pick($att_key, $info[0]);
            if ($val !== '') {
                $params[$info[1]] = ltrim($val, '#');
            }
        }

        $radius = $pick('radius', 'radius');
        if ($radius !== '' && is_numeric($radius)) {
            $params['ec_radius'] = (int) $radius;
        }

        // Font: '' falls back to the global setting; 'site' = match this site
        // (resolved in JS); 'custom' uses the configured custom font; any other
        // non-default value is treated as a literal font name.
        $font = $atts['font'] !== '' ? $atts['font'] : (isset($design['font_source']) ? $design['font_source'] : 'default');
        $match_site_font = false;
        if ($font === 'site') {
            $match_site_font = true;
        } elseif ($font === 'custom') {
            $custom = isset($design['font_custom']) ? $design['font_custom'] : '';
            if ($custom !== '') {
                $params['ec_font'] = $custom;
            }
        } elseif ($font !== '' && $font !== 'default' && $font !== 'inter') {
            $params['ec_font'] = $font;
        }

        return [add_query_arg($params, $base), $match_site_font];
    }

    /**
     * Resolve a remote checkout slug from a slug attribute or a local
     * ec_checkout post (without an API round-trip).
     *
     * @param int|string $id
     * @param string     $slug
     * @return string|\WP_Error
     */
    private function resolve_slug($id, $slug) {
        if (!empty($slug)) {
            return $slug;
        }
        if (!empty($id)) {
            $post = get_post($id);
            if ($post && $post->post_type === 'ec_checkout') {
                $remote_slug = get_post_meta($post->ID, '_ec_checkout_slug', true);
                if (!empty($remote_slug)) {
                    return $remote_slug;
                }
                return new \WP_Error('ec_no_slug', __('Diesem Checkout ist kein EasyCheckout-Slug zugeordnet.', 'easycheckout'));
            }
        }
        return new \WP_Error('ec_missing_checkout', __('Bitte gib einen Checkout-Slug an: [easycheckout slug="dein-slug"].', 'easycheckout'));
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
