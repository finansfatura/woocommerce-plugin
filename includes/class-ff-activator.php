<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Activation / deactivation lifecycle. Bootstraps the custom log table and
 * seeds the options array with safe defaults so the settings screen never
 * shows an empty form on first paint.
 */
class FF_Activator
{
    public static function activate(): void
    {
        self::create_log_table();
        self::seed_defaults();
    }

    public static function deactivate(): void
    {
        // Keep settings + log table on deactivate. uninstall.php handles full
        // teardown only when the merchant opts in.
    }

    private static function create_log_table(): void
    {
        global $wpdb;
        $table = $wpdb->prefix . FF_LOG_TABLE;
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            order_id BIGINT UNSIGNED NOT NULL,
            refund_id BIGINT UNSIGNED NULL,
            idempotency_key VARCHAR(128) NOT NULL,
            request_payload LONGTEXT NULL,
            response_status SMALLINT NULL,
            response_body LONGTEXT NULL,
            error_message TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_order (order_id),
            INDEX idx_idempotency (idempotency_key)
        ) {$charset};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    private static function seed_defaults(): void
    {
        $existing = get_option(FF_OPTION_KEY, []);
        // No issuer fields: the seller identity comes from the company profile
        // on the Finansfatura side, and sending our own would only let the two
        // drift apart.
        $defaults = [
            'auth_mode'                 => 'api_key',
            'api_key'                   => '',
            'oauth_client_id'           => '',
            'oauth_client_secret'       => '',
            // Literal, not Client::DEFAULT_BASE_URL: activation runs before
            // our plugins_loaded hook pulls in the Composer autoloader.
            'base_url'                  => 'https://api.finansfatura.com',
            'auto_sale'                 => 'yes',
            'auto_invoice'              => 'yes',
            'auto_issue_iade_on_refund' => 'yes',
        ];
        update_option(FF_OPTION_KEY, wp_parse_args($existing, $defaults));
    }
}
