<?php
/**
 * EasyCheckout "Embed" admin page.
 *
 * Lists the merchant's checkouts (created on EasyCheckout) and gives a ready
 * to paste shortcode for each, plus links to the hosted page and dashboard.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Admin page that lists remote checkouts for embedding.
 */
class Checkouts_Page {

    /**
     * Menu/page slug
     *
     * @var string
     */
    private $page_slug = 'easycheckout-embed';

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 20);
    }

    /**
     * Register the submenu under the EasyCheckout CPT menu.
     */
    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=ec_checkout',
            __('Checkout einbetten', 'easycheckout'),
            __('Einbetten', 'easycheckout'),
            'manage_options',
            $this->page_slug,
            [$this, 'render']
        );
    }

    /**
     * Base URL of the EasyCheckout app.
     *
     * @return string
     */
    private function app_url() {
        return rtrim(get_option('easycheckout_api_url', 'https://www.easycheckout.ch'), '/');
    }

    /**
     * Render the page.
     */
    public function render() {
        $api = new API_Client();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('EasyCheckout — Checkout einbetten', 'easycheckout'); ?></h1>
            <p class="description">
                <?php esc_html_e('Wähle einen deiner EasyCheckout-Checkouts und kopiere den Shortcode auf jede beliebige Seite. Käufer werden zur sicheren, gehosteten Bezahlseite weitergeleitet — ganz ohne WooCommerce.', 'easycheckout'); ?>
            </p>

            <?php
            if (!$api->is_configured()) {
                $this->render_no_key();
                echo '</div>';
                return;
            }

            $response = $api->get_checkouts(['limit' => 100]);

            if (is_wp_error($response)) {
                $this->render_api_error($response);
                echo '</div>';
                return;
            }

            $checkouts = isset($response['data']) && is_array($response['data']) ? $response['data'] : [];

            if (empty($checkouts)) {
                $this->render_empty();
                echo '</div>';
                return;
            }

            $this->render_table($checkouts);
            ?>

            <h2 style="margin-top:2em;"><?php esc_html_e('Manuell per Slug einbetten', 'easycheckout'); ?></h2>
            <p><?php esc_html_e('Du kannst einen Checkout auch direkt über seinen Slug einbetten, ohne ihn hier auszuwählen:', 'easycheckout'); ?></p>
            <code>[easycheckout slug="dein-slug"]</code>
        </div>
        <?php
    }

    /**
     * Render the checkout table.
     *
     * @param array $checkouts
     */
    private function render_table($checkouts) {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Name', 'easycheckout'); ?></th>
                    <th><?php esc_html_e('Slug', 'easycheckout'); ?></th>
                    <th><?php esc_html_e('Produkte', 'easycheckout'); ?></th>
                    <th><?php esc_html_e('Status', 'easycheckout'); ?></th>
                    <th style="width:30%;"><?php esc_html_e('Shortcode', 'easycheckout'); ?></th>
                    <th><?php esc_html_e('Links', 'easycheckout'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($checkouts as $c) :
                    $slug = isset($c['slug']) ? $c['slug'] : '';
                    if ($slug === '') {
                        continue;
                    }
                    $active = !isset($c['is_active']) || $c['is_active'];
                    $shortcode = '[easycheckout slug="' . $slug . '"]';
                    $hosted = $this->app_url() . '/c/' . rawurlencode($slug);
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html($c['name'] ?? $slug); ?></strong></td>
                        <td><code><?php echo esc_html($slug); ?></code></td>
                        <td><?php echo esc_html(isset($c['products_count']) ? (int) $c['products_count'] : '—'); ?></td>
                        <td>
                            <?php if ($active) : ?>
                                <span style="color:#008a20;">● <?php esc_html_e('Aktiv', 'easycheckout'); ?></span>
                            <?php else : ?>
                                <span style="color:#996800;">○ <?php esc_html_e('Inaktiv', 'easycheckout'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <input type="text" class="large-text code" readonly
                                   onclick="this.select();document.execCommand('copy');"
                                   value="<?php echo esc_attr($shortcode); ?>"
                                   title="<?php esc_attr_e('Klicken zum Kopieren', 'easycheckout'); ?>">
                        </td>
                        <td>
                            <a href="<?php echo esc_url($hosted); ?>" target="_blank" rel="noopener"><?php esc_html_e('Vorschau', 'easycheckout'); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * No API key configured.
     */
    private function render_no_key() {
        $settings_url = admin_url('options-general.php?page=easycheckout-settings');
        ?>
        <div class="notice notice-warning inline" style="margin-top:1em;">
            <p>
                <?php
                printf(
                    /* translators: %s: settings page URL */
                    wp_kses_post(__('Bitte hinterlege zuerst deinen EasyCheckout-API-Schlüssel unter <a href="%s">Einstellungen → EasyCheckout</a>.', 'easycheckout')),
                    esc_url($settings_url)
                );
                ?>
            </p>
        </div>
        <?php
    }

    /**
     * API returned an error (e.g. insufficient tier/scope).
     *
     * @param \WP_Error $error
     */
    private function render_api_error($error) {
        ?>
        <div class="notice notice-error inline" style="margin-top:1em;">
            <p><strong><?php esc_html_e('Checkouts konnten nicht geladen werden:', 'easycheckout'); ?></strong>
               <?php echo esc_html($error->get_error_message()); ?></p>
            <p><?php esc_html_e('Dein API-Schlüssel hat möglicherweise keine Dashboard-Leserechte (checkouts:read). Du kannst Checkouts trotzdem manuell per Slug einbetten:', 'easycheckout'); ?></p>
            <p><code>[easycheckout slug="dein-slug"]</code></p>
        </div>
        <?php
    }

    /**
     * No checkouts found.
     */
    private function render_empty() {
        $dashboard = $this->app_url() . '/dashboard';
        ?>
        <div class="notice notice-info inline" style="margin-top:1em;">
            <p>
                <?php
                printf(
                    /* translators: %s: EasyCheckout dashboard URL */
                    wp_kses_post(__('Noch keine Checkouts vorhanden. Erstelle einen in deinem <a href="%s" target="_blank" rel="noopener">EasyCheckout-Konto</a> und lade diese Seite neu.', 'easycheckout')),
                    esc_url($dashboard)
                );
                ?>
            </p>
        </div>
        <?php
    }
}
