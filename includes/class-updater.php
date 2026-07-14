<?php
/**
 * EasyCheckout Auto-Updater
 *
 * Haelt das Plugin automatisch aktuell — OHNE WordPress.org. Quelle sind die
 * GitHub-Releases von Konkursmasse/easycheckout-wp: bei jedem Release baut die CI
 * ein sauberes ZIP (Ordner „easycheckout-wp/") und haengt es als Asset an. Dieses
 * Plugin fragt die GitHub-API nach dem neuesten Release, vergleicht die Version und
 * meldet WordPress ein Update — inkl. „Details ansehen" und Ein-Klick-Update. Auf
 * Wunsch (Standard: AN) laesst WordPress das Update im Hintergrund automatisch
 * einspielen, damit auf keiner Kundenseite je eine veraltete/kaputte Version haengt.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

class Updater {

    /** GitHub-Repo (owner/name) mit den Releases. */
    const REPO = 'Konkursmasse/easycheckout-wp';

    /** Transient-Cache-Key fuer die Release-Antwort. */
    const CACHE_KEY = 'easycheckout_latest_release';

    /** plugin_basename, z.B. "easycheckout-wp/easycheckout.php". */
    private $basename;

    /** Ordnername des Plugins, z.B. "easycheckout-wp". */
    private $slug;

    /** Aktuell installierte Version. */
    private $version;

    public function __construct() {
        $this->basename = EASYCHECKOUT_PLUGIN_BASENAME;
        $this->slug     = dirname($this->basename);
        $this->version  = EASYCHECKOUT_VERSION;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'inject_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        // GitHub-Release-ZIPs koennen anders heissen als der Zielordner -> umbenennen.
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
        // Nach einem Update den Release-Cache leeren.
        add_action('upgrader_process_complete', [$this, 'flush_cache'], 10, 2);
        // Hintergrund-Auto-Updates fuer DIESES Plugin standardmaessig aktivieren.
        add_filter('auto_update_plugin', [$this, 'enable_auto_update'], 10, 2);
        // Manuelles „Nach Updates suchen": Cache leeren, wenn WP die Liste neu zieht.
        add_action('load-plugins.php', [$this, 'maybe_flush_on_force_check']);
    }

    /**
     * Holt das neueste Release von GitHub (gecacht 6 h; Fehler 1 h negativ-gecacht).
     *
     * @return array|null
     */
    private function get_release() {
        $cached = get_transient(self::CACHE_KEY);
        if ($cached !== false) {
            return is_array($cached) ? $cached : null;
        }

        $url  = 'https://api.github.com/repos/' . self::REPO . '/releases/latest';
        $resp = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept'     => 'application/vnd.github+json',
                'User-Agent' => 'EasyCheckout-WP/' . $this->version,
            ],
        ]);

        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            set_transient(self::CACHE_KEY, 'none', HOUR_IN_SECONDS);
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['tag_name'])) {
            set_transient(self::CACHE_KEY, 'none', HOUR_IN_SECONDS);
            return null;
        }

        set_transient(self::CACHE_KEY, $data, 6 * HOUR_IN_SECONDS);
        return $data;
    }

    /** Version aus einem Release-Tag (z.B. "v1.0.28" -> "1.0.28"). */
    private function tag_version($release) {
        return ltrim(isset($release['tag_name']) ? $release['tag_name'] : '', 'vV');
    }

    /**
     * Download-URL des Release-ZIPs. Bevorzugt ein angehaengtes .zip-Asset (sauberer
     * Ordner), faellt auf den GitHub-Zipball zurueck (Ordner wird dann umbenannt).
     *
     * @param array $release
     * @return string
     */
    private function package_url($release) {
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && substr($asset['name'], -4) === '.zip') {
                    return $asset['browser_download_url'];
                }
            }
        }
        return isset($release['zipball_url']) ? $release['zipball_url'] : '';
    }

    /**
     * Meldet WordPress ein verfuegbares Update, wenn GitHub eine hoehere Version hat.
     *
     * @param object $transient
     * @return object
     */
    public function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }

        $release = $this->get_release();
        if (!$release) {
            return $transient;
        }

        $new_version = $this->tag_version($release);
        $package     = $this->package_url($release);
        if ($new_version === '' || $package === '') {
            return $transient;
        }

        if (version_compare($new_version, $this->version, '>')) {
            $item = (object) [
                'id'            => 'github.com/' . self::REPO,
                'slug'          => $this->slug,
                'plugin'        => $this->basename,
                'new_version'   => $new_version,
                'url'           => 'https://easycheckout.ch',
                'package'       => $package,
                'icons'         => [],
                'banners'       => [],
                'tested'        => get_bloginfo('version'),
                'requires_php'  => '7.4',
                'compatibility' => new \stdClass(),
            ];
            $transient->response[$this->basename] = $item;
        } else {
            // Aktuell -> als „kein Update" markieren (sauberer Plugins-Screen).
            $item = (object) [
                'id'          => 'github.com/' . self::REPO,
                'slug'        => $this->slug,
                'plugin'      => $this->basename,
                'new_version' => $this->version,
                'url'         => 'https://easycheckout.ch',
                'package'     => '',
                'icons'       => [],
            ];
            if (isset($transient->no_update)) {
                $transient->no_update[$this->basename] = $item;
            }
        }

        return $transient;
    }

    /**
     * Liefert die Detail-Ansicht („Details ansehen") aus dem Release.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information' || empty($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }
        $release = $this->get_release();
        if (!$release) {
            return $result;
        }

        $notes = isset($release['body']) && $release['body'] !== '' ? $release['body'] : 'Neue Version verfügbar.';

        $info = (object) [
            'name'          => 'EasyCheckout',
            'slug'          => $this->slug,
            'version'       => $this->tag_version($release),
            'author'        => '<a href="https://easycheckout.ch">EasyCheckout</a>',
            'homepage'      => 'https://easycheckout.ch',
            'requires'      => '6.0',
            'requires_php'  => '7.4',
            'tested'        => get_bloginfo('version'),
            'download_link' => $this->package_url($release),
            'trunk'         => $this->package_url($release),
            'last_updated'  => isset($release['published_at']) ? $release['published_at'] : '',
            'sections'      => [
                'changelog' => $this->markdown_to_html($notes),
            ],
        ];
        return $info;
    }

    /**
     * Benennt den entpackten Ordner auf den erwarteten Plugin-Ordner um (noetig, wenn
     * das ZIP z.B. „Konkursmasse-easycheckout-wp-<sha>/" enthaelt).
     *
     * @param string      $source
     * @param string      $remote_source
     * @param \WP_Upgrader $upgrader
     * @param array       $hook_extra
     * @return string|\WP_Error
     */
    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = []) {
        global $wp_filesystem;
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->basename) {
            return $source;
        }
        if (!$wp_filesystem) {
            return $source;
        }
        $desired = trailingslashit($remote_source) . $this->slug;
        if (trailingslashit($source) === trailingslashit($desired)) {
            return $source;
        }
        if ($wp_filesystem->move($source, $desired, true)) {
            return trailingslashit($desired);
        }
        return $source;
    }

    /** Hintergrund-Auto-Update fuer dieses Plugin (Standard: AN, filterbar). */
    public function enable_auto_update($update, $item) {
        if (isset($item->plugin) && $item->plugin === $this->basename) {
            /** Ueber `add_filter('easycheckout_enable_auto_update','__return_false')` abschaltbar. */
            return apply_filters('easycheckout_enable_auto_update', true);
        }
        return $update;
    }

    public function flush_cache($upgrader = null, $extra = null) {
        delete_transient(self::CACHE_KEY);
    }

    /** Beim „Nach Updates suchen" (force-check) den Release-Cache leeren. */
    public function maybe_flush_on_force_check() {
        if (isset($_GET['force-check'])) {
            $this->flush_cache();
        }
    }

    /** Minimaler Markdown->HTML-Renderer fuer die Changelog-Anzeige. */
    private function markdown_to_html($md) {
        $html = esc_html($md);
        $html = preg_replace('/^### (.*)$/m', '<h4>$1</h4>', $html);
        $html = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $html);
        $html = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/^\- (.*)$/m', '<li>$1</li>', $html);
        $html = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html);
        $html = nl2br($html);
        return $html;
    }
}
