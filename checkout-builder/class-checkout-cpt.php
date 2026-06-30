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
     * @var string
     */
    const POST_TYPE = 'ec_checkout';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_meta']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
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
            'rewrite' => ['slug' => 'checkout', 'with_front' => false],
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
            '_ec_checkout_slug' => ['type' => 'string', 'default' => ''],
            '_ec_description' => ['type' => 'string', 'default' => ''],
            '_ec_currency' => ['type' => 'string', 'default' => 'CHF'],
            '_ec_vat_rate' => ['type' => 'number', 'default' => 0],
            '_ec_success_url' => ['type' => 'string', 'default' => ''],
            '_ec_cancel_url' => ['type' => 'string', 'default' => ''],
            '_ec_terms_url' => ['type' => 'string', 'default' => ''],
            '_ec_logo' => ['type' => 'string', 'default' => ''],
            '_ec_primary_color' => ['type' => 'string', 'default' => '#0066cc'],
            '_ec_payment_methods' => ['type' => 'array', 'default' => ['card', 'twint']],
            '_ec_products' => ['type' => 'array', 'default' => []],
            '_ec_email_notifications' => ['type' => 'boolean', 'default' => true],
        ];

        foreach ($meta_fields as $key => $args) {
            register_post_meta(self::POST_TYPE, $key, [
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
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'ec_checkout_settings',
            __('Checkout Settings', 'easycheckout'),
            [$this, 'render_settings_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'ec_checkout_products',
            __('Products', 'easycheckout'),
            [$this, 'render_products_meta_box'],
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'ec_checkout_shortcode',
            __('Shortcode', 'easycheckout'),
            [$this, 'render_shortcode_meta_box'],
            self::POST_TYPE,
            'side',
            'default'
        );

        add_meta_box(
            'ec_checkout_design',
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
        wp_nonce_field('ec_checkout_meta', 'ec_checkout_nonce');

        $slug = get_post_meta($post->ID, '_ec_checkout_slug', true);
        $description = get_post_meta($post->ID, '_ec_description', true);
        $currency = get_post_meta($post->ID, '_ec_currency', true) ?: 'CHF';
        $vat_rate = get_post_meta($post->ID, '_ec_vat_rate', true);
        $success_url = get_post_meta($post->ID, '_ec_success_url', true);
        $cancel_url = get_post_meta($post->ID, '_ec_cancel_url', true);
        $terms_url = get_post_meta($post->ID, '_ec_terms_url', true);
        $payment_methods = get_post_meta($post->ID, '_ec_payment_methods', true) ?: ['card', 'twint'];
        $email_notifications = get_post_meta($post->ID, '_ec_email_notifications', true);
        ?>
        <table class="form-table">
            <tr>
                <th><label for="ec_checkout_slug"><?php _e('Checkout Slug', 'easycheckout'); ?></label></th>
                <td>
                    <input type="text" id="ec_checkout_slug" name="ec_checkout_slug"
                           value="<?php echo esc_attr($slug); ?>" class="regular-text">
                    <p class="description">
                        <?php _e('Slug des Checkouts aus deinem EasyCheckout-Konto. Produkte und Design werden von dort übernommen; der Button leitet auf die gehostete Bezahlseite (/c/{slug}). Einbetten z. B. mit [easycheckout slug="dein-slug"].', 'easycheckout'); ?>
                    </p>
                </td>
            </tr>
            <tr>
                <th><label for="ec_description"><?php _e('Description', 'easycheckout'); ?></label></th>
                <td>
                    <textarea id="ec_description" name="ec_description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label for="ec_currency"><?php _e('Currency', 'easycheckout'); ?></label></th>
                <td>
                    <select id="ec_currency" name="ec_currency">
                        <option value="CHF" <?php selected($currency, 'CHF'); ?>>CHF</option>
                        <option value="EUR" <?php selected($currency, 'EUR'); ?>>EUR</option>
                        <option value="USD" <?php selected($currency, 'USD'); ?>>USD</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="ec_vat_rate"><?php _e('VAT Rate (%)', 'easycheckout'); ?></label></th>
                <td>
                    <input type="number" id="ec_vat_rate" name="ec_vat_rate"
                           value="<?php echo esc_attr($vat_rate); ?>" step="0.1" min="0" max="100" class="small-text">
                    <p class="description"><?php _e('Leave at 0 for no VAT.', 'easycheckout'); ?></p>
                </td>
            </tr>
            <tr>
                <th><?php _e('Payment Methods', 'easycheckout'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ec_payment_methods[]" value="card"
                               <?php checked(in_array('card', $payment_methods)); ?>>
                        <?php _e('Credit/Debit Cards', 'easycheckout'); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="ec_payment_methods[]" value="twint"
                               <?php checked(in_array('twint', $payment_methods)); ?>>
                        <?php _e('TWINT', 'easycheckout'); ?>
                    </label><br>
                    <label>
                        <input type="checkbox" name="ec_payment_methods[]" value="bank_transfer"
                               <?php checked(in_array('bank_transfer', $payment_methods)); ?>>
                        <?php _e('Bank Transfer (QR-Bill)', 'easycheckout'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="ec_success_url"><?php _e('Success URL', 'easycheckout'); ?></label></th>
                <td>
                    <input type="url" id="ec_success_url" name="ec_success_url"
                           value="<?php echo esc_url($success_url); ?>" class="regular-text">
                    <p class="description"><?php _e('Redirect URL after successful payment.', 'easycheckout'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="ec_cancel_url"><?php _e('Cancel URL', 'easycheckout'); ?></label></th>
                <td>
                    <input type="url" id="ec_cancel_url" name="ec_cancel_url"
                           value="<?php echo esc_url($cancel_url); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><label for="ec_terms_url"><?php _e('Terms URL', 'easycheckout'); ?></label></th>
                <td>
                    <input type="url" id="ec_terms_url" name="ec_terms_url"
                           value="<?php echo esc_url($terms_url); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th><?php _e('Email Notifications', 'easycheckout'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="ec_email_notifications" value="1"
                               <?php checked($email_notifications, true); ?>>
                        <?php _e('Send email notifications for orders', 'easycheckout'); ?>
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
        $products = get_post_meta($post->ID, '_ec_products', true) ?: [];
        ?>
        <div class="notice notice-info inline" style="margin:0 0 12px; padding:8px 12px;">
            <p style="margin:0;"><?php _e('Hinweis: Produkte werden in deinem EasyCheckout-Konto verwaltet und beim Einbetten automatisch aus dem Checkout (per Slug oben) geladen. Diese lokale Liste ist nur ein optionaler Fallback und wird nicht ins EasyCheckout-Konto synchronisiert.', 'easycheckout'); ?></p>
        </div>
        <div id="ec-products-container">
            <div id="ec-products-list">
                <?php foreach ($products as $index => $product) : ?>
                    <?php $this->render_product_row($index, $product); ?>
                <?php endforeach; ?>
            </div>

            <button type="button" class="button" id="ec-add-product">
                <?php _e('Add Product', 'easycheckout'); ?>
            </button>
        </div>

        <script type="text/template" id="ec-product-template">
            <?php $this->render_product_row('{{INDEX}}', []); ?>
        </script>

        <style>
            .ec-product-row {
                background: #f9f9f9;
                padding: 15px;
                margin-bottom: 10px;
                border: 1px solid #ddd;
            }
            .ec-product-row .ec-product-fields {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .ec-product-row label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }
            .ec-product-row input,
            .ec-product-row textarea {
                width: 100%;
            }
            .ec-product-row .ec-product-header {
                display: flex;
                justify-content: space-between;
                margin-bottom: 10px;
            }
        </style>

        <script>
        jQuery(function($) {
            var index = <?php echo count($products); ?>;

            $('#ec-add-product').on('click', function() {
                var template = $('#ec-product-template').html();
                var html = template.replace(/\{\{INDEX\}\}/g, index);
                $('#ec-products-list').append(html);
                index++;
            });

            $(document).on('click', '.ec-remove-product', function() {
                $(this).closest('.ec-product-row').remove();
            });
        });
        </script>
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
        ?>
        <div class="ec-product-row">
            <div class="ec-product-header">
                <strong><?php printf(__('Product #%s', 'easycheckout'), is_numeric($index) ? $index + 1 : ''); ?></strong>
                <button type="button" class="button-link ec-remove-product"><?php _e('Remove', 'easycheckout'); ?></button>
            </div>
            <div class="ec-product-fields">
                <div>
                    <label><?php _e('Name', 'easycheckout'); ?></label>
                    <input type="text" name="ec_products[<?php echo $index; ?>][name]"
                           value="<?php echo esc_attr($product['name']); ?>">
                </div>
                <div>
                    <label><?php _e('Price', 'easycheckout'); ?></label>
                    <input type="number" name="ec_products[<?php echo $index; ?>][price]"
                           value="<?php echo esc_attr($product['price']); ?>" step="0.01" min="0">
                </div>
                <div>
                    <label><?php _e('SKU', 'easycheckout'); ?></label>
                    <input type="text" name="ec_products[<?php echo $index; ?>][sku]"
                           value="<?php echo esc_attr($product['sku']); ?>">
                </div>
                <div>
                    <label><?php _e('Max Quantity', 'easycheckout'); ?></label>
                    <input type="number" name="ec_products[<?php echo $index; ?>][maxQuantity]"
                           value="<?php echo esc_attr($product['maxQuantity']); ?>" min="1">
                </div>
                <div style="grid-column: 1 / -1;">
                    <label><?php _e('Description', 'easycheckout'); ?></label>
                    <textarea name="ec_products[<?php echo $index; ?>][description]" rows="2"><?php echo esc_textarea($product['description']); ?></textarea>
                </div>
                <div style="grid-column: 1 / -1;">
                    <label><?php _e('Image URL', 'easycheckout'); ?></label>
                    <input type="url" name="ec_products[<?php echo $index; ?>][image]"
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
            echo '<p>' . __('Publish the checkout to get the shortcode.', 'easycheckout') . '</p>';
            return;
        }
        ?>
        <p><?php _e('Copy this shortcode to embed the checkout:', 'easycheckout'); ?></p>
        <code>[easycheckout id="<?php echo $post->ID; ?>"]</code>

        <p style="margin-top: 15px;"><?php _e('Or use the direct URL:', 'easycheckout'); ?></p>
        <code style="word-break: break-all;"><?php echo get_permalink($post->ID); ?></code>
        <?php
    }

    /**
     * Render design meta box
     *
     * @param \WP_Post $post
     */
    public function render_design_meta_box($post) {
        $logo = get_post_meta($post->ID, '_ec_logo', true);
        $primary_color = get_post_meta($post->ID, '_ec_primary_color', true) ?: '#0066cc';
        ?>
        <p>
            <label for="ec_logo"><?php _e('Logo URL', 'easycheckout'); ?></label><br>
            <input type="url" id="ec_logo" name="ec_logo" value="<?php echo esc_url($logo); ?>" class="widefat">
        </p>
        <p>
            <label for="ec_primary_color"><?php _e('Primary Color', 'easycheckout'); ?></label><br>
            <input type="color" id="ec_primary_color" name="ec_primary_color"
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
        if (!isset($_POST['ec_checkout_nonce']) || !wp_verify_nonce($_POST['ec_checkout_nonce'], 'ec_checkout_meta')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Save text fields
        $text_fields = [
            'ec_checkout_slug' => '_ec_checkout_slug',
            'ec_description' => '_ec_description',
            'ec_currency' => '_ec_currency',
            'ec_success_url' => '_ec_success_url',
            'ec_cancel_url' => '_ec_cancel_url',
            'ec_terms_url' => '_ec_terms_url',
            'ec_logo' => '_ec_logo',
            'ec_primary_color' => '_ec_primary_color',
        ];

        foreach ($text_fields as $field => $meta_key) {
            if (isset($_POST[$field])) {
                update_post_meta($post_id, $meta_key, sanitize_text_field($_POST[$field]));
            }
        }

        // Save VAT rate
        if (isset($_POST['ec_vat_rate'])) {
            update_post_meta($post_id, '_ec_vat_rate', floatval($_POST['ec_vat_rate']));
        }

        // Save payment methods
        $payment_methods = isset($_POST['ec_payment_methods']) ?
            array_map('sanitize_text_field', $_POST['ec_payment_methods']) : ['card'];
        update_post_meta($post_id, '_ec_payment_methods', $payment_methods);

        // Save email notifications
        update_post_meta($post_id, '_ec_email_notifications', !empty($_POST['ec_email_notifications']));

        // Save products
        $products = [];
        if (isset($_POST['ec_products']) && is_array($_POST['ec_products'])) {
            foreach ($_POST['ec_products'] as $product) {
                if (empty($product['name'])) {
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
        }
        update_post_meta($post_id, '_ec_products', $products);
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
                echo '<code>[easycheckout id="' . $post_id . '"]</code>';
                break;
            case 'products':
                $products = get_post_meta($post_id, '_ec_products', true) ?: [];
                echo count($products);
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
                get_permalink($post->ID),
                __('View Checkout', 'easycheckout')
            );
        }
        return $actions;
    }
}
