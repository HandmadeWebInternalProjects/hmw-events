<?php
/**
 * WP-CLI command for repairing foreign keys in the HMWEvents plugin.
 *
 * Usage:
 *   wp cms repair-foreign-keys
 *
 * @package HMWEvents
 */

defined('ABSPATH') || die();

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

/**
 * Repair and rebuild all foreign keys for the HMWEvents plugin.
 *
 * Drops all existing foreign keys, cleans up orphaned data across all
 * child tables, and recreates all foreign key constraints. Useful after
 * data migration or when foreign key issues are encountered.
 *
 * ## EXAMPLES
 *
 *     wp cms repair-foreign-keys
 *
 * @when after_wp_load
 */
WP_CLI::add_command('hmwevents repair-foreign-keys', function () {
    WP_CLI::log('Starting foreign key repair for HMWEvents...');

    $result = hmwevents_repair_and_rebuild_foreign_keys();

    if ($result) {
        WP_CLI::success('Foreign keys repaired successfully.');
    } else {
        WP_CLI::error('Foreign key repair failed. Check error log for details.');
    }
});
