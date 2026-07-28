<?php
/**
 * Währungsformatierung.
 *
 * Bisher wurde jeder Betrag schweizerisch formatiert — Apostroph als
 * Tausendertrennzeichen, Punkt als Dezimaltrennzeichen, Währungscode vorangestellt:
 * «EUR 1'234.50». Für einen deutschen oder französischen Shop ist das falsch
 * geschrieben.
 *
 * Hier steht die Schreibweise pro Währung an einer Stelle, damit sie nicht in
 * jeder Datei neu erfunden wird.
 *
 * @package EasyCheckout
 */

namespace EasyCheckout;

if (!defined('ABSPATH')) {
    exit;
}

class Money {

    /**
     * Schreibweise je Währung.
     *
     * symbol_after – Symbol hinter dem Betrag (EUR: «1.234,50 €»)
     * decimal      – Dezimaltrennzeichen
     * thousands    – Tausendertrennzeichen
     *
     * @var array
     */
    private static $formats = array(
        'CHF' => array('symbol' => 'CHF', 'symbol_after' => false, 'decimal' => '.', 'thousands' => "'"),
        'EUR' => array('symbol' => '€',   'symbol_after' => true,  'decimal' => ',', 'thousands' => '.'),
        'USD' => array('symbol' => '$',   'symbol_after' => false, 'decimal' => '.', 'thousands' => ','),
        'GBP' => array('symbol' => '£',   'symbol_after' => false, 'decimal' => '.', 'thousands' => ','),
    );

    /**
     * Betrag formatieren.
     *
     * Unbekannte Währungen bekommen den Code vorangestellt und eine neutrale
     * Schreibweise — besser als eine falsche Landeskonvention zu erzwingen.
     *
     * @param float  $amount
     * @param string $currency ISO-Code
     * @return string
     */
    public static function format($amount, $currency = 'CHF') {
        $code = strtoupper(substr((string) $currency, 0, 3));
        if ($code === '') {
            $code = 'CHF';
        }

        if (!isset(self::$formats[$code])) {
            return $code . ' ' . number_format((float) $amount, 2, '.', "'");
        }

        $f   = self::$formats[$code];
        $num = number_format((float) $amount, 2, $f['decimal'], $f['thousands']);

        return $f['symbol_after'] ? $num . ' ' . $f['symbol'] : $f['symbol'] . ' ' . $num;
    }

    /**
     * Symbol einer Währung, sonst der Code.
     *
     * @param string $currency
     * @return string
     */
    public static function symbol($currency = 'CHF') {
        $code = strtoupper(substr((string) $currency, 0, 3));
        return isset(self::$formats[$code]) ? self::$formats[$code]['symbol'] : $code;
    }
}
