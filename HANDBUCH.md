# EasyCheckout für WooCommerce — Handbuch

Karten- und TWINT-Zahlungen, Checkout-Seiten, Zahlungslinks und Rechnungen — direkt in deinem WordPress-Shop. Dieses Handbuch führt Schritt für Schritt durch Installation, Verbindung und alle Einstellungen.

> **Online mit Screenshots:** https://easycheckout.ch/handbuch/woocommerce

---

## 1. Überblick

EasyCheckout verbindet deinen WooCommerce-Shop mit der EasyCheckout-Plattform und ergänzt eine schlanke, schweizerische Kasse mit Kreditkarte, TWINT und QR-Rechnung — ohne dass deine Kunden deine Website verlassen.

- **Native Kasse** — Zwei-Spalten-Kasse auf deiner eigenen Domain, im Design deines Shops.
- **Sofort kaufen & Express** — Direkt-Kauf-Buttons auf Produkt- und Warenkorbseite.
- **Rechnungen** — erstellen, ansehen, als PDF speichern und versenden.
- **Kunden** — Kundenstamm mit Name, Firma, Adresse.

**Voraussetzungen:** WordPress 6.0+, WooCommerce 7.0+ und ein (kostenloses) EasyCheckout-Konto.

## 2. Installation & Aktivierung

1. Lade die Datei `easycheckout-wp.zip` herunter.
2. In WordPress: **Plugins → Installieren → Plugin hochladen** → ZIP auswählen → **Jetzt installieren**.
3. Auf **Aktivieren** klicken. Künftige Updates kommen automatisch.

## 3. Konto verbinden

Öffne **EasyCheckout → Übersicht** und melde dich mit E-Mail und Passwort an (oder registriere dich kostenlos direkt im Fenster). Beim Anmelden erzeugt das Plugin automatisch den Zahlungs-Schlüssel und registriert den Bestell-Webhook.

> Das separate „API key"-Feld ist optional — nur für die manuelle Verbindung mit einem bestehenden `eck_live_…`-Schlüssel.

## 4. Einstellungen

Unter **WooCommerce → Einstellungen → EasyCheckout** bündeln fünf Sektionen alle Optionen (Linkleiste oben):

| Sektion | Inhalt |
|---|---|
| **Zahlung & Kasse** | Bezahlweg aktivieren, Titel/Beschreibung, Produktquelle, Express-Checkout, „Sofort kaufen", Kasse ersetzen, Darstellung, eigenes CSS |
| **Design** | Logo (Mediathek), Primär-/Hintergrund-/Text-/Button-Farbe, Ecken-Radius, Schrift |
| **Shop & Firma** | Firmenname, Adresse, E-Mail, Telefon, MwSt-Nummer (Rechnungssteller + Absender) |
| **Rechnung** | IBAN/QR-IBAN, Kontoinhaber, Bank, Zahlungsfrist, Fußtext |
| **E-Mails** | Absender, Bestellbestätigungs-Vorlage (Platzhalter), Versand-Schalter |

**Logo hochladen:** Sektion **Design** → Feld **Logo** → **Bild wählen** → Mediathek → **Änderungen speichern**. Ohne eigenes Logo wird das WordPress-Website-Logo verwendet.

## 5. Kassen-Varianten

| Weg | Wo | Was wird gekauft |
|---|---|---|
| **Zur Kasse** | aus dem Warenkorb | gesamter Warenkorb — native Zwei-Spalten-Kasse |
| **Sofort kaufen** | Produktseite | nur dieses Produkt, direkt zur Kasse |
| **Express** | Warenkorb | ganzer Warenkorb, Adresse + Zahlung in einem Schritt |

**Darstellung** (in „Zahlung & Kasse"): **Nativ** auf deiner Website (empfohlen), **Eingebettet** (iFrame) oder **Weiterleitung** zu easycheckout.ch.

## 6. Dashboard

Das Menü **EasyCheckout** bringt dein Konto in WordPress: Übersicht (Umsatz, Bestellungen, Conversion), Checkouts & Einbindung, Bestellungen, Kunden, Rechnungen, E-Mails, Marketing, Verifizierung, Tarif und Support.

## 7. Rechnungen

Unter **EasyCheckout → Rechnungen** mit **„+ Neue Rechnung"** anlegen. Pro Rechnung:

- **Ansehen** — Web-Ansicht (Anrede, Positionen, Grussformel)
- **PDF** — druckfertiges PDF
- **Senden** — per E-Mail an den Kunden
- **Mahnen** — Zahlungserinnerung

Ansehen und PDF funktionieren sofort nach dem Erstellen. Rechnungen sind ab Tarif **Rechnungen**, **Basic** oder **Pro** verfügbar.

## 8. Kunden

Unter **EasyCheckout → Kunden**: E-Mail, Name, **Firma**, Telefon und Adresse. Dieselben Felder auch im EasyCheckout-Dashboard — beide greifen auf denselben Kundenstamm zu.

## 9. Tipps & FAQ

**Ich sehe eine Änderung nicht.** Meist Browser-/CDN-Cache → Strg+Shift+R oder privates Fenster; hinter Cloudflare dort Cache leeren.

**Kunde erhält doppelte E-Mails.** In der Sektion **E-Mails** die Versand-Schalter für Bestätigung/Rechnung abschalten, wenn WooCommerce die Kundenmails übernimmt.

**Absender der E-Mails.** Standard: easycheckout.ch. Eigene Absender-Domain im EasyCheckout-Dashboard unter Einstellungen verifizieren.

**Wo erscheint mein Logo?** Im Kassen-Kopf und als Absendername in Bestell-Mails — hinterlegt in **Design → Logo**, mit Website-Logo als Rückfall.

---

*EasyCheckout für WooCommerce — die einfachste Art, in der Schweiz online zu verkaufen. Support über EasyCheckout → Support im WordPress-Backend.*
