<?php
/**
 * Uninstall LSA Adbuilder plugin.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Include the install file for the uninstall function
require_once plugin_dir_path(__FILE__) . 'includes/install.php';

// Run the uninstall function
lsa_adbuilder_uninstall();
