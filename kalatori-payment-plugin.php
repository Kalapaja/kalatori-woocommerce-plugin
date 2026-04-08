<?php
/**
 * Plugin Name: Kalatori Payment Gateway
 * Description: Accept crypto payments on Polygon via a self-hosted Kalatori daemon.
 * Version: 0.0.1
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Text Domain: kalatori-payment-gateway
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * All possible statuses a Kalatori invoice can be in.
 */
enum KalatoriStatus: string
{
    /** Customer has not yet sent any funds; invoice is open. */
    case Waiting = 'Waiting';
    /** Full amount received — invoice is complete. */
    case Paid = 'Paid';
    /** More than the requested amount was received. */
    case OverPaid = 'OverPaid';
    /** Some funds received but less than the full amount; invoice is still open. */
    case PartiallyPaid = 'PartiallyPaid';
    /** Invoice expired while only partially funded. */
    case PartiallyPaidExpired = 'PartiallyPaidExpired';
    /** Invoice expired with no payment received. */
    case UnpaidExpired = 'UnpaidExpired';
    /** Customer explicitly cancelled the invoice via the payment page. */
    case CustomerCanceled = 'CustomerCanceled';
    /** Invoice was cancelled by the merchant or admin via the API. */
    case AdminCanceled = 'AdminCanceled';

    /** No further payment transitions can occur. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Paid, self::OverPaid,
            self::UnpaidExpired, self::PartiallyPaidExpired,
            self::CustomerCanceled, self::AdminCanceled => true,
            default => false,
        };
    }

    /** Invoice is still open and awaiting payment. */
    public function isActive(): bool
    {
        return match ($this) {
            self::Waiting, self::PartiallyPaid => true,
            default => false,
        };
    }

    /** Map to the corresponding WooCommerce order status slug. */
    public function toWcStatus(): string
    {
        return match ($this) {
            self::Waiting => 'pending',
            self::Paid, self::OverPaid => 'processing',
            self::PartiallyPaid, self::PartiallyPaidExpired => 'on-hold',
            self::UnpaidExpired => 'failed',
            self::CustomerCanceled, self::AdminCanceled => 'cancelled',
        };
    }
}

/**
 * WooCommerce order statuses relevant to the Kalatori payment flow.
 */
enum WcOrderStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case OnHold = 'on-hold';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /** Order has reached a terminal state — no further payment action expected. */
    public function isFinal(): bool
    {
        return match ($this) {
            self::Processing, self::Completed,
            self::Cancelled, self::Failed, self::Refunded => true,
            default => false,
        };
    }
}

add_action('plugins_loaded', 'kalatori_init_gateway');

/**
 * Bootstrap the Kalatori payment gateway once WooCommerce is available.
 *
 * Registers the gateway class, the WooCommerce Blocks integration, the webhook
 * REST route, and the background action handlers. Bails early if WooCommerce is
 * not active.
 */
