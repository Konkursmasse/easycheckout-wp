<?php
/**
 * Native EasyCheckout dashboard (WP admin app).
 *
 * Renders a native admin application (wp.element) and exposes a server-side
 * proxy so the app can call the EasyCheckout API with the stored JWT without
 * exposing the token or hitting CORS.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Native dashboard admin page + API proxy.
 */
class Native_Dashboard {

    private $page_slug = 'easycheckout';
    private $hook = '';

    /**
     * @var Native_API
     */
    private $api;

    public function __construct() {
        $this->api = new Native_API();
        add_action('admin_menu', [$this, 'add_menu'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('wp_ajax_easycheckout_native_login', [$this, 'ajax_login']);
        add_action('wp_ajax_easycheckout_native_register', [$this, 'ajax_register']);
        add_action('wp_ajax_easycheckout_native_logout', [$this, 'ajax_logout']);
        add_action('wp_ajax_easycheckout_native_proxy', [$this, 'ajax_proxy']);
    }

    public function add_menu() {
        $this->hook = add_menu_page(
            __('EasyCheckout', 'easycheckout'),
            __('EasyCheckout', 'easycheckout'),
            'manage_options',
            $this->page_slug,
            [$this, 'render'],
            'dashicons-cart',
            55
        );
    }

    public function enqueue($hook) {
        if ($hook !== $this->hook) {
            return;
        }

        wp_enqueue_style(
            'easycheckout-native',
            EASYCHECKOUT_PLUGIN_URL . 'assets/css/native-app.css',
            [],
            EASYCHECKOUT_VERSION
        );

        wp_enqueue_script(
            'easycheckout-native',
            EASYCHECKOUT_PLUGIN_URL . 'assets/js/native-app.js',
            ['wp-element'],
            EASYCHECKOUT_VERSION,
            true
        );

        wp_localize_script('easycheckout-native', 'ecNative', [
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('easycheckout_native'),
            'appUrl'   => $this->api->base_url(),
            'authed'   => $this->api->is_authenticated(),
            'merchant' => $this->api->get_merchant(),
        ]);
    }

    public function render() {
        echo '<div class="wrap" style="margin:0;"><div id="ec-native-app"></div></div>';
    }

    // --- AJAX ---------------------------------------------------------------

    private function guard() {
        if (!check_ajax_referer('easycheckout_native', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Nicht erlaubt.', 'easycheckout')], 403);
        }
    }

    public function ajax_login() {
        $this->guard();
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $pass  = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $r = $this->api->login($email, $pass);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        wp_send_json_success($r);
    }

    public function ajax_register() {
        $this->guard();
        $data = isset($_POST['data']) ? json_decode(wp_unslash($_POST['data']), true) : [];
        if (!is_array($data)) {
            $data = [];
        }
        $r = $this->api->register($data);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message()]);
        }
        wp_send_json_success($r);
    }

    public function ajax_logout() {
        $this->guard();
        $this->api->logout();
        wp_send_json_success();
    }

    /**
     * Generic authenticated proxy: { method, path, body } -> EasyCheckout API.
     */
    public function ajax_proxy() {
        $this->guard();
        $method = isset($_POST['method']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['method']))) : 'GET';
        $path   = isset($_POST['path']) ? wp_unslash($_POST['path']) : '';
        $body   = isset($_POST['body']) ? json_decode(wp_unslash($_POST['body']), true) : null;

        // Only allow /api/ paths.
        if (strpos($path, '/api/') !== 0) {
            wp_send_json_error(['message' => 'Invalid path'], 400);
        }

        $r = $this->api->request($method, $path, $body);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message(), 'status' => 0]);
        }
        wp_send_json_success(['status' => $r['status'], 'body' => $r['body']]);
    }
}
