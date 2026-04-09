# Kalatori Payment Gateway for WooCommerce

WooCommerce payment gateway plugin for accepting cryptocurrency payments via a
self-hosted [Kalatori](https://github.com/Kalapaja/kalatori) daemon.

## Requirements

- WordPress ≥ 6.0
- PHP ≥ 8.0
- WooCommerce (declared as a required plugin — WordPress enforces this automatically)
- USD-denominated WooCommerce store

## Installation

1. Build or download the plugin ZIP (see [Building](#building)).
2. In WordPress admin: **Plugins → Add New → Upload Plugin**, select the ZIP, and activate.
3. Go to **WooCommerce → Settings → Payments → Crypto (Kalatori)** and enter your daemon URL and secret key. (Optional)

## Configuration

| Parameter    | Description                                                                                                 |
|--------------|-------------------------------------------------------------------------------------------------------------|
| `daemon_url` | Base URL of your self-hosted Kalatori daemon, e.g. `https://pay.yourshop.com`                               |
| `secret_key` | HMAC-SHA256 secret shared with the daemon — used to sign outgoing API requests and verify incoming webhooks |
| `admin_url`  | URL of the Kalatori daemon admin interface                                                                   |

### Option A — WooCommerce admin UI

Enter the values at **WooCommerce → Settings → Payments → Crypto (Kalatori)**.

### Option B — Config file (pre-baked credentials)

Drop a `woocommerce-kalatori-config.json` file next to the main plugin PHP file. It is loaded once on first boot if the
database option is still empty:

```json
{
  "daemon_url": "https://pay.yourshop.com",
  "secret_key": "your-hmac-secret",
  "admin_url":  "https://admin.yourshop.com"
}
```

> **Do not commit this file.** It is gitignored.

## Building

`bin/bundle.sh` creates a distributable ZIP ready for WordPress upload. All flags are optional.

```bash
./bin/bundle.sh                                          # generic ZIP, no pre-baked config
./bin/bundle.sh --daemon_url <url> --secret_key <key> --admin_url <url>  # pre-configured ZIP
# Output: kalatori-woocommerce-plugin.zip
```

| Flag           | Description                              |
|----------------|------------------------------------------|
| `--daemon_url` | Kalatori daemon base URL                 |
| `--secret_key` | HMAC-SHA256 signing secret               |
| `--admin_url`  | Kalatori daemon admin interface URL      |

When at least one flag is provided, the values are baked into the ZIP as `woocommerce-kalatori-config.json` and loaded automatically on first install. Otherwise no config file is included and the merchant configures the gateway via the WooCommerce admin UI.

## Webhook

The plugin exposes a webhook endpoint that your Kalatori daemon should call when invoice status changes:

```
POST /wp-json/kalatori/v1/webhook
```

Configure this URL in your daemon. All requests must be HMAC-SHA256 signed using the same `secret_key` configured in the
plugin.

## Releasing

1. Update `Version: x.y.z` in `kalatori-woocommerce-plugin.php`.
2. Commit and push to `main`.
3. Tag and push:
   ```bash
   git tag v1.0.0
   git push origin v1.0.0
   ```

GitHub Actions builds the plugin ZIP and publishes it as a GitHub Release automatically.
The version in the ZIP is set from the tag name.

## Local development

Uses [`wp-env`](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/) (requires Node.js
and Docker):

```bash
npx wp-env start
./bin/setup-demo-data.sh
```

This creates demo products and pre-configures the gateway pointing at `http://host.docker.internal:8080`.

Configure your Kalatori daemon to send webhooks to:

```
http://host.docker.internal:8888/wp-json/kalatori/v1/webhook
```

| URL                              |                              |
|----------------------------------|------------------------------|
| `http://localhost:8888/shop`     | Shop                         |
| `http://localhost:8888/checkout` | Checkout                     |
| `http://localhost:8888/wp-admin` | Admin (`admin` / `password`) |
