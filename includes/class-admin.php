<?php
/**
 * EasyCheckout Admin Settings
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Admin settings class
 */
class Admin {

    /**
     * Settings page slug
     *
     * @var string
     */
    private $page_slug = 'easycheckout-settings';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu_page']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_ajax_easycheckout_test_connection', [$this, 'ajax_test_connection']);
        add_action('wp_ajax_easycheckout_clear_cache', [$this, 'ajax_clear_cache']);
        add_action('wp_ajax_easycheckout_register_webhook', [$this, 'ajax_register_webhook']);
        add_action('admin_notices', [$this, 'maybe_show_setup_notice']);
    }

    /**
     * AJAX: register this site's webhook URL with EasyCheckout and store the
     * returned signing secret.
     */
    public function ajax_register_webhook() {
        check_ajax_referer('easycheckout_admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'checkoutly')]);
        }

        $api = new API_Client();
        if (!$api->is_configured()) {
            wp_send_json_error(['message' => __('Bitte zuerst den API-Schlüssel speichern.', 'checkoutly')]);
        }

        $url = Webhook_Handler::get_webhook_url();
        $events = ['order.paid', 'order.failed', 'order.refunded', 'order.created'];

        $resp = $api->create_webhook_endpoint($url, $events);
        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()]);
        }

        $data = isset($resp['data']) && is_array($resp['data']) ? $resp['data'] : $resp;
        $secret = isset($data['secret']) ? $data['secret'] : '';
        if (!empty($secret)) {
            update_option('easycheckout_webhook_secret', $secret);
        }

        wp_send_json_success([
            'message' => __('Webhook registriert.', 'checkoutly'),
            'secret'  => $secret,
        ]);
    }

    /**
     * Show a one-line "connect your API key" notice on EasyCheckout admin pages
     * while no key is configured.
     */
    public function maybe_show_setup_notice() {
        if (!current_user_can('manage_options') || !function_exists('get_current_screen')) {
            return;
        }
        $screen = get_current_screen();
        $on_ec = $screen && (strpos($screen->id, 'easycheckout') !== false
            || (isset($screen->post_type) && $screen->post_type === CheckoutBuilder\Checkout_CPT::POST_TYPE));
        if (!$on_ec) {
            return;
        }

        $api = new API_Client();
        if ($api->is_configured()) {
            return;
        }

        $url = admin_url('options-general.php?page=' . $this->page_slug);
        echo '<div class="notice notice-warning"><p>'
            . wp_kses_post(sprintf(
                /* translators: %s: URL of the settings page */
                __('EasyCheckout ist noch nicht verbunden. <a href="%s">Jetzt API-Schlüssel hinterlegen</a>.', 'checkoutly'),
                esc_url($url)
            ))
            . '</p></div>';
    }

    /**
     * Add admin menu page
     */
    public function add_menu_page() {
        add_options_page(
            __('EasyCheckout Settings', 'checkoutly'),
            __('EasyCheckout Einstellungen', 'checkoutly'),
            'manage_options',
            $this->page_slug,
            [$this, 'render_settings_page']
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Connection settings
        register_setting('easycheckout_connection', 'easycheckout_api_key', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitize_api_key'],
        ]);
        register_setting('easycheckout_connection', 'easycheckout_api_url', [
            'type' => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default' => 'https://www.easycheckout.ch',
        ]);
        register_setting('easycheckout_connection', 'easycheckout_webhook_secret', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ]);
        register_setting('easycheckout_connection', 'easycheckout_test_mode', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'yes',
        ]);

        // Payment method settings
        register_setting('easycheckout_payments', 'easycheckout_payment_methods', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_payment_methods'],
            'default' => ['card', 'twint'],
        ]);

        // Advanced settings
        register_setting('easycheckout_advanced', 'easycheckout_debug_mode', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'no',
        ]);

        // Design settings (used by the embed iframe via ec_* params)
        register_setting('easycheckout_design', 'easycheckout_design', [
            'type' => 'array',
            'sanitize_callback' => [$this, 'sanitize_design'],
            'default' => [],
        ]);
    }

    /**
     * Sanitize design settings.
     *
     * @param mixed $value
     * @return array
     */
    public function sanitize_design($value) {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach (['primary', 'text', 'bg', 'button', 'buttontext'] as $k) {
            if (!empty($value[$k])) {
                $color = sanitize_hex_color($value[$k]);
                if ($color) {
                    $out[$k] = $color;
                }
            }
        }
        if (isset($value['radius']) && $value['radius'] !== '') {
            $out['radius'] = max(0, min(40, (int) $value['radius']));
        }
        $fs = isset($value['font_source']) ? $value['font_source'] : 'default';
        $out['font_source'] = in_array($fs, ['default', 'site', 'custom'], true) ? $fs : 'default';
        if (!empty($value['font_custom'])) {
            $out['font_custom'] = sanitize_text_field($value['font_custom']);
        }
        return $out;
    }

    /**
     * Sanitize API key
     *
     * @param string $value
     * @return string
     */
    public function sanitize_api_key($value) {
        $value = sanitize_text_field($value);

        if (empty($value)) {
            return '';
        }

        // Encrypt API key for storage
        return API_Client::encrypt_api_key($value);
    }

    /**
     * Sanitize payment methods
     *
     * @param mixed $value
     * @return array
     */
    public function sanitize_payment_methods($value) {
        if (!is_array($value)) {
            return ['card'];
        }

        $allowed = ['card', 'twint', 'bank_transfer'];
        return array_intersect($value, $allowed);
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook
     */
    public function enqueue_assets($hook) {
        if ($hook !== 'settings_page_' . $this->page_slug) {
            return;
        }

        wp_enqueue_style(
            'easycheckout-admin',
            EASYCHECKOUT_PLUGIN_URL . 'assets/css/admin.css',
            [],
            EASYCHECKOUT_VERSION
        );

        wp_enqueue_script(
            'easycheckout-admin',
            EASYCHECKOUT_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            EASYCHECKOUT_VERSION,
            true
        );

        wp_localize_script('easycheckout-admin', 'easycheckoutAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('easycheckout-admin'),
            'i18n' => [
                'testing' => __('Testing...', 'checkoutly'),
                'success' => __('Connection successful!', 'checkoutly'),
                'error' => __('Connection failed:', 'checkoutly'),
                'clearing' => __('Clearing...', 'checkoutly'),
                'cleared' => __('Cache cleared!', 'checkoutly'),
                'registering' => __('Registriere…', 'checkoutly'),
                'failed' => __('Fehler', 'checkoutly'),
                'registerError' => __('Fehler bei der Registrierung', 'checkoutly'),
            ],
            'webhookNonce' => wp_create_nonce('easycheckout_admin'),
        ]);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur die Wahl des angezeigten Reiters, keine Aktion.
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'connection';
        ?>
        <div class="wrap easycheckout-settings">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=connection"
                   class="nav-tab <?php echo $active_tab === 'connection' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Connection', 'checkoutly'); ?>
                </a>
                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=payments"
                   class="nav-tab <?php echo $active_tab === 'payments' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Payment Methods', 'checkoutly'); ?>
                </a>
                <?php if (class_exists('WooCommerce')) : ?>
                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=woocommerce"
                   class="nav-tab <?php echo $active_tab === 'woocommerce' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('WooCommerce', 'checkoutly'); ?>
                </a>
                <?php endif; ?>
                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=design"
                   class="nav-tab <?php echo $active_tab === 'design' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Design', 'checkoutly'); ?>
                </a>
                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=webhooks"
                   class="nav-tab <?php echo $active_tab === 'webhooks' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Webhooks', 'checkoutly'); ?>
                </a>
                <a href="?page=<?php echo esc_attr($this->page_slug); ?>&tab=logs"
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e('Logs', 'checkoutly'); ?>
                </a>
            </nav>

            <div class="tab-content">
                <?php
                switch ($active_tab) {
                    case 'payments':
                        $this->render_payments_tab();
                        break;
                    case 'woocommerce':
                        $this->render_woocommerce_tab();
                        break;
                    case 'design':
                        $this->render_design_tab();
                        break;
                    case 'webhooks':
                        $this->render_webhooks_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        $this->render_connection_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render connection tab
     */
    private function render_connection_tab() {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('easycheckout_connection'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="easycheckout_api_key"><?php esc_html_e('API Key', 'checkoutly'); ?></label>
                    </th>
                    <td>
                        <input type="password"
                               name="easycheckout_api_key"
                               id="easycheckout_api_key"
                               class="regular-text"
                               placeholder="<?php echo get_option('easycheckout_api_key') ? '••••••••' : ''; ?>"
                               autocomplete="off">
                        <p class="description">
                            <?php esc_html_e('Optional. In der Regel nicht nötig: Verbinde dein Konto einfach über das Menü „EasyCheckout" → Anmelden — der Schlüssel wird dann automatisch erstellt. Dieses Feld ist nur für die manuelle Verbindung mit einem bestehenden API-Schlüssel (Format eck_live_… / eck_test_…).', 'checkoutly'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="easycheckout_api_url"><?php esc_html_e('API URL', 'checkoutly'); ?></label>
                    </th>
                    <td>
                        <input type="url"
                               name="easycheckout_api_url"
                               id="easycheckout_api_url"
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('easycheckout_api_url', 'https://www.easycheckout.ch')); ?>">
                        <p class="description">
                            <?php esc_html_e('EasyCheckout API URL. Only change this for development.', 'checkoutly'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="easycheckout_test_mode"><?php esc_html_e('Test Mode', 'checkoutly'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="easycheckout_test_mode"
                                   id="easycheckout_test_mode"
                                   value="yes"
                                   <?php checked(get_option('easycheckout_test_mode', 'yes'), 'yes'); ?>>
                            <?php esc_html_e('Enable test mode (no real payments will be processed)', 'checkoutly'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <?php submit_button(__('Save Settings', 'checkoutly'), 'primary', 'submit', false); ?>
                <button type="button" id="easycheckout-test-connection" class="button">
                    <?php esc_html_e('Test Connection', 'checkoutly'); ?>
                </button>
                <span id="easycheckout-connection-result"></span>
            </p>
        </form>
        <?php
    }

    /**
     * Render payment methods tab
     */
    private function render_payments_tab() {
        $payment_methods = get_option('easycheckout_payment_methods', ['card', 'twint']);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('easycheckout_payments'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('Enabled Payment Methods', 'checkoutly'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox"
                                       name="easycheckout_payment_methods[]"
                                       value="card"
                                       <?php checked(in_array('card', $payment_methods)); ?>>
                                <?php esc_html_e('Kredit-/Debitkarten', 'checkoutly'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox"
                                       name="easycheckout_payment_methods[]"
                                       value="twint"
                                       <?php checked(in_array('twint', $payment_methods)); ?>>
                                <?php esc_html_e('TWINT', 'checkoutly'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox"
                                       name="easycheckout_payment_methods[]"
                                       value="bank_transfer"
                                       <?php checked(in_array('bank_transfer', $payment_methods)); ?>>
                                <?php esc_html_e('Bank Transfer (Swiss QR-Bill)', 'checkoutly'); ?>
                            </label>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render WooCommerce tab
     */
    private function render_woocommerce_tab() {
        if (!class_exists('WooCommerce')) {
            echo '<p>' . esc_html__('WooCommerce is not installed.', 'checkoutly') . '</p>';
            return;
        }
        ?>
        <p><?php esc_html_e('WooCommerce gateway settings are managed in WooCommerce > Settings > Payments.', 'checkoutly'); ?></p>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=checkout&section=easycheckout')); ?>" class="button">
                <?php esc_html_e('Configure WooCommerce Gateway', 'checkoutly'); ?>
            </a>
        </p>
        <?php
    }

    /**
     * Render design tab
     */
    private function render_design_tab() {
        $d = get_option('easycheckout_design', []);
        if (!is_array($d)) {
            $d = [];
        }
        $get = function ($k, $def = '') use ($d) {
            return isset($d[$k]) ? $d[$k] : $def;
        };
        $fs = $get('font_source', 'default');
        ?>
        <h2><?php esc_html_e('Design des eingebetteten Checkouts', 'checkoutly'); ?></h2>
        <p class="description">
            <?php esc_html_e('Diese Einstellungen werden an die eingebettete Checkout-Seite übergeben (Farben, Eckenradius, Schriftart). Leere Felder = Standard des Checkouts. Pro Einbettung überschreibbar via Shortcode-Attribute, z. B. [easycheckout slug="x" primary="#ff0000" font="site"].', 'checkoutly'); ?>
        </p>
        <form method="post" action="options.php">
            <?php settings_fields('easycheckout_design'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label><?php esc_html_e('Akzentfarbe (Primary)', 'checkoutly'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[primary]" value="<?php echo esc_attr($get('primary')); ?>" placeholder="#4F46E5" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e('Textfarbe', 'checkoutly'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[text]" value="<?php echo esc_attr($get('text')); ?>" placeholder="#111827" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e('Hintergrundfarbe', 'checkoutly'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[bg]" value="<?php echo esc_attr($get('bg')); ?>" placeholder="#F9FAFB" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e('Button-Farbe', 'checkoutly'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[button]" value="<?php echo esc_attr($get('button')); ?>" placeholder="#4F46E5" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e('Button-Textfarbe', 'checkoutly'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[buttontext]" value="<?php echo esc_attr($get('buttontext')); ?>" placeholder="#FFFFFF" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e('Eckenradius (px)', 'checkoutly'); ?></label></th>
                    <td><input type="number" min="0" max="40" name="easycheckout_design[radius]" value="<?php echo esc_attr($get('radius')); ?>" placeholder="12" class="small-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ec-font-source"><?php esc_html_e('Schriftart', 'checkoutly'); ?></label></th>
                    <td>
                        <select name="easycheckout_design[font_source]" id="ec-font-source">
                            <option value="default" <?php selected($fs, 'default'); ?>><?php esc_html_e('Standard (Inter)', 'checkoutly'); ?></option>
                            <option value="site" <?php selected($fs, 'site'); ?>><?php esc_html_e('Schriftart dieser Website übernehmen', 'checkoutly'); ?></option>
                            <option value="custom" <?php selected($fs, 'custom'); ?>><?php esc_html_e('Eigene (Google Font)', 'checkoutly'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('„Schriftart dieser Website übernehmen" liest die Font deiner Seite aus und wendet sie im Checkout an (passende Google-Font wird geladen).', 'checkoutly'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php esc_html_e('Eigene Schriftart (Name)', 'checkoutly'); ?></label></th>
                    <td>
                        <input type="text" name="easycheckout_design[font_custom]" value="<?php echo esc_attr($get('font_custom')); ?>" placeholder="Poppins" class="regular-text">
                        <p class="description"><?php esc_html_e('Google-Font-Name, z. B. „Poppins" oder „Roboto" (nur bei Auswahl „Eigene").', 'checkoutly'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <?php
    }

    /**
     * Render webhooks tab
     */
    private function render_webhooks_tab() {
        $webhook_url = Webhook_Handler::get_webhook_url();
        ?>
        <h2><?php esc_html_e('Webhook Configuration', 'checkoutly'); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Webhook URL', 'checkoutly'); ?></th>
                <td>
                    <code id="webhook-url"><?php echo esc_html($webhook_url); ?></code>
                    <button type="button" class="button" data-copy="<?php echo esc_attr($webhook_url); ?>">
                        <?php esc_html_e('Copy', 'checkoutly'); ?>
                    </button>
                    <p class="description">
                        <?php esc_html_e('Add this URL to your EasyCheckout dashboard under Settings > Webhooks.', 'checkoutly'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <form method="post" action="options.php">
            <?php settings_fields('easycheckout_connection'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="easycheckout_webhook_secret"><?php esc_html_e('Webhook Secret', 'checkoutly'); ?></label>
                    </th>
                    <td>
                        <input type="password"
                               name="easycheckout_webhook_secret"
                               id="easycheckout_webhook_secret"
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('easycheckout_webhook_secret', '')); ?>"
                               autocomplete="off">
                        <p class="description">
                            <?php esc_html_e('Webhook signing secret from your EasyCheckout dashboard.', 'checkoutly'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h3><?php esc_html_e('Webhook Events', 'checkoutly'); ?></h3>
        <p><?php esc_html_e('The following events are supported:', 'checkoutly'); ?></p>
        <ul class="ul-disc">
            <li><code>order.paid</code> - <?php esc_html_e('Triggered when a payment is completed', 'checkoutly'); ?></li>
            <li><code>order.failed</code> - <?php esc_html_e('Triggered when a payment fails', 'checkoutly'); ?></li>
            <li><code>order.refunded</code> - <?php esc_html_e('Triggered when a refund is processed', 'checkoutly'); ?></li>
        </ul>

        <h3><?php esc_html_e('Automatische Registrierung', 'checkoutly'); ?></h3>
        <p><?php esc_html_e('Registriert diese Webhook-URL direkt in deinem EasyCheckout-Konto (API-Key mit Scope webhooks:write nötig). Der Signing-Secret wird automatisch übernommen.', 'checkoutly'); ?></p>
        <p>
            <button type="button" class="button button-primary" id="ec-register-webhook"><?php esc_html_e('Webhook automatisch registrieren', 'checkoutly'); ?></button>
            <span id="ec-register-webhook-result" style="margin-left:10px;"></span>
        </p>
        <?php
    }

    /**
     * Render logs tab
     */
    private function render_logs_tab() {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('easycheckout_advanced'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="easycheckout_debug_mode"><?php esc_html_e('Debug Mode', 'checkoutly'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="easycheckout_debug_mode"
                                   id="easycheckout_debug_mode"
                                   value="yes"
                                   <?php checked(get_option('easycheckout_debug_mode', 'no'), 'yes'); ?>>
                            <?php esc_html_e('Enable debug logging', 'checkoutly'); ?>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Logs will be written to WooCommerce logs (if available) or PHP error log.', 'checkoutly'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <?php submit_button(__('Save Settings', 'checkoutly'), 'primary', 'submit', false); ?>
                <button type="button" id="easycheckout-clear-cache" class="button">
                    <?php esc_html_e('Clear Cache', 'checkoutly'); ?>
                </button>
                <span id="easycheckout-cache-result"></span>
            </p>
        </form>

        <h3><?php esc_html_e('Recent Transactions', 'checkoutly'); ?></h3>
        <?php $this->render_transactions_table(); ?>
        <?php
    }

    /**
     * Render transactions table
     */
    private function render_transactions_table() {
        global $wpdb;

        $table = $wpdb->prefix . 'easycheckout_transactions';
        $transactions = $wpdb->get_results(
            "SELECT * FROM $table ORDER BY created_at DESC LIMIT 50"
        );

        if (empty($transactions)) {
            echo '<p>' . esc_html__('No transactions yet.', 'checkoutly') . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Date', 'checkoutly'); ?></th>
                    <th><?php esc_html_e('EC Order ID', 'checkoutly'); ?></th>
                    <th><?php esc_html_e('WC Order', 'checkoutly'); ?></th>
                    <th><?php esc_html_e('Amount', 'checkoutly'); ?></th>
                    <th><?php esc_html_e('Status', 'checkoutly'); ?></th>
                    <th><?php esc_html_e('Payment Method', 'checkoutly'); ?></th>
                    <th><?php esc_html_e('Webhook', 'checkoutly'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $tx) : ?>
                <tr>
                    <td><?php echo esc_html($tx->created_at); ?></td>
                    <td><code><?php echo esc_html($tx->ec_order_id); ?></code></td>
                    <td>
                        <?php if ($tx->wc_order_id) : ?>
                            <a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $tx->wc_order_id . '&action=edit')); ?>">
                                #<?php echo esc_html($tx->wc_order_id); ?>
                            </a>
                        <?php else : ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($tx->amount . ' ' . $tx->currency); ?></td>
                    <td>
                        <span class="ec-status ec-status-<?php echo esc_attr($tx->status); ?>">
                            <?php echo esc_html($tx->status); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($tx->payment_method ?: '-'); ?></td>
                    <td><?php echo $tx->webhook_received ? '&#10003;' : '-'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('easycheckout-admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'checkoutly')]);
        }

        $api = new API_Client();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Connection successful!', 'checkoutly')]);
    }

    /**
     * AJAX: Clear cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('easycheckout-admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'checkoutly')]);
        }

        $api = new API_Client();
        $api->clear_cache();

        wp_send_json_success(['message' => __('Cache cleared!', 'checkoutly')]);
    }
}
