<?php
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('ff_settings');

global $wpdb;
$table = $wpdb->prefix . 'ff_invoice_log';
$wpdb->query("DROP TABLE IF EXISTS {$table}");
