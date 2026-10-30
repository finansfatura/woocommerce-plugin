<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('ff_settings');
delete_option('ff_oauth_tokens');

global $wpdb;
$table = $wpdb->prefix . 'ff_invoice_log';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name comes from the DB prefix and a fixed constant; dropping our own log table on uninstall.
$wpdb->query('DROP TABLE IF EXISTS `' . esc_sql($table) . '`');
