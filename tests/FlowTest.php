<?php

namespace Finansfatura\Woo\Tests;

use PHPUnit\Framework\TestCase;
use FF_Api_Client;
use FF_Order_Hooks;
use FF_Order_Meta;
use WC_Order;
use WC_Order_Item_Product;
use WC_Order_Refund;
use WP_Error;

/**
 * The order of operations is the whole point of this plugin: the sale is
 * pushed first and the invoice is issued on top of it. These tests fail if
 * anything ever reverses that, skips it, or repeats it.
 */
class FlowTest extends TestCase
{
    protected function setUp(): void
    {
        FF_Api_Client::reset();
    }

    private function order(): WC_Order
    {
        $item = new WC_Order_Item_Product();
        $item->id = 1;
        $item->name = 'Ürün';
        $item->quantity = 1;
        $item->subtotal = 100;
        $item->subtotal_tax = 20;
        $item->total = 100;
        $item->total_tax = 20;

        $order = new WC_Order();
        $order->id = 55;
        $order->total = 120.0;
        $order->items = [$item];
        FF_Order_Meta::set($order, FF_Order_Meta::RECIPIENT_TYPE, 'bireysel');
        FF_Order_Meta::set($order, FF_Order_Meta::TCKN, '12345678901');
        return $order;
    }

    public function test_sale_is_created_before_the_invoice_and_carries_its_id(): void
    {
        FF_Api_Client::$responses = [
            'create_sale' => [['transaction_id' => 'txn-9', 'imported' => true]],
            'issue'       => [['invoice_id' => 'inv-1', 'ettn' => 'ETTN-1', 'status' => 'SENT']],
        ];
        $order = $this->order();

        FF_Order_Hooks::issue_invoice($order, null);

        $methods = array_column(FF_Api_Client::$calls, 'method');
        self::assertSame(['create_sale', 'issue'], $methods);

        $payload = FF_Api_Client::calls_to('issue')[0]['args'][0];
        self::assertSame('txn-9', $payload['transaction_header_id']);
        self::assertSame('txn-9', FF_Order_Meta::get($order, FF_Order_Meta::TRANSACTION_ID));
        self::assertSame('inv-1', FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_ID));
    }

    public function test_a_second_pass_does_not_create_a_second_sale(): void
    {
        FF_Api_Client::$responses = [
            'create_sale' => [['transaction_id' => 'txn-9']],
            'issue'       => [['invoice_id' => 'inv-1'], ['invoice_id' => 'inv-1']],
        ];
        $order = $this->order();

        FF_Order_Hooks::issue_invoice($order, null);
        FF_Order_Hooks::issue_invoice($order, null);

        self::assertCount(1, FF_Api_Client::calls_to('create_sale'));
        self::assertCount(2, FF_Api_Client::calls_to('issue'));
    }

    public function test_a_failed_sale_stops_the_invoice(): void
    {
        FF_Api_Client::$responses = [
            'create_sale' => [new WP_Error('ff_api_error', 'sunucu hatası', ['retryable' => true])],
        ];
        $order = $this->order();

        $result = FF_Order_Hooks::issue_invoice($order, null);

        self::assertInstanceOf(WP_Error::class, $result);
        // Faturayı satışsız kesmek, satışsız bir belge bırakır: ciroya ve
        // stoğa hiç girmez.
        self::assertSame([], FF_Api_Client::calls_to('issue'));
        self::assertSame('sunucu hatası', FF_Order_Meta::get($order, FF_Order_Meta::LAST_ERROR));
    }

    public function test_refund_issues_iade_without_touching_the_sale_link(): void
    {
        FF_Api_Client::$responses = [
            'create_sale' => [['transaction_id' => 'txn-9']],
            'issue'       => [['invoice_id' => 'inv-2']],
        ];
        $order = $this->order();
        $refund = new WC_Order_Refund();
        $refund->id = 700;
        $refund->total = -120.0;
        $refund->items = $order->items;

        FF_Order_Hooks::issue_invoice($order, $refund);

        $payload = FF_Api_Client::calls_to('issue')[0]['args'][0];
        self::assertSame('IADE', $payload['canonical']['InvoiceTypeCode']);
        self::assertArrayNotHasKey('transaction_header_id', $payload);
    }

    public function test_status_refresh_fills_in_the_invoice_number(): void
    {
        FF_Api_Client::$responses = [
            'order_status' => [[
                'statuses' => [
                    ['external_id' => 'baska-siparis', 'invoice_number' => 'YANLIS'],
                    ['external_id' => \FF_Invoice_Mapper::external_id($this->order()),
                     'invoice_status' => 'SENT', 'invoice_number' => 'FA02026000000006'],
                ],
            ]],
        ];
        $order = $this->order();

        FF_Order_Hooks::refresh_status($order);

        // Gönderilmemiş id'ler cevapta hiç yok; sıraya güvenmek yanlış satırı yazar.
        self::assertSame('FA02026000000006', FF_Order_Meta::get($order, FF_Order_Meta::INVOICE_NUMBER));
        self::assertSame('SENT', FF_Order_Meta::get($order, FF_Order_Meta::STATUS));
    }
}
