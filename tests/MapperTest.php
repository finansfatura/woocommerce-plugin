<?php

namespace Finansfatura\Woo\Tests;

use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use FF_Invoice_Mapper;
use FF_Order_Meta;

class MapperTest extends TestCase
{
    private function build_item(string $name, string $sku, float $qty, float $subtotal, float $tax): WC_Order_Item_Product
    {
        $item = new WC_Order_Item_Product();
        $item->name = $name;
        $item->quantity = $qty;
        $item->subtotal = $subtotal;
        $item->subtotal_tax = $tax;
        $item->product = new class($sku) {
            public string $sku;
            public function __construct(string $sku) { $this->sku = $sku; }
            public function get_sku(): string { return $this->sku; }
        };
        return $item;
    }

    public function test_bireysel_satis_yields_earsiv(): void
    {
        $order = new WC_Order();
        $order->id = 101;
        $order->billing_first_name = 'Ali';
        $order->billing_last_name = 'Veli';
        $order->billing_email = 'ali@example.test';
        $order->total = 120.0;
        FF_Order_Meta::set($order, FF_Order_Meta::RECIPIENT_TYPE, 'bireysel');
        FF_Order_Meta::set($order, FF_Order_Meta::TCKN, '12345678901');
        $order->items = [$this->build_item('Ürün', 'SKU-1', 1, 100, 20)];

        $payload = FF_Invoice_Mapper::from_order($order, null);

        self::assertSame('EARSIV', $payload['document_type']);
        self::assertSame('SATIS', $payload['canonical']['invoice_type_code']);
        self::assertSame('12345678901', $payload['canonical']['recipient']['vkn_or_tckn']);
        self::assertSame(100.0, $payload['canonical']['lines'][0]['unit_price']);
        self::assertSame(0.2, $payload['canonical']['lines'][0]['vat_rate']);
        self::assertSame(120.0, $payload['canonical']['totals']['grand_total']);
    }

    public function test_kurumsal_mukellef_yields_efatura(): void
    {
        $order = new WC_Order();
        $order->id = 202;
        $order->billing_company = 'Beta Ltd.';
        $order->total = 1180.0;
        FF_Order_Meta::set($order, FF_Order_Meta::RECIPIENT_TYPE, 'kurumsal');
        FF_Order_Meta::set($order, FF_Order_Meta::VKN, '9876543210');
        FF_Order_Meta::set($order, FF_Order_Meta::TAX_OFFICE, 'Kadıköy');
        FF_Order_Meta::set($order, FF_Order_Meta::EFATURA_MUKELLEFI, 'yes');
        $order->items = [$this->build_item('Hizmet', 'SVC-1', 1, 1000, 180)];

        $payload = FF_Invoice_Mapper::from_order($order, null);

        self::assertSame('EFATURA', $payload['document_type']);
        self::assertSame('9876543210', $payload['canonical']['recipient']['vkn_or_tckn']);
        self::assertSame('Beta Ltd.', $payload['canonical']['recipient']['title']);
        self::assertSame('Kadıköy', $payload['canonical']['recipient']['tax_office']);
    }

    public function test_refund_yields_iade_with_positive_amounts(): void
    {
        $order = new WC_Order();
        $order->id = 303;
        FF_Order_Meta::set($order, FF_Order_Meta::RECIPIENT_TYPE, 'bireysel');
        FF_Order_Meta::set($order, FF_Order_Meta::TCKN, '12345678901');

        $refund = new WC_Order_Refund();
        $refund->id = 9001;
        $refund->total = -60.0;
        $refund->items = [$this->build_item('Ürün', 'SKU-1', -1, -50, -10)];

        $payload = FF_Invoice_Mapper::from_order($order, $refund);

        self::assertSame('IADE', $payload['canonical']['invoice_type_code']);
        self::assertSame(1.0, $payload['canonical']['lines'][0]['quantity']);
        self::assertSame(60.0, $payload['canonical']['totals']['grand_total']);
    }

    public function test_idempotency_key_is_deterministic(): void
    {
        $order = new WC_Order();
        $order->id = 42;
        $a = FF_Invoice_Mapper::idempotency_key($order, null);
        $b = FF_Invoice_Mapper::idempotency_key($order, null);
        self::assertSame($a, $b);
        self::assertStringStartsWith('wc_', $a);
    }
}
