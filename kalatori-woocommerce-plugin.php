<?php
/**
 * Plugin Name: Kalatori Payment Gateway
 * Description: Accept crypto payments via a self-hosted Kalatori daemon.
 * Version: 0.0.8
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Requires Plugins: woocommerce
 * Author: Kalapaja
 * Author URI: https://github.com/Kalapaja
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
    case Waiting = 'Waiting';
    case Paid = 'Paid';
    case OverPaid = 'OverPaid';
    case PartiallyPaid = 'PartiallyPaid';
    case PartiallyPaidExpired = 'PartiallyPaidExpired';
    case UnpaidExpired = 'UnpaidExpired';
    case CustomerCanceled = 'CustomerCanceled';
    case AdminCanceled = 'AdminCanceled';
}

/**
 * WooCommerce order statuses relevant to the Kalatori payment flow.
 */
enum WcOrderStatus: string
{
    case Pending    = 'pending';
    case Processing = 'processing';
    case OnHold     = 'on-hold';
    case Completed  = 'completed';
    case Cancelled  = 'cancelled';
    case Failed     = 'failed';
    case Refunded   = 'refunded';
}

/**
 * Kalatori invoice event types as sent in the webhook `event_type` field.
 */
enum KalatoriEventType: string
{
    case Created          = 'created';
    case Updated          = 'updated';
    case Paid             = 'paid';
    case PartiallyPaid    = 'partially_paid';
    case Expired          = 'expired';
    case AdminCanceled    = 'admin_canceled';
    case CustomerCanceled = 'customer_canceled';

    /** Human-readable one-sentence order note for the WC order history. */
    public function toOrderNote(): string
    {
        return match ($this) {
            self::Created          => __('Awaiting cryptocurrency payment via Kalatori.', 'kalatori-payment-gateway'),
            self::Paid             => __('Payment completed via Kalatori.', 'kalatori-payment-gateway'),
            self::PartiallyPaid    => __('Partial payment received via Kalatori; awaiting remaining balance.', 'kalatori-payment-gateway'),
            self::Expired          => __('Kalatori invoice expired without full payment.', 'kalatori-payment-gateway'),
            self::AdminCanceled    => __('Kalatori invoice cancelled by the merchant.', 'kalatori-payment-gateway'),
            self::CustomerCanceled => __('Kalatori invoice cancelled by the customer.', 'kalatori-payment-gateway'),
            self::Updated          => '',
        };
    }

    /** Map to WC order status, or null when no status transition is needed. */
    public function toWcStatus(): ?WcOrderStatus
    {
        return match ($this) {
            self::Expired,
            self::AdminCanceled    => WcOrderStatus::Cancelled,
            self::CustomerCanceled => WcOrderStatus::Pending,
            self::Paid,
            self::Created,
            self::PartiallyPaid,
            self::Updated          => null,
        };
    }
}

register_activation_hook(__FILE__, 'kalatori_activate');
register_deactivation_hook(__FILE__, 'kalatori_deactivate');

function kalatori_activate(): void
{
    if (!as_has_scheduled_action('kalatori_reconcile_orders')) {
        as_schedule_recurring_action(time() + HOUR_IN_SECONDS, HOUR_IN_SECONDS, 'kalatori_reconcile_orders');
    }
}

function kalatori_deactivate(): void
{
    as_unschedule_all_actions('kalatori_reconcile_orders');
}

add_action('plugins_loaded', 'kalatori_init_gateway');

