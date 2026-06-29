<?php
/**
 * Embedded EasyCheckout dashboard.
 *
 * Hosts the real easycheckout.ch merchant dashboard (login, register,
 * onboarding, checkouts, products, orders, settings) inside a WordPress admin
 * page via an iframe. The dashboard authenticates itself (JWT in localStorage),
 * so the merchant logs in or registers right here.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * Admin page that embeds the EasyCheckout dashboard.
 */
class Dashboard_Page {

    /**
     * Menu/page slug
     *
     * @var string
     */
    private $page_slug = 'easycheckout-dashboard';

    /**
     * Constructor
     */
    public function __construct() {
        // Register early so it sits at the top of the menu.
        add_action('admin_menu', [$this, 'add_menu'], 5);
        add_action('admin_head', [$this, 'fullscreen_css']);
    }

    /**
     * App base URL.
     *
     * @return string
     */
    private function app_url() {
        return rtrim(get_option('easycheckout_api_url', 'https://www.easycheckout.ch'), '/');
    }

    /**
     * Register the top-level menu.
     */
    public function add_menu() {
        add_menu_page(
            __('EasyCheckout', 'easycheckout'),
            __('EasyCheckout', 'easycheckout'),
            'manage_options',
            $this->page_slug,
            [$this, 'render'],
            'dashicons-cart',
            55
        );
    }

    /**
     * Remove the admin content padding on our page so the iframe is flush.
     */
    public function fullscreen_css() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos($screen->id, $this->page_slug) === false) {
            return;
        }
        echo '<style>
            #wpcontent { padding-left: 0 !important; }
            .wrap.easycheckout-dashboard-wrap { margin: 0; }
            #wpbody-content { padding-bottom: 0 !important; }
            #wpfooter { display: none; }
        </style>';
    }

    /**
     * Render the embedded dashboard.
     */
    public function render() {
        $url = $this->app_url() . '/dashboard?embed=1';
        ?>
        <div class="wrap easycheckout-dashboard-wrap">
            <div style="height: calc(100vh - 32px); width: 100%;">
                <iframe
                    src="<?php echo esc_url($url); ?>"
                    title="<?php esc_attr_e('EasyCheckout Dashboard', 'easycheckout'); ?>"
                    style="width:100%;height:100%;border:0;display:block;"
                    allow="payment *; clipboard-write; clipboard-read"
                    referrerpolicy="origin"></iframe>
            </div>
        </div>
        <?php
    }
}
