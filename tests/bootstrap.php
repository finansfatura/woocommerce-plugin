<?php
// Lightweight bootstrap for unit tests. Brain Monkey stubs WP functions; we
// also load the constants the plugin would normally define in
// finansfatura-woo.php so test files can require the classes under test.

require_once __DIR__ . '/../vendor/autoload.php';

if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}
if (!defined('FF_PLUGIN_VERSION')) {
    define('FF_PLUGIN_VERSION', '0.1.0');
}
if (!defined('FF_OPTION_KEY')) {
    define('FF_OPTION_KEY', 'ff_settings');
}
if (!defined('FF_LOG_TABLE')) {
    define('FF_LOG_TABLE', 'ff_invoice_log');
}

// Settings helper used by the mapper. The mapper imports FF_Settings statically;
// the test installs a stub when needed.
require_once __DIR__ . '/stubs/wc-classes.php';
require_once __DIR__ . '/stubs/ff-settings-stub.php';
require_once __DIR__ . '/../includes/class-ff-order-meta.php';
require_once __DIR__ . '/../includes/class-ff-invoice-mapper.php';
