<?php
/**
 * EasyCheckout – WooCommerce-Einstellungs-Tab mit Sektionen.
 *
 * Bündelt ALLE EasyCheckout-Einstellungen unter WooCommerce -> Einstellungen
 * -> EasyCheckout:
 *   - Zahlung & Kasse (Standard-Sektion): die Gateway-Einstellungen
 *   - Design:   Logo, Farben, Radius, Schrift (Option easycheckout_design;
 *               Primärfarbe wird zusätzlich in die Gateway-brand_color gespiegelt)
 *   - Shop:     Firmendaten (Option easycheckout_company)
 *   - Rechnung: Bankverbindung + Zahlungsfrist + Fußtext (easycheckout_bank /
 *               easycheckout_invoice)
 *   - E-Mails:  Absender + Vorlage der Bestellbestätigung mit Platzhaltern
 *               (Option easycheckout_emails)
 *
 * Die Optionen sind dieselben, die das native Dashboard und die Kassen-Renderer
 * bereits lesen — beide UIs bleiben austauschbar.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

class WC_Settings_Tab {

    const TAB = 'easycheckout';

    public function __construct() {
        add_filter('woocommerce_settings_tabs_array', [$this, 'add_tab'], 60);
        add_filter('woocommerce_get_sections_' . self::TAB, [$this, 'sections']);
        add_action('woocommerce_settings_tabs_' . self::TAB, [$this, 'output']);
        add_action('woocommerce_update_options_' . self::TAB, [$this, 'save']);
        // Eigener Feldtyp: Bild-Auswahl über die Mediathek.
        add_action('woocommerce_admin_field_easycheckout_media', [$this, 'field_media']);
        add_action('admin_enqueue_scripts', [$this, 'maybe_enqueue_media']);
    }

    public function add_tab($tabs) {
        $tabs[self::TAB] = __('EasyCheckout', 'easycheckout');
        return $tabs;
    }

    public function sections($sections) {
        return [
            ''        => __('Zahlung & Kasse', 'easycheckout'),
            'design'  => __('Design', 'easycheckout'),
            'shop'    => __('Shop & Firma', 'easycheckout'),
            'invoice' => __('Rechnung', 'easycheckout'),
            'emails'  => __('E-Mails', 'easycheckout'),
        ];
    }

    /** Felddefinitionen je Sektion (WooCommerce-Settings-API, Array-Options-IDs). */
    private function fields($section) {
        switch ($section) {
            case 'design':
                return [
                    ['type' => 'title', 'id' => 'ec_design_t', 'title' => __('Checkout-Design', 'easycheckout'),
                     'desc' => __('Gilt für die native Kasse, Shortcode-Checkouts und die Bestellbestätigung. Feinschliff ist zusätzlich über „Eigenes CSS" in Zahlung & Kasse möglich.', 'easycheckout')],
                    ['type' => 'easycheckout_media', 'id' => 'easycheckout_design[logoUrl]',
                     'title' => __('Logo', 'easycheckout'),
                     'desc' => __('Wird oben in der Kasse und in Bestell-Mails (Absendername) verwendet.', 'easycheckout')],
                    ['type' => 'color', 'id' => 'easycheckout_design[primary]', 'title' => __('Primärfarbe', 'easycheckout'),
                     'desc' => __('Buttons, Preise, Akzente.', 'easycheckout'), 'default' => '#4F46E5', 'css' => 'width:6em;'],
                    ['type' => 'color', 'id' => 'easycheckout_design[bg]', 'title' => __('Hintergrund', 'easycheckout'), 'default' => '#F9FAFB', 'css' => 'width:6em;'],
                    ['type' => 'color', 'id' => 'easycheckout_design[text]', 'title' => __('Textfarbe', 'easycheckout'), 'default' => '#111827', 'css' => 'width:6em;'],
                    ['type' => 'color', 'id' => 'easycheckout_design[buttonText]', 'title' => __('Button-Textfarbe', 'easycheckout'), 'default' => '#FFFFFF', 'css' => 'width:6em;'],
                    ['type' => 'number', 'id' => 'easycheckout_design[radius]', 'title' => __('Ecken-Radius (px)', 'easycheckout'),
                     'default' => '8', 'custom_attributes' => ['min' => 0, 'max' => 32], 'css' => 'width:6em;'],
                    ['type' => 'select', 'id' => 'easycheckout_design[font]', 'title' => __('Schrift', 'easycheckout'),
                     'default' => 'site', 'options' => [
                        'site'   => __('Schrift der Website (empfohlen)', 'easycheckout'),
                        'system' => __('Neutrale System-Schrift', 'easycheckout'),
                     ]],
                    ['type' => 'sectionend', 'id' => 'ec_design_e'],
                ];
            case 'shop':
                return [
                    ['type' => 'title', 'id' => 'ec_shop_t', 'title' => __('Shop & Firma', 'easycheckout'),
                     'desc' => __('Erscheint als Rechnungssteller auf Bestellbestätigungen, Swiss-QR-Rechnungen und als Mail-Absender.', 'easycheckout')],
                    ['type' => 'text', 'id' => 'easycheckout_company[name]', 'title' => __('Firmenname', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'text', 'id' => 'easycheckout_company[street]', 'title' => __('Strasse & Nr.', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'text', 'id' => 'easycheckout_company[postalCode]', 'title' => __('PLZ', 'easycheckout'), 'css' => 'width:8em;'],
                    ['type' => 'text', 'id' => 'easycheckout_company[city]', 'title' => __('Ort', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'text', 'id' => 'easycheckout_company[country]', 'title' => __('Land', 'easycheckout'), 'default' => 'CH', 'css' => 'width:8em;'],
                    ['type' => 'email', 'id' => 'easycheckout_company[email]', 'title' => __('E-Mail', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'text', 'id' => 'easycheckout_company[phone]', 'title' => __('Telefon', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'text', 'id' => 'easycheckout_company[vatNumber]', 'title' => __('MwSt-Nummer', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'sectionend', 'id' => 'ec_shop_e'],
                ];
            case 'invoice':
                return [
                    ['type' => 'title', 'id' => 'ec_inv_t', 'title' => __('Rechnung & Bankverbindung', 'easycheckout'),
                     'desc' => __('Grundlage für Banküberweisung und Swiss-QR-Rechnung bei lokalen Bestellungen.', 'easycheckout')],
                    ['type' => 'text', 'id' => 'easycheckout_bank[iban]', 'title' => __('IBAN / QR-IBAN', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'text', 'id' => 'easycheckout_bank[holder]', 'title' => __('Kontoinhaber', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'text', 'id' => 'easycheckout_bank[bankName]', 'title' => __('Bank', 'easycheckout'), 'css' => 'width:24em;'],
                    ['type' => 'number', 'id' => 'easycheckout_invoice[due_days]', 'title' => __('Zahlungsfrist (Tage)', 'easycheckout'),
                     'default' => '30', 'custom_attributes' => ['min' => 0, 'max' => 120], 'css' => 'width:6em;',
                     'desc' => __('0 = keine Frist anzeigen.', 'easycheckout')],
                    ['type' => 'textarea', 'id' => 'easycheckout_invoice[footer]', 'title' => __('Fußtext', 'easycheckout'),
                     'css' => 'width:24em;height:5em;',
                     'desc' => __('Erscheint am Ende von Bestellbestätigung und Mail (z. B. Dankestext, rechtliche Hinweise).', 'easycheckout')],
                    ['type' => 'sectionend', 'id' => 'ec_inv_e'],
                ];
            case 'emails':
                return [
                    ['type' => 'title', 'id' => 'ec_mail_t', 'title' => __('Bestellbestätigung (E-Mail)', 'easycheckout'),
                     'desc' => __('Vorlage für die Bestellbestätigung bei lokalen Bestellungen (Banküberweisung/QR). Leere Felder nutzen die Standard-Vorlage. Platzhalter: {ref}, {name}, {firma}, {positionen}, {total}, {waehrung}, {iban}, {empfaenger}, {bank}, {zahlungsfrist}, {rechnungsadresse}, {lieferadresse}, {art}, {fusszeile}. Hinweis: Mails des WooCommerce-Bestellflusses werden unter WooCommerce → Einstellungen → E-Mails verwaltet.', 'easycheckout')],
                    ['type' => 'text', 'id' => 'easycheckout_emails[from_name]', 'title' => __('Absender-Name', 'easycheckout'),
                     'css' => 'width:24em;', 'desc' => __('Leer = Firmenname aus Shop & Firma.', 'easycheckout')],
                    ['type' => 'email', 'id' => 'easycheckout_emails[from_email]', 'title' => __('Absender-Adresse', 'easycheckout'),
                     'css' => 'width:24em;', 'desc' => __('Leer = E-Mail aus Shop & Firma.', 'easycheckout')],
                    ['type' => 'text', 'id' => 'easycheckout_emails[subject]', 'title' => __('Betreff', 'easycheckout'),
                     'css' => 'width:24em;', 'placeholder' => __('Bestellbestätigung {ref}', 'easycheckout')],
                    ['type' => 'textarea', 'id' => 'easycheckout_emails[body]', 'title' => __('Inhalt', 'easycheckout'),
                     'css' => 'width:40em;height:16em;', 'placeholder' => __('Leer = Standard-Vorlage', 'easycheckout')],
                    ['type' => 'checkbox', 'id' => 'easycheckout_emails[bcc_shop]', 'title' => __('Kopie an Shop', 'easycheckout'),
                     'desc' => __('Jede Bestellbestätigung als Blindkopie an die Shop-E-Mail senden.', 'easycheckout'), 'default' => 'no'],
                    ['type' => 'sectionend', 'id' => 'ec_mail_e'],
                ];
        }
        return [];
    }

    public function output() {
        global $current_section;
        if ($current_section === '' || $current_section === null) {
            $gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
            if (isset($gateways['easycheckout'])) {
                $gateways['easycheckout']->admin_options();
            }
            return;
        }
        woocommerce_admin_fields($this->fields($current_section));
        if ($current_section === 'emails') {
            $this->output_platform_templates();
        }
    }

    /**
     * Plattform-Mail-Vorlagen (Online-Zahlungen über das easyCheckout-Konto):
     * Bestellbestätigung an den Käufer + „Neue Bestellung" an den Händler.
     * Werden per JWT-API im Konto gespeichert (gleiche Vorlagen wie im
     * easyCheckout-Dashboard unter „E-Mails").
     */
    private function platform_types() {
        return [
            'confirmation'   => __('Bestellbestätigung an Käufer (Online-Zahlung)', 'easycheckout'),
            'merchant_order' => __('„Neue Bestellung" an dich (Online-Zahlung)', 'easycheckout'),
        ];
    }

    private function output_platform_templates() {
        $api = new Native_API();
        echo '<h2>' . esc_html__('Online-Zahlungs-Mails (easyCheckout-Konto)', 'easycheckout') . '</h2>';

        $res = $api->request('GET', '/api/emails');
        if (is_wp_error($res)) {
            echo '<p class="description">'
                . esc_html__('Diese Mails versendet die easyCheckout-Plattform bei Karten-/TWINT-Zahlungen. Zum Bearbeiten muss das Plugin mit deinem easyCheckout-Konto verbunden sein (Menü „EasyCheckout" → Anmelden).', 'easycheckout')
                . '</p>';
            return;
        }
        $templates = [];
        foreach ((array) ($res['body']['templates'] ?? []) as $t) {
            if (!empty($t['type'])) { $templates[$t['type']] = $t; }
        }

        echo '<p class="description">'
            . esc_html__('Vorlagen für die Mails, die easyCheckout bei Online-Zahlungen (Karte/TWINT) versendet — identisch mit „E-Mails" im easyCheckout-Dashboard. Leer = Standard-Vorlage. Platzhalter: {{customer_name}}, {{order_number}}, {{items}}, {{total}}, {{subtotal}}, {{vat_amount}}, {{company_name}}, {{company_address}}, {{company_email}}, {{date}}.', 'easycheckout')
            . ' ' . esc_html__('Absender ist easycheckout.ch; eine eigene Absender-Domain kannst du im easyCheckout-Dashboard unter Einstellungen verifizieren.', 'easycheckout')
            . '</p>';
        // Versand-Schalter: welche Plattform-Mails überhaupt rausgehen.
        $flags = ['sendOrderConfirmationEmail' => true, 'sendOrderInvoiceEmail' => true];
        $fres = $api->request('GET', '/api/emails/settings');
        if (!is_wp_error($fres) && is_array($fres['body'] ?? null)) {
            foreach (array_keys($flags) as $k) {
                if (isset($fres['body'][$k])) { $flags[$k] = (bool) $fres['body'][$k]; }
            }
        }
        echo '<table class="form-table">';
        echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html__('Versand', 'easycheckout') . '</th><td class="forminp">';
        echo '<label style="display:block;margin-bottom:6px;"><input type="checkbox" name="ec_platform_flags[sendOrderConfirmationEmail]" value="1" ' . checked($flags['sendOrderConfirmationEmail'], true, false) . ' /> '
            . esc_html__('easyCheckout-Bestellbestätigung an den Käufer senden', 'easycheckout') . '</label>';
        echo '<label style="display:block;"><input type="checkbox" name="ec_platform_flags[sendOrderInvoiceEmail]" value="1" ' . checked($flags['sendOrderInvoiceEmail'], true, false) . ' /> '
            . esc_html__('Rechnung (mit PDF-Anhang) an den Käufer senden', 'easycheckout') . '</label>';
        echo '<p class="description">' . esc_html__('Abschalten, wenn WooCommerce die Kunden-Mails übernehmen soll (vermeidet doppelte Mails). Die „Neue Bestellung"-Mail an dich bleibt davon unberührt.', 'easycheckout') . '</p>';
        echo '<input type="hidden" name="ec_platform_flags[_present]" value="1" />';
        echo '</td></tr>';
        foreach ($this->platform_types() as $type => $label) {
            $t = $templates[$type] ?? null;
            $subject = $t['subject'] ?? '';
            $body = $t['body'] ?? '';
            echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html($label) . '</th><td class="forminp">';
            echo '<input type="text" name="ec_platform_mail[' . esc_attr($type) . '][subject]" style="width:40em;margin-bottom:6px;" placeholder="' . esc_attr__('Betreff (leer = Standard)', 'easycheckout') . '" value="' . esc_attr($subject) . '" /><br/>';
            echo '<textarea name="ec_platform_mail[' . esc_attr($type) . '][body]" style="width:40em;height:10em;" placeholder="' . esc_attr__('Inhalt (HTML möglich; leer = Standard)', 'easycheckout') . '">' . esc_textarea($body) . '</textarea>';
            echo '</td></tr>';
        }
        echo '</table>';
    }

    /** Plattform-Vorlagen speichern (Upsert via JWT-API; leer = löschen). */
    private function save_platform_templates() {
        if (!isset($_POST['ec_platform_mail']) || !is_array($_POST['ec_platform_mail'])) {
            return;
        }
        $api = new Native_API();

        // Versand-Schalter (nur wenn der Block gerendert war, erkennbar am
        // _present-Marker — sonst würden fehlende Checkboxen als "aus" gedeutet).
        if (isset($_POST['ec_platform_flags']['_present'])) {
            $api->request('PATCH', '/api/emails/settings', [
                'sendOrderConfirmationEmail' => !empty($_POST['ec_platform_flags']['sendOrderConfirmationEmail']),
                'sendOrderInvoiceEmail'      => !empty($_POST['ec_platform_flags']['sendOrderInvoiceEmail']),
            ]);
        }
        $existing = [];
        $res = $api->request('GET', '/api/emails');
        if (!is_wp_error($res)) {
            foreach ((array) ($res['body']['templates'] ?? []) as $t) {
                if (!empty($t['type'])) { $existing[$t['type']] = $t; }
            }
        }
        foreach ($this->platform_types() as $type => $label) {
            $in = wp_unslash($_POST['ec_platform_mail'][$type] ?? []);
            $subject = trim((string) ($in['subject'] ?? ''));
            $body = trim((string) ($in['body'] ?? ''));
            if ($subject !== '' && $body !== '') {
                $api->request('POST', '/api/emails', [
                    'type' => $type, 'subject' => $subject, 'body' => $body, 'isActive' => true,
                ]);
            } elseif ($subject === '' && $body === '' && isset($existing[$type]['id'])) {
                // Beide Felder geleert -> zurück zur Standard-Vorlage.
                $api->request('DELETE', '/api/emails/' . (int) $existing[$type]['id']);
            }
        }
    }

    public function save() {
        global $current_section;
        if ($current_section === '' || $current_section === null) {
            $gateways = WC()->payment_gateways ? WC()->payment_gateways->payment_gateways() : [];
            if (isset($gateways['easycheckout'])) {
                $gateways['easycheckout']->process_admin_options();
            }
            return;
        }
        woocommerce_update_options($this->fields($current_section));

        if ($current_section === 'emails') {
            $this->save_platform_templates();
        }

        // Primärfarbe in die Gateway-Einstellung spiegeln, damit native Kasse
        // (--ec-p via Design::color) und Design-Sektion nie auseinanderlaufen.
        if ($current_section === 'design') {
            $design = (array) get_option('easycheckout_design', []);
            if (!empty($design['primary'])) {
                $s = (array) get_option('woocommerce_easycheckout_settings', []);
                $s['brand_color'] = $design['primary'];
                update_option('woocommerce_easycheckout_settings', $s);
            }
        }
    }

    /** Mediathek nur auf unserem Tab laden. */
    public function maybe_enqueue_media($hook) {
        if ($hook === 'woocommerce_page_wc-settings'
            && isset($_GET['tab']) && $_GET['tab'] === self::TAB) {
            wp_enqueue_media();
        }
    }

    /** Feldtyp: Bild-URL + „Bild wählen"-Button (WP-Mediathek). */
    public function field_media($field) {
        $option_value = \WC_Admin_Settings::get_option($field['id'], '');
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="<?php echo esc_attr($field['id']); ?>"><?php echo esc_html($field['title']); ?></label>
            </th>
            <td class="forminp">
                <input name="<?php echo esc_attr($field['id']); ?>" id="<?php echo esc_attr($field['id']); ?>"
                       type="url" style="width:24em;" value="<?php echo esc_attr($option_value); ?>"
                       placeholder="https://…/logo.png" />
                <button type="button" class="button ec-media-pick" data-target="<?php echo esc_attr($field['id']); ?>">
                    <?php esc_html_e('Bild wählen', 'easycheckout'); ?>
                </button>
                <?php if (!empty($field['desc'])) : ?><p class="description"><?php echo esc_html($field['desc']); ?></p><?php endif; ?>
                <?php if ($option_value) : ?>
                    <p><img src="<?php echo esc_url($option_value); ?>" style="max-height:60px;max-width:220px;border:1px solid #e5e7eb;border-radius:6px;padding:4px;background:#fff;" alt="" /></p>
                <?php endif; ?>
                <script>
                jQuery(function($){
                    $('.ec-media-pick').off('click.ec').on('click.ec', function(){
                        var target = $(this).data('target');
                        var frame = wp.media({ title: '<?php echo esc_js(__('Logo wählen', 'easycheckout')); ?>', multiple: false, library: { type: 'image' } });
                        frame.on('select', function(){
                            var url = frame.state().get('selection').first().toJSON().url;
                            $('[id="' + target + '"]').val(url);
                        });
                        frame.open();
                    });
                });
                </script>
            </td>
        </tr>
        <?php
    }
}
