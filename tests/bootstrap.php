<?php
/**
 * PHPUnit bootstrap file for Handmade Web Event Manager Plugin
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

require_once __DIR__ . '/../vendor/antecedent/patchwork/Patchwork.php';

// Load WordPress class stubs
require_once __DIR__ . '/stubs/class-wp-stubs.php';

// Define WordPress constants if not already defined
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__, 5) . '/');
}

if (!defined('WP_DEBUG')) {
    define('WP_DEBUG', true);
}

if (!defined('HOUR_IN_SECONDS')) {
    define('HOUR_IN_SECONDS', 3600);
}

// Mock WordPress functions that are commonly used
\Brain\Monkey\Functions\when('wp_parse_args')->alias(function($args, $defaults = []) {
    return array_merge((array) $defaults, (array) $args);
});

// Mock other common WordPress functions
\Brain\Monkey\Functions\when('get_option')->justReturn(false);
\Brain\Monkey\Functions\when('update_option')->justReturn(true);

require_once __DIR__ . '/stubs/wp-filters-stubs.php';

// Set up WordPress-like environment for testing
function hexToRgb($hex) {
    // Remove # if present
    $hex = str_replace('#', '', $hex);
    
    // Convert hex to RGB
    if (strlen($hex) == 6) {
        list($r, $g, $b) = array_map('hexdec', str_split($hex, 2));
        return "rgb($r, $g, $b)";
    }
    
    return 'rgb(0, 0, 0)'; // Default fallback
}