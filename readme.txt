=== Wompi Pagos — Nequi, Daviplata y PSE ===
Contributors: moshipp
Tags: wompi, nequi, daviplata, pse, colombia
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.5.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept payments in Colombia with Nequi (push notification), Daviplata and PSE through Wompi, without sending customers away from your store.

== Description ==

Direct integration with the Wompi Colombia API:

* **Nequi**: the customer enters their mobile number at checkout, receives a push notification in the Nequi app and approves the payment. The store confirms the result in seconds, with no redirects.
* **Daviplata**: the customer enters their ID document, receives an OTP code via SMS and confirms it on Wompi's secure hosted page.
* **PSE**: bank debit; the customer picks their bank (live list from the API) and authorizes the payment on the bank's portal.
* Compatible with the **classic (shortcode) checkout** and the **block-based checkout**.
* Compatible with **HPOS** (High-Performance Order Storage).
* Payment confirmation via **signed webhooks** (SHA-256 checksum) as the source of truth, with thank-you page polling as backup and a **15-minute reconciliation cron** covering missed webhooks.
* "Wompi payment" panel on each order, status column on the orders list, and **estimated fee/net amounts** on the order totals.
* Optional customer email with payment instructions while the payment is pending.
* Sandbox and production modes with separate credentials, live credential verification, and optional debug logging.
* Wompi user-agreement and personal-data-authorization acceptance checkbox (Colombian regulatory requirement), with fresh acceptance tokens per transaction.

**Requirements**: an active Wompi account (comercios.wompi.co), store currency in Colombian pesos (COP), and HTTPS.

**Known limitations** (inherent to the Wompi API):

* Nequi/Daviplata/PSE refunds cannot be executed from WordPress; they are handled in the Wompi dashboard.
* COP currency only.
* Wompi does not report the actual fee charged via API: amounts shown on orders are estimates based on your configured rate.

== Installation ==

1. Upload and install the plugin. Requires WooCommerce.
2. Go to WooCommerce → Wompi (central settings page).
3. Enter your sandbox and/or production keys from comercios.wompi.co and use "Verify connection" to validate them.
4. Copy the events (webhook) URL shown on the settings page and register it in the Wompi dashboard for each environment.
5. Enable the payment methods and test in sandbox mode: Nequi `3991111111` approves, `3992222222` declines; Daviplata OTP `574829` approves, `932015` declines.

== Changelog ==

= 0.5.3 =
* readme.txt rewritten in English (WordPress.org guideline)

= 0.5.2 =
* Text domain unified with the plugin slug, input sanitization improvements, translation template added

= 0.5.0 =
* New PSE payment method (bank debit)
* Central settings page at WooCommerce → Wompi
* Automatic reconciliation of pending orders every 15 minutes
* Wompi status column on the orders list and estimated fee/net on order totals
* Optional pending-payment instructions email
* Official payment method logos, transaction expiration, and updates from GitHub

= 0.1.0 =
* Initial release: Nequi (push) and Daviplata (hosted) gateways, signed webhooks, Checkout Blocks and HPOS support.
