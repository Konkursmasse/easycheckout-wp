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
        // Bankverbindung (fuer Bankueberweisung) + lokale Bestellungen
        add_action('wp_ajax_easycheckout_bank_get', [$this, 'ajax_bank_get']);
        add_action('wp_ajax_easycheckout_bank_save', [$this, 'ajax_bank_save']);
        add_action('wp_ajax_easycheckout_local_orders', [$this, 'ajax_local_orders_list']);
        add_action('wp_ajax_easycheckout_local_order_update', [$this, 'ajax_local_order_update']);
        add_action('wp_ajax_easycheckout_local_order_delete', [$this, 'ajax_local_order_delete']);
        add_action('wp_ajax_easycheckout_local_upload', [$this, 'ajax_local_upload']);
        // Oeffentlicher Bestell-Endpunkt (Kunde, auch ausgeloggt)
        add_action('wp_ajax_easycheckout_place_order', [$this, 'ajax_place_order']);
        add_action('wp_ajax_nopriv_easycheckout_place_order', [$this, 'ajax_place_order']);
    }

    const LOCAL_OPT = 'easycheckout_local_checkouts';
    const BANK_OPT = 'easycheckout_bank';
    const ORDERS_OPT = 'easycheckout_local_orders';

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
            'siteUrl'  => home_url('/'),
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

        // Design (nur Farb-/Text-Strings)
        $design = [];
        if (isset($data['design']) && is_array($data['design'])) {
            foreach (['primary', 'text', 'bg', 'button', 'buttonText', 'radius'] as $k) {
                if (isset($data['design'][$k])) { $design[$k] = sanitize_text_field($data['design'][$k]); }
            }
            if (isset($data['design']['logoUrl'])) { $design['logoUrl'] = esc_url_raw($data['design']['logoUrl']); }
        }

        // Zahlungsarten (Whitelist). 'bank' = Bankueberweisung, funktioniert
        // lokal OHNE Konto/Stripe; card/twint brauchen ein verbundenes Konto.
        $pm = [];
        if (isset($data['paymentMethods']) && is_array($data['paymentMethods'])) {
            foreach ($data['paymentMethods'] as $m) {
                $m = sanitize_key($m);
                if (in_array($m, ['bank', 'card', 'twint', 'qr'], true)) { $pm[] = $m; }
            }
        }

        // Produkte
        $products = [];
        if (isset($data['products']) && is_array($data['products'])) {
            foreach ($data['products'] as $p) {
                if (!is_array($p)) { continue; }
                $products[] = [
                    'id'          => (!empty($p['id'])) ? sanitize_text_field($p['id']) : ('p_' . wp_generate_password(6, false, false)),
                    'name'        => isset($p['name']) ? sanitize_text_field($p['name']) : '',
                    'description' => isset($p['description']) ? sanitize_textarea_field($p['description']) : '',
                    'price'       => isset($p['price']) ? round((float) $p['price'], 2) : 0,
                    'imageUrl'    => (isset($p['imageUrl']) && $p['imageUrl']) ? esc_url_raw($p['imageUrl']) : '',
                ];
            }
        }

        $item = [
            'id'         => $id,
            'name'       => $name !== '' ? $name : 'Checkout',
            'slug'       => sanitize_title(!empty($data['slug']) ? $data['slug'] : $name),
            'design'     => $design,
            'paymentMethods' => $pm ? $pm : ['bank'],
            'vatEnabled' => !empty($data['vatEnabled']),
            'vatRate'    => isset($data['vatRate']) ? round((float) $data['vatRate'], 2) : 8.1,
            'currency'   => isset($data['currency']) ? strtoupper(substr(sanitize_text_field($data['currency']), 0, 3)) : 'CHF',
            'products'   => $products,
            'updatedAt'  => current_time('mysql'),
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

    // --- Bankverbindung + lokale Bestellungen -------------------------------

    public static function get_bank() {
        $b = (array) get_option(self::BANK_OPT, []);
        return [
            'iban'     => isset($b['iban']) ? $b['iban'] : '',
            'holder'   => isset($b['holder']) ? $b['holder'] : '',
            'bankName' => isset($b['bankName']) ? $b['bankName'] : '',
        ];
    }

    public function ajax_bank_get() {
        $this->guard();
        wp_send_json_success(self::get_bank());
    }

    public function ajax_bank_save() {
        $this->guard();
        $data = isset($_POST['data']) ? json_decode(wp_unslash($_POST['data']), true) : [];
        if (!is_array($data)) { $data = []; }
        $bank = [
            'iban'     => isset($data['iban']) ? strtoupper(preg_replace('/\s+/', '', sanitize_text_field($data['iban']))) : '',
            'holder'   => isset($data['holder']) ? sanitize_text_field($data['holder']) : '',
            'bankName' => isset($data['bankName']) ? sanitize_text_field($data['bankName']) : '',
        ];
        update_option(self::BANK_OPT, $bank, false);
        wp_send_json_success($bank);
    }

    public function ajax_local_orders_list() {
        $this->guard();
        $orders = array_values((array) get_option(self::ORDERS_OPT, []));
        usort($orders, function ($a, $b) { return strcmp($b['createdAt'] ?? '', $a['createdAt'] ?? ''); });
        wp_send_json_success($orders);
    }

    public function ajax_local_order_update() {
        $this->guard();
        $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : '';
        $orders = (array) get_option(self::ORDERS_OPT, []);
        if (!isset($orders[$id])) { wp_send_json_error(['message' => 'Nicht gefunden.'], 404); }
        if (in_array($status, ['awaiting_transfer', 'paid', 'cancelled'], true)) {
            $orders[$id]['status'] = $status;
            update_option(self::ORDERS_OPT, $orders, false);
        }
        wp_send_json_success($orders[$id]);
    }

    public function ajax_local_order_delete() {
        $this->guard();
        $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
        $orders = (array) get_option(self::ORDERS_OPT, []);
        unset($orders[$id]);
        update_option(self::ORDERS_OPT, $orders, false);
        wp_send_json_success();
    }

    /**
     * Lokaler Bild-Upload in die WP-Mediathek (fuer Produktbilder, ohne Konto).
     */
    public function ajax_local_upload() {
        $this->guard();
        if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
            wp_send_json_error(['message' => 'Keine Datei.'], 400);
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $overrides = ['test_form' => false, 'mimes' => ['jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp']];
        $moved = wp_handle_upload($_FILES['file'], $overrides);
        if (isset($moved['error'])) { wp_send_json_error(['message' => $moved['error']], 400); }
        // In die Mediathek eintragen (damit es verwaltbar ist)
        $attachment = [
            'post_mime_type' => $moved['type'],
            'post_title'     => sanitize_file_name(basename($moved['file'])),
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $moved['file']);
        if (!is_wp_error($attach_id)) {
            wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $moved['file']));
        }
        wp_send_json_success(['url' => $moved['url']]);
    }

    public static function get_local_checkout_by_slug($slug) {
        $slug = sanitize_title($slug);
        if ($slug === '') { return null; }
        foreach ((array) get_option(self::LOCAL_OPT, []) as $c) {
            if (isset($c['slug']) && $c['slug'] === $slug) { return $c; }
        }
        return null;
    }

    /**
     * Oeffentlicher Bestell-Endpunkt fuer den lokalen Bankueberweisungs-Checkout.
     * Preise werden serverseitig aus dem Checkout abgeleitet (Client nicht vertrauen).
     */
    public function ajax_place_order() {
        if (!check_ajax_referer('easycheckout_front', 'nonce', false)) {
            wp_send_json_error(['message' => __('Ungültige Anfrage.', 'easycheckout')], 400);
        }
        $slug = isset($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
        $checkout = self::get_local_checkout_by_slug($slug);
        if (!$checkout) { wp_send_json_error(['message' => __('Checkout nicht gefunden.', 'easycheckout')], 404); }

        $itemsIn = isset($_POST['items']) ? json_decode(wp_unslash($_POST['items']), true) : [];
        if (!is_array($itemsIn)) { $itemsIn = []; }
        $byId = [];
        foreach ((array) ($checkout['products'] ?? []) as $p) { $byId[$p['id']] = $p; }

        $lines = [];
        $total = 0;
        foreach ($itemsIn as $it) {
            $pid = isset($it['id']) ? sanitize_text_field($it['id']) : '';
            $qty = isset($it['qty']) ? max(0, intval($it['qty'])) : 0;
            if (!$qty || !isset($byId[$pid])) { continue; }
            $p = $byId[$pid];
            $lineTotal = round(((float) $p['price']) * $qty, 2);
            $lines[] = ['id' => $pid, 'name' => $p['name'], 'price' => (float) $p['price'], 'qty' => $qty, 'lineTotal' => $lineTotal];
            $total += $lineTotal;
        }
        if (!$lines) { wp_send_json_error(['message' => __('Warenkorb ist leer.', 'easycheckout')], 400); }

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $name  = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if (!$name || !is_email($email)) { wp_send_json_error(['message' => __('Bitte Name und gültige E-Mail angeben.', 'easycheckout')], 400); }

        $total = round($total, 2);
        $ref = 'EC-' . strtoupper(wp_generate_password(6, false, false));
        $order = [
            'id'            => 'ord_' . wp_generate_password(10, false, false),
            'ref'           => $ref,
            'checkoutSlug'  => $slug,
            'checkoutName'  => $checkout['name'] ?? '',
            'customerName'  => $name,
            'customerEmail' => $email,
            'items'         => $lines,
            'total'         => $total,
            'currency'      => $checkout['currency'] ?? 'CHF',
            'paymentMethod' => 'bank',
            'status'        => 'awaiting_transfer',
            'createdAt'     => current_time('mysql'),
        ];
        $orders = (array) get_option(self::ORDERS_OPT, []);
        $orders[$order['id']] = $order;
        update_option(self::ORDERS_OPT, $orders, false);

        $bank = self::get_bank();
        if ($email) {
            $body = sprintf(
                "Vielen Dank für deine Bestellung (%s).\n\nBitte überweise %s %s an:\nIBAN: %s\nEmpfänger: %s\n%sVerwendungszweck: %s\n",
                $ref, $order['currency'], number_format($total, 2, '.', "'"),
                $bank['iban'] ?: '—', $bank['holder'] ?: '—',
                $bank['bankName'] ? ('Bank: ' . $bank['bankName'] . "\n") : '',
                $ref
            );
            @wp_mail($email, sprintf(__('Bestellbestätigung %s', 'easycheckout'), $ref), $body);
        }

        wp_send_json_success([
            'ref'      => $ref,
            'total'    => $total,
            'currency' => $order['currency'],
            'bank'     => $bank,
        ]);
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
