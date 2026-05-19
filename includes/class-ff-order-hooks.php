<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wires every WooCommerce event that should turn into a Finansfatura API
 * call: auto-issue on order completion, IADE on refund creation, and an admin
 * "Fatura Kes" button on the order detail page for manual control.
 *
 * Retries lean on Action Scheduler when present (WooCommerce ships it) and
 * fall back to silent failure when the merchant disabled it.
 */
class FF_Order_Hooks
{
    public const RETRY_HOOK = 'ff_retry_issue';
    public const MAX_RETRIES = 3;

    public static function boot(): void
    {
        add_action('woocommerce_order_status_completed', [__CLASS__, 'on_completed'], 20, 1);
        add_action('woocommerce_order_refunded', [__CLASS__, 'on_refunded'], 20, 2);
        add_action(self::RETRY_HOOK, [__CLASS__, 'on_retry'], 10, 2);

        if (is_admin()) {
            add_action('add_meta_boxes', [__CLASS__, 'register_meta_box']);
            add_action('wp_ajax_ff_manual_issue', [__CLASS__, 'ajax_manual_issue']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin']);
            // Server-side download proxy — keeps the API key out of the
            // browser. WP admin hits ?action=ff_download_invoice and the
            // plugin streams the binary back, never exposing X-Api-Key.
            add_action('admin_post_ff_download_invoice', [__CLASS__, 'admin_post_download']);
        }
    }

    public static function enqueue_admin($hook): void
    {
        if (strpos((string) $hook, 'post.php') === false && strpos((string) $hook, 'wc-orders') === false) {
            return;
        }
        wp_enqueue_script('ff-admin', FF_PLUGIN_URL . 'assets/admin.js', ['jquery'], FF_PLUGIN_VERSION, true);
        wp_enqueue_style('ff-admin', FF_PLUGIN_URL . 'assets/admin.css', [], FF_PLUGIN_VERSION);
        wp_localize_script('ff-admin', 'FFAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('ff_admin'),
            'i18n'    => [
                'issuing' => __('Fatura kesiliyor…', 'finansfatura-woo'),
                'failed'  => __('Fatura kesilemedi.', 'finansfatura-woo'),
                'success' => __('Fatura kesildi.', 'finansfatura-woo'),
            ],
        ]);
    }

