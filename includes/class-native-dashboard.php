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
            'ec-embed'      => [__('Einbindung', 'easycheckout'), 'embed'],
            'ec-orders'     => [__('Bestellungen', 'easycheckout'), 'orders'],
            'ec-customers'  => [__('Kunden', 'easycheckout'), 'customers'],
            'ec-invoices'   => [__('Rechnungen', 'easycheckout'), 'invoices'],
            'ec-emails'     => [__('E-Mails', 'easycheckout'), 'emails'],
            'ec-marketing'  => [__('Marketing', 'easycheckout'), 'marketing'],
            'ec-onboarding' => [__('Verifizierung', 'easycheckout'), 'onboarding'],
            'ec-billing'    => [__('Tarif', 'easycheckout'), 'billing'],
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
        // Bankverbindung + Firmenangaben (fuer Rechnung) + lokale Bestellungen
        add_action('wp_ajax_easycheckout_bank_get', [$this, 'ajax_bank_get']);
        add_action('wp_ajax_easycheckout_bank_save', [$this, 'ajax_bank_save']);
        add_action('wp_ajax_easycheckout_company_get', [$this, 'ajax_company_get']);
        add_action('wp_ajax_easycheckout_company_save', [$this, 'ajax_company_save']);
        add_action('wp_ajax_easycheckout_local_orders', [$this, 'ajax_local_orders_list']);
        add_action('wp_ajax_easycheckout_local_order_update', [$this, 'ajax_local_order_update']);
        add_action('wp_ajax_easycheckout_local_order_delete', [$this, 'ajax_local_order_delete']);
        add_action('wp_ajax_easycheckout_local_upload', [$this, 'ajax_local_upload']);
        // Oeffentlicher Bestell-Endpunkt (Kunde, auch ausgeloggt)
        add_action('wp_ajax_easycheckout_place_order', [$this, 'ajax_place_order']);
        add_action('wp_ajax_nopriv_easycheckout_place_order', [$this, 'ajax_place_order']);
    }

    const LOCAL_OPT = 'easycheckout_local_checkouts';
    const LOCAL_LIMIT = 1; // Gratis-Modus: 1 lokaler Checkout; mehr -> Konto auf easycheckout.ch
    const BANK_OPT = 'easycheckout_bank';
    const COMPANY_OPT = 'easycheckout_company';
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
        $id = (!empty($data['id'])) ? sanitize_text_field($data['id']) : '';
        $isNew = ($id === '' || !isset($all[$id]));
        if ($isNew && count($all) >= self::LOCAL_LIMIT) {
            wp_send_json_error([
                'message' => __('Im kostenlosen Modus ist ein Checkout möglich. Für weitere Checkouts und Online-Zahlung erstelle ein Konto auf easycheckout.ch.', 'easycheckout'),
                'upgrade' => true,
            ], 403);
        }
        if ($id === '') { $id = 'loc_' . wp_generate_password(8, false, false); }
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

        // Produkte (inkl. Optionen, Infofeldern, Liefer-/Abholpreisen)
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
                    'categoryId'  => (isset($p['categoryId']) && $p['categoryId'] !== '' && $p['categoryId'] !== null) ? sanitize_text_field($p['categoryId']) : null,
                    // Fulfillment-Preise (null = Standardpreis gilt) + Liefergebuehr.
                    'pickupPrice'   => self::opt_num($p, 'pickupPrice'),
                    'deliveryPrice' => self::opt_num($p, 'deliveryPrice'),
                    'deliveryFee'   => self::opt_num($p, 'deliveryFee'),
                    'optionGroups'  => self::sanitize_option_groups(isset($p['optionGroups']) ? $p['optionGroups'] : []),
                    'customFields'  => self::sanitize_custom_fields(isset($p['customFields']) ? $p['customFields'] : []),
                ];
            }
        }

        // Kategorien
        $categories = [];
        if (isset($data['categories']) && is_array($data['categories'])) {
            foreach ($data['categories'] as $cat) {
                if (!is_array($cat)) { continue; }
                $categories[] = [
                    'id'            => (!empty($cat['id'])) ? sanitize_text_field($cat['id']) : ('c_' . wp_generate_password(6, false, false)),
                    'name'          => isset($cat['name']) ? sanitize_text_field($cat['name']) : '',
                    'description'   => isset($cat['description']) ? sanitize_textarea_field($cat['description']) : '',
                    'singleProduct' => !empty($cat['singleProduct']),
                    'allowQuantity' => !isset($cat['allowQuantity']) || !empty($cat['allowQuantity']),
                ];
            }
        }
        $categorySelection = (isset($data['categorySelection']) && $data['categorySelection'] === 'single') ? 'single' : 'multiple';

        $item = [
            'id'         => $id,
            'name'       => $name !== '' ? $name : 'Checkout',
            'slug'       => sanitize_title(!empty($data['slug']) ? $data['slug'] : $name),
            'design'     => $design,
            'paymentMethods' => $pm ? $pm : ['bank'],
            'vatEnabled' => !empty($data['vatEnabled']),
            'vatRate'    => isset($data['vatRate']) ? round((float) $data['vatRate'], 2) : 8.1,
            'currency'   => isset($data['currency']) ? strtoupper(substr(sanitize_text_field($data['currency']), 0, 3)) : 'CHF',
            // Dynamischer Produkt-Obertitel (z.B. „Tickets"); leer = „Produkte".
            'productsTitle' => isset($data['productsTitle']) ? sanitize_text_field($data['productsTitle']) : '',
            // Fulfillment: Abholung standardmaessig an, Lieferung opt-in.
            'pickupEnabled'   => !isset($data['pickupEnabled']) || !empty($data['pickupEnabled']),
            'deliveryEnabled' => !empty($data['deliveryEnabled']),
            'categorySelection' => $categorySelection,
            'categories' => $categories,
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

    public static function get_company() {
        $c = (array) get_option(self::COMPANY_OPT, []);
        $keys = ['name', 'street', 'postalCode', 'city', 'country', 'email', 'phone', 'vatNumber'];
        $out = [];
        foreach ($keys as $k) { $out[$k] = isset($c[$k]) ? $c[$k] : ''; }
        return $out;
    }

    public function ajax_company_get() {
        $this->guard();
        wp_send_json_success(self::get_company());
    }

    public function ajax_company_save() {
        $this->guard();
        $data = isset($_POST['data']) ? json_decode(wp_unslash($_POST['data']), true) : [];
        if (!is_array($data)) { $data = []; }
        $c = [];
        foreach (['name', 'street', 'postalCode', 'city', 'country', 'phone', 'vatNumber'] as $k) {
            $c[$k] = isset($data[$k]) ? sanitize_text_field($data[$k]) : '';
        }
        $c['email'] = isset($data['email']) ? sanitize_email($data['email']) : '';
        update_option(self::COMPANY_OPT, $c, false);
        wp_send_json_success($c);
    }

    private static function sanitize_address($a) {
        if (!is_array($a)) { $a = []; }
        return [
            'street'     => isset($a['street']) ? sanitize_text_field($a['street']) : '',
            'postalCode' => isset($a['postalCode']) ? sanitize_text_field($a['postalCode']) : '',
            'city'       => isset($a['city']) ? sanitize_text_field($a['city']) : '',
            'country'    => isset($a['country']) ? sanitize_text_field($a['country']) : '',
        ];
    }

    /** Optionaler Geldbetrag: leer/nicht gesetzt -> null (Standardpreis gilt). */
    private static function opt_num($arr, $key) {
        if (!isset($arr[$key]) || $arr[$key] === '' || $arr[$key] === null) { return null; }
        return round((float) $arr[$key], 2);
    }

    /** Optionsgruppen bereinigen: [{id,name,options:[{id,label,priceModifier}]}]. */
    private static function sanitize_option_groups($groups) {
        $out = [];
        if (!is_array($groups)) { return $out; }
        foreach ($groups as $g) {
            if (!is_array($g)) { continue; }
            $name = isset($g['name']) ? sanitize_text_field($g['name']) : '';
            $opts = [];
            foreach ((array) (isset($g['options']) ? $g['options'] : []) as $o) {
                if (!is_array($o)) { continue; }
                $label = isset($o['label']) ? sanitize_text_field($o['label']) : '';
                if ($label === '') { continue; }
                $opts[] = [
                    'id'            => (!empty($o['id'])) ? sanitize_text_field($o['id']) : ('o_' . wp_generate_password(6, false, false)),
                    'label'         => $label,
                    'priceModifier' => isset($o['priceModifier']) ? round((float) $o['priceModifier'], 2) : 0,
                ];
            }
            if ($name === '' || !$opts) { continue; }
            $out[] = [
                'id'      => (!empty($g['id'])) ? sanitize_text_field($g['id']) : ('g_' . wp_generate_password(6, false, false)),
                'name'    => $name,
                'options' => $opts,
            ];
        }
        return $out;
    }

    /** Infofelder bereinigen: [{id,label,fieldType,required,options[]}]. */
    private static function sanitize_custom_fields($fields) {
        $out = [];
        if (!is_array($fields)) { return $out; }
        foreach ($fields as $f) {
            if (!is_array($f)) { continue; }
            $label = isset($f['label']) ? sanitize_text_field($f['label']) : '';
            if ($label === '') { continue; }
            $type = (isset($f['fieldType']) && $f['fieldType'] === 'checkbox') ? 'checkbox' : 'text';
            $opts = [];
            if ($type === 'checkbox') {
                foreach ((array) (isset($f['options']) ? $f['options'] : []) as $opt) {
                    $opt = sanitize_text_field($opt);
                    if ($opt !== '') { $opts[] = $opt; }
                }
                if (!$opts) { continue; } // Checkbox ohne Auswahl ergibt keinen Sinn
            }
            $out[] = [
                'id'        => (!empty($f['id'])) ? sanitize_text_field($f['id']) : ('f_' . wp_generate_password(6, false, false)),
                'label'     => $label,
                'fieldType' => $type,
                'required'  => !empty($f['required']),
                'options'   => $opts,
            ];
        }
        return $out;
    }

    /** Basis-Stueckpreis je Modus (null-Override -> Standardpreis). */
    private static function base_unit($p, $mode) {
        if ($mode === 'delivery' && isset($p['deliveryPrice']) && $p['deliveryPrice'] !== null && $p['deliveryPrice'] !== '') {
            return (float) $p['deliveryPrice'];
        }
        if ($mode === 'pickup' && isset($p['pickupPrice']) && $p['pickupPrice'] !== null && $p['pickupPrice'] !== '') {
            return (float) $p['pickupPrice'];
        }
        return (float) $p['price'];
    }

    /** Liefergebuehr einer Zeile – nur im Liefermodus, EINMAL pro Zeile. */
    private static function line_delivery_fee($p, $mode) {
        if ($mode !== 'delivery') { return 0.0; }
        return round((float) (isset($p['deliveryFee']) && $p['deliveryFee'] !== null ? $p['deliveryFee'] : 0), 2);
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

        // Fulfillment-Modus: 'delivery' nur, wenn der Checkout Lieferung anbietet,
        // sonst Abholung (= Standardpreis, keine Liefergebuehr). SERVER entscheidet.
        $deliveryEnabled = !empty($checkout['deliveryEnabled']);
        $requestedMode = isset($_POST['fulfillmentMode']) ? sanitize_key(wp_unslash($_POST['fulfillmentMode'])) : '';
        $mode = ($requestedMode === 'delivery' && $deliveryEnabled) ? 'delivery' : 'pickup';

        $itemsIn = isset($_POST['items']) ? json_decode(wp_unslash($_POST['items']), true) : [];
        if (!is_array($itemsIn)) { $itemsIn = []; }
        $byId = [];
        foreach ((array) ($checkout['products'] ?? []) as $p) { $byId[$p['id']] = $p; }

        $lines = [];
        $subtotal = 0;
        $deliveryFeeTotal = 0;
        foreach ($itemsIn as $it) {
            $pid = isset($it['id']) ? sanitize_text_field($it['id']) : '';
            $qty = isset($it['qty']) ? max(0, intval($it['qty'])) : 0;
            if (!$qty || !isset($byId[$pid])) { continue; }
            $p = $byId[$pid];

            // --- Optionen validieren + Aufschlag (pro Gruppe genau eine Option) ---
            $selectedIds = (isset($it['optionIds']) && is_array($it['optionIds'])) ? array_map('strval', $it['optionIds']) : [];
            $chosenOpts = [];
            foreach ((array) (isset($p['optionGroups']) ? $p['optionGroups'] : []) as $g) {
                $match = null;
                foreach ((array) $g['options'] as $o) {
                    if (in_array((string) $o['id'], $selectedIds, true)) { $match = $o; break; }
                }
                if (!$match) {
                    wp_send_json_error(['message' => sprintf(__('Bitte eine Auswahl für „%1$s" bei „%2$s" treffen.', 'easycheckout'), $g['name'], $p['name'])], 400);
                }
                $chosenOpts[] = ['group' => $g['name'], 'label' => $match['label'], 'priceModifier' => (float) $match['priceModifier']];
            }

            // --- Infofelder validieren (Pflichtfelder) ---
            $answers = [];
            $fieldValues = (isset($it['fieldValues']) && is_array($it['fieldValues'])) ? $it['fieldValues'] : [];
            foreach ((array) (isset($p['customFields']) ? $p['customFields'] : []) as $f) {
                $raw = isset($fieldValues[$f['id']]) ? $fieldValues[$f['id']] : null;
                if ($f['fieldType'] === 'checkbox') {
                    $allowed = (array) $f['options'];
                    $arr = is_array($raw) ? array_map('sanitize_text_field', $raw) : ($raw !== null && $raw !== '' ? [sanitize_text_field($raw)] : []);
                    $val = array_values(array_filter($arr, function ($x) use ($allowed) { return in_array($x, $allowed, true); }));
                    $empty = count($val) === 0;
                } else {
                    $val = $raw !== null ? sanitize_text_field($raw) : '';
                    $empty = ($val === '');
                }
                if (!empty($f['required']) && $empty) {
                    wp_send_json_error(['message' => sprintf(__('Bitte „%1$s" bei „%2$s" ausfüllen.', 'easycheckout'), $f['label'], $p['name'])], 400);
                }
                if (!$empty) { $answers[] = ['label' => $f['label'], 'value' => $val]; }
            }

            // --- Preis (Modus-Preis + Options-Aufschlag), Liefergebuehr pro Zeile ---
            $surcharge = 0;
            foreach ($chosenOpts as $c) { $surcharge += $c['priceModifier']; }
            $unit = round(self::base_unit($p, $mode) + $surcharge, 2);
            $lineTotal = round($unit * $qty, 2);
            $lineFee = self::line_delivery_fee($p, $mode);
            $subtotal += $lineTotal;
            $deliveryFeeTotal += $lineFee;

            $line = ['id' => $pid, 'name' => $p['name'], 'price' => $unit, 'qty' => $qty, 'lineTotal' => $lineTotal];
            if ($chosenOpts) { $line['options'] = $chosenOpts; }
            if ($answers) { $line['customFields'] = $answers; }
            if ($lineFee) { $line['deliveryFee'] = $lineFee; }
            $lines[] = $line;
        }
        if (!$lines) { wp_send_json_error(['message' => __('Warenkorb ist leer.', 'easycheckout')], 400); }
        $deliveryFeeTotal = round($deliveryFeeTotal, 2);
        $total = round($subtotal + $deliveryFeeTotal, 2);

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $name  = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        if (!$name || !is_email($email)) { wp_send_json_error(['message' => __('Bitte Name und gültige E-Mail angeben.', 'easycheckout')], 400); }

        $company    = isset($_POST['company']) ? sanitize_text_field(wp_unslash($_POST['company'])) : '';
        $phone      = isset($_POST['phone']) ? sanitize_text_field(wp_unslash($_POST['phone'])) : '';
        $newsletter = !empty($_POST['newsletter']) && $_POST['newsletter'] === '1';
        $sameAddr   = !isset($_POST['sameAddress']) || $_POST['sameAddress'] === '1';
        $billing    = self::sanitize_address(isset($_POST['billing']) ? json_decode(wp_unslash($_POST['billing']), true) : []);
        $delivery   = $sameAddr ? $billing : self::sanitize_address(isset($_POST['delivery']) ? json_decode(wp_unslash($_POST['delivery']), true) : []);
        if (!$billing['street'] || !$billing['postalCode'] || !$billing['city']) {
            wp_send_json_error(['message' => __('Bitte vollständige Rechnungsadresse angeben.', 'easycheckout')], 400);
        }

        $ref = 'EC-' . strtoupper(wp_generate_password(6, false, false));
        $order = [
            'id'            => 'ord_' . wp_generate_password(10, false, false),
            'ref'           => $ref,
            'checkoutSlug'  => $slug,
            'checkoutName'  => $checkout['name'] ?? '',
            'customerName'  => $name,
            'customerEmail' => $email,
            'customerCompany' => $company,
            'customerPhone' => $phone,
            'newsletter'    => $newsletter,
            'billing'       => $billing,
            'delivery'      => $delivery,
            'sameAddress'   => $sameAddr,
            'fulfillmentMode' => $mode,
            'deliveryFeeTotal' => $deliveryFeeTotal ?: null,
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
            $cur = $order['currency'];
            $fmtAddr = function ($a) { return trim($a['street'] . "\n" . trim($a['postalCode'] . ' ' . $a['city']) . ($a['country'] ? "\n" . $a['country'] : '')); };
            $co = self::get_company();
            $issuer = '';
            if ($co['name']) {
                $issuer = "Rechnungssteller:\n" . $co['name']
                    . ($co['street'] ? "\n" . $co['street'] : '')
                    . ( ($co['postalCode'] || $co['city']) ? "\n" . trim($co['postalCode'] . ' ' . $co['city']) : '' )
                    . ($co['vatNumber'] ? "\nMwSt-Nr: " . $co['vatNumber'] : '')
                    . ($co['email'] ? "\n" . $co['email'] : '')
                    . "\n\n";
            }
            $itemsTxt = '';
            foreach ($lines as $l) {
                $itemsTxt .= sprintf("- %d× %s   %s %s\n", $l['qty'], $l['name'], $cur, number_format($l['lineTotal'], 2, '.', "'"));
                if (!empty($l['options'])) {
                    foreach ($l['options'] as $o) { $itemsTxt .= "    • " . $o['label'] . "\n"; }
                }
                if (!empty($l['customFields'])) {
                    foreach ($l['customFields'] as $f) {
                        $v = is_array($f['value']) ? implode(', ', $f['value']) : $f['value'];
                        $itemsTxt .= "    • " . $f['label'] . ": " . $v . "\n";
                    }
                }
                if (!empty($l['deliveryFee'])) {
                    $itemsTxt .= sprintf("    • Liefergebühr   %s %s\n", $cur, number_format($l['deliveryFee'], 2, '.', "'"));
                }
            }
            $modeTxt = ($mode === 'delivery') ? "Lieferung" : "Abholung";
            $delivTxt = ($mode === 'delivery' && !$sameAddr) ? ("Lieferadresse:\n" . $name . "\n" . $fmtAddr($delivery) . "\n\n") : '';
            $body = "Vielen Dank für deine Bestellung ({$ref}).\n\n"
                . $issuer
                . "Rechnungsadresse:\n" . $name . ($company ? "\n{$company}" : '') . "\n" . $fmtAddr($billing) . "\n\n"
                . $delivTxt
                . "Art: {$modeTxt}\n\n"
                . "Positionen:\n" . $itemsTxt
                . ($deliveryFeeTotal ? ("Liefergebühren: {$cur} " . number_format($deliveryFeeTotal, 2, '.', "'") . "\n") : '')
                . "Total: {$cur} " . number_format($total, 2, '.', "'") . "\n\n"
                . "Bitte überweise den Betrag an:\n"
                . "IBAN: " . ($bank['iban'] ?: '—') . "\n"
                . "Empfänger: " . ($bank['holder'] ?: '—') . "\n"
                . ($bank['bankName'] ? ("Bank: " . $bank['bankName'] . "\n") : '')
                . "Verwendungszweck: {$ref}\n";
            $headers = [];
            if ($co['email']) { $headers[] = 'From: ' . ($co['name'] ?: get_bloginfo('name')) . ' <' . $co['email'] . '>'; }
            @wp_mail($email, sprintf(__('Bestellbestätigung %s', 'easycheckout'), $ref), $body, $headers);
        }

        // Swiss-QR-Rechnung (nur wenn IBAN hinterlegt)
        $qr = null;
        if ($bank['iban']) {
            $co = self::get_company();
            $qr = [
                'iban'     => $bank['iban'],
                'amount'   => number_format($total, 2, '.', ''),
                'currency' => $order['currency'],
                'message'  => 'Bestellung ' . $ref,
                'creditor' => [
                    'name'       => $co['name'] ?: $bank['holder'],
                    'street'     => $co['street'],
                    'postalCode' => $co['postalCode'],
                    'city'       => $co['city'],
                ],
                'debtor'   => [
                    'name'       => $name,
                    'street'     => $billing['street'],
                    'postalCode' => $billing['postalCode'],
                    'city'       => $billing['city'],
                ],
            ];
        }

        wp_send_json_success([
            'ref'      => $ref,
            'total'    => $total,
            'currency' => $order['currency'],
            'bank'     => $bank,
            'qr'       => $qr,
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
