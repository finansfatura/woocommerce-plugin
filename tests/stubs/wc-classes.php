<?php
// Minimal stand-ins for the WooCommerce data shapes the mapper touches.
// Real WC_Order is too heavy to load standalone — these stubs implement just
// the surface FF_Invoice_Mapper / FF_Order_Meta hit.

if (!class_exists('WC_Order_Item')) {
    class WC_Order_Item
    {
        public int $id = 0;
        public string $name = '';
        public float $total = 0.0;      // KDV excl, after discount
        public float $total_tax = 0.0;

        public function get_id(): int { return $this->id; }
        public function get_name(): string { return $this->name; }
        public function get_total(): float { return $this->total; }
        public function get_total_tax(): float { return $this->total_tax; }
    }
}

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product extends WC_Order_Item
    {
        public float $quantity = 0.0;
        public float $subtotal = 0.0;      // KDV excl, before discount
        public float $subtotal_tax = 0.0;
        public ?object $product = null;

        public function get_quantity(): float { return $this->quantity; }
        public function get_subtotal(): float { return $this->subtotal; }
        public function get_subtotal_tax(): float { return $this->subtotal_tax; }
        public function get_product() { return $this->product; }
    }
}

if (!class_exists('WC_Order_Item_Shipping')) {
    class WC_Order_Item_Shipping extends WC_Order_Item {}
}

if (!class_exists('WC_Abstract_Order')) {
    class WC_Abstract_Order
    {
        public int $id = 0;
        public array $meta = [];
        /** @var array<int,WC_Order_Item> */
        public array $items = [];
        public float $total = 0.0;
        public float $total_tax = 0.0;
        public float $discount = 0.0;
        public string $currency = 'TRY';

        /** @var array<int,string> */
        public array $notes = [];

        public function get_id(): int { return $this->id; }
        public function get_meta(string $key) { return $this->meta[$key] ?? ''; }
        public function update_meta_data(string $key, $value): void { $this->meta[$key] = $value; }
        public function save(): void {}
        public function add_order_note(string $note): void { $this->notes[] = $note; }
        /** @param array<int,string>|string $types */
        public function get_items($types = 'line_item'): array { return $this->items; }
        public function get_total(): float { return $this->total; }
        public function get_total_tax(): float { return $this->total_tax; }
        public function get_total_discount(): float { return $this->discount; }
        public function get_currency(): string { return $this->currency; }
    }
}

if (!class_exists('WC_Order')) {
    class WC_Order extends WC_Abstract_Order
    {
        public string $billing_first_name = '';
        public string $billing_last_name = '';
        public string $billing_company = '';
        public string $billing_address_1 = '';
        public string $billing_address_2 = '';
        public string $billing_city = '';
        public string $billing_state = '';
        public string $billing_postcode = '';
        public string $billing_country = 'TR';
        public string $billing_email = '';
        public string $billing_phone = '';
        public string $status = 'completed';
        public bool $paid = true;

        public function get_billing_first_name(): string { return $this->billing_first_name; }
        public function get_billing_last_name(): string { return $this->billing_last_name; }
        public function get_billing_company(): string { return $this->billing_company; }
        public function get_billing_address_1(): string { return $this->billing_address_1; }
        public function get_billing_address_2(): string { return $this->billing_address_2; }
        public function get_billing_city(): string { return $this->billing_city; }
        public function get_billing_state(): string { return $this->billing_state; }
        public function get_billing_postcode(): string { return $this->billing_postcode; }
        public function get_billing_country(): string { return $this->billing_country; }
        public function get_billing_email(): string { return $this->billing_email; }
        public function get_billing_phone(): string { return $this->billing_phone; }
        public function get_order_number(): string { return (string) $this->id; }
        public function get_status(): string { return $this->status; }
        public function is_paid(): bool { return $this->paid; }
        public function get_date_created() { return null; }
    }
}

if (!class_exists('WC_Order_Refund')) {
    class WC_Order_Refund extends WC_Abstract_Order {}
}

if (!function_exists('home_url')) {
    function home_url(): string { return 'https://example.test'; }
}
if (!function_exists('get_bloginfo')) {
    function get_bloginfo(string $key): string { return 'Test Store'; }
}
if (!function_exists('__')) {
    function __(string $s, string $d = ''): string { return $s; }
}