function kalatori_init_gateway(): void
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    add_filter('woocommerce_payment_gateways', static function (array $gateways): array {
        $gateways[] = 'WC_Gateway_Kalatori';
        return $gateways;
    });

    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        static function ($registry): void {
            if (class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
                $registry->register(new WC_Gateway_Kalatori_Blocks());
            }
        }
    );

    class WC_Gateway_Kalatori_Blocks extends \Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType
    {
        protected $name = 'kalatori';

        public function initialize(): void
        {
            $this->settings = get_option('woocommerce_kalatori_settings', []);
        }

        public function is_active(): bool
        {
            return true;
        }

        public function get_payment_method_script_handles(): array
        {
            wp_register_script(
                'wc-payment-method-kalatori',
                plugin_dir_url(__FILE__) . 'assets/js/kalatori-blocks.js',
                ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'],
                filemtime(plugin_dir_path(__FILE__) . 'assets/js/kalatori-blocks.js') ?: '0.0.1',
                true
            );
            return ['wc-payment-method-kalatori'];
        }

        public function get_payment_method_data(): array
        {
            return [
                'title'       => __('Crypto (Kalatori)', 'kalatori-payment-gateway'),
                'description' => __('Pay with cryptocurrency via Kalatori', 'kalatori-payment-gateway'),
                'icon'        => plugin_dir_url(__FILE__) . 'assets/images/kalatori-logo.svg',
                'supports'    => $this->get_supported_features(),
            ];
        }
    }

    add_action('kalatori_poll_invoice_status', 'kalatori_poll_invoice_status_handler');
    add_action('woocommerce_order_status_cancelled', 'kalatori_cancel_invoice_on_wc_cancel');

    add_action('rest_api_init', static function (): void {
        register_rest_route('kalatori/v1', '/webhook', [
            'methods' => 'POST',
            'callback' => 'kalatori_webhook_handler',
            'permission_callback' => '__return_true',
        ]);
    });

    /**
     * Kalatori Payment Gateway.
     *
     * Redirect-based gateway that creates a Kalatori invoice for each order and
     * sends the customer to the daemon-hosted payment page. Payment status updates
     * are delivered via HMAC-signed webhooks and a background Action Scheduler
     * poller as fallback.
     *
     * Order meta keys used by this gateway:
     *  - `_kalatori_invoice_id`   UUID of the Kalatori invoice (set on first payment attempt).
     *  - `_kalatori_payment_url`  Public Kalatori payment page URL for the active invoice.
     *  - `_kalatori_attempt`      Counter incremented on each new invoice attempt; used to
     *                             produce unique daemon order IDs on retries (e.g. "123-2").
     */
    class WC_Gateway_Kalatori extends WC_Payment_Gateway
    {

        /**
         * Load settings from DB then merge `woocommerce-kalatori-config.json.json` on first run.
         */
        public function init_settings(): void
        {
            parent::init_settings();
            if (!empty($this->settings['daemon_url'])) {
                return; // Already configured — skip file override.
            }
            $config_file = plugin_dir_path(__FILE__) . 'woocommerce-kalatori-config.json';
            if (!file_exists($config_file)) {
                return;
            }
            $file_config = json_decode(file_get_contents($config_file), true);
            if (is_array($file_config)) {
                $this->settings = array_merge($this->settings, $file_config);
            }
        }

        public function __construct()
        {
            $this->id = 'kalatori';
            $this->has_fields = false;
            $this->method_title = __('Crypto (Kalatori)', 'kalatori-payment-gateway');
            $this->method_description = __('Accept crypto payments via Kalatori', 'kalatori-payment-gateway');
            $this->supports = ['products'];

            $this->init_form_fields();
            $this->init_settings();

            $this->title       = __('Crypto (Kalatori)', 'kalatori-payment-gateway');
            $this->description = __('Pay with cryptocurrency via Kalatori.', 'kalatori-payment-gateway');
            $this->icon = esc_url(plugin_dir_url(__FILE__) . 'assets/images/kalatori-logo.svg');

            add_action(
                'woocommerce_update_options_payment_gateways_' . $this->id,
                [$this, 'process_admin_options']
            );
        }

        /**
         * Whether this gateway is available at checkout.
         *
         * Kalatori only supports USD-denominated stores because the daemon invoices amounts in USD.
         *
         * @return bool
         */
        public function is_available(): bool
        {
            return get_woocommerce_currency() === 'USD';
        }

        public function init_form_fields(): void
        {
            $this->form_fields = [
                'daemon_url' => [
                    'title' => __('Daemon URL', 'kalatori-payment-gateway'),
                    'type' => 'text',
                    'description' => __('Base URL of your self-hosted Kalatori daemon (e.g. https://pay.yourshop.com).', 'kalatori-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ],
                'secret_key' => [
                    'title' => __('Secret Key', 'kalatori-payment-gateway'),
                    'type' => 'password',
                    'description' => __('HMAC-SHA256 secret used to sign requests to the private Kalatori API.', 'kalatori-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ],
            ];
        }

        /**
         * Create a Kalatori invoice and redirect the customer to the payment page.
         *
         * Each call creates a new invoice with an incremented attempt suffix
         * (e.g. "123" → "123-2") to guarantee a unique daemon order ID on retries.
         *
         * On success, schedules a background poll 2 minutes later as a webhook
         * fallback, empties the cart, and redirects to the Kalatori payment page.
         *
         * @param int $order_id WooCommerce order ID.
         * @return array{result: string, redirect?: string} WC payment result array.
         */
        public function process_payment($order_id): array
        {
            $order = wc_get_order($order_id);

            wc_get_logger()->info(
                sprintf('process_payment called: order_id=%d, amount=%s', $order_id, $order->get_total()),
                ['source' => 'kalatori']
            );

            // Use an attempt counter so each new invoice gets a unique order_id in the daemon.
            $attempt = (int)$order->get_meta('_kalatori_attempt') + 1;
            $daemon_order_id = $attempt === 1 ? (string)$order_id : $order_id . '-' . $attempt;
            $order->update_meta_data('_kalatori_attempt', $attempt);
            $order->save();

            $cart_items = [];
            foreach ($order->get_items() as $item) {
                /** @var WC_Order_Item_Product $item */
                $qty = $item->get_quantity();
                $product = $item->get_product();

                $entry = [
                    'name' => $item->get_name(),
                    'quantity' => $qty,
                    'price' => (string)round($item->get_subtotal() / $qty, 2),
                ];

                if ($product) {
                    $permalink = $product->get_permalink();
                    if ($permalink) {
                        $entry['product_url'] = $permalink;
                    }

                    $image_id = $product->get_image_id();
                    $image_url = $image_id ? wp_get_attachment_url($image_id) : '';
                    if ($image_url) {
                        $entry['image_url'] = $image_url;
                    }
                }

                $tax = $item->get_total_tax();
                if ($tax > 0) {
                    $entry['tax'] = (string)round($tax / $qty, 2);
                }

                $discount = $item->get_subtotal() - $item->get_total();
                if ($discount > 0) {
                    $entry['discount'] = (string)round($discount / $qty, 2);
                }

                $cart_items[] = $entry;
            }

            $redirect_url = $this->get_return_url($order);

            $body = [
                'order_id' => $daemon_order_id,
                'amount' => (string)$order->get_total(),
                'redirect_url' => $redirect_url,
                'cart' => ['items' => $cart_items],
            ];

            wc_get_logger()->debug(
                sprintf(
                    'Calling Kalatori API: daemon=%s, body=%s',
                    $this->get_option('daemon_url'),
                    wp_json_encode($body)
                ),
                ['source' => 'kalatori']
            );

            $response = $this->api_request('POST', '/private/v3/invoice/create', $body);

            if (is_wp_error($response)) {
                wc_get_logger()->error(
                    sprintf('Invoice creation failed: %s', $response->get_error_message()),
                    ['source' => 'kalatori']
                );
                wc_add_notice(
                    __('Payment error: unable to create invoice. Please try again.', 'kalatori-payment-gateway'),
                    'error'
                );
                return ['result' => 'failure'];
            }

            $invoice_id = $response['result']['id'] ?? '';
            $payment_url = $response['result']['payment_url'] ?? '';

            if (empty($invoice_id) || empty($payment_url)) {
                wc_get_logger()->error(
                    sprintf('Invoice creation failed: unexpected response: %s', wp_json_encode($response)),
                    ['source' => 'kalatori']
                );
                wc_add_notice(
                    __('Payment error: invalid response from payment daemon.', 'kalatori-payment-gateway'),
                    'error'
                );
                return ['result' => 'failure'];
            }

            $order->update_meta_data('_kalatori_invoice_id', $invoice_id);
            $order->update_meta_data('_kalatori_payment_url', $payment_url);
            $order->update_status('pending', __('Awaiting Kalatori crypto payment.', 'kalatori-payment-gateway'));
            $order->save();

            wc_get_logger()->info(
                sprintf('Invoice created: id=%s, redirect=%s', $invoice_id, $payment_url),
                ['source' => 'kalatori']
            );

            // Schedule a fallback status poll in case the webhook is not delivered.
            as_schedule_single_action(
                time() + 120,
                'kalatori_poll_invoice_status',
                ['order_id' => $order_id],
                'kalatori'
            );

            WC()->cart->empty_cart();

            return [
                'result' => 'success',
                'redirect' => $payment_url,
            ];
        }

        /**
         * Send a HMAC-signed request to the Kalatori private API.
         *
         * @param string $method HTTP method (GET|POST).
         * @param string $path API path (e.g. /private/v3/invoice/get). Signature is computed over $path only, not the query string.
         * @param array|null $body Request body (will be JSON-encoded). Null for GET requests.
         * @param array|null $query Query string parameters for GET requests (e.g. ['invoice_id' => $uuid]).
         * @return array|\WP_Error Decoded response body or WP_Error on failure.
         */
        public function api_request(string $method, string $path, ?array $body = null, ?array $query = null): array|\WP_Error
        {
            $daemon_url = rtrim($this->get_option('daemon_url'), '/');
            $secret_key = $this->get_option('secret_key');

            if (empty($daemon_url) || empty($secret_key)) {
                return new \WP_Error('kalatori_config', __('Kalatori daemon URL or secret key is not configured.', 'kalatori-payment-gateway'));
            }

            $timestamp = (string)time();
            $json_body = $body !== null ? wp_json_encode($body) : '';
            $message = strtoupper($method) . "\n" . $path . "\n" . $json_body . "\n" . $timestamp;
            $signature = hash_hmac('sha256', $message, $secret_key);

            $headers = [
                'Content-Type' => 'application/json',
                'X-KALATORI-SIGNATURE' => $signature,
                'X-KALATORI-TIMESTAMP' => $timestamp,
            ];

            $args = [
                'method' => strtoupper($method),
                'headers' => $headers,
                'timeout' => 15,
            ];

            if ($json_body !== '') {
                $args['body'] = $json_body;
            }

            $url = $daemon_url . $path;
            if (!empty($query)) {
                $url .= '?' . http_build_query($query);
            }

            $http_response = wp_remote_request($url, $args);

            if (is_wp_error($http_response)) {
                return $http_response;
            }

            $http_code = wp_remote_retrieve_response_code($http_response);
            $raw_body = wp_remote_retrieve_body($http_response);
            $decoded = json_decode($raw_body, true);

            if ($http_code < 200 || $http_code >= 300) {
                $error_message = $decoded['error']['message'] ?? "HTTP {$http_code}";
                return new \WP_Error('kalatori_api', $error_message);
            }

            return $decoded ?? [];
        }
    }
}

/**
 * Poll Kalatori for the current invoice status and update the WC order accordingly (Kalatori→WC sync).
 * Acts as a fallback for missed webhooks.
 *
 * Scheduled 2 minutes after invoice creation, then every hour while the invoice is active.
 * Stops when the WC order reaches a final state or after 2 days.
 *
 * @param int $order_id WooCommerce order ID.
 */
function kalatori_poll_invoice_status_handler(int $order_id): void
{
    $order = wc_get_order($order_id);
    $invoice_id = $order ? $order->get_meta('_kalatori_invoice_id') : '';

    if (empty($invoice_id)) {
        return;
    }

    // Hard stop after 2 days regardless of state.
    $wcStatus = WcOrderStatus::tryFrom($order->get_status());

    if (time() - $order->get_date_created()->getTimestamp() > 172800) {
        wc_get_logger()->warning(
            sprintf('Poller stopping for order %d: 2-day hard limit reached (WC=%s).', $order_id, $wcStatus?->value ?? 'unknown'),
            ['source' => 'kalatori']
        );
        return;
    }

    // Stop when WC order is already in a final state — nothing left to sync.
    if ($wcStatus?->isFinal()) {
        wc_get_logger()->debug(
            sprintf('Poller stopping for order %d: WC status %s is final.', $order_id, $wcStatus->value),
            ['source' => 'kalatori']
        );
        return;
    }

    $gateway = new WC_Gateway_Kalatori();

    // Poll daemon for current status and update WC if it changed.
    $response = $gateway->api_request('GET', '/private/v3/invoice/get', null, ['invoice_id' => $invoice_id]);

    if (is_wp_error($response)) {
        wc_get_logger()->warning(
            sprintf('Invoice poll failed for order %d: %s', $order_id, $response->get_error_message()),
            ['source' => 'kalatori']
        );
        as_schedule_single_action(time() + 3600, 'kalatori_poll_invoice_status', ['order_id' => $order_id], 'kalatori');
        return;
    }

    $kalatoriStatus = KalatoriStatus::tryFrom($response['result']['status'] ?? '');

    if ($kalatoriStatus !== null) {
        $newWcStatus = $kalatoriStatus->toWcStatus();
        $currentWcStatus = WcOrderStatus::tryFrom($order->get_status());
        if ($newWcStatus !== $order->get_status() && !$currentWcStatus?->isFinal()) {
            $order->update_status(
                $newWcStatus,
                sprintf(__('Kalatori poll: invoice %1$s — status: %2$s.', 'kalatori-payment-gateway'), $invoice_id, $kalatoriStatus->value)
            );
            wc_get_logger()->info(
                sprintf('Poller updated order %d: Kalatori=%s, WC=%s.', $order_id, $kalatoriStatus->value, $order->get_status()),
                ['source' => 'kalatori']
            );
        }
    } else {
        wc_get_logger()->warning(
            sprintf('Poller received unknown Kalatori status "%s" for order %d.', $response['result']['status'] ?? '', $order_id),
            ['source' => 'kalatori']
        );
    }

    // Reschedule if Kalatori invoice is still active.
    if ($kalatoriStatus?->isActive()) {
        as_schedule_single_action(time() + 3600, 'kalatori_poll_invoice_status', ['order_id' => $order_id], 'kalatori');
    }
}

/**
 * Cancel the Kalatori invoice in the daemon when a WooCommerce order is cancelled.
 * Fires on the `woocommerce_order_status_cancelled` action.
 *
 * @param int $order_id WooCommerce order ID.
 */
function kalatori_cancel_invoice_on_wc_cancel(int $order_id): void
{
    $order = wc_get_order($order_id);
    $invoice_id = $order ? $order->get_meta('_kalatori_invoice_id') : '';

    if (empty($invoice_id) || $order->get_payment_method() !== 'kalatori') {
        return;
    }

    $gateway = new WC_Gateway_Kalatori();
    $response = $gateway->api_request('POST', '/private/v3/invoice/cancel', ['invoice_id' => $invoice_id]);

    if (is_wp_error($response)) {
        wc_get_logger()->error(
            sprintf('Failed to cancel Kalatori invoice %s for order %d: %s', $invoice_id, $order_id, $response->get_error_message()),
            ['source' => 'kalatori']
        );
        return;
    }

    wc_get_logger()->info(
        sprintf('Kalatori invoice %s cancelled in daemon for order %d.', $invoice_id, $order_id),
        ['source' => 'kalatori']
    );

    $order->add_order_note(
        sprintf(
        /* translators: Kalatori invoice UUID */
            __('Kalatori invoice %s cancelled in daemon.', 'kalatori-payment-gateway'),
            $invoice_id
        )
    );
}

/**
 * Handle incoming Kalatori webhook callbacks.
 * Status mapping is defined in {@see KalatoriStatus::toWcStatus()}.
 *
 * @param WP_REST_Request $request
 * @return WP_REST_Response
 */
function kalatori_webhook_handler(WP_REST_Request $request): WP_REST_Response
{
    $gateway = new WC_Gateway_Kalatori();
    $secret_key = $gateway->get_option('secret_key');

    $signature = $request->get_header('X-KALATORI-SIGNATURE');
    $timestamp = $request->get_header('X-KALATORI-TIMESTAMP');
    $raw_body = $request->get_body();

    if (empty($secret_key) || empty($signature) || empty($timestamp)) {
        wc_get_logger()->warning('Webhook rejected: missing authentication headers.', ['source' => 'kalatori']);
        return new WP_REST_Response(['error' => 'Missing authentication headers.'], 401);
    }

    if (abs(time() - (int)$timestamp) > 300) {
        wc_get_logger()->warning('Webhook rejected: timestamp expired.', ['source' => 'kalatori']);
        return new WP_REST_Response(['error' => 'Timestamp expired.'], 401);
    }

    $webhook_path = '/wp-json/kalatori/v1/webhook';
    $message = 'POST' . "\n" . $webhook_path . "\n" . $raw_body . "\n" . $timestamp;
    $expected = hash_hmac('sha256', $message, $secret_key);

    if (!hash_equals($expected, $signature)) {
        wc_get_logger()->warning('Webhook rejected: invalid HMAC signature.', ['source' => 'kalatori']);
        return new WP_REST_Response(['error' => 'Invalid signature.'], 401);
    }

    // Webhook body is GenericEvent<Invoice>: {"id":..., "event_type":..., "payload":{invoice}, ...}
    $data = json_decode($raw_body, true);
    $invoice_id = $data['payload']['id'] ?? '';
    $status = $data['payload']['status'] ?? '';

    if (empty($invoice_id) || empty($status)) {
        return new WP_REST_Response(['error' => 'Missing invoice id or status.'], 400);
    }

    $orders = wc_get_orders([
        'meta_query' => [[
            'key' => '_kalatori_invoice_id',
            'value' => $invoice_id,
            'compare' => '=',
        ]],
        'limit' => 1,
    ]);

    if (empty($orders)) {
        return new WP_REST_Response(['error' => 'Order not found.'], 404);
    }

    $order = $orders[0];
    $kalatoriStatus = KalatoriStatus::tryFrom($status);

    if ($kalatoriStatus !== null) {
        $wc_status = $kalatoriStatus->toWcStatus();
        $currentWcStatus = WcOrderStatus::tryFrom($order->get_status());
        if ($wc_status !== $order->get_status() && !$currentWcStatus?->isFinal()) {
            $order->update_status(
                $wc_status,
                sprintf(
                /* translators: 1: Kalatori invoice ID, 2: Kalatori status */
                    __('Kalatori invoice %1$s — status: %2$s.', 'kalatori-payment-gateway'),
                    $invoice_id,
                    $kalatoriStatus->value
                )
            );
        }

        wc_get_logger()->info(
            sprintf('Webhook: order %d — Kalatori status %s → WC status %s.', $order->get_id(), $kalatoriStatus->value, $wc_status),
            ['source' => 'kalatori']
        );
    } else {
        wc_get_logger()->warning(
            sprintf('Webhook received unknown Kalatori status "%s" for invoice %s.', $status, $invoice_id),
            ['source' => 'kalatori']
        );
    }

    return new WP_REST_Response(['result' => 'ok'], 200);
}
