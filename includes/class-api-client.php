<?php
/**
 * EasyCheckout API Client
 *
 * 100% white-label - all interactions through EasyCheckout API only.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * API Client for communicating with EasyCheckout backend
 */
class API_Client {

    /**
     * API base URL
     *
     * @var string
     */
    private $api_url;

    /**
     * API key
     *
     * @var string
     */
    private $api_key;

    /**
     * Test mode
     *
     * @var bool
     */
    private $test_mode;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api_url = rtrim(get_option('easycheckout_api_url', 'https://www.easycheckout.ch'), '/');
        $this->api_key = $this->decrypt_api_key(get_option('easycheckout_api_key', ''));
        $this->test_mode = get_option('easycheckout_test_mode', 'yes') === 'yes';
    }

    /**
     * Make API request
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $data Request data
     * @param bool $public Whether this is a public endpoint
     * @return array|\WP_Error
     */
    private function request($method, $endpoint, $data = [], $public = false) {
        $url = $this->api_url . $endpoint;

        $args = [
            'method' => $method,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ];

        // Add API key for authenticated endpoints
        if (!$public && !empty($this->api_key)) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->api_key;
        }

        // Add request body for POST/PUT/PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($data)) {
            $args['body'] = wp_json_encode($data);
        }

        // Add query params for GET
        if ($method === 'GET' && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $this->log('API Request: ' . $method . ' ' . $url);

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            $this->log('API Error: ' . $response->get_error_message(), 'error');
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);

        $this->log('API Response: ' . $status_code . ' - ' . substr($body, 0, 500));

        if ($status_code >= 400) {
            // Public endpoints return { error: "string" }; v1 endpoints return
            // { error: { code, message } }. Handle both.
            $error_message = 'Unknown API error';
            if (isset($decoded['error'])) {
                if (is_array($decoded['error'])) {
                    $error_message = isset($decoded['error']['message']) ? $decoded['error']['message'] : $error_message;
                } else {
                    $error_message = $decoded['error'];
                }
            } elseif (isset($decoded['message'])) {
                $error_message = $decoded['message'];
            }
            return new \WP_Error(
                'easycheckout_api_error',
                $error_message,
                ['status' => $status_code, 'body' => $decoded]
            );
        }

        return $decoded;
    }

    /**
     * GET request
     *
     * @param string $endpoint
     * @param array $params
     * @param bool $public
     * @return array|\WP_Error
     */
    public function get($endpoint, $params = [], $public = false) {
        return $this->request('GET', $endpoint, $params, $public);
    }

    /**
     * POST request
     *
     * @param string $endpoint
     * @param array $data
     * @param bool $public
     * @return array|\WP_Error
     */
    public function post($endpoint, $data = [], $public = false) {
        return $this->request('POST', $endpoint, $data, $public);
    }

    /**
     * PUT request
     *
     * @param string $endpoint
     * @param array $data
     * @return array|\WP_Error
     */
    public function put($endpoint, $data = []) {
        return $this->request('PUT', $endpoint, $data);
    }

    /**
     * DELETE request
     *
     * @param string $endpoint
     * @return array|\WP_Error
     */
    public function delete($endpoint) {
        return $this->request('DELETE', $endpoint);
    }

    // =========================================================================
    // Checkout Endpoints
    // =========================================================================

    /**
     * Create a new checkout
     *
     * @param array $data Checkout data
     * @return array|\WP_Error
     */
    public function create_checkout($data) {
        return $this->post('/api/v1/checkouts', $data);
    }

    /**
     * Get all checkouts
     *
     * @param array $params Query parameters
     * @return array|\WP_Error
     */
    public function get_checkouts($params = []) {
        $cache_key = 'easycheckout_checkouts_' . md5(serialize($params));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = $this->get('/api/v1/checkouts', $params);

        if (!is_wp_error($response)) {
            set_transient($cache_key, $response, 5 * MINUTE_IN_SECONDS);
        }

        return $response;
    }

    /**
     * Get a single checkout by ID
     *
     * @param string $id Checkout ID
     * @return array|\WP_Error
     */
    public function get_checkout($id) {
        return $this->get('/api/v1/checkouts/' . $id);
    }

    /**
     * Get checkout by slug (public)
     *
     * @param string $slug Checkout slug
     * @return array|\WP_Error
     */
    public function get_checkout_by_slug($slug) {
        return $this->get('/api/public/checkout/' . $slug, [], true);
    }

    /**
     * Update a checkout
     *
     * @param string $id Checkout ID
     * @param array $data Checkout data
     * @return array|\WP_Error
     */
    public function update_checkout($id, $data) {
        // Clear cache
        delete_transient('easycheckout_checkouts_' . md5(serialize([])));
        return $this->put('/api/v1/checkouts/' . $id, $data);
    }

    /**
     * Delete a checkout
     *
     * @param string $id Checkout ID
     * @return array|\WP_Error
     */
    public function delete_checkout($id) {
        delete_transient('easycheckout_checkouts_' . md5(serialize([])));
        return $this->delete('/api/v1/checkouts/' . $id);
    }

    // =========================================================================
    // Payment Endpoints
    // =========================================================================

    /**
     * Create a payment - returns redirect URL to hosted payment page
     *
     * @param string $checkout_slug Checkout slug
     * @param array $data Payment data
     * @return array|\WP_Error Response with paymentUrl for redirect
     */
    public function create_payment($checkout_slug, $data) {
        return $this->post('/api/public/checkout/' . $checkout_slug . '/pay', $data, true);
    }

    /**
     * Create an authenticated payment session (ad-hoc amount) and get the
     * hosted payment page URL. Used by the WooCommerce gateway, where the cart
     * total does not map to a fixed checkout's products.
     *
     * Requires an API key with the payments:write scope. Response data contains
     * payment_url, order_id and order_number.
     *
     * @param array $data { amount (cents int), currency, success_url, cancel_url, description, metadata }
     * @return array|\WP_Error
     */
    public function create_payment_session($data) {
        return $this->post('/api/v1/payment-sessions', $data);
    }

    /**
     * Register a webhook endpoint so EasyCheckout notifies this site of events
     * (e.g. order.paid). Requires an API key with the webhooks:write scope.
     * Response data contains the signing secret (only returned on creation).
     *
     * @param string $url    Endpoint URL (this site's webhook receiver)
     * @param array  $events Event names to subscribe to
     * @return array|\WP_Error
     */
    public function create_webhook_endpoint($url, $events) {
        return $this->post('/api/v1/webhooks/endpoints', [
            'url' => $url,
            'events' => $events,
        ]);
    }

    // =========================================================================
    // Order Endpoints
    // =========================================================================

    /**
     * Get all orders
     *
     * @param array $params Query parameters
     * @return array|\WP_Error
     */
    public function get_orders($params = []) {
        return $this->get('/api/v1/orders', $params);
    }

    /**
     * Get a single order
     *
     * @param string $id Order ID
     * @return array|\WP_Error
     */
    public function get_order($id) {
        return $this->get('/api/v1/orders/' . $id);
    }

    /**
     * Update order status
     *
     * @param string $id Order ID
     * @param string $status New status
     * @return array|\WP_Error
     */
    public function update_order_status($id, $status) {
        return $this->put('/api/v1/orders/' . $id . '/status', ['status' => $status]);
    }

    // =========================================================================
    // Product Endpoints
    // =========================================================================

    /**
     * Get all products
     *
     * @param array $params Query parameters
     * @return array|\WP_Error
     */
    public function get_products($params = []) {
        $cache_key = 'easycheckout_products_' . md5(serialize($params));
        $cached = get_transient($cache_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = $this->get('/api/v1/products', $params);

        if (!is_wp_error($response)) {
            set_transient($cache_key, $response, 5 * MINUTE_IN_SECONDS);
        }

        return $response;
    }

    /**
     * Create a product
     *
     * @param array $data Product data
     * @return array|\WP_Error
     */
    public function create_product($data) {
        delete_transient('easycheckout_products_' . md5(serialize([])));
        return $this->post('/api/v1/products', $data);
    }

    /**
     * Update a product
     *
     * @param string $id Product ID
     * @param array $data Product data
     * @return array|\WP_Error
     */
    public function update_product($id, $data) {
        delete_transient('easycheckout_products_' . md5(serialize([])));
        return $this->put('/api/v1/products/' . $id, $data);
    }

    // =========================================================================
    // Config/Settings Endpoints
    // =========================================================================

    /**
     * Get API configuration
     *
     * @return array|\WP_Error
     */
    public function get_config() {
        $cached = get_transient('easycheckout_config');
        if ($cached !== false) {
            return $cached;
        }

        // Backend has no /api/v1/config; the merchant profile is the closest
        // authenticated "who am I / settings" endpoint.
        $response = $this->get('/api/v1/settings/profile');

        if (!is_wp_error($response)) {
            set_transient('easycheckout_config', $response, HOUR_IN_SECONDS);
        }

        return $response;
    }

    /**
     * Get merchant profile (company name, email, address, ...)
     *
     * @return array|\WP_Error
     */
    public function get_profile() {
        return $this->get('/api/v1/settings/profile');
    }

    /**
     * Test API connection
     *
     * @return bool|\WP_Error
     */
    public function test_connection() {
        // Validates the Bearer API key and returns identity/scopes/tier.
        $response = $this->get('/api/v1/auth/token');

        if (is_wp_error($response)) {
            return $response;
        }

        return true;
    }

    // =========================================================================
    // Utility Methods
    // =========================================================================

    /**
     * Encrypt API key for storage
     *
     * @param string $api_key
     * @return string
     */
    public static function encrypt_api_key($api_key) {
        if (empty($api_key)) {
            return '';
        }

        // Use WordPress auth key for encryption
        $key = wp_salt('auth');
        $encrypted = openssl_encrypt($api_key, 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16));

        return base64_encode($encrypted);
    }

    /**
     * Decrypt API key from storage
     *
     * @param string $encrypted
     * @return string
     */
    private function decrypt_api_key($encrypted) {
        if (empty($encrypted)) {
            return '';
        }

        $key = wp_salt('auth');
        $decrypted = openssl_decrypt(base64_decode($encrypted), 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16));

        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Clear all cached data
     */
    public function clear_cache() {
        delete_transient('easycheckout_config');
        delete_transient('easycheckout_checkouts_' . md5(serialize([])));
        delete_transient('easycheckout_products_' . md5(serialize([])));
    }

    /**
     * Log messages
     *
     * @param string $message
     * @param string $level
     */
    private function log($message, $level = 'info') {
        if (get_option('easycheckout_debug_mode', 'no') !== 'yes') {
            return;
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $message, ['source' => 'easycheckout-api']);
        } else {
            error_log('[EasyCheckout API] ' . $message);
        }
    }

    /**
     * Get API URL
     *
     * @return string
     */
    public function get_api_url() {
        return $this->api_url;
    }

    /**
     * Check if API key is configured
     *
     * @return bool
     */
    public function is_configured() {
        return !empty($this->api_key);
    }

    /**
     * Check if in test mode
     *
     * @return bool
     */
    public function is_test_mode() {
        return $this->test_mode;
    }
}
