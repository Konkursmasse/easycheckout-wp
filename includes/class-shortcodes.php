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
        // Eigenstaendige Vorschau-/Checkout-URL: /?ec_local=<slug>
        add_action('template_redirect', [$this, 'maybe_render_standalone']);
        // Public-Proxies (server-seitig -> kein CORS) fuer den nativen Konto-Checkout.
        add_action('wp_ajax_easycheckout_pub_checkout', [$this, 'ajax_pub_checkout']);
        add_action('wp_ajax_nopriv_easycheckout_pub_checkout', [$this, 'ajax_pub_checkout']);
        add_action('wp_ajax_easycheckout_pub_pay', [$this, 'ajax_pub_pay']);
        add_action('wp_ajax_nopriv_easycheckout_pub_pay', [$this, 'ajax_pub_pay']);
    }

    /** Basis-URL der easyCheckout-API. */
    private function api_base() {
        return rtrim(get_option('easycheckout_api_url', 'https://www.easycheckout.ch'), '/');
    }

    /** Proxy: oeffentliche Checkout-Daten (Produkte/Preise/Design) holen. */
    public function ajax_pub_checkout() {
        if (!check_ajax_referer('easycheckout_front', 'nonce', false)) { wp_send_json_error(['message' => 'Ungültige Anfrage.'], 400); }
        $slug = isset($_REQUEST['slug']) ? sanitize_title(wp_unslash($_REQUEST['slug'])) : '';
        if ($slug === '') { wp_send_json_error(['message' => 'Kein Slug.'], 400); }
        $resp = wp_remote_get($this->api_base() . '/api/public/checkout/' . rawurlencode($slug), ['timeout' => 20]);
        if (is_wp_error($resp)) { wp_send_json_error(['message' => $resp->get_error_message()], 502); }
        wp_send_json_success([
            'status' => wp_remote_retrieve_response_code($resp),
            'body'   => json_decode(wp_remote_retrieve_body($resp), true),
        ]);
    }

    /** Proxy: Zahlung anlegen (PaymentIntent) fuer den nativen Konto-Checkout. */
    public function ajax_pub_pay() {
        if (!check_ajax_referer('easycheckout_front', 'nonce', false)) { wp_send_json_error(['message' => 'Ungültige Anfrage.'], 400); }
        $slug    = isset($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
        $payload = isset($_POST['payload']) ? json_decode(wp_unslash($_POST['payload']), true) : null;
        if ($slug === '' || !is_array($payload)) { wp_send_json_error(['message' => 'Ungültige Daten.'], 400); }
        $resp = wp_remote_post($this->api_base() . '/api/public/checkout/' . rawurlencode($slug) . '/pay', [
            'timeout' => 25,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode($payload),
        ]);
        if (is_wp_error($resp)) { wp_send_json_error(['message' => $resp->get_error_message()], 502); }
        wp_send_json_success([
            'status' => wp_remote_retrieve_response_code($resp),
            'body'   => json_decode(wp_remote_retrieve_body($resp), true),
        ]);
    }

    /**
     * Rendert einen lokalen Checkout als eigenstaendige Seite unter
     * ?ec_local=<slug> — praktisch fuer „Ansehen" aus dem Dashboard und als
     * teilbarer Link, ohne erst eine WP-Seite anzulegen.
     */
    public function maybe_render_standalone() {
        $localSlug   = !empty($_GET['ec_local']) ? sanitize_title(wp_unslash($_GET['ec_local'])) : '';
        $previewSlug = !empty($_GET['ec_preview']) ? sanitize_title(wp_unslash($_GET['ec_preview'])) : '';
        if ($localSlug === '' && $previewSlug === '') { return; }

        if ($previewSlug !== '') {
            // Vorschau der EINBETTUNG auf der eigenen Domain (lokal ODER Konto-Checkout).
            $html = $this->render_checkout(['slug' => $previewSlug]);
            $titleSlug = $previewSlug;
        } else {
            if (!class_exists('EasyCheckout\\Native_Dashboard')) { return; }
            $local = \EasyCheckout\Native_Dashboard::get_local_checkout_by_slug($localSlug);
            if (!$local) { return; }
            $html = $this->render_local_checkout($local);
            $titleSlug = $local['name'];
        }

        nocache_headers();
        $title = esc_html($titleSlug . ' – Checkout');
        echo '<!DOCTYPE html><html ' . get_language_attributes() . '><head><meta charset="' . esc_attr(get_bloginfo('charset')) . '">';
        echo '<meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">';
        echo '<title>' . $title . '</title>';
        wp_print_styles();
        wp_print_head_scripts();
        echo '</head><body style="margin:0;">';
        echo $html;
        wp_print_footer_scripts();
        echo '</body></html>';
        exit;
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

        // Lokaler Checkout (Bankueberweisung, ohne Konto) hat Vorrang, wenn der
        // Slug zu einem lokal gebauten Checkout passt.
        $want = $atts['slug'] !== '' ? $atts['slug'] : $atts['id'];
        if ($want !== '' && class_exists('EasyCheckout\\Native_Dashboard')) {
            $local = \EasyCheckout\Native_Dashboard::get_local_checkout_by_slug($want);
            if ($local) {
                return $this->render_local_checkout($local);
            }
        }

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

        // NATIVER Konto-Checkout: Produkte/Preise/Design nativ im Plugin gerendert
        // (gleiches, anpassbares CSS wie der lokale Checkout), Bezahlung per Stripe
        // Elements white-label. KEIN iFrame mehr.
        return $this->render_account_checkout($slug, $atts);
    }

    /**
     * Nativer Konto-Checkout (Daten via Public-Proxy, Zahlung via Stripe Elements).
     *
     * @param string $slug
     * @param array  $atts
     * @return string
     */
    private function render_account_checkout($slug, $atts) {
        wp_register_style('easycheckout-local-checkout', EASYCHECKOUT_PLUGIN_URL . 'assets/css/local-checkout.css', [], EASYCHECKOUT_VERSION);
        wp_enqueue_style('easycheckout-local-checkout');
        // Stripe.js (unsichtbar/white-label) fuer die native Kartenzahlung.
        wp_register_script('stripe-js', 'https://js.stripe.com/v3/', [], null, true);
        wp_enqueue_script('stripe-js');
        wp_register_script('easycheckout-account-checkout', EASYCHECKOUT_PLUGIN_URL . 'assets/js/account-checkout.js', ['stripe-js'], EASYCHECKOUT_VERSION, true);
        $primary = (isset($atts['primary']) && $atts['primary']) ? $atts['primary'] : '';
        wp_localize_script('easycheckout-account-checkout', 'ecAccount', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('easycheckout_front'),
            'slug'    => $slug,
            'primary' => $primary,
        ]);
        wp_enqueue_script('easycheckout-account-checkout');
        $style = $primary ? ' style="--ec-p:' . esc_attr($primary) . ';"' : '';
        return '<div class="ec-local-checkout ec-account-checkout" data-ec-account="1"' . $style . '></div>';
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
     * Rendert einen lokal gebauten Checkout (Bankueberweisung, ohne Konto).
     * Die eigentliche UI baut assets/js/local-checkout.js aus ecLocal.
     *
     * @param array $c Lokaler Checkout.
     * @return string
     */
    private function render_local_checkout($c) {
        $handle = 'easycheckout-local-checkout';
        wp_register_style($handle, EASYCHECKOUT_PLUGIN_URL . 'assets/css/local-checkout.css', [], EASYCHECKOUT_VERSION);
        wp_enqueue_style($handle);
        wp_register_script('easycheckout-qrgen', EASYCHECKOUT_PLUGIN_URL . 'assets/js/qrcode-generator.js', [], '1.4.4', true);
        wp_register_script($handle, EASYCHECKOUT_PLUGIN_URL . 'assets/js/local-checkout.js', ['easycheckout-qrgen'], EASYCHECKOUT_VERSION, true);

        $primary = (isset($c['design']['primary']) && $c['design']['primary']) ? $c['design']['primary'] : '#4F46E5';
        $products = [];
        foreach ((array) (isset($c['products']) ? $c['products'] : []) as $p) {
            $products[] = [
                'id'          => $p['id'],
                'name'        => $p['name'],
                'description' => isset($p['description']) ? $p['description'] : '',
                'price'       => (float) $p['price'],
                'imageUrl'    => isset($p['imageUrl']) ? $p['imageUrl'] : '',
                'categoryId'  => (isset($p['categoryId']) && $p['categoryId'] !== '') ? $p['categoryId'] : null,
                // Fulfillment-Preise (null = Standardpreis) + Liefergebuehr.
                'pickupPrice'   => (isset($p['pickupPrice']) && $p['pickupPrice'] !== null && $p['pickupPrice'] !== '') ? (float) $p['pickupPrice'] : null,
                'deliveryPrice' => (isset($p['deliveryPrice']) && $p['deliveryPrice'] !== null && $p['deliveryPrice'] !== '') ? (float) $p['deliveryPrice'] : null,
                'deliveryFee'   => (isset($p['deliveryFee']) && $p['deliveryFee'] !== null && $p['deliveryFee'] !== '') ? (float) $p['deliveryFee'] : null,
                // Optionsgruppen + Infofelder.
                'optionGroups'  => array_values((array) (isset($p['optionGroups']) ? $p['optionGroups'] : [])),
                'customFields'  => array_values((array) (isset($p['customFields']) ? $p['customFields'] : [])),
            ];
        }
        $categories = [];
        foreach ((array) (isset($c['categories']) ? $c['categories'] : []) as $cat) {
            $categories[] = [
                'id'            => $cat['id'],
                'name'          => isset($cat['name']) ? $cat['name'] : '',
                'description'   => isset($cat['description']) ? $cat['description'] : '',
                'singleProduct' => !empty($cat['singleProduct']),
                'allowQuantity' => !isset($cat['allowQuantity']) || $cat['allowQuantity'],
            ];
        }
        wp_localize_script($handle, 'ecLocal', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('easycheckout_front'),
            'checkout' => [
                'slug'       => $c['slug'],
                'name'       => $c['name'],
                'logo'       => (isset($c['design']['logoUrl']) && $c['design']['logoUrl']) ? $c['design']['logoUrl'] : '',
                'currency'   => isset($c['currency']) ? $c['currency'] : 'CHF',
                'productsTitle' => isset($c['productsTitle']) ? $c['productsTitle'] : '',
                'primary'    => $primary,
                'vatEnabled' => !empty($c['vatEnabled']),
                'vatRate'    => isset($c['vatRate']) ? (float) $c['vatRate'] : 0,
                'pickupEnabled'   => !isset($c['pickupEnabled']) || !empty($c['pickupEnabled']),
                'deliveryEnabled' => !empty($c['deliveryEnabled']),
                'categorySelection' => isset($c['categorySelection']) ? $c['categorySelection'] : 'multiple',
                'categories' => $categories,
                'products'   => $products,
            ],
        ]);
        wp_enqueue_script($handle);

        return '<div class="ec-local-checkout" data-ec-local="1" style="--ec-p:' . esc_attr($primary) . ';"></div>';
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
        // Checkouts werden IMMER als Subdomain adressiert (<slug>.easycheckout.ch),
        // nie als /c/<slug>. Host aus der API-URL ableiten, www entfernen.
        $parts  = wp_parse_url($base);
        $scheme = isset($parts['scheme']) ? $parts['scheme'] : 'https';
        $host   = isset($parts['host']) ? preg_replace('/^www\./', '', $parts['host']) : 'easycheckout.ch';
        $safe   = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        $url    = $scheme . '://' . $safe . '.' . $host;

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
