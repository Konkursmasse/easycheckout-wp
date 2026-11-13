<?php
/**
 * Bereinigung von JSON-Daten aus Anfragen.
 *
 * Mehrere Ajax-Endpunkte erhalten verschachtelte Daten als JSON-String
 * (Warenkorb, Kundendaten, Proxy-Aufrufe an die easyCheckout-API).
 * json_decode() allein bereinigt nichts; diese Funktionen gehen jeden Wert
 * durch und wählen die passende WordPress-Funktion nach Datentyp und Feldname.
 *
 * @package EasyCheckout
 */

if (!defined('ABSPATH')) { exit; }

/**
 * Bereinigt einen dekodierten JSON-Wert rekursiv.
 *
 * Zahlen und Wahrheitswerte bleiben, wie sie sind. Zeichenketten werden je
 * nach Feldname als E-Mail, URL oder Text bereinigt; Zeilenumbrüche bleiben
 * erhalten (Beschreibungen, Adressen).
 *
 * @param mixed  $value Dekodierter Wert.
 * @param string $key   Feldname des Werts (bestimmt die Art der Bereinigung).
 * @return mixed
 */
function easycheckout_sanitize_deep($value, $key = '') {
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
            $clean_key = is_int($k) ? $k : sanitize_text_field((string) $k);
            $out[$clean_key] = easycheckout_sanitize_deep($v, is_int($k) ? $key : (string) $k);
        }
        return $out;
    }
    if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
        return $value;
    }

    $value = (string) $value;
    $name  = strtolower((string) $key);

    if (strpos($name, 'password') !== false) {
        // Passwörter unverändert weitergeben: jede Bereinigung würde das
        // Passwort verändern. Es wird nie gespeichert oder ausgegeben, nur
        // per HTTPS an die easyCheckout-API gesendet.
        return $value;
    }
    if ($name === 'email' || substr($name, -5) === 'email') {
        return sanitize_email($value);
    }
    if (preg_match('/(html|body|content|template)$/', $name)) {
        // Mail-Vorlagen und Texte mit erlaubtem HTML.
        return wp_kses_post($value);
    }
    if (preg_match('/(url|href|link|logo|image)$/', $name)) {
        // data:-Bilder (Produktbilder aus dem Dashboard) zulassen.
        return esc_url_raw($value, ['http', 'https', 'data']);
    }
    return sanitize_textarea_field($value);
}

/**
 * Liest ein JSON-Feld aus $_POST, dekodiert und bereinigt es.
 *
 * @param string $field   Name des POST-Felds.
 * @param mixed  $default Rückgabe, wenn das Feld fehlt oder kein JSON-Objekt/-Array ist.
 * @return mixed
 */
function easycheckout_post_json($field, $default = []) {
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Die Aufrufer prüfen die Nonce vorher.
    if (!isset($_POST[$field])) {
        return $default;
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON wird unten Feld für Feld bereinigt.
    $decoded = json_decode(wp_unslash($_POST[$field]), true);
    if (!is_array($decoded)) {
        return $default;
    }
    return easycheckout_sanitize_deep($decoded);
}

/**
 * Bereinigt einen API-Pfad für die Proxy-Endpunkte.
 *
 * Erlaubt sind nur Pfade unter /api/ mit den Zeichen, die in Pfaden und
 * Abfrageteilen der easyCheckout-API vorkommen.
 *
 * @param string $path Roh-Pfad.
 * @return string Bereinigter Pfad oder '' wenn unzulässig.
 */
function easycheckout_sanitize_api_path($path) {
    $path = preg_replace('#[^A-Za-z0-9/_\-.~?=&%,:+]#', '', (string) $path);
    if (strpos($path, '/api/') !== 0 || strpos($path, '..') !== false) {
        return '';
    }
    return $path;
}
