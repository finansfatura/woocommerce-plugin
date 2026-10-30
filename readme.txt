=== Finansfatura for WooCommerce ===
Contributors: finansfatura
Tags: woocommerce, e-fatura, e-arsiv, invoice, turkey
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turns WooCommerce orders into Finansfatura sales, then issues Turkish e-Arşiv / e-Fatura invoices for them automatically.

== Description ==

Finansfatura for WooCommerce connects your store to [Finansfatura](https://finansfatura.com), a Turkish e-invoicing (e-Fatura / e-Arşiv) service. Every order is processed in two steps:

1. **Sale** — when payment is received, the order is sent to Finansfatura as a sale. It enters your revenue and stock reports right away, so even if the invoice later fails the sale is never lost. The invoice recipient (company name or full name, tax number or national ID, tax office, address, e-mail, phone) is stored on the sale itself; no separate customer record is created.
2. **Invoice** — the document is issued against that sale, either automatically when the order is marked "completed" or manually with the **Issue invoice** button on the order screen. The manual button does not skip the first step: if the sale is missing it is created first, then the invoice is issued.

Documents are always submitted as e-Arşiv. If the recipient's tax number is registered as an e-Fatura taxpayer with the Turkish Revenue Administration (GİB), the Finansfatura server upgrades the document to e-Fatura automatically. The customer is never asked about this at checkout.

= Features =
* Individual / corporate recipient selection at checkout with national ID (TCKN) / tax number (VKN) and tax office fields, all written to the sale's invoice recipient
* Automatic sale transfer when payment is received
* Automatic invoice when the order is completed, automatic return (IADE) invoice when a refund is created
* Manual "Issue invoice", "Transfer sale" and "Refresh status" buttons on the order screen
* Shipping and fee lines are included in the invoice
* Connect with an API key or with OAuth 2.0 (authorization code + PKCE)
* Fully compatible with High-Performance Order Storage (HPOS / custom order tables)
* Deterministic Idempotency-Key, with Action Scheduler retries only for errors the API marks as retryable

The plugin uses the official Finansfatura PHP library (`finansfatura/finansfatura`) for endpoints, request bodies and error classes. The library is bundled in the plugin, no additional installation is required.

= External service =
This plugin sends order and recipient data (order lines, amounts, recipient name, tax number / national ID, tax office, address, e-mail, phone) to the Finansfatura API at `https://api.finansfatura.com` (or the sandbox at `https://sandbox-api.finansfatura.com`) in order to create sales and issue invoices. It also downloads issued invoice documents (PDF / HTML / XML) from the same service when you click a download button. A Finansfatura account is required.

* Service: [Finansfatura](https://finansfatura.com)
* Terms of service: [https://finansfatura.com/kullanim-kosullari](https://finansfatura.com/kullanim-kosullari)
* Privacy policy: [https://finansfatura.com/gizlilik-politikasi](https://finansfatura.com/gizlilik-politikasi)

== Installation ==

1. Make sure WooCommerce 8.0+ and PHP 8.1+ are installed.
2. Upload the plugin via WordPress → Plugins → Add New → Upload Plugin and activate it.
3. Open WooCommerce → Finansfatura and enter your API key.
4. Generate the key in the Finansfatura panel under **Settings → API Keys**. The full key is shown only once, at creation time.

== Frequently Asked Questions ==

= If the invoice fails, is the sale lost too? =
No. The sale is a separate step. Even if the invoice fails, the sale stays in Finansfatura and you can issue the invoice later from the panel or with the "Issue invoice" button.

= What happens if I click "Issue invoice" twice for the same order? =
The Idempotency-Key is deterministic, so Finansfatura returns the same invoice. No duplicate document is issued and no credit is charged twice. The same applies to the sale (`external_id`).

= Why can't I see the invoice number immediately? =
The number is assigned by the integrator after the document is issued; it is not part of the issue response. The "Refresh status" button on the order screen fetches the number and the current status.

= How do I use OAuth? =
Send the callback URL shown on the settings screen to partner@finansfatura.com to receive a client_id / client_secret for your store, enter them on the settings screen and click "Connect to Finansfatura". Stores without a registered callback can keep using an API key.

== Changelog ==

= 1.0.2 =
* Text domain changed to `finansfatura-for-woocommerce` to match the WordPress.org slug.
* Readme rewritten in English; "Tested up to" raised to WordPress 7.1.
* Escaping and translator-comment fixes reported by Plugin Check.
* Development files (tests, PHPUnit config) are no longer shipped in the release package.

= 1.0.1 =
* Plugin header, version constant and stable tag aligned.

= 1.0.0 =
* Sale first, invoice second: the order is converted into a sale via `POST /v1/integrations/orders` and the invoice is linked to that sale's `transaction_id`.
* Switched to the official `finansfatura/finansfatura` PHP library. Invoice bodies are now built with the library's `Payload` builder; PascalCase field names inside `canonical` were previously sent as snake_case and silently ignored by the server.
* OAuth 2.0 (authorization code + PKCE) support as an alternative authentication method.
* Shipping and fee lines are added to the invoice (previously the invoice total was lower than what the customer paid).
* "Transfer sale" and "Refresh status" buttons; invoice number shown on the order screen.
* Retries only run for errors the API marks as retryable (429 / 5xx / connection).
* The "e-Fatura taxpayer" checkbox at checkout and the issuer settings were removed: both are resolved by the server.

= 0.1.0 =
* Initial release.
