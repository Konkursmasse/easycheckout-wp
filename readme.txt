=== EasyCheckout ===
Contributors: easycheckout
Tags: checkout, payments, twint, woocommerce, qr-bill
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.90
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Checkout pages and payment links for Switzerland: cards, TWINT and Swiss QR-bill. Works standalone or with WooCommerce, no code required.

== Description ==

**EasyCheckout** is the official WordPress plugin of easyCheckout (https://easycheckout.io), a Swiss payment platform operated by lokal werbung GmbH, Effretikon, Switzerland.

The plugin adds ready-made checkout pages and payment links to your WordPress site. Your customers pay by **credit/debit card**, **TWINT** or **Swiss QR-bill**, and you manage orders, customers and invoices directly in the WordPress admin.

The plugin works in two modes:

* **Standalone** – no WooCommerce needed. Embed a checkout on any page with a shortcode and start selling.
* **With WooCommerce** – EasyCheckout registers as a payment method and can optionally replace the WooCommerce checkout with a native checkout rendered on your own domain (editable cart, VAT, pickup/delivery options and loyalty points).

= Features =

* Mobile-ready **checkout pages** and **payment links**, set up in minutes
* **Credit/debit cards**, **TWINT** and **Swiss QR-bill** as payment methods
* **Shortcodes** `[easycheckout]`, `[easycheckout_button]` and `[easycheckout_product]` for any page
* **WooCommerce integration**: payment method, "Buy now" button, express checkout and an optional **full checkout replacement** (native on your domain, embedded, or as a redirect)
* **Editable cart** in the checkout
* Support for the **WooCommerce Cart and Checkout blocks**
* **HPOS compatible** (High-Performance Order Storage)
* **Design settings**: logo, brand colour, background, text and button colours, corner radius and font. Developers can additionally use the filters `easycheckout_checkout_color` and `easycheckout_checkout_css`.
* Adopts the **fonts and look** of your theme
* **Webhooks** for payment status, orders and refunds, with developer hooks
* **Invoices**, customer and order management in the native dashboard

= Getting started =

1. **Install and activate** the plugin. The menu "EasyCheckout" and a tab in the WooCommerce settings appear.
2. **Connect your account** under "EasyCheckout → Übersicht" (overview) with your e-mail and password, or register for free. The payment key and the order webhook are created automatically.
3. **Configure** the plugin under "WooCommerce → Settings → EasyCheckout": payment and checkout, design (logo, colours), shop and company, invoice (IBAN, payment terms) and e-mails (templates, sending switches).
4. **Sell** – the checkout is active. Optionally enable "Buy now", express checkout or the full checkout replacement.

The admin interface is currently in German, with French and Italian translations.

= For developers =

Actions: `easycheckout_order_paid`, `easycheckout_order_failed`, `easycheckout_order_refunded`, `easycheckout_order_created`, `easycheckout_wc_order_completed`, `easycheckout_wc_order_failed`, `easycheckout_wc_order_refunded`.

Filters: `easycheckout_should_load_assets`, `easycheckout_hosted_checkout_url`, `easycheckout_checkout_color`, `easycheckout_checkout_css`.

= Source code =

The complete, human-readable source code of this plugin is public at https://github.com/Konkursmasse/easycheckout-wp

All JavaScript files in `assets/js/` are written by hand and shipped as they are; there is no build step and no minification. `assets/js/native-app.js` uses `wp.element` (React) directly without JSX.

Third-party library: `assets/js/qrcode-generator.js` is "QR Code Generator for JavaScript" by Kazuhiko Arase, version 1.4.4, MIT license, https://github.com/kazuhikoarase/qrcode-generator

== External services ==

This plugin connects to external services to process payments and to manage checkouts and orders. Without these services the payment functions do not work.

**easyCheckout (easycheckout.io)**

The easyCheckout platform of lokal werbung GmbH provides the checkout pages, processes payments and stores orders, customers and invoices.

* What is sent: amount and currency, order and cart items and, if entered by the buyer, name, e-mail address, phone number and billing/delivery address. To connect, the site uses an API key or a login session of the merchant account.
* When: when a payment session is created, when an order is completed, when the merchant uses the EasyCheckout dashboard in the WordPress admin, and when the platform sends status webhooks to the site.
* Terms of service: https://easycheckout.io/agb
* Privacy policy: https://easycheckout.io/datenschutz

**Stripe (js.stripe.com)**

Card payments are processed by Stripe, the payment processor of the easyCheckout platform. The checkout page loads Stripe.js from js.stripe.com to collect card details securely. Card and payment data go directly and encrypted from the buyer's browser to Stripe and never touch your server.

* What is sent: card and payment details entered by the buyer, plus the technical data Stripe.js needs for fraud prevention.
* When: only on checkout pages, when a buyer pays by card or wallet.
* Terms of service: https://stripe.com/legal
* Privacy policy: https://stripe.com/privacy

== Installation ==

= 1. Install the plugin =
Install the plugin via **Plugins → Add New** or upload the ZIP file under **Plugins → Add New → Upload Plugin**, then activate it. The menu **"EasyCheckout"** (left sidebar) and a tab under **WooCommerce → Settings → EasyCheckout** appear.

= 2. Connect your account =
Open **EasyCheckout → Übersicht** (overview) and sign in with the e-mail and password of your easyCheckout account, or register for free in the same window. When you sign in, the plugin creates the required **payment key** and registers the **order webhook** automatically. The separate "API key" field is optional and only meant for a manual connection with an existing `eck_live_…` key.

= 3. Settings (WooCommerce → Settings → EasyCheckout) =
The options are grouped into five sections:

* **Payment & checkout** – enable the payment method, title and description, product source, express checkout, "Buy now", replacement of the WooCommerce checkout and display mode.
* **Design** – logo (the "Bild wählen" button opens the media library), primary, background, text and button colours, corner radius and font. Without a logo, the site logo of your WordPress site is used.
* **Shop & company** – company name, address, e-mail, phone, VAT number. Shown as invoice issuer and e-mail sender.
* **Invoice** – IBAN/QR-IBAN, account holder, bank, payment terms and footer text (used for bank transfer and the Swiss QR-bill).
* **E-mails** – sender and template of the order confirmation (placeholders such as `{ref}`, `{total}`, `{positionen}`) and switches to turn confirmation and invoice e-mails to the buyer on or off.

= 4. Checkout variants =
There are three ways into the checkout; all end in the easyCheckout payment:

* **Checkout** (from the cart) – the native two-column checkout with the whole cart.
* **Buy now** (product page) – direct purchase of this product only. The button adopts the design of your theme's "Add to cart" button.
* **Express** (cart) – address and payment in one quick step.

The **display mode** (in "Payment & checkout") defines how the checkout appears: **native** on your site (recommended, no iframe), **embedded** (iframe) or as a **redirect** to the easyCheckout payment page.

= 5. Without WooCommerce (standalone) =
Embed a checkout on any page with the shortcode `[easycheckout slug="your-checkout"]`.

An easyCheckout account is required for card and TWINT payments. You can start for free at https://easycheckout.io. The full manual with screenshots (German) is available at https://easycheckout.io/handbuch/woocommerce

== Frequently Asked Questions ==

= Do I need WooCommerce? =
No. The plugin works on its own with shortcodes. With WooCommerce you additionally get the payment method and the optional checkout replacement.

= Which payment methods are supported? =
Credit/debit cards, TWINT and the Swiss QR-bill. The methods offered depend on your connected account.

= Are card details stored on my server? =
No. Card details are sent directly and encrypted from the browser to the payment processor. Your server and your WordPress database never see full card details.

= Can I customise the design? =
Yes. Logo, colours, corner radius and font can be set in the settings. Developers can additionally use the filters `easycheckout_checkout_color` and `easycheckout_checkout_css`. For further changes, use the CSS editor of WordPress (Customizer or Site Editor). The checkout also adopts the font of your theme.

= Is the plugin compatible with the WooCommerce block checkout? =
Yes, the WooCommerce Cart and Checkout blocks are supported, as is HPOS.

= Where do I upload my logo? =
Under **WooCommerce → Settings → EasyCheckout → Design**, click "Bild wählen" next to the "Logo" field. If nothing is set, the checkout uses your WordPress site logo.

= Can I view invoices and save them as PDF? =
Yes. Under **EasyCheckout → Rechnungen** (invoices) each invoice has the actions "Ansehen" (view) and "PDF", both available right after the invoice is created. Invoices are available from the "Rechnungen", "Basic" or "Pro" plan.

= My customer receives duplicate e-mails =
Both WooCommerce and easyCheckout can send order e-mails. In the **E-mails** section, turn off the sending switches for confirmation and/or invoice if WooCommerce should send the customer e-mails.

= Is there a "Powered by" link on my site? =
Only if you enable it. The "Powered by easyCheckout" note at the bottom of the checkout is off by default and can be switched on under **Design**.

== Screenshots ==

1. Native WooCommerce checkout: two columns, in the design of your theme.
2. Settings – "Design" section: logo upload, colours, radius, font.
3. Settings – "Payment & checkout" section with all checkout options.
4. Native EasyCheckout dashboard in the WordPress admin: overview with orders, customers and invoices.
5. Product page with the "Buy now" button in the shop design.

== Changelog ==

= 1.0.90 =
* Changes from the WordPress.org plugin review: output escaping, input sanitizing, scripts and styles loaded via wp_enqueue, unique prefixes, signed webhooks only.
* Removed the free-text custom CSS field; developers can use the `easycheckout_checkout_css` filter.
* Checkout posts and their settings are renamed to the new prefix automatically on update.

= 1.0.73 =
* Settings tab with sections: payment & checkout, design (logo/colours), shop & company, invoice, e-mails.
* Invoices: "view" and "PDF" directly from the list.
* Customers: company field added.
* Checkout: aligned layout, "delivery address equals billing address" checkbox, optional "Powered by easyCheckout" logo, "Buy now" button in the shop design, automatic fallback to the site logo.
* Direct "EasyCheckout" entry in the WooCommerce menu.

= 1.0.57 =
* Block checkout (WooCommerce Cart/Checkout blocks): EasyCheckout now appears correctly as a payment method, including the logo.

= 1.0.56 =
* The WooCommerce payment method shows the EasyCheckout logo next to its name.

= 1.0.53 =
* New native WooCommerce checkout with editable cart.
* Selectable checkout mode (native / embedded / redirect) and product source.

= 1.0.0 =
* First release: checkout pages, payment links, shortcodes, WooCommerce payment method, native dashboard, webhooks.

== Upgrade Notice ==

= 1.0.90 =
Security and compatibility update from the WordPress.org review. Existing checkouts are migrated automatically.
