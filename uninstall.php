<?php
/**
 * Uninstall HMWEvents plugin.
 *
 * @package HMWEvents
 * @since 2.0.0
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/install.php';

hmwevents_uninstall();
