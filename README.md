# MutoPay for WooCommerce

Accept crypto payments in your WooCommerce store via [MutoPay](https://mutopay.com). Customers pay with any token on 7+ chains, you receive stablecoins (USDC, USDT, DAI).

## Install

1. Download the latest `mutopay-for-woocommerce.zip` from [Releases](https://github.com/mutopay/mutopay-for-woocommerce/releases/latest).
2. WordPress admin: **Plugins → Add New → Upload Plugin**, choose the zip, install + activate.
3. WordPress will redirect you to the MutoPay settings page automatically.
4. Click **Connect to MutoPay**. You'll bounce to mutopay.com, sign in, and return with your API key and webhook configured. No copy-paste.
5. Set your settlement wallet address in your [MutoPay dashboard](https://mutopay.com/dashboard) if you haven't already, the gateway can't be enabled without it.
6. Toggle the gateway on, save, and run a test checkout.

The WordPress.org plugin directory listing is in review and will be available there once approved. Until then, install from this repo.

## How it works

1. Customer picks "Pay with Crypto" at checkout.
2. They're redirected to MutoPay's hosted payment page.
3. Customer connects their wallet and pays with any supported token.
4. MutoPay routes the payment via DEX aggregation and settles to your preferred stablecoin.
5. Your WooCommerce order is updated via webhook (HMAC-SHA256 signed). A 15-minute WP-Cron fallback handles cases where the webhook is blocked.

**Supported chains:** Ethereum, Optimism, BSC, Polygon, Base, Arbitrum, Avalanche.

**Compatibility:** WooCommerce 8.0+, WordPress 6.0+, PHP 8.0+, HPOS-ready, Block Checkout supported.

## Fees

Flat 0.5% on settled volume. No setup or monthly fees. See [mutopay.com](https://mutopay.com) for details.

## Refunds

Crypto payments are not auto-refundable. Issue refunds manually to the customer's wallet address.

## Support

- **Issues / bugs:** [open a GitHub issue](https://github.com/mutopay/mutopay-for-woocommerce/issues)
- **Account / API questions:** support@mutopay.com
- **Docs:** [mutopay.com/docs](https://mutopay.com/docs)

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

## Releases

Tag pushes (`v*`) trigger a GitHub Action that builds `mutopay-for-woocommerce.zip` and attaches it to a GitHub Release. To cut a release:

```bash
git tag v1.0.1
git push origin v1.0.1
```
