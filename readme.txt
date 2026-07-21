=== EasyCheckout – Checkout, Zahlungslinks & TWINT für die Schweiz ===
Contributors: easycheckout
Tags: checkout, payments, twint, woocommerce, qr-bill
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.57
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Fertige Checkout-Seiten & Zahlungslinks für die Schweiz – Karte, TWINT & QR-Rechnung. Standalone oder mit WooCommerce, ganz ohne Code.

== Description ==

**EasyCheckout** bringt eine vollständige, schweizerische Checkout-Lösung in dein WordPress – ohne dass du eine Zeile Code schreibst. Erstelle Checkout-Seiten und Zahlungslinks, akzeptiere **Kredit-/Debitkarten**, **TWINT** und die **Swiss QR-Rechnung**, und verwalte Bestellungen, Kunden und Rechnungen direkt im WP-Admin.

Das Plugin funktioniert in zwei Betriebsarten:

* **Standalone** – Du brauchst kein WooCommerce. Baue Checkouts per Shortcode auf jede Seite ein und verkaufe sofort.
* **Mit WooCommerce** – EasyCheckout registriert sich als Zahlungsart und kann die WooCommerce-Kasse vollständig durch eine native, auf deiner eigenen Domain gerenderte Kasse ersetzen (inkl. editierbarem Warenkorb, MwSt., Liefer-/Abholoptionen und Treuepunkten).

= Funktionen =

* Fertige, mobil-optimierte **Checkout-Seiten** und **Zahlungslinks** – in Minuten erstellt
* **Kredit-/Debitkarten**, **TWINT** und **Swiss QR-Rechnung** als Zahlungsarten
* **Shortcodes** `[easycheckout]`, `[easycheckout_button]`, `[easycheckout_product]` für jede Seite
* **WooCommerce-Integration**: Zahlungsart, „Sofort kaufen", Express-Checkout und optionaler **vollständiger Kassen-Ersatz** (nativ auf deiner Domain, als iFrame oder als Weiterleitung)
* **Editierbarer Warenkorb** in der Kasse – Mengen direkt ändern
* **WooCommerce Blocks (Cart & Checkout)** werden unterstützt
* **HPOS-kompatibel** (High-Performance Order Storage)
* **Design anpassbar**: Markenfarbe und eigenes CSS über die Einstellungen – oder für Entwickler per Filter (`easycheckout_checkout_color`, `easycheckout_checkout_css`)
* Übernimmt automatisch **Schriften und Look** deines Themes
* **Webhooks** für Zahlungsstatus, Bestellungen und Rückerstattungen inkl. Entwickler-Hooks
* **Rechnungen**, Kunden- und Bestellverwaltung im nativen Dashboard

= Für Entwickler =

Actions: `easycheckout_order_paid`, `easycheckout_order_failed`, `easycheckout_order_refunded`, `easycheckout_order_created`, `easycheckout_wc_order_completed`, `easycheckout_wc_order_failed`, `easycheckout_wc_order_refunded`.

Filter: `easycheckout_should_load_assets`, `easycheckout_hosted_checkout_url`, `easycheckout_checkout_color`, `easycheckout_checkout_css`, `easycheckout_enable_auto_update`, `easycheckout_enable_updater`.

== External services ==

Dieses Plugin verbindet sich mit dem EasyCheckout-Dienst, um Zahlungen zu verarbeiten und Checkouts/Bestellungen zu verwalten. Ohne diesen Dienst funktioniert das Plugin nicht.

**EasyCheckout (easycheckout.ch)**
Wofür: Erstellen und Ausliefern von Checkout-Seiten, Verarbeiten von Zahlungen, Verwalten von Bestellungen, Kunden und Rechnungen.
Übermittelte Daten: Betrag und Währung, Bestell-/Warenkorbpositionen, sowie – sofern vom Käufer eingegeben – Name, E-Mail-Adresse, Telefon und Rechnungs-/Lieferadresse. Zur Anbindung wird ein API-Schlüssel bzw. eine Login-Sitzung der Website verwendet.
Wann: Beim Anlegen einer Zahl-Sitzung, beim Abschluss einer Bestellung und beim Empfang von Status-Webhooks.
Anbieter-Bedingungen: https://easycheckout.ch/agb – Datenschutz: https://easycheckout.ch/datenschutz

**Zahlungsabwicklung (Stripe)**
Zur sicheren Erfassung von Kartendaten lädt die Bezahlseite das Browser-SDK des Zahlungsabwicklers (js.stripe.com). Karten- und Zahlungsdaten werden direkt und verschlüsselt an den Zahlungsabwickler übertragen und berühren deinen Server nicht.
Anbieter-Bedingungen: https://stripe.com/legal – Datenschutz: https://stripe.com/privacy

