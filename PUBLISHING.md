# EasyCheckout – Veröffentlichung

Dieses Dokument beschreibt, wie das Plugin über **beide** Kanäle veröffentlicht wird:
Self-Hosting (Direkt-Download mit Auto-Updater) **und** das WordPress.org-Verzeichnis.

Aktuelle Version: siehe `Stable tag` in `readme.txt` und `Version` in `easycheckout.php`
(beide müssen identisch sein).

---

## 1. Release bauen (automatisch, beide ZIPs)

Ein Version-Tag löst die CI (`.github/workflows/release.yml`) aus. Sie baut zwei Assets:

| Asset | Für | Enthält Auto-Updater? |
|---|---|---|
| `easycheckout-wp.zip` | **Self-Hosting / Direkt-Download** | **Ja** (`includes/class-updater.php`) |
| `easycheckout-wp-wporg.zip` | **WordPress.org-Einreichung** | **Nein** (Datei + Dev-Dokus entfernt) |

Release auslösen:

```bash
# Version in BEIDEN Dateien bumpen: easycheckout.php (Header + Konstante) und readme.txt (Stable tag)
git commit -am "Release vX.Y.Z"
git tag vX.Y.Z
git push origin HEAD --tags
```

Nach ~15 s liegen beide ZIPs unter „Releases" auf GitHub.

---

## 2. Self-Hosting / Direkt-Download

Kein Freigabeprozess. So kommt das Update zu den Kunden:

1. Der **Auto-Updater** im Plugin fragt `releases/latest` auf GitHub ab. Bei einer neueren
   Version meldet er WordPress ein Update (inkl. „Details ansehen" + Ein-Klick/Hintergrund-Update).
2. Für Neukunden: `easycheckout-wp.zip` als Download auf **easycheckout.ch** anbieten
   (z. B. `/plugin`). Manuelle Installation via **Plugins → Installieren → Plugin hochladen**.

Abschalten des Auto-Updaters (falls gewünscht): `add_filter('easycheckout_enable_auto_update','__return_false')`
oder komplett: `add_filter('easycheckout_enable_updater','__return_false')`.

---

## 3. WordPress.org-Verzeichnis

### 3.1 Einmalig: Einreichen

1. WordPress.org-Account anlegen und den **Contributor-Slug** in `readme.txt`
   (`Contributors:`) eintragen (aktuell Platzhalter `easycheckout`).
2. Plugin unter https://wordpress.org/plugins/developers/add/ einreichen
   (`easycheckout-wp-wporg.zip` hochladen). Das Plugin-Review-Team prüft (i. d. R. 2–6 Wochen)
   und legt bei Freigabe ein **SVN-Repository** an.

### 3.2 Nach Freigabe: via SVN veröffentlichen

```
svn co https://plugins.svn.wordpress.org/easycheckout your-local-svn
# Plugin-Dateien (Inhalt von easycheckout-wp-wporg.zip, OHNE den Ordner-Wrapper) nach trunk/ kopieren
# Marketing-Assets:
cp .wordpress-org/assets/* your-local-svn/assets/
# Version taggen:
svn cp trunk tags/X.Y.Z
svn ci -m "Release X.Y.Z"
```

`readme.txt` `Stable tag` = die getaggte Version → damit wird sie live.

### 3.3 Assets (liegen in `.wordpress-org/assets/`, gehören in SVN `assets/`, NICHT ins Plugin-ZIP)

- `icon-128x128.png`, `icon-256x256.png`
- `banner-772x250.png`, `banner-1544x500.png`
- `screenshot-1.png` … `screenshot-4.png` (Reihenfolge = Nummerierung in `readme.txt` → `== Screenshots ==`)

Neu generieren: `node scratchpad/wporg-assets.js` (Icon+Banner) und `node scratchpad/wporg-shots.js` (Screenshots).

### 3.4 Wichtige Review-Punkte (vorab geklärt)

- ✅ **GPLv2** (`LICENSE`), Header vollständig, Text-Domain `easycheckout`.
- ✅ **`readme.txt`** mit `== External services ==`-Abschnitt (Pflicht, da SaaS-Anbindung).
- ✅ **Auto-Updater entfernt** in der .org-Fassung (WP.org verwaltet Updates selbst).
- ✅ **`uninstall.php`** räumt Optionen, Transients und Tabelle restlos auf.
- ⚠️ **Externer Dienst „Stripe" offengelegt:** Die Bezahlseite lädt browserseitig `js.stripe.com`.
  WP.org verlangt die Offenlegung ALLER externen Dienste inkl. Anbieter + ToS/Datenschutz-Links.
  Deshalb nennt der `== External services ==`-Abschnitt der `readme.txt` den Zahlungsabwickler
  namentlich. **Das ist die einzige Stelle, an der „Stripe" auftaucht** – ausschließlich im
  rechtlich-technischen Offenlegungs-Abschnitt, NICHT in der Händler-Oberfläche. Ohne diese
  Offenlegung ist eine .org-Freigabe nicht möglich. Bei einem Veto müsste die browserseitige
  Karten-Erfassung für die .org-Fassung anders gelöst werden.
- ⚠️ **Konto-Pflicht:** Das Plugin braucht ein EasyCheckout-Konto. Das ist erlaubt (wie bei
  Stripe/PayPal/Mailchimp-Plugins), muss aber – wie geschehen – in Beschreibung + Installation
  klar stehen.

---

## 4. Vor jedem Release – Checkliste

- [ ] Version in `easycheckout.php` (Header **und** `EASYCHECKOUT_VERSION`) gebumpt
- [ ] `Stable tag` in `readme.txt` = neue Version
- [ ] `== Changelog ==` in `readme.txt` ergänzt
- [ ] `WC tested up to` / `Tested up to` aktuell
- [ ] Tag gepusht → beide ZIPs im Release vorhanden
- [ ] (nur .org) `svn ci` nach Freigabe