/**
 * Bootstrap the Kalatori payment gateway once WooCommerce is available.
 *
 * Registers the gateway class, the WooCommerce Blocks integration, and the webhook
 * REST route. Bails early if WooCommerce is not active.
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
                'title' => __('Crypto (Kalatori)', 'kalatori-payment-gateway'),
                'description' => __('Pay with cryptocurrency via Kalatori', 'kalatori-payment-gateway'),
                'icon' => plugin_dir_url(__FILE__) . 'assets/images/kalatori-logo.svg',
                'supports' => $this->get_supported_features(),
            ];
        }
    }

    add_action('woocommerce_order_status_cancelled', 'kalatori_cancel_invoice_on_wc_cancel');
    add_action('woocommerce_update_order', 'kalatori_update_invoice_on_order_edit');
    add_action('kalatori_reconcile_orders', 'kalatori_reconcile_orders_handler');
    add_action('action_scheduler_ensure_recurring_actions', 'kalatori_activate');

    add_action('wp_ajax_kalatori_test_connection', static function (): void {
        (new WC_Gateway_Kalatori())->handle_test_connection_ajax();
    });

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
     * are delivered via HMAC-signed webhooks.
     *
     * Order meta keys used by this gateway:
     *  - `_kalatori_invoice_id`   UUID of the Kalatori invoice (set on first payment attempt).
     *  - `_kalatori_payment_url`  Public Kalatori payment page URL for the active invoice.
     *  - `_kalatori_attempt`      Counter incremented on each new invoice attempt; used to
     *                             produce unique daemon order IDs on retries (e.g. "123-2").
     */
    class WC_Gateway_Kalatori extends WC_Payment_Gateway
    {
        private const CONFIG_VERSION = '1';

        /**
         * Load settings from DB then merge `woocommerce-kalatori-config.json` on first run.
         * Bump CONFIG_VERSION to force a re-apply on existing installs.
         */
        public function init_settings(): void
        {
            parent::init_settings();
            if (($this->settings['_config_version'] ?? '') === self::CONFIG_VERSION) {
                return; // Config already applied for this version.
            }
            $config_file = plugin_dir_path(__FILE__) . 'woocommerce-kalatori-config.json';
            if (!file_exists($config_file)) {
                return;
            }
            $file_config = json_decode(file_get_contents($config_file), true);
            if (is_array($file_config)) {
                $this->settings = array_merge($this->settings, $file_config);
                $this->settings['_config_version'] = self::CONFIG_VERSION;
                update_option($this->get_option_key(), $this->settings);
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

            $this->title = __('Crypto (Kalatori)', 'kalatori-payment-gateway');
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
                'admin_url' => [
                    'title' => __('Admin URL', 'kalatori-payment-gateway'),
                    'type' => 'text',
                    'description' => __('URL of the Kalatori daemon admin interface.', 'kalatori-payment-gateway'),
                    'default' => '',
                    'desc_tip' => true,
                ],
                'test_connection' => [
                    'title' => __('Connection', 'kalatori-payment-gateway'),
                    'type'  => 'test_connection',
                ],
            ];
        }

        public function generate_test_connection_html(string $key, array $data): string
        {
            $nonce        = wp_create_nonce('kalatori_test_connection');
            $field_prefix = 'woocommerce_' . $this->id . '_';
            ob_start(); ?>
            <tr>
                <th><?= esc_html($data['title']) ?></th>
                <td>
                    <div style="display:flex;align-items:center;gap:8px">
                        <button type="button" id="kalatori-test-btn" class="button">
                            <?= esc_html__('Test connection', 'kalatori-payment-gateway') ?>
                        </button>
                        <span id="kalatori-test-result"></span>
                    </div>
                    <script>
                    jQuery('#kalatori-test-btn').on('click', function () {
                        var btn    = jQuery(this);
                        var result = jQuery('#kalatori-test-result');
                        btn.prop('disabled', true);
                        result.removeAttr('style').text('<?= esc_js(__('Testing…', 'kalatori-payment-gateway')) ?>');
                        jQuery.post(ajaxurl, {
                            action:     'kalatori_test_connection',
                            _wpnonce:   '<?= esc_js($nonce) ?>',
                            daemon_url: jQuery('#<?= esc_js($field_prefix) ?>daemon_url').val(),
                            secret_key: jQuery('#<?= esc_js($field_prefix) ?>secret_key').val(),
                        }, function (response) {
                            result.text(response.data).css('color', response.success ? 'green' : 'red');
                        }).always(function () { btn.prop('disabled', false); });
                    });
                    </script>
                </td>
            </tr>
            <?php return ob_get_clean();
        }

        public function handle_test_connection_ajax(): void
        {
            check_ajax_referer('kalatori_test_connection');
            if (!current_user_can('manage_woocommerce')) {
                wp_send_json_error(__('Permission denied.', 'kalatori-payment-gateway'));
            }

            $this->settings['daemon_url'] = sanitize_url(wp_unslash($_POST['daemon_url'] ?? ''));
            $this->settings['secret_key'] = sanitize_text_field(wp_unslash($_POST['secret_key'] ?? ''));

            $response = $this->api_request(
                'GET', '/private/v3/invoice/get', null,
                ['invoice_id' => '00000000-0000-0000-0000-000000000000']
            );

            if (!is_wp_error($response) || str_starts_with($response->get_error_code(), 'kalatori_')) {
                $http_code = is_wp_error($response) ? ($response->get_error_data()['http_code'] ?? 0) : 200;
                if ($http_code === 401) {
                    wp_send_json_error(__('Invalid credentials.', 'kalatori-payment-gateway'));
                } else {
                    wp_send_json_success(__('Connected successfully.', 'kalatori-payment-gateway'));
                }
            } else {
                wp_send_json_error(__('Daemon unreachable — check the Daemon URL.', 'kalatori-payment-gateway'));
            }
        }

        /**
         * Create a Kalatori invoice and redirect the customer to the payment page.
         *
         * Each call creates a new invoice with an incremented attempt suffix
         * (e.g. "123" → "123-2") to guarantee a unique daemon order ID on retries.
         *
         * On success, empties the cart and redirects the customer to the Kalatori payment page.
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

            // Reuse existing invoice if still active.
            $existing_invoice_id  = $order->get_meta('_kalatori_invoice_id');
            $existing_payment_url = $order->get_meta('_kalatori_payment_url');
            if ($existing_invoice_id && $existing_payment_url) {
                $invoice = $this->api_request('GET', '/private/v3/invoice/get', null, ['invoice_id' => $existing_invoice_id]);
                if (!is_wp_error($invoice) && ($invoice['result']['status'] ?? '') === KalatoriStatus::Waiting->value) {
                    wc_get_logger()->info(
                        sprintf('Reusing existing invoice %s for order %d.', $existing_invoice_id, $order_id),
                        ['source' => 'kalatori']
                    );
                    return ['result' => 'success', 'redirect' => $existing_payment_url];
                }
            }

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
                if ($response->get_error_code() === 'kalatori_amount_too_low') {
                    wc_add_notice(
                        sprintf(__('Payment error: %s', 'kalatori-payment-gateway'), $response->get_error_message()),
                        'error'
                    );
                } else {
                    wc_add_notice(
                        __('Payment error: unable to create invoice. Please try again.', 'kalatori-payment-gateway'),
                        'error'
                    );
                }
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
            $order->update_meta_data('_kalatori_invoice_amount', (string)$order->get_total());
            $order->set_transaction_id($invoice_id);
            $order->update_status(WcOrderStatus::OnHold->value, __('Awaiting cryptocurrency payment via Kalatori.', 'kalatori-payment-gateway'));

            wc_get_logger()->info(
                sprintf('Invoice created: id=%s, redirect=%s', $invoice_id, $payment_url),
                ['source' => 'kalatori']
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
         * @param string $path API path (e.g. /private/v3/invoice/get). For GET requests the signature covers the sorted query string; for POST it covers the JSON body.
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
            if (strtoupper($method) === 'GET' && !empty($query)) {
                $sorted_query = $query;
                ksort($sorted_query);
                $signed_payload = http_build_query($sorted_query);
            } else {
                $signed_payload = $json_body;
            }
            $message = strtoupper($method) . "\n" . $path . "\n" . $signed_payload . "\n" . $timestamp;
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
                $error_type    = $decoded['error']['type'] ?? $decoded['error']['category'] ?? 'api_error';
                $error_message = $decoded['error']['message'] ?? "HTTP {$http_code}";
                return new \WP_Error('kalatori_' . $error_type, $error_message, ['http_code' => $http_code]);
            }

            return $decoded ?? [];
        }
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

    $statusResponse = $gateway->api_request('GET', '/private/v3/invoice/get', null, ['invoice_id' => $invoice_id]);
    if (!is_wp_error($statusResponse)) {
        $kalatoriStatus = KalatoriStatus::tryFrom($statusResponse['result']['status'] ?? '');
        if ($kalatoriStatus === KalatoriStatus::CustomerCanceled
            || $kalatoriStatus === KalatoriStatus::AdminCanceled
            || $kalatoriStatus === KalatoriStatus::UnpaidExpired
            || $kalatoriStatus === KalatoriStatus::PartiallyPaidExpired
        ) {
            wc_get_logger()->info(
                sprintf('Cancel skipped: invoice %s for order %d is already cancelled or expired (%s).', $invoice_id, $order_id, $kalatoriStatus->value),
                ['source' => 'kalatori']
            );
            return;
        }
    }

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
 * Update the Kalatori invoice amount when the admin edits the order total.
 * Fires on every order save; acts only when the total has changed while the invoice is active.
 *
 * @param int $order_id WooCommerce order ID.
 */
function kalatori_update_invoice_on_order_edit(int $order_id): void
{
    $order = wc_get_order($order_id);
    if (!$order || $order->get_payment_method() !== 'kalatori' || !$order->has_status(WcOrderStatus::OnHold->value)) {
        return;
    }

    $invoice_id      = $order->get_meta('_kalatori_invoice_id');
    $invoiced_amount = $order->get_meta('_kalatori_invoice_amount');
    $current_total   = (string)$order->get_total();

    if (empty($invoice_id) || $invoiced_amount === $current_total) {
        return;
    }

    $gateway  = new WC_Gateway_Kalatori();
    $response = $gateway->api_request('POST', '/private/v3/invoice/update', [
        'invoice_id' => $invoice_id,
        'amount'     => $current_total,
    ]);

    if (is_wp_error($response)) {
        wc_get_logger()->warning(
            sprintf('Failed to update Kalatori invoice %s for order %d: %s', $invoice_id, $order_id, $response->get_error_message()),
            ['source' => 'kalatori']
        );
        $order->add_order_note(
            __('Kalatori invoice could not be updated to match the new order total. Consider canceling and having the customer repay.', 'kalatori-payment-gateway')
        );
        return;
    }

    $order->update_meta_data('_kalatori_invoice_amount', $current_total);
    $order->save();

    wc_get_logger()->info(
        sprintf('Kalatori invoice %s updated to amount %s for order %d.', $invoice_id, $current_total, $order_id),
        ['source' => 'kalatori']
    );
}

/**
 * Reconcile all on-hold Kalatori orders against the daemon.
 * Scheduled hourly by Action Scheduler. Catches orders whose webhooks were missed.
 */
function kalatori_reconcile_orders_handler(int $page = 1): void
{
    $batch_size = 50;
    $orders = wc_get_orders([
        'status'         => WcOrderStatus::OnHold->value,
        'payment_method' => 'kalatori',
        'limit'          => $batch_size,
        'paged'          => $page,
        'meta_query'     => [[
            'key'     => '_kalatori_invoice_id',
            'compare' => 'EXISTS',
        ]],
    ]);

    if (empty($orders)) {
        return;
    }

    wc_get_logger()->info(
        sprintf('Reconciliation: checking %d on-hold Kalatori order(s) (page %d).', count($orders), $page),
        ['source' => 'kalatori']
    );

    $gateway = new WC_Gateway_Kalatori();

    foreach ($orders as $order) {
        kalatori_reconcile_single_order($order, $gateway);
    }

    // Full batch — there may be more; queue next page as a separate async action.
    if (count($orders) === $batch_size) {
        as_enqueue_async_action('kalatori_reconcile_orders', ['page' => $page + 1]);
    }
}

/**
 * Fetch the current invoice status from the daemon and reconcile a single order.
 *
 * @param WC_Order           $order
 * @param WC_Gateway_Kalatori $gateway
 */
function kalatori_reconcile_single_order(WC_Order $order, WC_Gateway_Kalatori $gateway): void
{
    $order_id   = $order->get_id();
    $invoice_id = $order->get_meta('_kalatori_invoice_id');

    $response = $gateway->api_request('GET', '/private/v3/invoice/get', null, ['invoice_id' => $invoice_id]);

    if (is_wp_error($response)) {
        wc_get_logger()->warning(
            sprintf('Reconciliation: failed to fetch invoice %s for order %d: %s', $invoice_id, $order_id, $response->get_error_message()),
            ['source' => 'kalatori']
        );
        return;
    }

    $kalatoriStatus = KalatoriStatus::tryFrom($response['result']['status'] ?? '');

    if ($kalatoriStatus === null) {
        wc_get_logger()->warning(
            sprintf('Reconciliation: unknown invoice status for order %d.', $order_id),
            ['source' => 'kalatori']
        );
        return;
    }

    switch ($kalatoriStatus) {
        case KalatoriStatus::Waiting:
        case KalatoriStatus::PartiallyPaid:
            return;

        case KalatoriStatus::Paid:
        case KalatoriStatus::OverPaid:
            $order->payment_complete($invoice_id);
            $order->add_order_note(__('Reconciliation: payment confirmed via Kalatori.', 'kalatori-payment-gateway'));
            wc_get_logger()->info(
                sprintf('Reconciliation: order %d marked as paid (invoice status: %s).', $order_id, $kalatoriStatus->value),
                ['source' => 'kalatori']
            );
            return;

        case KalatoriStatus::UnpaidExpired:
        case KalatoriStatus::PartiallyPaidExpired:
            $order->update_status(
                WcOrderStatus::Cancelled->value,
                __('Reconciliation: Kalatori invoice expired without full payment.', 'kalatori-payment-gateway')
            );
            wc_get_logger()->info(
                sprintf('Reconciliation: order %d cancelled — expired invoice (%s).', $order_id, $kalatoriStatus->value),
                ['source' => 'kalatori']
            );
            return;

        case KalatoriStatus::CustomerCanceled:
            $order->update_status(
                WcOrderStatus::Pending->value,
                __('Reconciliation: Kalatori invoice was cancelled by the customer.', 'kalatori-payment-gateway')
            );
            wc_get_logger()->info(
                sprintf('Reconciliation: order %d set to pending — customer cancelled invoice.', $order_id),
                ['source' => 'kalatori']
            );
            return;

        case KalatoriStatus::AdminCanceled:
            $order->update_status(
                WcOrderStatus::Cancelled->value,
                __('Reconciliation: Kalatori invoice was cancelled by the merchant.', 'kalatori-payment-gateway')
            );
            wc_get_logger()->info(
                sprintf('Reconciliation: order %d cancelled — merchant cancelled invoice.', $order_id),
                ['source' => 'kalatori']
            );
            return;
    }
}

/**
 * Handle incoming Kalatori webhook callbacks.
 * Status mapping is defined in {@see KalatoriEventType::toWcStatus()}.
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

    $dedup_key = 'kalatori_wh_' . md5($raw_body);
    if (get_transient($dedup_key)) {
        wc_get_logger()->info('Webhook: duplicate delivery ignored.', ['source' => 'kalatori']);
        return new WP_REST_Response(['result' => 'ok'], 200);
    }

    // Webhook body is GenericEvent<Invoice>: {"id":..., "event_type":..., "payload":{invoice}, ...}
    $data = json_decode($raw_body, true);
    $invoice_id = $data['payload']['id'] ?? '';
    $event_type = $data['event_type'] ?? '';

    if (empty($invoice_id) || empty($event_type)) {
        return new WP_REST_Response(['error' => 'Missing invoice id or event_type.'], 400);
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
    $kalatoriEvent = KalatoriEventType::tryFrom($event_type);

    if ($kalatoriEvent === null) {
        wc_get_logger()->warning(
            sprintf('Webhook: unknown event_type "%s" for invoice %s.', $event_type, $invoice_id),
            ['source' => 'kalatori']
        );
        return new WP_REST_Response(['result' => 'ok'], 200);
    }

    $wc_status = $kalatoriEvent->toWcStatus();
    $note = $kalatoriEvent->toOrderNote();
    if ($kalatoriEvent === KalatoriEventType::Paid) {
        $order->payment_complete($invoice_id);
        if ($note !== '') {
            $order->add_order_note($note);
        }
        wc_get_logger()->info(
            sprintf('Webhook: order %d — event %s → WC status %s.', $order->get_id(), $event_type, WcOrderStatus::Processing->value . ' (via payment_complete)'),
            ['source' => 'kalatori']
        );
    } elseif ($wc_status !== null) {
        $order->update_status($wc_status->value, $note);
        wc_get_logger()->info(
            sprintf('Webhook: order %d — event %s → WC status %s.', $order->get_id(), $event_type, $wc_status->value),
            ['source' => 'kalatori']
        );
    } elseif ($note !== '') {
        $order->add_order_note($note);
        wc_get_logger()->info(
            sprintf('Webhook: order %d — event %s → no status change, note added.', $order->get_id(), $event_type),
            ['source' => 'kalatori']
        );
    }

    set_transient($dedup_key, 1, DAY_IN_SECONDS);

    return new WP_REST_Response(['result' => 'ok'], 200);
}

