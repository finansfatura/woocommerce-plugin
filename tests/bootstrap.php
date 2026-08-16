<?php
// Lightweight bootstrap for unit tests: the WooCommerce shapes the mapper
// touches are stubbed, the constants the plugin would define in
// finansfatura-woo.php are declared here, and the real
// finansfatura/finansfatura package is loaded from vendor — the payload casing
// is its job and the tests assert against the real builder.

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('FF_PLUGIN_VERSION')) {
    define('FF_PLUGIN_VERSION', '1.0.0');
}
if (!defined('FF_OPTION_KEY')) {
    define('FF_OPTION_KEY', 'ff_settings');
}
if (!defined('FF_LOG_TABLE')) {
    define('FF_LOG_TABLE', 'ff_invoice_log');
}

require_once __DIR__ . '/stubs/wc-classes.php';
require_once __DIR__ . '/stubs/wp-stubs.php';
require_once __DIR__ . '/../includes/class-ff-order-meta.php';
require_once __DIR__ . '/../includes/class-ff-invoice-mapper.php';
require_once __DIR__ . '/../includes/class-ff-order-hooks.php';
