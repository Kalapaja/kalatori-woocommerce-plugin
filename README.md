# Kalatori Payment Gateway for WooCommerce

WooCommerce payment gateway plugin for accepting cryptocurrency payments via a
self-hosted [Kalatori](https://github.com/Kalapaja/kalatori) daemon.

## Requirements

- WordPress ≥ 6.0
- PHP ≥ 8.0
- WooCommerce (declared as a required plugin — WordPress enforces this automatically)
- USD-denominated WooCommerce store
- WordPress permalink structure set to anything other than **Plain** (required for the webhook endpoint to resolve)

## Installation

1. Build or download the plugin ZIP (see [Building](#building)).
2. In WordPress admin: **Plugins → Add New → Upload Plugin**, select the ZIP, and activate.
3. Go to **WooCommerce → Settings → Payments → Kalatori (Crypto)** and enter your daemon URL and secret key. (Optional)

## Configuration

| Parameter    | Description                                                                                                 |
|--------------|-------------------------------------------------------------------------------------------------------------|
| `daemon_url` | Base URL of your self-hosted Kalatori daemon, e.g. `https://pay.yourshop.com`                               |
| `secret_key` | HMAC-SHA256 secret shared with the daemon — used to sign outgoing API requests and verify incoming webhooks |

### Option A — WooCommerce admin UI

Enter the values at **WooCommerce → Settings → Payments → Kalatori (Crypto)**.

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

`bin/bundle.sh` creates a distributable ZIP ready for WordPress upload. All flags are optional.

```bash
./bin/bundle.sh                                          # generic ZIP, no pre-baked config
./bin/bundle.sh --daemon_url <url> --secret_key <key>  # pre-configured ZIP
# Output: kalatori-woocommerce-plugin.zip
```

| Flag           | Description                              |
|----------------|------------------------------------------|
| `--daemon_url` | Kalatori daemon base URL                 |
| `--secret_key` | HMAC-SHA256 signing secret               |

When at least one flag is provided, the values are baked into the ZIP as `woocommerce-kalatori-config.json` and loaded automatically on first install. Otherwise no config file is included and the merchant configures the gateway via the WooCommerce admin UI.

## Webhook

> **Plain permalinks are not supported.** WordPress must use a non-plain permalink structure (e.g. "Post name") for the REST API — and therefore the webhook — to work. If plain permalinks are detected, the plugin displays a warning in its settings page and hides itself from the checkout.

The plugin exposes a webhook endpoint that your Kalatori daemon should call when invoice status changes:

```
POST /wp-json/kalatori/v1/webhook
```

Configure this URL in your daemon. All requests must be HMAC-SHA256 signed using the same `secret_key` configured in the plugin.

The plugin validates two headers on every incoming webhook:

| Header                  | Requirement                                                    |
|-------------------------|----------------------------------------------------------------|
| `X-KALATORI-SIGNATURE`  | HMAC-SHA256 of `METHOD\nPATH\nBODY\nTIMESTAMP` with `secret_key` |
| `X-KALATORI-TIMESTAMP`  | Unix timestamp; rejected if more than **5 minutes** from server time |

Requests that pass signature validation are deduplicated by event UUID for **24 hours** — safe to retry on network errors.

### Order status mapping

| Kalatori event       | WooCommerce order status        |
|----------------------|---------------------------------|
| `created`            | no change (already `on-hold`)   |
| `paid`               | → `processing` (via `payment_complete`) |
| `partially_paid`     | no change, order note added     |
| `expired`            | → `cancelled`                   |
| `admin_canceled`     | → `cancelled`                   |
| `customer_canceled`  | → `pending` (customer can retry)|
| `updated`            | no change, no note              |

## Reconciliation

The plugin schedules an hourly background job (via WooCommerce Action Scheduler) that polls the daemon for any `on-hold` orders whose webhooks may have been missed. Orders that received a webhook within the last 90 minutes are skipped.

To trigger reconciliation manually:

```bash
npx wp-env run cli wp action-scheduler run --hooks=kalatori_reconcile_orders
```

Or via **WooCommerce → Status → Scheduled Actions**.

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