== Installation ==

1. Lade die Plugin-ZIP unter **Plugins → Installieren → Plugin hochladen** hoch, installiere und aktiviere sie. Es erscheint ein neues Menü **„EasyCheckout"**.
2. Öffne **EasyCheckout** im WP-Admin und melde dich mit deinem EasyCheckout-Konto an (oder trage unter **Einstellungen → Verbindung** deinen API-Schlüssel ein).
3. Baue einen Checkout mit dem Shortcode `[easycheckout slug="dein-checkout"]` auf einer beliebigen Seite ein.
4. Optional: Aktiviere bei installiertem WooCommerce unter **WooCommerce → Einstellungen → Zahlungen → EasyCheckout** die Zahlungsart und wähle den Kassen-Modus.

Ein EasyCheckout-Konto ist erforderlich. Du kannst kostenlos unter https://easycheckout.ch starten.

== Frequently Asked Questions ==

= Brauche ich WooCommerce? =
Nein. Das Plugin funktioniert eigenständig über Shortcodes. Mit WooCommerce erhältst du zusätzlich die Zahlungsart und den optionalen Kassen-Ersatz.

= Welche Zahlungsarten werden unterstützt? =
Kredit-/Debitkarten, TWINT und die Swiss QR-Rechnung. Welche Methoden angeboten werden, richtet sich nach deinem verbundenen Konto.

= Werden Kartendaten auf meinem Server gespeichert? =
Nein. Kartendaten werden direkt und verschlüsselt vom Browser an den Zahlungsabwickler übertragen. Dein Server und deine WordPress-Datenbank sehen keine vollständigen Kartendaten.

= Kann ich das Design anpassen? =
Ja. Markenfarbe und eigenes CSS lassen sich in den Einstellungen setzen. Entwickler können zusätzlich die Filter `easycheckout_checkout_color` und `easycheckout_checkout_css` nutzen. Die Kasse übernimmt zudem automatisch die Schrift deines Themes.

= Ist das Plugin mit dem Block-Checkout von WooCommerce kompatibel? =
Ja, die WooCommerce Cart- und Checkout-Blocks werden unterstützt, ebenso HPOS.

== Screenshots ==

1. Fertige Checkout-Seite mit Karten- und TWINT-Zahlung, an das Theme angepasst.
2. Native WooCommerce-Kasse mit editierbarem Warenkorb (Mengen direkt änderbar).
3. Zahlungsart-Einstellungen inkl. Design-Optionen (Markenfarbe, eigenes CSS).
4. Natives EasyCheckout-Dashboard im WP-Admin: Bestellungen, Kunden, Rechnungen.

== Changelog ==

= 1.0.57 =
* Block-Checkout (WooCommerce Cart/Checkout-Blocks): EasyCheckout erscheint jetzt korrekt als Zahlungsart inkl. Logo. Zuvor fehlte das Integrations-Script.

= 1.0.56 =
* WooCommerce-Zahlungsart zeigt jetzt das EasyCheckout-Logo neben dem Namen.

= 1.0.55 =
* Veröffentlichung vorbereitet: readme.txt, GPLv2-Lizenz, saubere Deinstallation (uninstall.php).
* Auto-Updater ist abschaltbar und wird in der WordPress.org-Fassung nicht ausgeliefert.

= 1.0.54 =
* Tarifanzeige im Dashboard korrigiert (Kommission 3,5 % + CHF 0,35).
* Oberfläche vereinheitlicht (white-label): interne Anbieternamen aus sichtbaren Texten entfernt.

= 1.0.53 =
* Neue native WooCommerce-Kasse mit editierbarem Warenkorb (Mengen direkt änderbar).
* Wählbarer Kassen-Modus (nativ / eingebettet / Weiterleitung) und Produktquelle.
* Entwickler-Design: Markenfarbe (`--ec-p`) und eigenes CSS über Einstellungen und Filter.

= 1.0.48 =
* Auto-Updater und Release-Build stabilisiert; „Nach Updates suchen" leert den Cache.

= 1.0.44 =
* WooCommerce-Kassen-Ersatz standardmäßig nativ (inline) statt iFrame, frei wählbar.

= 1.0.0 =
* Erste Veröffentlichung: Checkout-Seiten, Zahlungslinks, Shortcodes, WooCommerce-Zahlungsart, natives Dashboard, Webhooks.

== Upgrade Notice ==

= 1.0.55 =
Veröffentlichungsreife: Lizenz, saubere Deinstallation, abschaltbarer Auto-Updater.

= 1.0.54 =
Korrekte Tarifanzeige und vereinheitlichte Oberfläche. Update empfohlen.
