# Kalatori Payment Gateway for WooCommerce

WooCommerce payment gateway plugin for accepting cryptocurrency payments via a
self-hosted [Kalatori](https://github.com/Kalapaja/kalatori) daemon.

## Requirements

- WordPress ≥ 6.0
- PHP ≥ 8.0
- WooCommerce (declared as a required plugin — WordPress enforces this automatically)
- Action Scheduler (bundled with WooCommerce)
- USD-denominated WooCommerce store

## Installation

1. Build or download the plugin ZIP (see [Building](#building)).
2. In WordPress admin: **Plugins → Add New → Upload Plugin**, select the ZIP, and activate.
3. Go to **WooCommerce → Settings → Payments → Crypto (Kalatori)** and enter your daemon URL and secret key. (Optional)

## Configuration

Two parameters are required:

| Parameter    | Description                                                                                                 |
|--------------|-------------------------------------------------------------------------------------------------------------|
| `daemon_url` | Base URL of your self-hosted Kalatori daemon, e.g. `https://pay.yourshop.com`                               |
| `secret_key` | HMAC-SHA256 secret shared with the daemon — used to sign outgoing API requests and verify incoming webhooks |

### Option A — WooCommerce admin UI

Enter the values at **WooCommerce → Settings → Payments → Crypto (Kalatori)**.

### Option B — Config file (pre-baked credentials)

Drop a `woocommerce-kalatori-config.json` file next to the main plugin PHP file. It is loaded once on first boot if the
database option is still empty:

```json
{
  "daemon_url": "https://pay.yourshop.com",
  "secret_key": "your-hmac-secret"
}
```

> **Do not commit this file.** It is gitignored.

## Building

`bin/bundle.sh` creates a distributable ZIP ready for WordPress upload.

```bash
./bin/bundle.sh --daemon_url <url> --secret_key <key>
# Output: kalatori-payment-plugin.zip
```

| Flag           | Required | Description                |
|----------------|----------|----------------------------|
| `--daemon_url` | yes      | Kalatori daemon base URL   |
| `--secret_key` | yes      | HMAC-SHA256 signing secret |

The config is baked into the ZIP as `woocommerce-kalatori-config.json`, so no admin setup is needed after installation.

## Webhook

The plugin exposes a webhook endpoint that your Kalatori daemon should call when invoice status changes:

```
POST /wp-json/kalatori/v1/webhook
```

Configure this URL in your daemon. All requests must be HMAC-SHA256 signed using the same `secret_key` configured in the
plugin.

## Local development

Uses [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (requires Node.js
and Docker):

```bash
npx wp-env start
./bin/setup-demo-data.sh
```

This creates demo products and pre-configures the gateway pointing at `http://host.docker.internal:8080`.

| URL                              |                              |
|----------------------------------|------------------------------|
| `http://localhost:8888/shop`     | Shop                         |
| `http://localhost:8888/checkout` | Checkout                     |
| `http://localhost:8888/wp-admin` | Admin (`admin` / `password`) |
