<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Single source of truth for the order-level metadata the plugin reads and
 * writes. Keeping the keys in one class makes the HPOS / CPT storage swap a
 * non-event: every read/write goes through WC_Data accessors here.
 */
class FF_Order_Meta
{
    public const RECIPIENT_TYPE     = '_ff_recipient_type';      // "bireysel" | "kurumsal"
    public const TCKN               = '_ff_tckn';
    public const VKN                = '_ff_vkn';
    public const TAX_OFFICE         = '_ff_tax_office';

    // Step 1: the sale. Its presence is what keeps a second push from
    // creating a second sale, and what the invoice hangs off.
    public const TRANSACTION_ID     = '_ff_transaction_id';

    // Step 2: the document.
    public const INVOICE_ID         = '_ff_invoice_id';
    public const INVOICE_NUMBER     = '_ff_invoice_number';
    public const ETTN               = '_ff_ettn';
    public const PDF_URL            = '_ff_pdf_url';
    public const STATUS             = '_ff_status';
    public const DOC_TYPE           = '_ff_doc_type';
    public const RETRY_COUNT        = '_ff_retry_count';
    public const LAST_ERROR         = '_ff_last_error';

    public static function get(WC_Abstract_Order $order, string $key, string $default = ''): string
    {
        $val = $order->get_meta($key);
        return $val === '' ? $default : (string) $val;
    }

    public static function set(WC_Abstract_Order $order, string $key, $value): void
    {
        $order->update_meta_data($key, $value);
    }

    public static function bulk_set(WC_Abstract_Order $order, array $kv): void
    {
        foreach ($kv as $k => $v) {
            $order->update_meta_data($k, $v);
        }
    }
}
