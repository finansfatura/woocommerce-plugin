<?php
// WordPress + plugin collaborators, stubbed just far enough to run
// FF_Order_Hooks::issue_for_order end to end.
//
// FF_Api_Client, FF_Settings and FF_Logger are declared HERE, before the
// autoloader ever sees those names, so the hooks under test talk to these
// instead of the real ones. No seam in production code, no HTTP in tests.

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '', private $data = null) {}
        public function get_error_code(): string { return $this->code; }
        public function get_error_message(): string { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing): bool { return $thing instanceof WP_Error; }
}
if (!function_exists('wp_json_encode')) {
    function wp_json_encode($data) { return json_encode($data); }
}
if (!function_exists('is_admin')) {
    function is_admin(): bool { return false; }
}
if (!function_exists('add_action')) {
    function add_action(...$args): void {}
}
if (!function_exists('function_exists_as_schedule')) {
    // as_schedule_single_action is intentionally NOT defined: the retry path
    // checks for it and stays quiet when Action Scheduler is absent.
}

/** Scripted API double. Each method returns the next queued response. */
class FF_Api_Client
{
    /** @var array<string,array<int,mixed>> */
    public static array $responses = [];
    /** @var array<int,array{method:string,args:array}> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$responses = [];
        self::$calls = [];
    }

    public function create_sale(array $order) { return self::pop('create_sale', [$order]); }
    public function issue(array $payload, string $key) { return self::pop('issue', [$payload, $key]); }
    public function order_status(array $ids) { return self::pop('order_status', [$ids]); }

    private static function pop(string $method, array $args)
    {
        self::$calls[] = ['method' => $method, 'args' => $args];
        $queue = self::$responses[$method] ?? [];
        $next = array_shift($queue);
        self::$responses[$method] = $queue;
        return $next ?? [];
    }

    /** @return array<int,array{method:string,args:array}> */
    public static function calls_to(string $method): array
    {
        return array_values(array_filter(self::$calls, fn($c) => $c['method'] === $method));
    }
}

class FF_Settings
{
    /** @var array<string,string> */
    public static array $values = [
        'auto_sale'                 => 'yes',
        'auto_invoice'              => 'yes',
        'auto_issue_iade_on_refund' => 'yes',
    ];

    public static function get(string $key, $default = ''): string
    {
        return isset(self::$values[$key]) && self::$values[$key] !== '' ? (string) self::$values[$key] : (string) $default;
    }

    public static function is_enabled(string $key): bool
    {
        return self::get($key) === 'yes';
    }
}

class FF_Logger
{
    /** @var array<int,array<string,mixed>> */
    public static array $entries = [];

    public static function write(array $entry): void { self::$entries[] = $entry; }
}
