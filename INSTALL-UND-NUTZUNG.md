# EasyCheckout WP-Plugin – Installation & Nutzung

**Plugin-ZIP:** `C:\Users\pmorg\easycheckout-wp-1.0.0.zip` (Version 1.0.0)
**Plattform / API-URL:** `https://www.easycheckout.ch`
**Dein Merchant:** „lokal werbung gmbh" · `pmorgenthaler8@gmail.com` (Stripe onboarding complete, charges enabled)
**Test-Checkout (live, aktiv):** Slug `pistoleros` → https://www.easycheckout.ch/c/pistoleros

---

## 1. Plugin installieren
1. WP-Admin → **Plugins → Installieren → Plugin hochladen**.
2. `easycheckout-wp-1.0.0.zip` hochladen → **Installieren** → **Aktivieren**.
3. Es erscheint ein neues Top-Level-Menü **„EasyCheckout"**.

## 2. Verbinden – zwei Wege

### Weg A (empfohlen): Natives Dashboard – kein API-Key nötig
- WP-Admin → **EasyCheckout** → mit `pmorgenthaler8@gmail.com` + Passwort einloggen (JWT).
- Damit verwaltest du Checkouts, Produkte, Bestellungen, Kunden, Rechnungen,
  E-Mails, Marketing, Webhooks, Onboarding/KYC direkt im WP-Admin.

### Weg B: API-Key (für die `/api/v1`-Client-Funktionen)
- WP-Admin → **EasyCheckout → Einstellungen → Verbindung**:
  - **API URL:** `https://www.easycheckout.ch`
  - **API Key:** `eck_live_…` (bzw. `eck_test_…`)
- Key erzeugen (einmalig, Admin-seitig auf der Plattform): Endpoint
  `POST /api/admin/merchants/1/api-key` → liefert den `eck_live_`-Key **einmal**
  im Klartext zurück (in der DB nur SHA-256-Hash). Key kopieren und oben eintragen.
- **Test-Modus** in den Einstellungen auf **Aus** stellen für echte Zahlungen
  (Default ist „yes" = Test).

## 3. Checkout auf einer Seite einbauen (Shortcode)

Inline-Checkout (pixelgleich zu easycheckout.ch, Zahlung passiert im iFrame):
```
[easycheckout slug="pistoleros"]
```

Als Redirect-Button statt Inline:
```
[easycheckout slug="pistoleros" mode="button" button_text="Jetzt kaufen"]
```

Nützliche Attribute:
- `slug="…"` oder `id="…"` – wählt den Checkout
- `mode="iframe"` (Standard) | `mode="button"`
- `height="1250"` fixe Höhe in px (sonst responsive Auto-Höhe)
- `max_width="1100"`
- Design-Overrides: `primary` `bg` `button` `button_text_color` `text` `radius`
- `font="site"` – übernimmt den Font der Website

Weitere Shortcodes: `[easycheckout_button slug="…"]`, `[easycheckout_product …]`.

## 4. WooCommerce (optional)
Bei aktivem WooCommerce registriert sich EasyCheckout automatisch als Zahlungsart
(Karte / TWINT / Swiss QR-Bill). Aktivieren unter **WooCommerce → Einstellungen →
Zahlungen → EasyCheckout**. Blocks-Checkout wird unterstützt.

## 5. Webhooks (Statusrückmeldung)
EasyCheckout meldet Zahlungsstatus an:
```
https://DEINE-DOMAIN/wp-json/easycheckout/v1/webhook
```
Webhook-Secret in **Einstellungen → Verbindung** hinterlegen. Eingehende Webhooks
aktualisieren die lokale Transaktionstabelle (`wp_easycheckout_transactions`) bzw.
die WooCommerce-Bestellung.

## 6. Schnelltest
1. Neue WP-Seite anlegen, `[easycheckout slug="pistoleros"]` einfügen, veröffentlichen.
2. Seite im Frontend öffnen → der Pistoleros-Checkout lädt inline.
3. (Im Test-Modus) Testzahlung durchführen → Bestellung erscheint im Dashboard
   unter **EasyCheckout → Bestellungen**.

---
*Stand: 2026-06-30. Plugin master @ 4e415b1 (natives Dashboard komplett).*
