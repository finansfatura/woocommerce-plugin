<?php
if (!defined('ABSPATH')) {
    exit;
}

use Finansfatura\Payload;

/**
 * Turns a WC_Order into the two payloads the API takes, in order:
 *
 *   1. the SALE  (POST /v1/integrations/orders) — prices KDV-INCLUSIVE,
 *      vat_rate as a percentage (20).
 *   2. the INVOICE (POST /v1/invoicing/invoices/) — prices KDV-EXCLUSIVE,
 *      vat_rate as a ratio (0.20), built by Finansfatura\Payload so the
 *      PascalCase-inside-`canonical` casing is not ours to get wrong.
 *
 * Mixing those two conventions up is the classic integration bug; they are
 * built by two separate methods here for exactly that reason.
 */
class FF_Invoice_Mapper
{
    /** Source label for the sale — shows the WooCommerce brand on the sale. */
    public const PROVIDER = 'WOOCOMMERCE';

    /**
     * Stable, per-store order id. The store hash keeps two shops of the same
     * company from colliding on WooCommerce's per-site order numbering.
     */
    public static function external_id(WC_Abstract_Order $order): string
    {
        return sprintf('wc_%s_%d', substr(sha1(home_url()), 0, 8), $order->get_id());
    }

    /**
     * The sale. Line prices are KDV-inclusive: `total_price` is the pre-discount
     * gross and `discount` what the coupon took off, so the API's
     * `final = total - discount` lands on what the customer actually paid.
     */
    public static function sale(WC_Order $order): array
    {
        $lines = [];
        foreach (self::billable_items($order) as $item) {
            $qty      = self::qty($item);
            $net      = abs((float) $item->get_total());       // after discount, KDV excl
            $tax      = abs((float) $item->get_total_tax());
            $gross    = abs((float) self::gross_before_discount($item));
            $discount = round($gross - ($net + $tax), 2);

            $lines[] = [
                'external_line_id' => (string) $item->get_id(),
                'sku'              => self::sku($item),
                'title'            => (string) $item->get_name(),
                'quantity'         => $qty,
                'unit_price'       => $qty > 0 ? round($gross / $qty, 4) : 0.0,
                'vat_rate'         => self::vat_percent($net, $tax),
                'total_price'      => round($gross, 2),
                'discount'         => $discount > 0 ? $discount : 0.0,
            ];
        }

        $type = FF_Order_Meta::get($order, FF_Order_Meta::RECIPIENT_TYPE, 'bireysel');

        return [
            'provider'       => self::PROVIDER,
            'external_id'    => self::external_id($order),
            'order_number'   => (string) $order->get_order_number(),
            'customer_name'  => self::buyer_title($order),
            'ordered_at'     => self::ordered_at($order),
            'status'         => (string) $order->get_status(),
            'payment_status' => $order->is_paid() ? 'PAID' : 'UNPAID',
            'currency'       => $order->get_currency() ?: 'TRY',
            'total_price'    => round(abs((float) $order->get_total()), 2),
            'lines'          => $lines,
            'buyer'          => [
                'title'        => self::buyer_title($order),
                'contact_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'email'        => (string) $order->get_billing_email(),
                'phone'        => (string) $order->get_billing_phone(),
                'tckn'         => $type === 'kurumsal' ? '' : FF_Order_Meta::get($order, FF_Order_Meta::TCKN),
                'tax_number'   => $type === 'kurumsal' ? FF_Order_Meta::get($order, FF_Order_Meta::VKN) : '',
                'tax_office'   => FF_Order_Meta::get($order, FF_Order_Meta::TAX_OFFICE),
                'address'      => self::address($order),
            ],
        ];
    }

