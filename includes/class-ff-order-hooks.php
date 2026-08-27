<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * The order lifecycle, in the order the API wants it:
 *
 *   1. SALE  — pushed as soon as the order is paid. It feeds turnover, the
 *      current account and stock, and it survives a failed invoice attempt.
 *   2. INVOICE — issued on top of that sale, automatically when the order
 *      completes (if the merchant left it on) or from the "Fatura Kes" button.
 *
 * The button never skips step 1: it creates the missing sale first, then
 * invoices it. Pressing it is a request for a document, not a request to
 * bypass the sale.
 *
 * Retries lean on Action Scheduler (WooCommerce ships it) and only fire for
 * failures the API marked retryable — a rejected payload is not worth
 * re-sending unchanged.
 */
class FF_Order_Hooks
{
    public const RETRY_HOOK = 'ff_retry_issue';
    public const MAX_RETRIES = 3;

    public static function boot(): void
    {
        add_action('woocommerce_order_status_processing', [__CLASS__, 'on_paid'], 20, 1);
        add_action('woocommerce_order_status_completed', [__CLASS__, 'on_completed'], 20, 1);
        add_action('woocommerce_order_refunded', [__CLASS__, 'on_refunded'], 20, 2);
        add_action(self::RETRY_HOOK, [__CLASS__, 'on_retry'], 10, 2);

        if (is_admin()) {
            add_action('add_meta_boxes', [__CLASS__, 'register_meta_box']);
            add_action('wp_ajax_ff_manual_issue', [__CLASS__, 'ajax_manual_issue']);
            add_action('wp_ajax_ff_sync_sale', [__CLASS__, 'ajax_sync_sale']);
            add_action('wp_ajax_ff_refresh_status', [__CLASS__, 'ajax_refresh_status']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin']);
            // Server-side download proxy — keeps the API key out of the
            // browser. WP admin hits ?action=ff_download_invoice and the
            // plugin streams the binary back, never exposing the credential.
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
                'working' => __('İşleniyor…', 'finansfatura-woo'),
                'failed'  => __('İşlem başarısız.', 'finansfatura-woo'),
                'success' => __('Tamam.', 'finansfatura-woo'),
            ],
        ]);
    }

    /** Payment landed → the sale exists from here on. */
    public static function on_paid(int $order_id): void
    {
        if (!FF_Settings::is_enabled('auto_sale')) {
            return;
        }
        $order = wc_get_order($order_id);
        if ($order) {
            self::ensure_sale($order);
        }
    }

    /** Order completed → sale (if the store skipped "processing") + invoice. */
    public static function on_completed(int $order_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        if (FF_Settings::is_enabled('auto_sale')) {
            self::ensure_sale($order);
        }
        if (FF_Settings::is_enabled('auto_invoice') && FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_ID) === '') {
            self::issue_invoice($order, null);
        }
    }

    public static function on_refunded(int $order_id, int $refund_id): void
    {
        if (!FF_Settings::is_enabled('auto_issue_iade_on_refund')) {
            return;
        }
        $order  = wc_get_order($order_id);
        $refund = wc_get_order($refund_id);
        if ($order && $refund instanceof WC_Order_Refund) {
            self::issue_invoice($order, $refund);
        }
    }

    public static function on_retry(int $order_id, int $refund_id): void
    {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }
        $refund = $refund_id ? wc_get_order($refund_id) : null;
        self::issue_invoice($order, $refund instanceof WC_Order_Refund ? $refund : null);
    }

    /**
     * Step 1. Returns the sale's transaction id, or a WP_Error.
     *
     * Idempotent twice over: the stored id short-circuits the call, and the
     * API answers a repeat `external_id` with the same sale instead of a
     * duplicate.
     *
     * @return string|WP_Error
     */
    public static function ensure_sale(WC_Order $order)
    {
        $existing = FF_Order_Meta::get($order, FF_Order_Meta::TRANSACTION_ID);
        if ($existing !== '') {
            return $existing;
        }

        $payload = FF_Invoice_Mapper::sale($order);
        $client  = new FF_Api_Client();
        $resp    = $client->create_sale($payload);

        FF_Logger::write([
            'order_id'        => $order->get_id(),
            'idempotency_key' => $payload['external_id'],
            'payload'         => wp_json_encode($payload),
            'status'          => is_wp_error($resp) ? null : 200,
            'body'            => is_wp_error($resp) ? '' : wp_json_encode($resp),
            'error'           => is_wp_error($resp) ? $resp->get_error_message() : '',
        ]);

        if (is_wp_error($resp)) {
            self::record_error($order, $resp, __('Finansfatura satış aktarımı başarısız: %s', 'finansfatura-woo'));
            return $resp;
        }

        $txn = (string) ($resp['transaction_id'] ?? '');
        // Satis id'siz bir 2xx beklenmiyor, ama gelirse fatura adimi satissiz
        // kesime kayardi — API artik onu reddediyor, SDK ise istisna atiyor.
        // Burada WP_Error'a cevirmek hatayi normal retry/hata yoluna sokar.
        if ($txn === '') {
            $err = new WP_Error('ff_no_transaction_id', __('Satış oluşturuldu ama id dönmedi.', 'finansfatura-woo'));
            self::record_error($order, $err, __('Finansfatura satış aktarımı başarısız: %s', 'finansfatura-woo'));
            return $err;
        }
        FF_Order_Meta::set($order, FF_Order_Meta::TRANSACTION_ID, $txn);
        FF_Order_Meta::set($order, FF_Order_Meta::LAST_ERROR, '');
        $order->save();

        if (empty($resp['already_imported'])) {
            $order->add_order_note(__('Finansfatura: satış aktarıldı.', 'finansfatura-woo'));
        }
        return $txn;
    }

    /**
     * Step 2. Always runs on top of a sale — when there isn't one yet, it is
     * created first.
     *
     * @return array|WP_Error
     */
    public static function issue_invoice(WC_Order $order, ?WC_Order_Refund $refund)
    {
        $txn = self::ensure_sale($order);
        if (is_wp_error($txn)) {
            self::maybe_retry($order, $refund, $txn);
            return $txn;
        }

        // ponytail: an IADE is deliberately NOT attached to the original sale —
        // it would count the sale twice. The refund lives as its own document.
        $payload = FF_Invoice_Mapper::invoice($order, $refund, $refund ? '' : $txn);
        $key     = FF_Invoice_Mapper::idempotency_key($order, $refund);

        $client = new FF_Api_Client();
        $resp   = $client->issue($payload, $key);

        FF_Logger::write([
            'order_id'        => $order->get_id(),
            'refund_id'       => $refund ? $refund->get_id() : 0,
            'idempotency_key' => $key,
            'payload'         => wp_json_encode($payload),
            'status'          => is_wp_error($resp) ? null : 200,
            'body'            => is_wp_error($resp) ? '' : wp_json_encode($resp),
            'error'           => is_wp_error($resp) ? $resp->get_error_message() : '',
        ]);

        if (is_wp_error($resp)) {
            self::record_error($order, $resp, __('Finansfatura hatası: %s', 'finansfatura-woo'));
            self::maybe_retry($order, $refund, $resp);
            return $resp;
        }

        FF_Order_Meta::bulk_set($order, [
            FF_Order_Meta::INVOICE_ID  => (string) ($resp['invoice_id'] ?? ''),
            FF_Order_Meta::ETTN        => (string) ($resp['ettn'] ?? ''),
            FF_Order_Meta::PDF_URL     => (string) ($resp['pdf_url'] ?? ''),
            FF_Order_Meta::STATUS      => (string) ($resp['status'] ?? ''),
            FF_Order_Meta::DOC_TYPE    => (string) ($payload['document_type'] ?? ''),
            FF_Order_Meta::RETRY_COUNT => '0',
            FF_Order_Meta::LAST_ERROR  => '',
        ]);
        $order->save();

        $order->add_order_note(sprintf(
            /* translators: 1: document type, 2: ETTN */
            __('Finansfatura: %1$s kesildi (ETTN: %2$s)', 'finansfatura-woo'),
            (string) $payload['document_type'],
            (string) ($resp['ettn'] ?? '—')
        ));
        return $resp;
    }

    /**
     * The invoice NUMBER is assigned by the provider after issuance, so it is
     * never in the issue response — the status endpoint is where it appears.
     *
     * @return array|WP_Error
     */
    public static function refresh_status(WC_Order $order)
    {
        $client = new FF_Api_Client();
        $resp = $client->order_status([FF_Invoice_Mapper::external_id($order)]);
        if (is_wp_error($resp)) {
            return $resp;
        }
        // Ids we never sent are simply absent, so match instead of taking [0].
        $wanted = FF_Invoice_Mapper::external_id($order);
        foreach ((array) ($resp['statuses'] ?? []) as $row) {
            if (($row['external_id'] ?? '') !== $wanted) {
                continue;
            }
            FF_Order_Meta::bulk_set($order, [
                FF_Order_Meta::STATUS         => (string) ($row['invoice_status'] ?? ''),
                FF_Order_Meta::INVOICE_ID     => (string) ($row['invoice_id'] ?? FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_ID)),
                FF_Order_Meta::INVOICE_NUMBER => (string) ($row['invoice_number'] ?? ''),
                FF_Order_Meta::DOC_TYPE       => (string) ($row['document_type'] ?? FF_Order_Meta::get($order, FF_Order_Meta::DOC_TYPE)),
                FF_Order_Meta::PDF_URL        => (string) ($row['pdf_url'] ?? FF_Order_Meta::get($order, FF_Order_Meta::PDF_URL)),
            ]);
            $order->save();
            return $row;
        }
        return new WP_Error('ff_not_found', __('Bu sipariş Finansfatura tarafında bulunamadı.', 'finansfatura-woo'));
    }

    private static function record_error(WC_Order $order, WP_Error $err, string $note_format): void
    {
        FF_Order_Meta::set($order, FF_Order_Meta::LAST_ERROR, $err->get_error_message());
        $order->save();
        $order->add_order_note(sprintf($note_format, $err->get_error_message()));
    }

    /**
     * Only failures the API called retryable (429 / 5xx / transport) come back.
     * A 400 will fail identically forever; the merchant has to fix the data.
     */
    private static function maybe_retry(WC_Order $order, ?WC_Order_Refund $refund, WP_Error $err): void
    {
        $data = $err->get_error_data();
        if (empty($data['retryable']) || !function_exists('as_schedule_single_action')) {
            return;
        }
        $count = (int) FF_Order_Meta::get($order, FF_Order_Meta::RETRY_COUNT, '0') + 1;
        FF_Order_Meta::set($order, FF_Order_Meta::RETRY_COUNT, (string) $count);
        $order->save();
        if ($count >= self::MAX_RETRIES) {
            return;
        }
        as_schedule_single_action(
            time() + (60 * 5 * $count),
            self::RETRY_HOOK,
            [$order->get_id(), $refund ? $refund->get_id() : 0],
            'finansfatura'
        );
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
        $txn        = FF_Order_Meta::get($order, FF_Order_Meta::TRANSACTION_ID);
        $invoice_id = FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_ID);
        $number     = FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_NUMBER);
        $ettn       = FF_Order_Meta::get($order, FF_Order_Meta::ETTN);
        $status     = FF_Order_Meta::get($order, FF_Order_Meta::STATUS);
        $last_error = FF_Order_Meta::get($order, FF_Order_Meta::LAST_ERROR);
        ?>
        <div class="ff-meta-box" data-order-id="<?php echo esc_attr((string) $order_id); ?>">
            <p>
                <strong><?php esc_html_e('Satış', 'finansfatura-woo'); ?>:</strong>
                <?php echo $txn !== ''
                    ? esc_html__('aktarıldı', 'finansfatura-woo')
                    : esc_html__('aktarılmadı', 'finansfatura-woo'); ?>
            </p>
            <?php if ($invoice_id !== ''): ?>
                <p><strong><?php esc_html_e('Durum', 'finansfatura-woo'); ?>:</strong> <?php echo esc_html($status); ?></p>
                <?php if ($number !== ''): ?>
                    <p><strong><?php esc_html_e('Fatura no', 'finansfatura-woo'); ?>:</strong> <code><?php echo esc_html($number); ?></code></p>
                <?php endif; ?>
                <p><strong>ETTN:</strong> <code><?php echo esc_html($ettn ?: '—'); ?></code></p>
                <p>
                    <a class="button" href="<?php echo esc_url(self::build_download_url($invoice_id, 'pdf')); ?>">
                        <?php esc_html_e('PDF indir', 'finansfatura-woo'); ?>
                    </a>
                    <a class="button" href="<?php echo esc_url(self::build_download_url($invoice_id, 'html')); ?>">
                        <?php esc_html_e('HTML', 'finansfatura-woo'); ?>
                    </a>
                </p>
            <?php else: ?>
                <p><?php esc_html_e('Bu sipariş için henüz fatura kesilmedi.', 'finansfatura-woo'); ?></p>
            <?php endif; ?>
            <?php if ($last_error !== ''): ?>
                <p class="ff-error"><strong><?php esc_html_e('Son hata', 'finansfatura-woo'); ?>:</strong> <?php echo esc_html($last_error); ?></p>
            <?php endif; ?>
            <p>
                <?php if ($txn === ''): ?>
                    <button type="button" class="button" data-ff-action="ff_sync_sale">
                        <?php esc_html_e('Satışı aktar', 'finansfatura-woo'); ?>
                    </button>
                <?php endif; ?>
                <button type="button" class="button button-primary" data-ff-action="ff_manual_issue">
                    <?php esc_html_e('Fatura Kes', 'finansfatura-woo'); ?>
                </button>
                <button type="button" class="button" data-ff-action="ff_refresh_status">
                    <?php esc_html_e('Durumu yenile', 'finansfatura-woo'); ?>
                </button>
            </p>
            <span class="ff-result"></span>
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
     * Streams the rendered invoice through the WP admin. The credential never
     * leaves the server; the browser only sees the bytes.
     */
    public static function admin_post_download(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Yetkisiz erişim.', 'finansfatura-woo'), '', ['response' => 403]);
        }
        $invoice_id = isset($_GET['invoice_id']) ? sanitize_text_field(wp_unslash($_GET['invoice_id'])) : '';
        $format     = isset($_GET['format']) ? sanitize_key(wp_unslash($_GET['format'])) : 'pdf';
        $types      = ['pdf' => 'application/pdf', 'html' => 'text/html', 'xml' => 'application/xml'];
        if ($invoice_id === '' || !isset($types[$format])) {
            wp_die(esc_html__('Geçersiz istek.', 'finansfatura-woo'), '', ['response' => 400]);
        }
        if (!check_admin_referer('ff_download_' . $invoice_id)) {
            wp_die(esc_html__('Geçersiz nonce.', 'finansfatura-woo'), '', ['response' => 403]);
        }

        $client = new FF_Api_Client();
        $body = $client->download($invoice_id, $format);
        if (is_wp_error($body)) {
            wp_die(esc_html($body->get_error_message()), '', ['response' => 502]);
        }

        nocache_headers();
        header('Content-Type: ' . $types[$format]);
        header('Content-Disposition: attachment; filename="' . $invoice_id . '.' . $format . '"');
        header('Content-Length: ' . strlen($body));
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — binary payload
        exit;
    }

    /** "Fatura Kes" — creates the missing sale first, then issues. */
    public static function ajax_manual_issue(): void
    {
        $order = self::ajax_order();
        $resp = self::issue_invoice($order, null);
        if (is_wp_error($resp)) {
            wp_send_json_error(['message' => $resp->get_error_message()]);
        }
        wp_send_json_success([
            'invoice_id' => FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_ID),
            'ettn'       => FF_Order_Meta::get($order, FF_Order_Meta::ETTN),
            'status'     => FF_Order_Meta::get($order, FF_Order_Meta::STATUS),
        ]);
    }

    /** "Satışı aktar" — step 1 alone, for stores that invoice from the panel. */
    public static function ajax_sync_sale(): void
    {
        $order = self::ajax_order();
        $txn = self::ensure_sale($order);
        if (is_wp_error($txn)) {
            wp_send_json_error(['message' => $txn->get_error_message()]);
        }
        wp_send_json_success(['transaction_id' => $txn]);
    }

    public static function ajax_refresh_status(): void
    {
        $order = self::ajax_order();
        $row = self::refresh_status($order);
        if (is_wp_error($row)) {
            wp_send_json_error(['message' => $row->get_error_message()]);
        }
        wp_send_json_success($row);
    }

    /** Shared guard for the three admin-ajax actions. */
    private static function ajax_order(): WC_Order
    {
        if (!current_user_can('manage_woocommerce') || !check_ajax_referer('ff_admin', 'nonce', false)) {
            wp_send_json_error(['message' => 'forbidden'], 403);
        }
        $order = wc_get_order((int) ($_POST['order_id'] ?? 0));
        if (!$order instanceof WC_Order) {
            wp_send_json_error(['message' => 'order not found'], 404);
        }
        return $order;
    }
}
