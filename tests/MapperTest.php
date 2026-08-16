<?php

namespace Finansfatura\Woo\Tests;

use PHPUnit\Framework\TestCase;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;
use FF_Invoice_Mapper;
use FF_Order_Meta;

/**
 * The two payloads use opposite conventions — the sale is KDV-inclusive with a
 * percentage rate, the invoice KDV-exclusive with a ratio. Every assertion
 * here exists to catch them being swapped.
 */
class MapperTest extends TestCase
{
    private function item(string $name, string $sku, float $qty, float $subtotal, float $tax, ?float $total = null): WC_Order_Item_Product
    {
        $item = new WC_Order_Item_Product();
        $item->id = 1;
        $item->name = $name;
        $item->quantity = $qty;
        $item->subtotal = $subtotal;
        $item->subtotal_tax = $tax;
        // Undiscounted line: total == subtotal.
        $item->total = $total ?? $subtotal;
        $item->total_tax = $total === null ? $tax : round($total * ($tax / $subtotal), 2);
        $item->product = new class($sku) {
            public function __construct(public string $sku) {}
            public function get_sku(): string { return $this->sku; }
        };
        return $item;
    }

    private function order(int $id = 101): WC_Order
    {
        $order = new WC_Order();
        $order->id = $id;
        $order->billing_first_name = 'Ali';
        $order->billing_last_name = 'Veli';
        $order->billing_email = 'ali@example.test';
        $order->total = 120.0;
        FF_Order_Meta::set($order, FF_Order_Meta::RECIPIENT_TYPE, 'bireysel');
        FF_Order_Meta::set($order, FF_Order_Meta::TCKN, '12345678901');
        $order->items = [$this->item('Ürün', 'SKU-1', 1, 100, 20)];
        return $order;
    }

    public function test_sale_is_vat_inclusive_with_percent_rate(): void
    {
        $sale = FF_Invoice_Mapper::sale($this->order());

        self::assertSame('WOOCOMMERCE', $sale['provider']);
        self::assertSame('PAID', $sale['payment_status']);
        self::assertSame(120.0, $sale['lines'][0]['unit_price']);   // KDV dahil
        self::assertSame(120.0, $sale['lines'][0]['total_price']);
        self::assertSame(20.0, $sale['lines'][0]['vat_rate']);      // yüzde, oran değil
        self::assertSame('12345678901', $sale['buyer']['tckn']);
        self::assertSame('', $sale['buyer']['tax_number']);
    }

    public function test_sale_carries_the_coupon_discount(): void
    {
        $order = $this->order();
        // 100 + 20 KDV listeden, 10 TL indirimle 90 + 18 ödendi.
        $order->items = [$this->item('Ürün', 'SKU-1', 1, 100, 20, 90)];

        $sale = FF_Invoice_Mapper::sale($order);

        self::assertSame(120.0, $sale['lines'][0]['total_price']);
        self::assertSame(12.0, $sale['lines'][0]['discount']);
        self::assertSame(20.0, $sale['lines'][0]['vat_rate']);
    }

    public function test_invoice_is_vat_exclusive_with_ratio_and_pascal_case(): void
    {
        $payload = FF_Invoice_Mapper::invoice($this->order(), null, 'txn-1');

        self::assertSame('EARSIV', $payload['document_type']);
        self::assertSame('txn-1', $payload['transaction_header_id']);
        // Inside `canonical` the API only reads PascalCase; snake_case is
        // silently ignored, which is why the package builds this.
        $canon = $payload['canonical'];
        self::assertSame('SATIS', $canon['InvoiceTypeCode']);
        self::assertSame('12345678901', $canon['Recipient']['VKNorTCKN']);
        self::assertSame(100.0, $canon['Lines'][0]['UnitPrice']);   // KDV hariç
        self::assertSame(0.2, $canon['Lines'][0]['VatRate']);       // oran, yüzde değil
        self::assertSame(100.0, $canon['Totals']['SubtotalExclVAT']);
        self::assertSame(20.0, $canon['Totals']['VatTotal']);
        self::assertSame(120.0, $canon['Totals']['GrandTotal']);
    }

    public function test_shipping_is_invoiced_too(): void
    {
        $order = $this->order();
        $shipping = new WC_Order_Item_Shipping();
        $shipping->id = 2;
        $shipping->name = 'Kargo';
        $shipping->total = 50.0;
        $shipping->total_tax = 10.0;
        $order->items[] = $shipping;

        $canon = FF_Invoice_Mapper::invoice($order, null, 'txn-1')['canonical'];

        // Kargo satırı düşerse fatura, müşterinin ödediğinden az olur.
        self::assertCount(2, $canon['Lines']);
        self::assertSame(50.0, $canon['Lines'][1]['UnitPrice']);
        self::assertSame(180.0, $canon['Totals']['GrandTotal']);
    }

    public function test_refund_yields_iade_with_positive_amounts(): void
    {
        $order = $this->order(303);

        $refund = new WC_Order_Refund();
        $refund->id = 9001;
        $refund->total = -60.0;
        $refund->items = [$this->item('Ürün', 'SKU-1', -1, -50, -10)];

        $canon = FF_Invoice_Mapper::invoice($order, $refund, '')['canonical'];

        self::assertSame('IADE', $canon['InvoiceTypeCode']);
        self::assertSame(1.0, $canon['Lines'][0]['Quantity']);
        self::assertSame(60.0, $canon['Totals']['GrandTotal']);
    }

    public function test_refund_is_not_attached_to_the_sale(): void
    {
        $payload = FF_Invoice_Mapper::invoice($this->order(), new WC_Order_Refund(), '');

        // Satışa bağlanırsa aynı satış iki kez sayılır.
        self::assertArrayNotHasKey('transaction_header_id', $payload);
    }

    public function test_ids_are_deterministic_and_store_scoped(): void
    {
        $order = $this->order(42);

        self::assertSame(FF_Invoice_Mapper::external_id($order), FF_Invoice_Mapper::external_id($order));
        self::assertStringStartsWith('wc_', FF_Invoice_Mapper::external_id($order));
        self::assertStringEndsWith('_42', FF_Invoice_Mapper::external_id($order));

        $refund = new WC_Order_Refund();
        $refund->id = 9001;

        $key = FF_Invoice_Mapper::idempotency_key($order, null);
        self::assertSame($key, FF_Invoice_Mapper::idempotency_key($order, null));
        // İade ayrı bir belge: satışın anahtarını paylaşırsa API iade yerine
        // satış faturasını tekrar döner.
        self::assertNotSame($key, FF_Invoice_Mapper::idempotency_key($order, $refund));
    }
}
