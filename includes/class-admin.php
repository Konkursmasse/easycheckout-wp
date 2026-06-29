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
            wp_send_json_error(['message' => __('Keine Berechtigung.', 'easycheckout')]);
        }

        $api = new API_Client();
        if (!$api->is_configured()) {
            wp_send_json_error(['message' => __('Bitte zuerst den API-Schlüssel speichern.', 'easycheckout')]);
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
            'message' => __('Webhook registriert.', 'easycheckout'),
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
            || (isset($screen->post_type) && $screen->post_type === 'ec_checkout'));
        if (!$on_ec) {
            return;
        }

        $api = new API_Client();
        if ($api->is_configured()) {
            return;
        }

        $url = admin_url('options-general.php?page=' . $this->page_slug);
        echo '<div class="notice notice-warning"><p>'
            . sprintf(
                wp_kses_post(__('EasyCheckout ist noch nicht verbunden. <a href="%s">Jetzt API-Schlüssel hinterlegen</a>.', 'easycheckout')),
                esc_url($url)
            )
            . '</p></div>';
    }

    /**
     * Add admin menu page
     */
    public function add_menu_page() {
        add_options_page(
            __('EasyCheckout Settings', 'easycheckout'),
            __('EasyCheckout', 'easycheckout'),
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
                'testing' => __('Testing...', 'easycheckout'),
                'success' => __('Connection successful!', 'easycheckout'),
                'error' => __('Connection failed:', 'easycheckout'),
                'clearing' => __('Clearing...', 'easycheckout'),
                'cleared' => __('Cache cleared!', 'easycheckout'),
            ],
        ]);
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'connection';
        ?>
        <div class="wrap easycheckout-settings">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=<?php echo $this->page_slug; ?>&tab=connection"
                   class="nav-tab <?php echo $active_tab === 'connection' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Connection', 'easycheckout'); ?>
                </a>
                <a href="?page=<?php echo $this->page_slug; ?>&tab=payments"
                   class="nav-tab <?php echo $active_tab === 'payments' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Payment Methods', 'easycheckout'); ?>
                </a>
                <?php if (class_exists('WooCommerce')) : ?>
                <a href="?page=<?php echo $this->page_slug; ?>&tab=woocommerce"
                   class="nav-tab <?php echo $active_tab === 'woocommerce' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('WooCommerce', 'easycheckout'); ?>
                </a>
                <?php endif; ?>
                <a href="?page=<?php echo $this->page_slug; ?>&tab=design"
                   class="nav-tab <?php echo $active_tab === 'design' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Design', 'easycheckout'); ?>
                </a>
                <a href="?page=<?php echo $this->page_slug; ?>&tab=webhooks"
                   class="nav-tab <?php echo $active_tab === 'webhooks' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Webhooks', 'easycheckout'); ?>
                </a>
                <a href="?page=<?php echo $this->page_slug; ?>&tab=logs"
                   class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php _e('Logs', 'easycheckout'); ?>
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
                        <label for="easycheckout_api_key"><?php _e('API Key', 'easycheckout'); ?></label>
                    </th>
                    <td>
                        <input type="password"
                               name="easycheckout_api_key"
                               id="easycheckout_api_key"
                               class="regular-text"
                               placeholder="<?php echo get_option('easycheckout_api_key') ? '••••••••' : ''; ?>"
                               autocomplete="off">
                        <p class="description">
                            <?php _e('Your EasyCheckout API key. Find it in your EasyCheckout dashboard under Settings > API.', 'easycheckout'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="easycheckout_api_url"><?php _e('API URL', 'easycheckout'); ?></label>
                    </th>
                    <td>
                        <input type="url"
                               name="easycheckout_api_url"
                               id="easycheckout_api_url"
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('easycheckout_api_url', 'https://www.easycheckout.ch')); ?>">
                        <p class="description">
                            <?php _e('EasyCheckout API URL. Only change this for development.', 'easycheckout'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="easycheckout_test_mode"><?php _e('Test Mode', 'easycheckout'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="easycheckout_test_mode"
                                   id="easycheckout_test_mode"
                                   value="yes"
                                   <?php checked(get_option('easycheckout_test_mode', 'yes'), 'yes'); ?>>
                            <?php _e('Enable test mode (no real payments will be processed)', 'easycheckout'); ?>
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <?php submit_button(__('Save Settings', 'easycheckout'), 'primary', 'submit', false); ?>
                <button type="button" id="easycheckout-test-connection" class="button">
                    <?php _e('Test Connection', 'easycheckout'); ?>
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
                    <th scope="row"><?php _e('Enabled Payment Methods', 'easycheckout'); ?></th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox"
                                       name="easycheckout_payment_methods[]"
                                       value="card"
                                       <?php checked(in_array('card', $payment_methods)); ?>>
                                <?php _e('Credit/Debit Cards (Stripe)', 'easycheckout'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox"
                                       name="easycheckout_payment_methods[]"
                                       value="twint"
                                       <?php checked(in_array('twint', $payment_methods)); ?>>
                                <?php _e('TWINT', 'easycheckout'); ?>
                            </label>
                            <br>
                            <label>
                                <input type="checkbox"
                                       name="easycheckout_payment_methods[]"
                                       value="bank_transfer"
                                       <?php checked(in_array('bank_transfer', $payment_methods)); ?>>
                                <?php _e('Bank Transfer (Swiss QR-Bill)', 'easycheckout'); ?>
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
            echo '<p>' . __('WooCommerce is not installed.', 'easycheckout') . '</p>';
            return;
        }
        ?>
        <p><?php _e('WooCommerce gateway settings are managed in WooCommerce > Settings > Payments.', 'easycheckout'); ?></p>
        <p>
            <a href="<?php echo admin_url('admin.php?page=wc-settings&tab=checkout&section=easycheckout'); ?>" class="button">
                <?php _e('Configure WooCommerce Gateway', 'easycheckout'); ?>
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
        <h2><?php _e('Design des eingebetteten Checkouts', 'easycheckout'); ?></h2>
        <p class="description">
            <?php _e('Diese Einstellungen werden an die eingebettete Checkout-Seite übergeben (Farben, Eckenradius, Schriftart). Leere Felder = Standard des Checkouts. Pro Einbettung überschreibbar via Shortcode-Attribute, z. B. [easycheckout slug="x" primary="#ff0000" font="site"].', 'easycheckout'); ?>
        </p>
        <form method="post" action="options.php">
            <?php settings_fields('easycheckout_design'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><label><?php _e('Akzentfarbe (Primary)', 'easycheckout'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[primary]" value="<?php echo esc_attr($get('primary')); ?>" placeholder="#4F46E5" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Textfarbe', 'easycheckout'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[text]" value="<?php echo esc_attr($get('text')); ?>" placeholder="#111827" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Hintergrundfarbe', 'easycheckout'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[bg]" value="<?php echo esc_attr($get('bg')); ?>" placeholder="#F9FAFB" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Button-Farbe', 'easycheckout'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[button]" value="<?php echo esc_attr($get('button')); ?>" placeholder="#4F46E5" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Button-Textfarbe', 'easycheckout'); ?></label></th>
                    <td><input type="text" name="easycheckout_design[buttontext]" value="<?php echo esc_attr($get('buttontext')); ?>" placeholder="#FFFFFF" class="regular-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Eckenradius (px)', 'easycheckout'); ?></label></th>
                    <td><input type="number" min="0" max="40" name="easycheckout_design[radius]" value="<?php echo esc_attr($get('radius')); ?>" placeholder="12" class="small-text"></td>
                </tr>
                <tr>
                    <th scope="row"><label for="ec-font-source"><?php _e('Schriftart', 'easycheckout'); ?></label></th>
                    <td>
                        <select name="easycheckout_design[font_source]" id="ec-font-source">
                            <option value="default" <?php selected($fs, 'default'); ?>><?php _e('Standard (Inter)', 'easycheckout'); ?></option>
                            <option value="site" <?php selected($fs, 'site'); ?>><?php _e('Schriftart dieser Website übernehmen', 'easycheckout'); ?></option>
                            <option value="custom" <?php selected($fs, 'custom'); ?>><?php _e('Eigene (Google Font)', 'easycheckout'); ?></option>
                        </select>
                        <p class="description"><?php _e('„Schriftart dieser Website übernehmen" liest die Font deiner Seite aus und wendet sie im Checkout an (passende Google-Font wird geladen).', 'easycheckout'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label><?php _e('Eigene Schriftart (Name)', 'easycheckout'); ?></label></th>
                    <td>
                        <input type="text" name="easycheckout_design[font_custom]" value="<?php echo esc_attr($get('font_custom')); ?>" placeholder="Poppins" class="regular-text">
                        <p class="description"><?php _e('Google-Font-Name, z. B. „Poppins" oder „Roboto" (nur bei Auswahl „Eigene").', 'easycheckout'); ?></p>
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
        <h2><?php _e('Webhook Configuration', 'easycheckout'); ?></h2>

        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Webhook URL', 'easycheckout'); ?></th>
                <td>
                    <code id="webhook-url"><?php echo esc_html($webhook_url); ?></code>
                    <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js($webhook_url); ?>')">
                        <?php _e('Copy', 'easycheckout'); ?>
                    </button>
                    <p class="description">
                        <?php _e('Add this URL to your EasyCheckout dashboard under Settings > Webhooks.', 'easycheckout'); ?>
                    </p>
                </td>
            </tr>
        </table>

        <form method="post" action="options.php">
            <?php settings_fields('easycheckout_connection'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="easycheckout_webhook_secret"><?php _e('Webhook Secret', 'easycheckout'); ?></label>
                    </th>
                    <td>
                        <input type="password"
                               name="easycheckout_webhook_secret"
                               id="easycheckout_webhook_secret"
                               class="regular-text"
                               value="<?php echo esc_attr(get_option('easycheckout_webhook_secret', '')); ?>"
                               autocomplete="off">
                        <p class="description">
                            <?php _e('Webhook signing secret from your EasyCheckout dashboard.', 'easycheckout'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <?php submit_button(); ?>
        </form>

        <h3><?php _e('Webhook Events', 'easycheckout'); ?></h3>
        <p><?php _e('The following events are supported:', 'easycheckout'); ?></p>
        <ul class="ul-disc">
            <li><code>order.paid</code> - <?php _e('Triggered when a payment is completed', 'easycheckout'); ?></li>
            <li><code>order.failed</code> - <?php _e('Triggered when a payment fails', 'easycheckout'); ?></li>
            <li><code>order.refunded</code> - <?php _e('Triggered when a refund is processed', 'easycheckout'); ?></li>
        </ul>

        <h3><?php _e('Automatische Registrierung', 'easycheckout'); ?></h3>
        <p><?php _e('Registriert diese Webhook-URL direkt in deinem EasyCheckout-Konto (API-Key mit Scope webhooks:write nötig). Der Signing-Secret wird automatisch übernommen.', 'easycheckout'); ?></p>
        <p>
            <button type="button" class="button button-primary" id="ec-register-webhook"><?php _e('Webhook automatisch registrieren', 'easycheckout'); ?></button>
            <span id="ec-register-webhook-result" style="margin-left:10px;"></span>
        </p>
        <script>
        (function(){
            var btn = document.getElementById('ec-register-webhook');
            if (!btn) { return; }
            btn.addEventListener('click', function(){
                var out = document.getElementById('ec-register-webhook-result');
                btn.disabled = true;
                out.style.color = '';
                out.textContent = '<?php echo esc_js(__('Registriere…', 'easycheckout')); ?>';
                var body = new URLSearchParams();
                body.append('action', 'easycheckout_register_webhook');
                body.append('nonce', '<?php echo esc_js(wp_create_nonce('easycheckout_admin')); ?>');
                fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
                    .then(function(r){ return r.json(); })
                    .then(function(j){
                        btn.disabled = false;
                        var msg = (j && j.data && j.data.message) ? j.data.message : (j && j.success ? 'OK' : '<?php echo esc_js(__('Fehler', 'easycheckout')); ?>');
                        out.textContent = msg;
                        out.style.color = (j && j.success) ? '#008a20' : '#d63638';
                        if (j && j.success && j.data && j.data.secret) {
                            var s = document.getElementById('easycheckout_webhook_secret');
                            if (s) { s.value = j.data.secret; }
                        }
                    })
                    .catch(function(){
                        btn.disabled = false;
                        out.textContent = '<?php echo esc_js(__('Fehler bei der Registrierung', 'easycheckout')); ?>';
                        out.style.color = '#d63638';
                    });
            });
        })();
        </script>
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
                        <label for="easycheckout_debug_mode"><?php _e('Debug Mode', 'easycheckout'); ?></label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox"
                                   name="easycheckout_debug_mode"
                                   id="easycheckout_debug_mode"
                                   value="yes"
                                   <?php checked(get_option('easycheckout_debug_mode', 'no'), 'yes'); ?>>
                            <?php _e('Enable debug logging', 'easycheckout'); ?>
                        </label>
                        <p class="description">
                            <?php _e('Logs will be written to WooCommerce logs (if available) or PHP error log.', 'easycheckout'); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <?php submit_button(__('Save Settings', 'easycheckout'), 'primary', 'submit', false); ?>
                <button type="button" id="easycheckout-clear-cache" class="button">
                    <?php _e('Clear Cache', 'easycheckout'); ?>
                </button>
                <span id="easycheckout-cache-result"></span>
            </p>
        </form>

        <h3><?php _e('Recent Transactions', 'easycheckout'); ?></h3>
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
            echo '<p>' . __('No transactions yet.', 'easycheckout') . '</p>';
            return;
        }
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Date', 'easycheckout'); ?></th>
                    <th><?php _e('EC Order ID', 'easycheckout'); ?></th>
                    <th><?php _e('WC Order', 'easycheckout'); ?></th>
                    <th><?php _e('Amount', 'easycheckout'); ?></th>
                    <th><?php _e('Status', 'easycheckout'); ?></th>
                    <th><?php _e('Payment Method', 'easycheckout'); ?></th>
                    <th><?php _e('Webhook', 'easycheckout'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($transactions as $tx) : ?>
                <tr>
                    <td><?php echo esc_html($tx->created_at); ?></td>
                    <td><code><?php echo esc_html($tx->ec_order_id); ?></code></td>
                    <td>
                        <?php if ($tx->wc_order_id) : ?>
                            <a href="<?php echo admin_url('post.php?post=' . $tx->wc_order_id . '&action=edit'); ?>">
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
                    <td><?php echo $tx->webhook_received ? '✓' : '-'; ?></td>
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
            wp_send_json_error(['message' => __('Permission denied', 'easycheckout')]);
        }

        $api = new API_Client();
        $result = $api->test_connection();

        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        }

        wp_send_json_success(['message' => __('Connection successful!', 'easycheckout')]);
    }

    /**
     * AJAX: Clear cache
     */
    public function ajax_clear_cache() {
        check_ajax_referer('easycheckout-admin', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'easycheckout')]);
        }

        $api = new API_Client();
        $api->clear_cache();

        wp_send_json_success(['message' => __('Cache cleared!', 'easycheckout')]);
    }
}
