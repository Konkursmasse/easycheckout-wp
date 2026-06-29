<?php
/**
 * Native EasyCheckout API client (server-side, JWT).
 *
 * Authenticates with email/password against the EasyCheckout dashboard API,
 * stores the JWT encrypted, and proxies authenticated requests to the same
 * internal endpoints the dashboard uses. Keeps the token server-side (never
 * exposed to the browser) and avoids CORS.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

defined('ABSPATH') || exit;

/**
 * JWT-based API client for the native dashboard.
 */
class Native_API {

    const OPT_TOKEN    = 'easycheckout_jwt';
    const OPT_MERCHANT = 'easycheckout_merchant';

    /**
     * Base app URL.
     *
     * @return string
     */
    public function base_url() {
        return rtrim(get_option('easycheckout_api_url', 'https://www.easycheckout.ch'), '/');
    }

    /**
     * Are we authenticated?
     *
     * @return bool
     */
    public function is_authenticated() {
        return (bool) $this->get_token();
    }

    /**
     * Get the stored merchant info.
     *
     * @return array
     */
    public function get_merchant() {
        $m = get_option(self::OPT_MERCHANT, []);
        return is_array($m) ? $m : [];
    }

    /**
     * Log in with email + password; store the JWT + merchant on success.
     *
     * @param string $email
     * @param string $password
     * @return array|\WP_Error
     */
    public function login($email, $password) {
        $resp = $this->raw_request('POST', '/api/auth/login', [
            'email' => $email,
            'password' => $password,
        ], false);

        if (is_wp_error($resp)) {
            return $resp;
        }

        if (empty($resp['token'])) {
            return new \WP_Error('ec_login_failed', __('Anmeldung fehlgeschlagen.', 'easycheckout'));
        }

        update_option(self::OPT_TOKEN, $this->encrypt($resp['token']), false);
        update_option(self::OPT_MERCHANT, isset($resp['merchant']) ? $resp['merchant'] : [], false);

        return ['success' => true, 'merchant' => isset($resp['merchant']) ? $resp['merchant'] : []];
    }

    /**
     * Register a new merchant account, then log in.
     *
     * @param array $data
     * @return array|\WP_Error
     */
    public function register($data) {
        $resp = $this->raw_request('POST', '/api/auth/register', $data, false);
        if (is_wp_error($resp)) {
            return $resp;
        }
        // Some register endpoints return a token directly; otherwise log in.
        if (!empty($resp['token'])) {
            update_option(self::OPT_TOKEN, $this->encrypt($resp['token']), false);
            update_option(self::OPT_MERCHANT, isset($resp['merchant']) ? $resp['merchant'] : [], false);
            return ['success' => true, 'merchant' => isset($resp['merchant']) ? $resp['merchant'] : []];
        }
        if (!empty($data['email']) && !empty($data['password'])) {
            return $this->login($data['email'], $data['password']);
        }
        return ['success' => true];
    }

    /**
     * Log out (clear stored token).
     */
    public function logout() {
        delete_option(self::OPT_TOKEN);
        delete_option(self::OPT_MERCHANT);
    }

    /**
     * Make an authenticated API request (JWT bearer).
     *
     * @param string $method
     * @param string $path   Path beginning with /api/...
     * @param mixed  $data
     * @return array { status:int, body:mixed } | \WP_Error
     */
    public function request($method, $path, $data = null) {
        $token = $this->get_token();
        if (!$token) {
            return new \WP_Error('ec_not_authenticated', __('Nicht angemeldet.', 'easycheckout'), ['status' => 401]);
        }

        $args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Accept'        => 'application/json',
                'Authorization' => 'Bearer ' . $token,
            ],
        ];

        if (in_array($args['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true) && $data !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode($data);
        }

        $url = $this->base_url() . $path;
        if ($args['method'] === 'GET' && is_array($data) && !empty($data)) {
            $url = add_query_arg($data, $url);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        // Token expired/invalid -> drop it so the UI shows the login screen.
        if ($status === 401) {
            $this->logout();
        }

        return ['status' => $status, 'body' => $body];
    }

    // ---------------------------------------------------------------------

    /**
     * Unauthenticated request (login/register).
     *
     * @return array|\WP_Error decoded body or error
     */
    private function raw_request($method, $path, $data, $auth = true) {
        $args = [
            'method'  => strtoupper($method),
            'timeout' => 30,
            'headers' => [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode($data),
        ];

        $response = wp_remote_request($this->base_url() . $path, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status >= 400) {
            $msg = is_array($body) && !empty($body['error']) ? $body['error'] : __('Anfrage fehlgeschlagen.', 'easycheckout');
            return new \WP_Error('ec_api_error', $msg, ['status' => $status]);
        }

        return is_array($body) ? $body : [];
    }

    /**
     * Decrypted token or empty string.
     *
     * @return string
     */
    private function get_token() {
        $stored = get_option(self::OPT_TOKEN, '');
        return $stored ? $this->decrypt($stored) : '';
    }

    /**
     * @param string $value
     * @return string
     */
    private function encrypt($value) {
        if ($value === '') {
            return '';
        }
        $key = wp_salt('auth');
        $enc = openssl_encrypt($value, 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16));
        return base64_encode($enc);
    }

    /**
     * @param string $value
     * @return string
     */
    private function decrypt($value) {
        $key = wp_salt('auth');
        $dec = openssl_decrypt(base64_decode($value), 'AES-256-CBC', $key, 0, substr(md5($key), 0, 16));
        return $dec !== false ? $dec : '';
    }
}
