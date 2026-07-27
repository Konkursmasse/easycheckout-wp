=== EasyCheckout ===
Contributors: easycheckout
Tags: checkout, payments, twint, woocommerce, qr-bill
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.84
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

= So richtest du es ein (Kurzüberblick) =

1. **Installieren & aktivieren** – ZIP hochladen, aktivieren. Es erscheinen das Menü „EasyCheckout" und ein Tab in den WooCommerce-Einstellungen.
2. **Konto verbinden** – unter „EasyCheckout → Übersicht" mit E-Mail/Passwort anmelden (oder kostenlos registrieren). Der Zahlungs-Schlüssel und der Bestell-Webhook werden dabei automatisch erstellt.
3. **Einstellungen** – unter „WooCommerce → Einstellungen → EasyCheckout" in fünf Sektionen: **Zahlung & Kasse**, **Design** (Logo, Farben), **Shop & Firma**, **Rechnung** (IBAN, Zahlungsfrist), **E-Mails** (Vorlagen, Versand-Schalter).
4. **Verkaufen** – die Kasse ist aktiv; optional „Sofort kaufen", Express-Checkout oder vollständiger Kassen-Ersatz.

Ausführliches Handbuch mit Screenshots: siehe Abschnitt **Installation** unten.

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

= 1. Plugin installieren =
Lade die Plugin-ZIP unter **Plugins → Installieren → Plugin hochladen** hoch, installiere und aktiviere sie. Danach erscheinen das Menü **„EasyCheckout"** (linke Seitenleiste) und ein eigener Tab unter **WooCommerce → Einstellungen → EasyCheckout**. Künftige Updates kommen automatisch.

= 2. Konto verbinden =
Öffne **EasyCheckout → Übersicht** und melde dich mit E-Mail und Passwort deines EasyCheckout-Kontos an – oder registriere dich kostenlos direkt im Fenster. Beim Anmelden erzeugt das Plugin automatisch den nötigen **Zahlungs-Schlüssel** und registriert den **Bestell-Webhook**; du musst nichts kopieren. (Das separate „API key"-Feld ist optional und nur für die manuelle Verbindung mit einem bestehenden `eck_live_…`-Schlüssel gedacht.)

= 3. Einstellungen (WooCommerce → Einstellungen → EasyCheckout) =
Die Optionen sind in fünf Sektionen gegliedert (Linkleiste oben):

* **Zahlung & Kasse** – Bezahlweg aktivieren, Titel/Beschreibung, Produktquelle, Express-Checkout, „Sofort kaufen", WooCommerce-Kasse ersetzen, Darstellung sowie ein Feld für eigenes CSS.
* **Design** – Logo (Button „Bild wählen" öffnet die Mediathek), Primär-/Hintergrund-/Text-/Button-Farbe, Ecken-Radius und Schrift. Ohne eigenes Logo wird automatisch das WordPress-Website-Logo verwendet.
* **Shop & Firma** – Firmenname, Adresse, E-Mail, Telefon, MwSt-Nummer. Erscheint als Rechnungssteller und Mail-Absender.
* **Rechnung** – IBAN/QR-IBAN, Kontoinhaber, Bank, Zahlungsfrist und Fußtext (Grundlage für Banküberweisung und Swiss-QR-Rechnung).
* **E-Mails** – Absender und Vorlage der Bestellbestätigung (Platzhalter wie `{ref}`, `{total}`, `{positionen}`) sowie Versand-Schalter, um Bestätigung/Rechnung an den Käufer an- oder abzuschalten.

= 4. Kassen-Varianten =
Es gibt drei Wege in die Kasse, alle enden in der EasyCheckout-Zahlung:

* **Zur Kasse** (aus dem Warenkorb) – die native Zwei-Spalten-Kasse mit dem gesamten Warenkorb.
* **Sofort kaufen** (Produktseite) – direkter Kauf nur dieses Produkts. Der Button übernimmt automatisch das Design deines Shop-Buttons „In den Warenkorb".
* **Express** (Warenkorb) – Adresse und Zahlung in einem schnellen Schritt.

Die **Darstellung** (in „Zahlung & Kasse") legt fest, wie die Kasse erscheint: **Nativ** auf deiner Website (empfohlen, kein iFrame), **Eingebettet** (iFrame) oder **Weiterleitung** zu easycheckout.ch.

= 5. Ohne WooCommerce (Standalone) =
Baue einen Checkout mit dem Shortcode `[easycheckout slug="dein-checkout"]` auf jede beliebige Seite ein.

Ein EasyCheckout-Konto ist erforderlich. Du kannst kostenlos unter https://easycheckout.ch starten. Das vollständige Handbuch mit Screenshots findest du unter https://easycheckout.ch/handbuch/woocommerce

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

= Wo lade ich mein Logo hoch? =
Unter **WooCommerce → Einstellungen → EasyCheckout → Design** beim Feld „Logo" auf „Bild wählen" klicken. Ist dort nichts hinterlegt, verwendet die Kasse automatisch dein WordPress-Website-Logo.

= Kann ich Rechnungen ansehen und als PDF speichern? =
Ja. Unter **EasyCheckout → Rechnungen** hat jede Rechnung die Aktionen „Ansehen" (Web-Ansicht) und „PDF" – beides funktioniert sofort nach dem Erstellen, ohne vorheriges Versenden. Rechnungen sind ab dem Tarif „Rechnungen", „Basic" oder „Pro" verfügbar.

= Mein Kunde bekommt doppelte E-Mails =
Sowohl WooCommerce als auch EasyCheckout können Bestell-Mails senden. In der Sektion **E-Mails** die Versand-Schalter für Bestätigung und/oder Rechnung abschalten, wenn WooCommerce die Kundenmails übernehmen soll.

= Ich sehe eine Änderung nicht =
Meist Browser- oder CDN-Cache. Seite mit Strg+Shift+R neu laden oder ein privates Fenster nutzen; hinter einem CDN (z. B. Cloudflare) dort den Cache leeren.

== Screenshots ==

1. Native WooCommerce-Kasse: zweispaltig, im Theme-Design.
2. Einstellungen – Sektion „Design": Logo-Upload, Farben, Radius, Schrift.
3. Einstellungen – Sektion „Zahlung & Kasse" mit allen Checkout-Optionen.
4. Natives EasyCheckout-Dashboard im WP-Admin: Übersicht mit Bestellungen, Kunden, Rechnungen.
5. Produktseite mit „Sofort kaufen"-Button im Shop-Design.

== Changelog ==

= 1.0.73 =
* Gebündelter Einstellungs-Tab mit Sektionen: Zahlung & Kasse, Design (Logo/Farben), Shop & Firma, Rechnung, E-Mails – inkl. sichtbarer Sektions-Navigation.
* Rechnungen: „Ansehen" und „PDF" direkt aus der Liste, ohne vorheriges Versenden; planabhängige Freischaltung.
* Kunden: Firma-Feld ergänzt.
* Kasse: Produkte/Bestellung bündig ausgerichtet, Checkbox „Lieferadresse entspricht Rechnungsadresse", „Powered by easyCheckout"-Logo, „Sofort kaufen"-Button im Shop-Design; automatischer Logo-Rückfall auf das Website-Logo.
* Direkter Menüpunkt „EasyCheckout" im WooCommerce-Menü.

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
