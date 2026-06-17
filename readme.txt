=== MutoPay for WooCommerce ===
Contributors: mutopay
Tags: crypto, payments, woocommerce, stablecoin, blockchain
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Requires Plugins: woocommerce
WC requires at least: 8.0
WC tested up to: 9.5
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept crypto payments in your WooCommerce store via MutoPay. Customers pay with any token on 7+ chains, you receive stablecoins.

== Description ==

MutoPay for WooCommerce lets your customers pay with any cryptocurrency token across 7+ blockchain networks. You always receive your preferred stablecoin (USDC, USDT, or DAI).

**How it works:**

1. Customer selects "Pay with Crypto" at checkout
2. They're redirected to MutoPay's hosted payment page
3. Customer connects their wallet and pays with any token
4. MutoPay converts the payment to your preferred stablecoin
5. Your WooCommerce order is automatically updated via webhook

**Supported chains:** Ethereum, Optimism, BSC, Polygon, Base, Arbitrum, Avalanche

**Features:**

* No frontend JS — uses MutoPay's hosted payment page
* Automatic order status updates via webhooks
* WP-Cron fallback polling for reliability
* HMAC-SHA256 webhook signature verification
* Debug logging for troubleshooting
* HPOS (High-Performance Order Storage) compatible

**External Service**

This plugin relies on the [MutoPay](https://mutopay.com) payment processing service to handle cryptocurrency payments. When a customer selects crypto payment at checkout, the plugin communicates with the MutoPay API (`https://mutopay.com/api/`) to create and monitor payments.

Data sent to MutoPay includes: order total, currency, order number, item count, customer email, and your store URL. No sensitive payment card data is ever transmitted.

* [MutoPay Website](https://mutopay.com)
* [Terms of Service](https://mutopay.com/terms)
* [Privacy Policy](https://mutopay.com/privacy)

== Installation ==

1. From WP Admin, go to Plugins → Add New → Upload Plugin, choose the `mutopay-for-woocommerce.zip` file, then install and activate. (Alternatively, upload the unzipped `mutopay-for-woocommerce` folder to `/wp-content/plugins/` via FTP.)
2. After activation, WordPress redirects you to the MutoPay settings page automatically.
3. Click "Connect to MutoPay". You'll be sent to mutopay.com to sign in, then bounced back with your API key and webhook secret configured for you. No manual copy-paste.
4. Set your settlement wallet address in your MutoPay dashboard if you haven't already. The plugin won't let you enable the gateway without it.
5. Toggle the gateway on, save, and run a test checkout.

== Frequently Asked Questions ==

= What currencies are supported? =

MutoPay supports 30+ fiat currencies including USD, EUR, GBP, SAR, AED, JPY, and more. Your store's currency is automatically converted to USD for on-chain settlement.

= How do I set up webhooks? =

The "Connect to MutoPay" button handles webhook setup automatically. The plugin generates a webhook URL on your store and registers it with MutoPay along with a shared HMAC secret during the OAuth flow. No manual configuration is required.

= Can I issue refunds through the plugin? =

Crypto payments cannot be refunded automatically. You'll need to arrange manual refunds directly to the customer's wallet address.

= What happens if webhooks fail? =

The plugin includes a WP-Cron fallback that polls MutoPay for payment status every 15 minutes. Orders will be updated even if webhooks are blocked by your firewall.

= Is my webhook URL behind HTTP auth or a firewall? =

The webhook URL must be publicly accessible for MutoPay to deliver payment notifications. If your site is behind HTTP auth (common in staging), the WP-Cron fallback will handle order updates instead.

== Screenshots ==

1. "Pay with Crypto" option at WooCommerce checkout
2. Plugin settings with one-click MutoPay connection
3. Hosted payment page — select payment token across 7+ chains
4. Payment summary with route details before confirming
5. WooCommerce order with MutoPay payment details and transaction hash

== Changelog ==

= 1.0.4 =
* Removed "Powered by MutoPay" attribution from the default checkout description shown to customers. Per WordPress.org guidelines, credit links must not appear on user-facing interfaces without explicit admin opt-in. Merchants can still add any description they choose in the gateway settings.

= 1.0.3 =
* Annotated Plugin Check warnings: the connect (OAuth) callback uses a state token rather than a nonce for CSRF, and the cron poller's order meta query is intentionally bounded.

= 1.0.2 =
* Security: added a one-time state parameter to the MutoPay connect (OAuth) callback to defend against CSRF, plus an explicit capability check on the callback handler.

= 1.0.1 =
* Moved inline admin scripts into enqueued JS files for WordPress.org guideline compliance.

= 1.0.0 =
* Initial release
* Payment gateway with hosted checkout redirect
* Webhook receiver with HMAC-SHA256 verification
* WP-Cron fallback polling
* HPOS compatibility
