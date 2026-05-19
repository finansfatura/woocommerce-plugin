<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Translates a WC_Order (and optionally a WC_Order_Refund) into the
 * service.IssueRequest JSON the invoicing-service expects. The shape mirrors
 * the Go side at:
 *   - invoicing-service/app/service/issuance.go (IssueRequest)
 *   - invoicing-service/app/adapter/adapter.go (CanonicalInvoice)
 */
class FF_Invoice_Mapper
{
    public static function from_order(WC_Order $order, ?WC_Order_Refund $refund = null): array
    {
        $type = FF_Order_Meta::get($order, FF_Order_Meta::RECIPIENT_TYPE, 'bireysel');
        $is_kurumsal = $type === 'kurumsal';
        $is_mukellef = FF_Order_Meta::get($order, FF_Order_Meta::EFATURA_MUKELLEFI) === 'yes';

        $doc_type = $is_kurumsal && $is_mukellef ? 'EFATURA' : 'EARSIV';
        $invoice_type_code = $refund ? 'IADE' : 'SATIS';

        $canonical = [
            'company_id'        => 0, // backend resolves from auth context
            'document_type'     => $doc_type,
            'invoice_type_code' => $invoice_type_code,
            'currency'          => $order->get_currency() ?: 'TRY',
            'issue_date'        => gmdate('Y-m-d\TH:i:s\Z'),
            'issuer'            => self::issuer_party(),
            'recipient'         => self::recipient_party($order, $is_kurumsal),
            'lines'             => self::lines($order, $refund),
            'totals'            => self::totals($order, $refund),
            'note'              => sprintf(
                /* translators: %1$s = store name, %2$d = order id */
                __('%1$s — Sipariş #%2$d', 'finansfatura-woo'),
                get_bloginfo('name'),
                $order->get_id()
            ),
        ];

        return [
            'document_type' => $doc_type,
            'canonical'     => $canonical,
        ];
    }

    public static function idempotency_key(WC_Order $order, ?WC_Order_Refund $refund = null): string
    {
        $site = substr(sha1(home_url()), 0, 8);
        $refund_part = $refund ? (int) $refund->get_id() : 0;
        return sprintf('wc_%s_%d_%d', $site, $order->get_id(), $refund_part);
    }

    private static function issuer_party(): array
    {
        return [
            'vkn_or_tckn' => FF_Settings::get('issuer_vkn'),
            'title'       => FF_Settings::get('issuer_title'),
            'address'     => FF_Settings::get('issuer_address'),
            'tax_office'  => FF_Settings::get('issuer_tax_office'),
            'email'       => FF_Settings::get('issuer_email'),
            'phone'       => FF_Settings::get('issuer_phone'),
        ];
    }

    private static function recipient_party(WC_Order $order, bool $is_kurumsal): array
    {
        $title = $is_kurumsal && $order->get_billing_company()
            ? $order->get_billing_company()
            : trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());

        $address = implode(' ', array_filter([
            $order->get_billing_address_1(),
            $order->get_billing_address_2(),
            $order->get_billing_city(),
            $order->get_billing_state(),
            $order->get_billing_postcode(),
            $order->get_billing_country(),
        ]));

        $vkn_or_tckn = $is_kurumsal
            ? FF_Order_Meta::get($order, FF_Order_Meta::VKN)
            : FF_Order_Meta::get($order, FF_Order_Meta::TCKN);

        return [
            'vkn_or_tckn' => $vkn_or_tckn,
            'title'       => $title,
            'address'     => $address,
            'tax_office'  => FF_Order_Meta::get($order, FF_Order_Meta::TAX_OFFICE),
            'email'       => $order->get_billing_email(),
            'phone'       => $order->get_billing_phone(),
        ];
    }

    private static function lines(WC_Order $order, ?WC_Order_Refund $refund): array
    {
        $source = $refund ? $refund : $order;
        $lines = [];
        foreach ($source->get_items() as $item) {
            /** @var WC_Order_Item_Product $item */
            $qty = abs((float) $item->get_quantity());
            if ($qty <= 0) {
                continue;
            }
            $subtotal = abs((float) $item->get_subtotal());
            $tax      = abs((float) $item->get_subtotal_tax());
            $unit_price = $qty > 0 ? round($subtotal / $qty, 4) : 0.0;
            $vat_rate = $subtotal > 0 ? round($tax / $subtotal, 4) : 0.0;

            $product = $item->get_product();
            $sku = $product ? (string) $product->get_sku() : '';

            $lines[] = [
                'title'        => (string) $item->get_name(),
                'product_code' => $sku,
                'quantity'     => $qty,
                'unit_price'   => $unit_price,
                'unit_code'    => 'C62',
                'vat_rate'     => $vat_rate,
            ];
        }
        return $lines;
    }

    private static function totals(WC_Order $order, ?WC_Order_Refund $refund): array
    {
        $source = $refund ? $refund : $order;
        $subtotal_excl_vat = 0.0;
        $vat_total = 0.0;
        foreach ($source->get_items() as $item) {
            $subtotal_excl_vat += abs((float) $item->get_subtotal());
            $vat_total += abs((float) $item->get_subtotal_tax());
        }
        $grand_total = abs((float) $source->get_total());
        $discount_total = abs((float) $order->get_total_discount());

        return [
            'subtotal_excl_vat' => round($subtotal_excl_vat, 2),
            'vat_total'         => round($vat_total, 2),
            'discount_total'    => round($discount_total, 2),
            'grand_total'       => round($grand_total, 2),
        ];
    }
}
