<?php
/**
 * Plugin Name:       Finansfatura for WooCommerce
 * Plugin URI:        https://finansfatura.com/woocommerce
 * Description:       Issues Turkish e-Arşiv / e-Fatura invoices through the Finansfatura API when WooCommerce orders complete or get refunded.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Finansfatura
 * License:           GPL-2.0-or-later
 * Text Domain:       finansfatura-woo
 */

if (!defined('ABSPATH')) {
    exit;
}

define('FF_PLUGIN_VERSION', '0.1.0');
define('FF_PLUGIN_FILE', __FILE__);
define('FF_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FF_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FF_OPTION_KEY', 'ff_settings');
define('FF_LOG_TABLE', 'ff_invoice_log');

spl_autoload_register(function ($class) {
    if (strpos($class, 'FF_') !== 0) {
        return;
    }
    $file = FF_PLUGIN_DIR . 'includes/class-' . str_replace('_', '-', strtolower($class)) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

register_activation_hook(__FILE__, ['FF_Activator', 'activate']);
register_deactivation_hook(__FILE__, ['FF_Activator', 'deactivate']);

// HPOS (custom order tables) compatibility — WooCommerce 9+ requires this
// declaration before init or it will flag the plugin in admin.
add_action('before_woocommerce_init', function () {
    if (class_exists('\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    load_plugin_textdomain('finansfatura-woo', false, dirname(plugin_basename(__FILE__)) . '/languages');

    FF_Settings::boot();
    FF_Checkout_Fields::boot();
    FF_Order_Hooks::boot();
});