    public static function on_completed(int $order_id): void
    {
        if (!FF_Settings::is_enabled('auto_issue_on_complete')) {
            return;
        }
        $order = wc_get_order($order_id);
        if (!$order || FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_ID) !== '') {
            return;
        }
        self::issue_for_order($order, null);
    }

    public static function on_refunded(int $order_id, int $refund_id): void
    {
        if (!FF_Settings::is_enabled('auto_issue_iade_on_refund')) {
            return;
        }
        $order  = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if (!$order || !$refund) {
            return;
        }
        self::issue_for_order($order, $refund);
    }

    public static function on_retry(int $order_id, int $refund_id): void
    {
        $order  = wc_get_order($order_id);
        $refund = $refund_id ? wc_get_order($refund_id) : null;
        if (!$order) {
            return;
        }
        self::issue_for_order($order, $refund instanceof WC_Order_Refund ? $refund : null);
    }

    public static function issue_for_order(WC_Order $order, ?WC_Order_Refund $refund): void
    {
        $payload = FF_Invoice_Mapper::from_order($order, $refund);
        $key     = FF_Invoice_Mapper::idempotency_key($order, $refund);

        $client = new FF_Api_Client();
        $resp = $client->issue($payload, $key);

        $log_target_id = $refund ? $refund->get_id() : 0;
        FF_Logger::write([
            'order_id'        => $order->get_id(),
            'refund_id'       => $log_target_id,
            'idempotency_key' => $key,
            'payload'         => wp_json_encode($payload),
            'status'          => is_wp_error($resp) ? null : 200,
            'body'            => is_wp_error($resp) ? '' : wp_json_encode($resp),
            'error'           => is_wp_error($resp) ? $resp->get_error_message() : '',
        ]);

        if (is_wp_error($resp)) {
            self::handle_failure($order, $refund, $resp);
            return;
        }

        FF_Order_Meta::bulk_set($order, [
            FF_Order_Meta::INVOICE_ID => (string) ($resp['invoice_id'] ?? ''),
            FF_Order_Meta::ETTN       => (string) ($resp['ettn'] ?? ''),
            FF_Order_Meta::PDF_URL    => (string) ($resp['pdf_url'] ?? ''),
            FF_Order_Meta::STATUS     => (string) ($resp['status'] ?? ''),
            FF_Order_Meta::DOC_TYPE   => (string) ($payload['document_type'] ?? ''),
            FF_Order_Meta::RETRY_COUNT=> '0',
            FF_Order_Meta::LAST_ERROR => '',
        ]);
        $order->save();

        $order->add_order_note(sprintf(
            /* translators: 1: document type, 2: ETTN */
            __('Finansfatura: %1$s kesildi (ETTN: %2$s)', 'finansfatura-woo'),
            (string) $payload['document_type'],
            (string) ($resp['ettn'] ?? '—')
        ));
    }

    private static function handle_failure(WC_Order $order, ?WC_Order_Refund $refund, WP_Error $err): void
    {
        $count = (int) FF_Order_Meta::get($order, FF_Order_Meta::RETRY_COUNT, '0');
        $next = $count + 1;
        FF_Order_Meta::set($order, FF_Order_Meta::RETRY_COUNT, (string) $next);
        FF_Order_Meta::set($order, FF_Order_Meta::LAST_ERROR, $err->get_error_message());
        $order->save();

        $order->add_order_note(sprintf(
            /* translators: %s = error message */
            __('Finansfatura hatası: %s', 'finansfatura-woo'),
            $err->get_error_message()
        ));

        if ($next < self::MAX_RETRIES && function_exists('as_schedule_single_action')) {
            as_schedule_single_action(
                time() + (60 * 5 * $next),
                self::RETRY_HOOK,
                [$order->get_id(), $refund ? $refund->get_id() : 0],
                'finansfatura'
            );
        }
    }

    public static function register_meta_box(): void
    {
        $screens = ['shop_order', 'woocommerce_page_wc-orders'];
        foreach ($screens as $screen) {
            add_meta_box(
                'ff_invoice_box',
                __('Finansfatura', 'finansfatura-woo'),
                [__CLASS__, 'render_meta_box'],
                $screen,
                'side',
                'default'
            );
        }
    }

    public static function render_meta_box($post_or_order): void
    {
        $order_id = $post_or_order instanceof WP_Post ? (int) $post_or_order->ID : (int) $post_or_order->get_id();
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $invoice_id = FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_ID);
        $ettn       = FF_Order_Meta::get($order, FF_Order_Meta::ETTN);
        $status     = FF_Order_Meta::get($order, FF_Order_Meta::STATUS);
        $last_error = FF_Order_Meta::get($order, FF_Order_Meta::LAST_ERROR);
        $download_url = $invoice_id !== '' ? self::build_download_url($invoice_id, 'pdf') : '';
        $download_html_url = $invoice_id !== '' ? self::build_download_url($invoice_id, 'html') : '';
        ?>
        <div class="ff-meta-box" data-order-id="<?php echo esc_attr((string) $order_id); ?>">
            <?php if ($invoice_id !== ''): ?>
                <p><strong><?php esc_html_e('Durum', 'finansfatura-woo'); ?>:</strong> <?php echo esc_html($status); ?></p>
                <p><strong>ETTN:</strong> <code><?php echo esc_html($ettn ?: '—'); ?></code></p>
                <p>
                    <a class="button" href="<?php echo esc_url($download_url); ?>">
                        <?php esc_html_e('PDF indir', 'finansfatura-woo'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url($download_html_url); ?>">
                        <?php esc_html_e('HTML', 'finansfatura-woo'); ?>
                    </a>
                </p>
            <?php else: ?>
                <p><?php esc_html_e('Bu sipariş için henüz fatura kesilmedi.', 'finansfatura-woo'); ?></p>
            <?php endif; ?>
            <?php if ($last_error !== ''): ?>
                <p class="ff-error"><strong><?php esc_html_e('Son hata', 'finansfatura-woo'); ?>:</strong> <?php echo esc_html($last_error); ?></p>
            <?php endif; ?>
            <button type="button" class="button button-primary" id="ff-manual-issue">
                <?php esc_html_e('Fatura Kes', 'finansfatura-woo'); ?>
            </button>
            <span id="ff-manual-issue-result"></span>
        </div>
        <?php
    }

    /** Builds a nonced admin-post URL that proxies the download server-side. */
    private static function build_download_url(string $invoice_id, string $format): string
    {
        return add_query_arg(
            [
                'action'     => 'ff_download_invoice',
                'invoice_id' => $invoice_id,
                'format'     => $format,
                '_wpnonce'   => wp_create_nonce('ff_download_' . $invoice_id),
            ],
            admin_url('admin-post.php')
        );
    }

    /**
     * Streams the rendered invoice through the WP admin. The API key never
     * leaves the server; the browser only sees the binary content + filename.
     */
    public static function admin_post_download(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('Yetkisiz erişim.', 'finansfatura-woo'), '', ['response' => 403]);
        }
        $invoice_id = isset($_GET['invoice_id']) ? sanitize_text_field(wp_unslash($_GET['invoice_id'])) : '';
        $format     = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'pdf';
        if ($invoice_id === '' || !in_array($format, ['pdf', 'html', 'xml'], true)) {
            wp_die(__('Geçersiz istek.', 'finansfatura-woo'), '', ['response' => 400]);
        }
        if (!check_admin_referer('ff_download_' . $invoice_id)) {
            wp_die(__('Geçersiz nonce.', 'finansfatura-woo'), '', ['response' => 403]);
        }

        $client = new FF_Api_Client();
        $resp = $client->download($invoice_id, $format);
        if (is_wp_error($resp)) {
            wp_die(esc_html($resp->get_error_message()), '', ['response' => 502]);
        }

        nocache_headers();
        header('Content-Type: ' . $resp['content_type']);
        header('Content-Disposition: attachment; filename="' . $resp['filename'] . '"');
        header('Content-Length: ' . strlen($resp['body']));
        echo $resp['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — binary payload
        exit;
    }

    public static function ajax_manual_issue(): void
    {
        if (!current_user_can('manage_woocommerce') || !check_ajax_referer('ff_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $order_id = (int) ($_POST['order_id'] ?? 0);
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => 'order not found'], 404);
        }
        self::issue_for_order($order, null);
        $invoice_id = FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_ID);
        if ($invoice_id === '') {
            wp_send_json_error(['message' => FF_Order_Meta::get($order, FF_Order_Meta::LAST_ERROR) ?: 'failed']);
        }
        wp_send_json_success([
            'invoice_id' => $invoice_id,
            'ettn'       => FF_Order_Meta::get($order, FF_Order_Meta::ETTN),
            'pdf_url'    => FF_Order_Meta::get($order, FF_Order_Meta::PDF_URL),
            'status'     => FF_Order_Meta::get($order, FF_Order_Meta::STATUS),
        ]);
    }
}
