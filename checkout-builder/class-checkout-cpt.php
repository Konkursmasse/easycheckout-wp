<?php
/**
 * EasyCheckout Custom Post Type for Checkouts
 *
 * @package EasyCheckout\CheckoutBuilder
 */

namespace EasyCheckout\CheckoutBuilder;

defined('ABSPATH') || exit;

/**
 * Custom Post Type for checkout pages
 */
class Checkout_CPT {

    /**
     * Post type name
     *
     * Bis 1.0.89 hiess der Typ „ec_checkout“ und die Meta-Felder „_ec_*“;
     * easycheckout_migrate_prefixes() benennt bestehende Einträge um.
     *
     * @var string
     */
    const POST_TYPE = 'easycheckout_page';

    /**
     * Präfix der Meta-Felder.
     *
     * @var string
     */
    const META = '_easycheckout_';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_meta']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta'], 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'add_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_columns'], 10, 2);
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
    }

    /**
     * Register custom post type
     */
    public function register_post_type() {
        $labels = [
            'name' => __('Checkouts', 'easycheckout'),
            'singular_name' => __('Checkout', 'easycheckout'),
            'add_new' => __('Add New', 'easycheckout'),
            'add_new_item' => __('Add New Checkout', 'easycheckout'),
            'edit_item' => __('Edit Checkout', 'easycheckout'),
            'new_item' => __('New Checkout', 'easycheckout'),
            'view_item' => __('View Checkout', 'easycheckout'),
            'search_items' => __('Search Checkouts', 'easycheckout'),
            'not_found' => __('No checkouts found', 'easycheckout'),
            'not_found_in_trash' => __('No checkouts found in trash', 'easycheckout'),
            'menu_name' => __('EasyCheckout', 'easycheckout'),
        ];

        $args = [
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            // Eigenes Top-Level-Menue ausgeblendet: das native EasyCheckout-
            // Dashboard verwaltet Checkouts. Sonst erschiene ein zweiter,
            // gleichnamiger "EasyCheckout"-Menuepunkt (CPT menu_name) mit
            // demselben Warenkorb-Icon direkt neben dem nativen Dashboard.
            'show_in_menu' => false,
            'query_var' => true,
            // WICHTIG: Slug darf NICHT 'checkout' sein — das kollidiert mit der
            // WooCommerce-Kassen-Seite (/checkout/): die CPT-Rule schluckt dann
            // /checkout/order-received/<id>/ und die Danke-Seite wird 404.
            'rewrite' => ['slug' => 'ec-checkout', 'with_front' => false],
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => 56,
            'menu_icon' => 'dashicons-cart',
            'supports' => ['title', 'editor', 'thumbnail'],
            'show_in_rest' => true,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Register post meta for REST API
     */
    public function register_meta() {
        $meta_fields = [
            'checkout_slug' => ['type' => 'string', 'default' => ''],
            'description' => ['type' => 'string', 'default' => ''],
            'currency' => ['type' => 'string', 'default' => 'CHF'],
            'vat_rate' => ['type' => 'number', 'default' => 0],
            'success_url' => ['type' => 'string', 'default' => ''],
            'cancel_url' => ['type' => 'string', 'default' => ''],
            'terms_url' => ['type' => 'string', 'default' => ''],
            'logo' => ['type' => 'string', 'default' => ''],
            'primary_color' => ['type' => 'string', 'default' => '#0066cc'],
            'payment_methods' => ['type' => 'array', 'default' => ['card', 'twint']],
            'products' => ['type' => 'array', 'default' => []],
            'email_notifications' => ['type' => 'boolean', 'default' => true],
        ];

        foreach ($meta_fields as $key => $args) {
            register_post_meta(self::POST_TYPE, self::META . $key, [
                'show_in_rest' => true,
                'single' => true,
                'type' => $args['type'],
                'default' => $args['default'],
                'auth_callback' => function() {
                    return current_user_can('edit_posts');
                },
            ]);
        }
    }

    /**
     * Read a checkout meta field.
     *
     * @param int    $post_id
     * @param string $key Feldname ohne Präfix, z. B. 'checkout_slug'.
     * @return mixed
     */
    public static function get_meta($post_id, $key) {
        return get_post_meta($post_id, self::META . $key, true);
    }

    /**
     * Stylesheet und Skript der Produkt-Metabox laden (nur im Checkout-Editor).
     *
     * @param string $hook
     */
    public function enqueue_admin_assets($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }
        wp_enqueue_style('easycheckout-checkout-cpt', EASYCHECKOUT_PLUGIN_URL . 'assets/css/checkout-cpt.css', [], EASYCHECKOUT_VERSION);
        wp_enqueue_script('easycheckout-checkout-cpt', EASYCHECKOUT_PLUGIN_URL . 'assets/js/checkout-cpt.js', ['jquery'], EASYCHECKOUT_VERSION, true);
    }

    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'easycheckout_checkout_settings',
            __('Checkout Settings', 'easycheckout'),
            [$this, 'render_settings_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'easycheckout_checkout_products',
            __('Products', 'easycheckout'),
            [$this, 'render_products_meta_box'],
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'easycheckout_checkout_shortcode',
            __('Shortcode', 'easycheckout'),
            [$this, 'render_shortcode_meta_box'],
            self::POST_TYPE,
            'side',
            'default'
        );

        add_meta_box(
            'easycheckout_checkout_design',
            __('Design', 'easycheckout'),
            [$this, 'render_design_meta_box'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Render settings meta box
     *
     * @param \WP_Post $post
     */
    public function render_settings_meta_box($post) {
        wp_nonce_field('easycheckout_checkout_meta', 'easycheckout_checkout_nonce');

        $slug = self::get_meta($post->ID, 'checkout_slug');
        $description = self::get_meta($post->ID, 'description');
        $currency = self::get_meta($post->ID, 'currency') ?: 'CHF';
        $vat_rate = self::get_meta($post->ID, 'vat_rate');
        $success_url = self::get_meta($post->ID, 'success_url');
        $cancel_url = self::get_meta($post->ID, 'cancel_url');
        $terms_url = self::get_meta($post->ID, 'terms_url');
        $payment_methods = self::get_meta($post->ID, 'payment_methods') ?: ['card', 'twint'];
        $email_notifications = self::get_meta($post->ID, 'email_notifications');
        ?>
        <table class="form-table">
            <tr>
                <th><label for="easycheckout_checkout_slug"><?php esc_html_e('Checkout Slug', 'easycheckout'); ?></label></th>
                <td>
                    <input type="text" id="easycheckout_checkout_slug" name="easycheckout_checkout_slug"
                           value="<?php echo esc_attr($slug); ?>" class="regular-text">
                    <p class="description">
                        <?php esc_html_e('Slug des Checkouts aus deinem EasyCheckout-Konto. Produkte und Design werden von dort übernommen; der Button leitet auf die gehostete Bezahlseite (/c/{slug}). Einbetten z. B. mit [easycheckout slug="dein-slug"].', 'easycheckout'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="easycheckout_description"><?php esc_html_e('Description', 'easycheckout'); ?></label></th>
                <td>
                    <textarea id="easycheckout_description" name="easycheckout_description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label for="easycheckout_currency"><?php esc_html_e('Currency', 'easycheckout'); ?></label></th>
                <td>
                    <select id="easycheckout_currency" name="easycheckout_currency">
                        <option value="CHF" <?php selected($currency, 'CHF'); ?>>CHF</option>
                        <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR</option>
                        <option value="USD" <?php selected($currency, 'USD'); ?>>USD</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="easycheckout_vat_rate"><?php esc_html_e('VAT Rate (%)', 'easycheckout'); ?></label></th>
                <td>
                    <input type="number" id="easycheckout_vat_rate" name="easycheckout_vat_rate"
                           value="<?php echo esc_attr($vat_rate); ?>" step="0.1" min="0" max="100" class="small-text">
                    <p class="description"><?php esc_html_e('Leave at 0 for no VAT.', 'easycheckout'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Payment Methods', 'easycheckout'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="easycheckout_payment_methods[]" value="card"
                               <?php checked(in_array('card', $payment_methods, true)); ?>>
                        <?php esc_html_e('Credit/Debit Cards', 'easycheckout'); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="easycheckout_payment_methods[]" value="twint"
                               <?php checked(in_array('twint', $payment_methods, true)); ?>>
                        <?php esc_html_e('TWINT', 'easycheckout'); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="easycheckout_payment_methods[]" value="bank_transfer"
                               <?php checked(in_array('bank_transfer', $payment_methods, true)); ?>>
                        <?php esc_html_e('Bank Transfer (QR-Bill)', 'easycheckout'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="easycheckout_success_url"><?php esc_html_e('Success URL', 'easycheckout'); ?></label></th>
                <td>
                    <input type="url" id="easycheckout_success_url" name="easycheckout_success_url"
                           value="<?php echo esc_url($success_url); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Redirect URL after successful payment.', 'easycheckout'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="easycheckout_cancel_url"><?php esc_html_e('Cancel URL', 'easycheckout'); ?></label></th>
                <td>
                    <input type="url" id="easycheckout_cancel_url" name="easycheckout_cancel_url"
                           value="<?php echo esc_url($cancel_url); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="easycheckout_terms_url"><?php esc_html_e('Terms URL', 'easycheckout'); ?></label></th>
                <td>
                    <input type="url" id="easycheckout_terms_url" name="easycheckout_terms_url"
                           value="<?php echo esc_url($terms_url); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Email Notifications', 'easycheckout'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="easycheckout_email_notifications" value="1"
                               <?php checked($email_notifications, true); ?>>
                        <?php esc_html_e('Send email notifications for orders', 'easycheckout'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render products meta box
     *
     * @param \WP_Post $post
     */
    public function render_products_meta_box($post) {
        $products = self::get_meta($post->ID, 'products') ?: [];
        ?>
        <div class="notice notice-info inline ec-products-notice">
            <p><?php esc_html_e('Hinweis: Produkte werden in deinem EasyCheckout-Konto verwaltet und beim Einbetten automatisch aus dem Checkout (per Slug oben) geladen. Diese lokale Liste ist nur ein optionaler Fallback und wird nicht ins EasyCheckout-Konto synchronisiert.', 'easycheckout'); ?></p>
        </div>
        <div id="ec-products-container">
            <div id="ec-products-list" data-next-index="<?php echo esc_attr(count($products)); ?>">
                <?php foreach ($products as $index => $product) : ?>
                    <?php $this->render_product_row($index, $product); ?>
                <?php endforeach; ?>
            </div>

            <button type="button" class="button" id="ec-add-product">
                <?php esc_html_e('Add Product', 'easycheckout'); ?>
            </button>
        </div>

        <template id="ec-product-template">
            <?php $this->render_product_row('{{INDEX}}', []); ?>
        </template>
        <?php
    }

    /**
     * Render single product row
     *
     * @param int|string $index
     * @param array $product
     */
    private function render_product_row($index, $product) {
        $defaults = [
            'name' => '',
            'description' => '',
            'price' => '',
            'image' => '',
            'sku' => '',
            'maxQuantity' => 99,
        ];
        $product = wp_parse_args($product, $defaults);
        // Zeilen-Index: Zahl oder der Platzhalter {{INDEX}} der JS-Vorlage.
        $index = is_numeric($index) ? (int) $index : '{{INDEX}}';
        $field = function ($name) use ($index) {
            return 'easycheckout_products[' . $index . '][' . $name . ']';
        };
        ?>
        <div class="ec-product-row">
            <div class="ec-product-header">
                <strong><?php
                    /* translators: %s: position of the product in the list */
                    echo esc_html(sprintf(__('Product #%s', 'easycheckout'), is_int($index) ? $index + 1 : ''));
                ?></strong>
                <button type="button" class="button-link ec-remove-product"><?php esc_html_e('Remove', 'easycheckout'); ?></button>
            </div>
            <div class="ec-product-fields">
                <div>
                    <label><?php esc_html_e('Name', 'easycheckout'); ?></label>
                    <input type="text" name="<?php echo esc_attr($field('name')); ?>"
                           value="<?php echo esc_attr($product['name']); ?>">
                </div>
                <div>
                    <label><?php esc_html_e('Price', 'easycheckout'); ?></label>
                    <input type="number" name="<?php echo esc_attr($field('price')); ?>"
                           value="<?php echo esc_attr($product['price']); ?>" step="0.01" min="0">
                </div>
                <div>
                    <label><?php esc_html_e('SKU', 'easycheckout'); ?></label>
                    <input type="text" name="<?php echo esc_attr($field('sku')); ?>"
                           value="<?php echo esc_attr($product['sku']); ?>">
                </div>
                <div>
                    <label><?php esc_html_e('Max Quantity', 'easycheckout'); ?></label>
                    <input type="number" name="<?php echo esc_attr($field('maxQuantity')); ?>"
                           value="<?php echo esc_attr($product['maxQuantity']); ?>" min="1">
                </div>
                <div class="ec-product-wide">
                    <label><?php esc_html_e('Description', 'easycheckout'); ?></label>
                    <textarea name="<?php echo esc_attr($field('description')); ?>" rows="2"><?php echo esc_textarea($product['description']); ?></textarea>
                </div>
                <div class="ec-product-wide">
                    <label><?php esc_html_e('Image URL', 'easycheckout'); ?></label>
                    <input type="url" name="<?php echo esc_attr($field('image')); ?>"
                           value="<?php echo esc_url($product['image']); ?>">
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render shortcode meta box
     *
     * @param \WP_Post $post
     */
    public function render_shortcode_meta_box($post) {
        if ($post->post_status !== 'publish') {
            echo '<p>' . esc_html__('Publish the checkout to get the shortcode.', 'easycheckout') . '</p>';
            return;
        }
        ?>
        <p><?php esc_html_e('Copy this shortcode to embed the checkout:', 'easycheckout'); ?></p>
        <code>[easycheckout id="<?php echo esc_html((string) $post->ID); ?>"]</code>

        <p style="margin-top: 15px;"><?php esc_html_e('Or use the direct URL:', 'easycheckout'); ?></p>
        <code style="word-break: break-all;"><?php echo esc_url(get_permalink($post->ID)); ?></code>
        <?php
    }

    /**
     * Render design meta box
     *
     * @param \WP_Post $post
     */
    public function render_design_meta_box($post) {
        $logo = self::get_meta($post->ID, 'logo');
        $primary_color = self::get_meta($post->ID, 'primary_color') ?: '#0066cc';
        ?>
        <p>
            <label for="easycheckout_logo"><?php esc_html_e('Logo URL', 'easycheckout'); ?></label><br>
            <input type="url" id="easycheckout_logo" name="easycheckout_logo" value="<?php echo esc_url($logo); ?>" class="widefat">
        </p>
        <p>
            <label for="easycheckout_primary_color"><?php esc_html_e('Primary Color', 'easycheckout'); ?></label><br>
            <input type="color" id="easycheckout_primary_color" name="easycheckout_primary_color"
                   value="<?php echo esc_attr($primary_color); ?>">
        </p>
        <?php
    }

    /**
     * Save meta data
     *
     * @param int $post_id
     * @param \WP_Post $post
     */
    public function save_meta($post_id, $post) {
        if (!isset($_POST['easycheckout_checkout_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['easycheckout_checkout_nonce'])), 'easycheckout_checkout_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Textfelder: Feldname => Bereinigung.
        $fields = [
            'checkout_slug' => 'sanitize_title',
            'description'   => 'sanitize_textarea_field',
            'currency'      => 'sanitize_text_field',
            'success_url'   => 'esc_url_raw',
            'cancel_url'    => 'esc_url_raw',
            'terms_url'     => 'esc_url_raw',
            'logo'          => 'esc_url_raw',
            'primary_color' => 'sanitize_hex_color',
        ];

        foreach ($fields as $name => $sanitize) {
            $field = 'easycheckout_' . $name;
            if (!isset($_POST[$field])) {
                continue;
            }
            // Mehrzeilige Beschreibung nicht vorab auf eine Zeile kürzen.
            $raw = $name === 'description'
                ? sanitize_textarea_field(wp_unslash($_POST[$field]))
                : sanitize_text_field(wp_unslash($_POST[$field]));
            update_post_meta($post_id, self::META . $name, (string) call_user_func($sanitize, $raw));
        }

        // Save VAT rate
        if (isset($_POST['easycheckout_vat_rate'])) {
            update_post_meta($post_id, self::META . 'vat_rate', floatval(wp_unslash($_POST['easycheckout_vat_rate'])));
        }

        // Save payment methods (nur bekannte Werte)
        $payment_methods = isset($_POST['easycheckout_payment_methods']) && is_array($_POST['easycheckout_payment_methods'])
            ? array_map('sanitize_key', wp_unslash($_POST['easycheckout_payment_methods']))
            : ['card'];
        $payment_methods = array_values(array_intersect($payment_methods, ['card', 'twint', 'bank_transfer']));
        update_post_meta($post_id, self::META . 'payment_methods', $payment_methods ?: ['card']);

        // Save email notifications
        update_post_meta($post_id, self::META . 'email_notifications', !empty($_POST['easycheckout_email_notifications']));

        // Save products
        $products = [];
        $posted = isset($_POST['easycheckout_products']) && is_array($_POST['easycheckout_products'])
            ? easycheckout_sanitize_deep(wp_unslash($_POST['easycheckout_products'])) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- easycheckout_sanitize_deep() bereinigt jedes Feld.
            : [];
        foreach ($posted as $product) {
            if (!is_array($product) || empty($product['name'])) {
                continue;
            }
            $products[] = [
                'id' => wp_generate_uuid4(),
                'name' => sanitize_text_field($product['name']),
                'description' => sanitize_textarea_field($product['description'] ?? ''),
                'price' => floatval($product['price'] ?? 0),
                'sku' => sanitize_text_field($product['sku'] ?? ''),
                'image' => esc_url_raw($product['image'] ?? ''),
                'maxQuantity' => intval($product['maxQuantity'] ?? 99),
            ];
        }
        update_post_meta($post_id, self::META . 'products', $products);
    }

    /**
     * Add custom columns
     *
     * @param array $columns
     * @return array
     */
    public function add_columns($columns) {
        $new_columns = [];
        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;
            if ($key === 'title') {
                $new_columns['shortcode'] = __('Shortcode', 'easycheckout');
                $new_columns['products'] = __('Products', 'easycheckout');
            }
        }
        return $new_columns;
    }

    /**
     * Render custom columns
     *
     * @param string $column
     * @param int $post_id
     */
    public function render_columns($column, $post_id) {
        switch ($column) {
            case 'shortcode':
                echo '<code>[easycheckout id="' . esc_html((string) (int) $post_id) . '"]</code>';
                break;
            case 'products':
                $products = self::get_meta($post_id, 'products') ?: [];
                echo esc_html((string) count($products));
                break;
        }
    }

    /**
     * Add row actions
     *
     * @param array $actions
     * @param \WP_Post $post
     * @return array
     */
    public function row_actions($actions, $post) {
        if ($post->post_type === self::POST_TYPE && $post->post_status === 'publish') {
            $actions['view_checkout'] = sprintf(
                '<a href="%s" target="_blank">%s</a>',
                esc_url(get_permalink($post->ID)),
                esc_html__('View Checkout', 'easycheckout')
            );
        }
        return $actions;
    }
}
