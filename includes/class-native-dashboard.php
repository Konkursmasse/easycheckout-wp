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
    private $hooks = [];

    /**
     * WP-Untermenues -> native View. slug => [Label, view-key].
     * Der Parent-Slug 'easycheckout' benennt zugleich den ersten Untereintrag.
     */
    private function sections() {
        return [
            'easycheckout'  => [__('Übersicht', 'easycheckout'), 'overview'],
            'ec-checkouts'  => [__('Checkouts', 'easycheckout'), 'checkouts'],
            'ec-orders'     => [__('Bestellungen', 'easycheckout'), 'orders'],
            'ec-customers'  => [__('Kunden', 'easycheckout'), 'customers'],
            'ec-invoices'   => [__('Rechnungen', 'easycheckout'), 'invoices'],
            'ec-emails'     => [__('E-Mails', 'easycheckout'), 'emails'],
            'ec-marketing'  => [__('Marketing', 'easycheckout'), 'marketing'],
            'ec-onboarding' => [__('Verifizierung', 'easycheckout'), 'onboarding'],
            'ec-billing'    => [__('Tarif', 'easycheckout'), 'billing'],
            'ec-webhooks'   => [__('Webhooks', 'easycheckout'), 'webhooks'],
            'ec-support'    => [__('Support', 'easycheckout'), 'support'],
            'ec-settings'   => [__('Einstellungen', 'easycheckout'), 'settings'],
        ];
    }

    /**
     * @var Native_API
     */
    private $api;

    public function __construct() {
        $this->api = new Native_API();
        add_action('admin_menu', [$this, 'add_menu'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        // Fremde Plugin-Hinweise (WooCommerce/Pinterest/…) auf der nativen
        // EasyCheckout-Seite ausblenden -> sauberer nativer Screen.
        add_action('in_admin_header', [$this, 'suppress_foreign_notices'], 1);
        add_action('wp_ajax_easycheckout_native_login', [$this, 'ajax_login']);
        add_action('wp_ajax_easycheckout_native_register', [$this, 'ajax_register']);
        add_action('wp_ajax_easycheckout_native_logout', [$this, 'ajax_logout']);
        add_action('wp_ajax_easycheckout_native_proxy', [$this, 'ajax_proxy']);
        add_action('wp_ajax_easycheckout_native_upload', [$this, 'ajax_upload']);
        // Lokale Checkout-Entwuerfe (nutzbar OHNE Konto; werden bei Verbindung veroeffentlicht)
        add_action('wp_ajax_easycheckout_local_get', [$this, 'ajax_local_get']);
        add_action('wp_ajax_easycheckout_local_save', [$this, 'ajax_local_save']);
        add_action('wp_ajax_easycheckout_local_delete', [$this, 'ajax_local_delete']);
    }

    const LOCAL_OPT = 'easycheckout_local_checkouts';

    public function add_menu() {
        $this->hooks[] = add_menu_page(
            __('EasyCheckout', 'easycheckout'),
            __('EasyCheckout', 'easycheckout'),
            'manage_options',
            $this->page_slug,
            [$this, 'render'],
            'dashicons-cart',
            55
        );
        foreach ($this->sections() as $slug => $def) {
            $this->hooks[] = add_submenu_page(
                $this->page_slug,
                $def[0],
                $def[0],
                'manage_options',
                $slug,
                [$this, 'render']
            );
        }
    }

    public function enqueue($hook) {
        if (!in_array($hook, $this->hooks, true)) {
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
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : $this->page_slug;
        $sections = $this->sections();
        $view = isset($sections[$page]) ? $sections[$page][1] : 'overview';
        echo '<div class="wrap" style="margin:0;"><div id="ec-native-app" data-view="' . esc_attr($view) . '"></div></div>';
    }

    /**
     * Remove other plugins' admin notices on our native dashboard pages.
     */
    public function suppress_foreign_notices() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && in_array($screen->id, $this->hooks, true)) {
            remove_all_actions('admin_notices');
            remove_all_actions('all_admin_notices');
        }
    }

    // --- Lokale Checkout-Entwuerfe (ohne Konto) -----------------------------

    public function ajax_local_get() {
        $this->guard();
        wp_send_json_success(array_values((array) get_option(self::LOCAL_OPT, [])));
    }

    public function ajax_local_save() {
        $this->guard();
        $data = isset($_POST['data']) ? json_decode(wp_unslash($_POST['data']), true) : [];
        if (!is_array($data)) { $data = []; }
        $all = (array) get_option(self::LOCAL_OPT, []);
        $id = (!empty($data['id'])) ? sanitize_text_field($data['id']) : ('loc_' . wp_generate_password(8, false, false));
        $name = isset($data['name']) ? sanitize_text_field($data['name']) : 'Checkout';
        $item = [
            'id'        => $id,
            'name'      => $name !== '' ? $name : 'Checkout',
            'slug'      => sanitize_title(!empty($data['slug']) ? $data['slug'] : $name),
            'design'    => (isset($data['design']) && is_array($data['design'])) ? $data['design'] : [],
            'products'  => (isset($data['products']) && is_array($data['products'])) ? $data['products'] : [],
            'updatedAt' => current_time('mysql'),
        ];
        $all[$id] = $item;
        update_option(self::LOCAL_OPT, $all, false);
        wp_send_json_success($item);
    }

    public function ajax_local_delete() {
        $this->guard();
        $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
        $all = (array) get_option(self::LOCAL_OPT, []);
        unset($all[$id]);
        update_option(self::LOCAL_OPT, $all, false);
        wp_send_json_success();
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

    /**
     * Multipart upload proxy: forwards $_FILES to the API with the JWT.
     */
    public function ajax_upload() {
        $this->guard();
        $method = isset($_POST['method']) ? strtoupper(sanitize_text_field(wp_unslash($_POST['method']))) : 'POST';
        $path   = isset($_POST['path']) ? wp_unslash($_POST['path']) : '';
        if (strpos($path, '/api/') !== 0) {
            wp_send_json_error(['message' => 'Invalid path'], 400);
        }

        $files = [];
        foreach ($_FILES as $field => $f) {
            if (!empty($f['tmp_name']) && is_uploaded_file($f['tmp_name'])) {
                $files[$field] = [
                    'name' => sanitize_file_name($f['name']),
                    'type' => $f['type'],
                    'tmp_name' => $f['tmp_name'],
                ];
            }
        }
        if (empty($files)) {
            wp_send_json_error(['message' => 'Keine Datei.'], 400);
        }

        $r = $this->api->upload($method, $path, $files);
        if (is_wp_error($r)) {
            wp_send_json_error(['message' => $r->get_error_message(), 'status' => 0]);
        }
        wp_send_json_success(['status' => $r['status'], 'body' => $r['body']]);
    }
}