    /**
     * The document. Always EARSIV: the server looks the VKN/TCKN up at GİB and
     * upgrades to EFATURA (with the right mailbox alias) by itself, which is
     * more reliable than asking the buyer at checkout.
     *
     * `$transaction_id` is the sale's id — without it the invoice hangs off no
     * sale and stays out of the turnover report, the current account and stock.
     */
    public static function invoice(WC_Order $order, ?WC_Order_Refund $refund, string $transaction_id): array
    {
        $source = $refund ?: $order;
        $lines = [];
        foreach (self::billable_items($source) as $item) {
            $qty = self::qty($item);
            if ($qty <= 0) {
                continue;
            }
            $net = abs((float) $item->get_total());        // KDV excl, after discount
            $tax = abs((float) $item->get_total_tax());

            $lines[] = [
                'title'        => (string) $item->get_name(),
                'product_code' => self::sku($item),
                'qty'          => $qty,
                'unit_price'   => round($net / $qty, 4),
                'unit_code'    => 'C62',
                'vat_rate'     => $net > 0 ? round($tax / $net, 4) : 0.0,
            ];
        }

        $opts = [
            'transactionHeaderId' => $transaction_id,
            'invoiceTypeCode'     => $refund ? 'IADE' : 'SATIS',
            'note'                => sprintf(
                /* translators: %1$s = store name, %2$s = order number */
                __('%1$s — Sipariş #%2$s', 'finansfatura-woo'),
                get_bloginfo('name'),
                (string) $order->get_order_number()
            ),
        ];

        return Payload::earsiv(self::recipient($order), $lines, $opts);
    }

    /**
     * Deterministic per order/refund: retrying never issues a second document
     * and never charges credits twice.
     */
    public static function idempotency_key(WC_Order $order, ?WC_Order_Refund $refund = null): string
    {
        return self::external_id($order) . '_' . ($refund ? (int) $refund->get_id() : 0);
    }

    /** @return array<string,string> */
    private static function recipient(WC_Order $order): array
    {
        $kurumsal = FF_Order_Meta::get($order, FF_Order_Meta::RECIPIENT_TYPE, 'bireysel') === 'kurumsal';

        return [
            'vkn_tckn'   => $kurumsal
                ? FF_Order_Meta::get($order, FF_Order_Meta::VKN)
                : FF_Order_Meta::get($order, FF_Order_Meta::TCKN),
            'title'      => self::buyer_title($order),
            'address'    => self::address($order),
            'tax_office' => FF_Order_Meta::get($order, FF_Order_Meta::TAX_OFFICE),
            'email'      => (string) $order->get_billing_email(),
            'phone'      => (string) $order->get_billing_phone(),
        ];
    }

    private static function buyer_title(WC_Order $order): string
    {
        $kurumsal = FF_Order_Meta::get($order, FF_Order_Meta::RECIPIENT_TYPE, 'bireysel') === 'kurumsal';
        $company  = (string) $order->get_billing_company();
        if ($kurumsal && $company !== '') {
            return $company;
        }
        return trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
    }

    private static function address(WC_Order $order): string
    {
        return implode(' ', array_filter([
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
            $order->get_billing_city(),
            $order->get_billing_state(),
            $order->get_billing_postcode(),
            $order->get_billing_country(),
        ]));
    }

    /**
     * Products AND shipping AND fees. Totals are derived from the lines on the
     * server, so leaving shipping out would issue an invoice for less than the
     * customer paid.
     *
     * @return array<int,WC_Order_Item>
     */
    private static function billable_items(WC_Abstract_Order $source): array
    {
        return array_values($source->get_items(['line_item', 'shipping', 'fee']));
    }

    /** Shipping and fee items carry no quantity; they are one unit each. */
    private static function qty(WC_Order_Item $item): float
    {
        $qty = method_exists($item, 'get_quantity') ? abs((float) $item->get_quantity()) : 0.0;
        return $qty > 0 ? $qty : 1.0;
    }

    private static function sku(WC_Order_Item $item): string
    {
        if (!method_exists($item, 'get_product')) {
            return '';
        }
        $product = $item->get_product();
        return $product ? (string) $product->get_sku() : '';
    }

    /** Pre-discount gross, KDV included. Only line items carry a subtotal. */
    private static function gross_before_discount(WC_Order_Item $item): float
    {
        if (method_exists($item, 'get_subtotal')) {
            return abs((float) $item->get_subtotal()) + abs((float) $item->get_subtotal_tax());
        }
        return abs((float) $item->get_total()) + abs((float) $item->get_total_tax());
    }

    /**
     * KDV percentage for the sale payload. Rounded to a whole percent: Turkish
     * rates are 0/1/10/20 and float division of kuruş amounts lands on 19.9997.
     */
    private static function vat_percent(float $net, float $tax): float
    {
        return $net > 0 ? round(($tax / $net) * 100, 0) : 0.0;
    }

    private static function ordered_at(WC_Order $order): string
    {
        $date = $order->get_date_created();
        return $date ? gmdate('Y-m-d\TH:i:s\Z', $date->getTimestamp()) : gmdate('Y-m-d\TH:i:s\Z');
    }
}
