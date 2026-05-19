<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DB-backed log for every issuance attempt. Reading the table is cheap and
 * gives merchants something to paste into support tickets when they hit
 * upstream failures.
 */
class FF_Logger
{
    public static function write(array $entry): void
    {
        global $wpdb;
        $table = $wpdb->prefix . FF_LOG_TABLE;
        $wpdb->insert(
            $table,
            [
                'order_id'        => isset($entry['order_id']) ? (int) $entry['order_id'] : 0,
                'refund_id'       => isset($entry['refund_id']) ? (int) $entry['refund_id'] : null,
                'idempotency_key' => substr((string) ($entry['idempotency_key'] ?? ''), 0, 128),
                'request_payload' => (string) ($entry['payload'] ?? ''),
                'response_status' => isset($entry['status']) ? (int) $entry['status'] : null,
                'response_body'   => (string) ($entry['body'] ?? ''),
                'error_message'   => (string) ($entry['error'] ?? ''),
            ]
        );
        if (!empty($entry['error'])) {
            error_log('[finansfatura-woo] ' . $entry['error']);
        }
    }
}
