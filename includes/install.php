<?php

/**
 * Installation functions for HMWEvents plugin (Rebuild v2).
 *
 * Handles database table creation and foreign key management for the
 * new hmwevents_* table set only. Old educator_* tables are treated
 * as legacy and are not created or managed here.
 *
 * Features:
 * - Creates only hmwevents_* tables
 * - Dynamic foreign key recreation when table prefixes change
 * - Graceful handling of hosting environments that don't support foreign keys
 * - Option to disable foreign keys via HMWEvents_DISABLE_FOREIGN_KEYS constant
 * - Automatic cleanup and recreation during migrations
 *
 * To disable foreign keys, add this to wp-config.php:
 * define('HMWEvents_DISABLE_FOREIGN_KEYS', true);
 *
 * @package HMWEvents
 * @since 2.0.0
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Install plugin - create hmwevents_* database tables.
 *
 * @since 2.0.0
 */
function hmwevents_install()
{
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    require_once __DIR__ . '/schema.php';

    $schemas = hmwevents_get_schema();
    $prefix = $wpdb->prefix . 'hmwevents_';

    // TIER 1 — Independent tables (no FK deps on other hmwevents tables)
    dbDelta($schemas['event_recurrence']);
    dbDelta($schemas['booking_groups']);
    dbDelta($schemas['event_availability']);
    dbDelta($schemas['event_attendance_options']);
    dbDelta($schemas['event_templates']);
    dbDelta($schemas['saved_report_filters']);
    dbDelta($schemas['email_queue']);
    dbDelta($schemas['email_templates']);
    dbDelta($schemas['private_registration_tokens']);

    // TIER 2 — Tables referencing booking_groups
    dbDelta($schemas['bookings']);
    dbDelta($schemas['payment_transactions']);

    // TIER 3 — Tables referencing bookings
    dbDelta($schemas['booking_details']);
    dbDelta($schemas['booking_meta']);
    dbDelta($schemas['booking_history']);
    dbDelta($schemas['voucher_usage']);
    dbDelta($schemas['coupon_usage']);
    dbDelta($schemas['registration_documents']);

    // TIER 4 — Other dependent tables
    dbDelta($schemas['waitlist']);
    dbDelta($schemas['email_attachments']);

    // Add foreign keys separately since dbDelta doesn't handle them
    hmwevents_ensure_foreign_keys();

    if ($wpdb->last_error) {
        error_log('HMWEvents install error: ' . $wpdb->last_error);
        return new WP_Error('hmwevents_install_error', $wpdb->last_error, ['status' => 500]);
    }

    flush_rewrite_rules();
}

/**
 * Get list of all foreign key constraint names.
 *
 * @since 2.0.0
 * @return array List of foreign key constraint names
 */
function hmwevents_get_foreign_key_constraints()
{
    return [
        'fk_hmwevents_booking_groups_registrant',
        'fk_hmwevents_event_recurrence_event',
        'fk_hmwevents_event_availability_event',
        'fk_hmwevents_event_attendance_options_event',
        'fk_hmwevents_bookings_group',
        'fk_hmwevents_bookings_event',
        'fk_hmwevents_bookings_registrant',
        'fk_hmwevents_bookings_attendance_option',
        'fk_hmwevents_booking_details_booking',
        'fk_hmwevents_booking_meta_booking',
        'fk_hmwevents_booking_history_booking',
        'fk_hmwevents_voucher_usage_booking',
        'fk_hmwevents_coupon_usage_booking',
        'fk_hmwevents_coupon_usage_coupon',
        'fk_hmwevents_registration_documents_booking',
        'fk_hmwevents_payment_transactions_group',
        'fk_hmwevents_waitlist_event',
        'fk_hmwevents_waitlist_registrant',
        'fk_hmwevents_private_registration_tokens_event',
        'fk_hmwevents_email_attachments_queue',
    ];
}

/**
 * Drop all foreign keys (used before migration).
 *
 * @since 2.0.0
 */
function hmwevents_drop_foreign_keys()
{
    global $wpdb;

    $constraints = hmwevents_get_foreign_key_constraints();

    foreach ($constraints as $constraint) {
        $table = $wpdb->get_var($wpdb->prepare("
            SELECT TABLE_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = %s
            AND CONSTRAINT_NAME = %s
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ", DB_NAME, $constraint));

        if ($table) {
            $result = $wpdb->query("ALTER TABLE {$table} DROP FOREIGN KEY {$constraint}");
            if ($result === false) {
                error_log('HMWEvents: Error dropping foreign key ' . $constraint . ': ' . $wpdb->last_error);
            } else {
                error_log('HMWEvents: Dropped foreign key ' . $constraint . ' from table ' . $table);
            }
        }
    }
}

/**
 * Check and recreate foreign keys after migration.
 *
 * @since 2.0.0
 */
function hmwevents_ensure_foreign_keys()
{
    global $wpdb;

    $supports_fk = $wpdb->get_var("SELECT @@foreign_key_checks");
    if (!$supports_fk) {
        error_log('HMWEvents: Foreign keys not supported on this server');
        return false;
    }

    if (defined('HMWEvents_DISABLE_FOREIGN_KEYS') && HMWEvents_DISABLE_FOREIGN_KEYS) {
        error_log('HMWEvents: Foreign keys disabled via constant');
        return false;
    }

    $table_prefix = $wpdb->prefix;
    $stored_prefix = get_option('hmwevents_table_prefix', '');

    $should_recreate = false;

    if ($stored_prefix && $stored_prefix !== $table_prefix) {
        error_log('HMWEvents: Table prefix changed from ' . $stored_prefix . ' to ' . $table_prefix . ', dropping all foreign keys...');
        hmwevents_drop_foreign_keys();
        $should_recreate = true;
    }

    update_option('hmwevents_table_prefix', $table_prefix);

    if ($should_recreate) {
        error_log('HMWEvents: Recreating all foreign keys with new prefix...');
        hmwevents_add_foreign_keys();
        return;
    }

    $expected_constraints = hmwevents_get_foreign_key_constraints();
    $existing_count = 0;
    $missing_constraints = [];

    foreach ($expected_constraints as $constraint) {
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = %s
            AND CONSTRAINT_NAME = %s
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ", DB_NAME, $constraint));

        if ($exists) {
            $existing_count++;
        } else {
            $missing_constraints[] = $constraint;
        }
    }

    $total_expected = count($expected_constraints);

    if ($existing_count === 0) {
        error_log('HMWEvents: No foreign keys found, creating all foreign keys...');
        hmwevents_add_foreign_keys();
    } elseif ($existing_count < $total_expected) {
        error_log('HMWEvents: Only ' . $existing_count . '/' . $total_expected . ' foreign keys found. Missing: ' . implode(', ', $missing_constraints));
        hmwevents_drop_foreign_keys();
        hmwevents_add_foreign_keys();
    } else {
        error_log('HMWEvents: All ' . $total_expected . ' foreign keys are present and correct.');
    }
}

/**
 * Add foreign keys for all hmwevents_* tables.
 *
 * @since 2.0.0
 */
function hmwevents_add_foreign_keys()
{
    global $wpdb;
    $p = $wpdb->prefix . 'hmwevents_';

    $foreign_keys = [
        // TIER 1 — Ref wp_posts (CPT references)
        [
            'table' => "{$p}booking_groups",
            'constraint' => 'fk_hmwevents_booking_groups_registrant',
            'sql' => "ALTER TABLE {$p}booking_groups ADD CONSTRAINT fk_hmwevents_booking_groups_registrant FOREIGN KEY (registrant_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE bg FROM {$p}booking_groups bg LEFT JOIN {$wpdb->posts} p ON bg.registrant_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}event_recurrence",
            'constraint' => 'fk_hmwevents_event_recurrence_event',
            'sql' => "ALTER TABLE {$p}event_recurrence ADD CONSTRAINT fk_hmwevents_event_recurrence_event FOREIGN KEY (event_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE er FROM {$p}event_recurrence er LEFT JOIN {$wpdb->posts} p ON er.event_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}event_availability",
            'constraint' => 'fk_hmwevents_event_availability_event',
            'sql' => "ALTER TABLE {$p}event_availability ADD CONSTRAINT fk_hmwevents_event_availability_event FOREIGN KEY (event_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE ea FROM {$p}event_availability ea LEFT JOIN {$wpdb->posts} p ON ea.event_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}event_attendance_options",
            'constraint' => 'fk_hmwevents_event_attendance_options_event',
            'sql' => "ALTER TABLE {$p}event_attendance_options ADD CONSTRAINT fk_hmwevents_event_attendance_options_event FOREIGN KEY (event_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE eao FROM {$p}event_attendance_options eao LEFT JOIN {$wpdb->posts} p ON eao.event_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}private_registration_tokens",
            'constraint' => 'fk_hmwevents_private_registration_tokens_event',
            'sql' => "ALTER TABLE {$p}private_registration_tokens ADD CONSTRAINT fk_hmwevents_private_registration_tokens_event FOREIGN KEY (event_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE prt FROM {$p}private_registration_tokens prt LEFT JOIN {$wpdb->posts} p ON prt.event_post_id = p.ID WHERE p.ID IS NULL",
        ],

        // TIER 2 — Ref booking_groups
        [
            'table' => "{$p}bookings",
            'constraint' => 'fk_hmwevents_bookings_group',
            'sql' => "ALTER TABLE {$p}bookings ADD CONSTRAINT fk_hmwevents_bookings_group FOREIGN KEY (booking_group_id) REFERENCES {$p}booking_groups(id) ON DELETE CASCADE",
            'cleanup' => "DELETE b FROM {$p}bookings b LEFT JOIN {$p}booking_groups bg ON b.booking_group_id = bg.id WHERE bg.id IS NULL",
        ],
        [
            'table' => "{$p}bookings",
            'constraint' => 'fk_hmwevents_bookings_event',
            'sql' => "ALTER TABLE {$p}bookings ADD CONSTRAINT fk_hmwevents_bookings_event FOREIGN KEY (event_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE b FROM {$p}bookings b LEFT JOIN {$wpdb->posts} p ON b.event_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}bookings",
            'constraint' => 'fk_hmwevents_bookings_registrant',
            'sql' => "ALTER TABLE {$p}bookings ADD CONSTRAINT fk_hmwevents_bookings_registrant FOREIGN KEY (registrant_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE b FROM {$p}bookings b LEFT JOIN {$wpdb->posts} p ON b.registrant_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}bookings",
            'constraint' => 'fk_hmwevents_bookings_attendance_option',
            'sql' => "ALTER TABLE {$p}bookings ADD CONSTRAINT fk_hmwevents_bookings_attendance_option FOREIGN KEY (attendance_option_id) REFERENCES {$p}event_attendance_options(id) ON DELETE SET NULL",
            'cleanup' => '',
        ],
        [
            'table' => "{$p}payment_transactions",
            'constraint' => 'fk_hmwevents_payment_transactions_group',
            'sql' => "ALTER TABLE {$p}payment_transactions ADD CONSTRAINT fk_hmwevents_payment_transactions_group FOREIGN KEY (booking_group_id) REFERENCES {$p}booking_groups(id) ON DELETE CASCADE",
            'cleanup' => "DELETE pt FROM {$p}payment_transactions pt LEFT JOIN {$p}booking_groups bg ON pt.booking_group_id = bg.id WHERE bg.id IS NULL",
        ],

        // TIER 3 — Ref bookings
        [
            'table' => "{$p}booking_details",
            'constraint' => 'fk_hmwevents_booking_details_booking',
            'sql' => "ALTER TABLE {$p}booking_details ADD CONSTRAINT fk_hmwevents_booking_details_booking FOREIGN KEY (booking_id) REFERENCES {$p}bookings(id) ON DELETE CASCADE",
            'cleanup' => "DELETE bd FROM {$p}booking_details bd LEFT JOIN {$p}bookings b ON bd.booking_id = b.id WHERE b.id IS NULL",
        ],
        [
            'table' => "{$p}booking_meta",
            'constraint' => 'fk_hmwevents_booking_meta_booking',
            'sql' => "ALTER TABLE {$p}booking_meta ADD CONSTRAINT fk_hmwevents_booking_meta_booking FOREIGN KEY (booking_id) REFERENCES {$p}bookings(id) ON DELETE CASCADE",
            'cleanup' => "DELETE bm FROM {$p}booking_meta bm LEFT JOIN {$p}bookings b ON bm.booking_id = b.id WHERE b.id IS NULL",
        ],
        [
            'table' => "{$p}booking_history",
            'constraint' => 'fk_hmwevents_booking_history_booking',
            'sql' => "ALTER TABLE {$p}booking_history ADD CONSTRAINT fk_hmwevents_booking_history_booking FOREIGN KEY (booking_id) REFERENCES {$p}bookings(id) ON DELETE CASCADE",
            'cleanup' => "DELETE bh FROM {$p}booking_history bh LEFT JOIN {$p}bookings b ON bh.booking_id = b.id WHERE b.id IS NULL",
        ],
        [
            'table' => "{$p}voucher_usage",
            'constraint' => 'fk_hmwevents_voucher_usage_booking',
            'sql' => "ALTER TABLE {$p}voucher_usage ADD CONSTRAINT fk_hmwevents_voucher_usage_booking FOREIGN KEY (booking_id) REFERENCES {$p}bookings(id) ON DELETE CASCADE",
            'cleanup' => "DELETE vu FROM {$p}voucher_usage vu LEFT JOIN {$p}bookings b ON vu.booking_id = b.id WHERE b.id IS NULL",
        ],
        [
            'table' => "{$p}coupon_usage",
            'constraint' => 'fk_hmwevents_coupon_usage_booking',
            'sql' => "ALTER TABLE {$p}coupon_usage ADD CONSTRAINT fk_hmwevents_coupon_usage_booking FOREIGN KEY (booking_id) REFERENCES {$p}bookings(id) ON DELETE CASCADE",
            'cleanup' => "DELETE cu FROM {$p}coupon_usage cu LEFT JOIN {$p}bookings b ON cu.booking_id = b.id WHERE b.id IS NULL",
        ],
        [
            'table' => "{$p}coupon_usage",
            'constraint' => 'fk_hmwevents_coupon_usage_coupon',
            'sql' => "ALTER TABLE {$p}coupon_usage ADD CONSTRAINT fk_hmwevents_coupon_usage_coupon FOREIGN KEY (coupon_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE cu FROM {$p}coupon_usage cu LEFT JOIN {$wpdb->posts} p ON cu.coupon_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}registration_documents",
            'constraint' => 'fk_hmwevents_registration_documents_booking',
            'sql' => "ALTER TABLE {$p}registration_documents ADD CONSTRAINT fk_hmwevents_registration_documents_booking FOREIGN KEY (booking_id) REFERENCES {$p}bookings(id) ON DELETE CASCADE",
            'cleanup' => "DELETE rd FROM {$p}registration_documents rd LEFT JOIN {$p}bookings b ON rd.booking_id = b.id WHERE b.id IS NULL",
        ],

        // TIER 4 — Other dependent tables
        [
            'table' => "{$p}waitlist",
            'constraint' => 'fk_hmwevents_waitlist_event',
            'sql' => "ALTER TABLE {$p}waitlist ADD CONSTRAINT fk_hmwevents_waitlist_event FOREIGN KEY (event_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE w FROM {$p}waitlist w LEFT JOIN {$wpdb->posts} p ON w.event_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}waitlist",
            'constraint' => 'fk_hmwevents_waitlist_registrant',
            'sql' => "ALTER TABLE {$p}waitlist ADD CONSTRAINT fk_hmwevents_waitlist_registrant FOREIGN KEY (registrant_post_id) REFERENCES {$wpdb->posts}(ID) ON DELETE CASCADE",
            'cleanup' => "DELETE w FROM {$p}waitlist w LEFT JOIN {$wpdb->posts} p ON w.registrant_post_id = p.ID WHERE p.ID IS NULL",
        ],
        [
            'table' => "{$p}email_attachments",
            'constraint' => 'fk_hmwevents_email_attachments_queue',
            'sql' => "ALTER TABLE {$p}email_attachments ADD CONSTRAINT fk_hmwevents_email_attachments_queue FOREIGN KEY (email_queue_id) REFERENCES {$p}email_queue(id) ON DELETE CASCADE",
            'cleanup' => "DELETE ea FROM {$p}email_attachments ea LEFT JOIN {$p}email_queue eq ON ea.email_queue_id = eq.id WHERE eq.id IS NULL",
        ],
    ];

    foreach ($foreign_keys as $fk) {
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*)
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = %s
            AND TABLE_NAME = %s
            AND CONSTRAINT_NAME = %s
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'
        ", DB_NAME, $fk['table'], $fk['constraint']));

        if (!$exists) {
            $result = $wpdb->query($fk['sql']);
            if ($result === false) {
                $is_fk_error = strpos($wpdb->last_error, '1452') !== false
                    || strpos($wpdb->last_error, 'foreign key constraint fails') !== false
                    || strpos($wpdb->last_error, 'Cannot add or update a child row') !== false;

                if ($is_fk_error && !empty($fk['cleanup'])) {
                    error_log('HMWEvents: FK ' . $fk['constraint'] . ' failed with referential error. Cleaning orphans and retrying...');
                    $cleaned = $wpdb->query($fk['cleanup']);
                    error_log('HMWEvents: Cleaned ' . ($cleaned ?: 0) . ' orphaned rows for ' . $fk['constraint']);
                    $result = $wpdb->query($fk['sql']);
                    if ($result === false) {
                        error_log('HMWEvents: Retry failed for ' . $fk['constraint'] . ': ' . $wpdb->last_error);
                    } else {
                        error_log('HMWEvents: Successfully added foreign key ' . $fk['constraint'] . ' after cleanup');
                    }
                } else {
                    error_log('HMWEvents foreign key error: ' . $wpdb->last_error . ' for constraint: ' . $fk['constraint']);
                }
            } else {
                error_log('HMWEvents: Successfully added foreign key ' . $fk['constraint']);
            }
        }
    }

    update_option('hmwevents_uses_foreign_keys', true);
    return true;
}

/**
 * Clean up orphaned data in hmwevents_* child tables.
 *
 * @since 2.0.0
 * @return array Summary of cleaned records per table/column
 */
function hmwevents_clean_orphaned_data()
{
    global $wpdb;
    $p = $wpdb->prefix . 'hmwevents_';
    $cleaned = [];

    $orphan_checks = [
        // Child → Parent order
        "email_attachments"     => ["LEFT JOIN {$p}email_queue eq ON ea.email_queue_id = eq.id", "eq.id IS NULL", "email_queue_id"],
        "registration_documents" => ["LEFT JOIN {$p}bookings b ON rd.booking_id = b.id", "b.id IS NULL", "booking_id"],
        "coupon_usage"          => ["LEFT JOIN {$p}bookings b ON cu.booking_id = b.id", "b.id IS NULL", "booking_id"],
        "voucher_usage"         => ["LEFT JOIN {$p}bookings b ON vu.booking_id = b.id", "b.id IS NULL", "booking_id"],
        "booking_history"       => ["LEFT JOIN {$p}bookings b ON bh.booking_id = b.id", "b.id IS NULL", "booking_id"],
        "booking_meta"          => ["LEFT JOIN {$p}bookings b ON bm.booking_id = b.id", "b.id IS NULL", "booking_id"],
        "booking_details"       => ["LEFT JOIN {$p}bookings b ON bd.booking_id = b.id", "b.id IS NULL", "booking_id"],
        "payment_transactions"  => ["LEFT JOIN {$p}booking_groups bg ON pt.booking_group_id = bg.id", "bg.id IS NULL", "booking_group_id"],
        "bookings"              => ["LEFT JOIN {$p}booking_groups bg ON b.booking_group_id = bg.id", "bg.id IS NULL", "booking_group_id"],
        "booking_groups"        => ["LEFT JOIN {$wpdb->posts} p ON bg.registrant_post_id = p.ID", "p.ID IS NULL", "registrant_post_id"],
        "waitlist"              => ["LEFT JOIN {$wpdb->posts} p ON w.event_post_id = p.ID", "p.ID IS NULL", "event_post_id"],
        "event_recurrence"      => ["LEFT JOIN {$wpdb->posts} p ON er.event_post_id = p.ID", "p.ID IS NULL", "event_post_id"],
        "event_availability"    => ["LEFT JOIN {$wpdb->posts} p ON ea.event_post_id = p.ID", "p.ID IS NULL", "event_post_id"],
        "event_attendance_options" => ["LEFT JOIN {$wpdb->posts} p ON eao.event_post_id = p.ID", "p.ID IS NULL", "event_post_id"],
        "private_registration_tokens" => ["LEFT JOIN {$wpdb->posts} p ON prt.event_post_id = p.ID", "p.ID IS NULL", "event_post_id"],
    ];

    $aliases = [
        'email_attachments'     => 'ea',
        'registration_documents' => 'rd',
        'coupon_usage'          => 'cu',
        'voucher_usage'         => 'vu',
        'booking_history'       => 'bh',
        'booking_meta'          => 'bm',
        'booking_details'       => 'bd',
        'payment_transactions'  => 'pt',
        'bookings'              => 'b',
        'booking_groups'        => 'bg',
        'waitlist'              => 'w',
        'event_recurrence'      => 'er',
        'event_availability'    => 'ea',
        'event_attendance_options' => 'eao',
        'private_registration_tokens' => 'prt',
    ];

    foreach ($orphan_checks as $table => [$join, $where, $col]) {
        $alias = $aliases[$table];
        $full_table = "{$p}{$table}";
        $orphaned = $wpdb->get_var("
            SELECT COUNT(*) FROM {$full_table} {$alias} {$join} WHERE {$where}
        ");
        if ($orphaned > 0) {
            $wpdb->query("DELETE {$alias} FROM {$full_table} {$alias} {$join} WHERE {$where}");
            error_log("HMWEvents: Cleaned $orphaned orphaned {$table} (missing {$col})");
            $cleaned["{$table}.{$col}"] = (int) $orphaned;
        }
    }

    return $cleaned;
}

/**
 * Full repair: drop FKs, clean orphans, rebuild FKs.
 *
 * @since 2.0.0
 * @return bool True on success
 */
function hmwevents_repair_and_rebuild_foreign_keys()
{
    global $wpdb;

    $supports_fk = $wpdb->get_var("SELECT @@foreign_key_checks");
    if (!$supports_fk) {
        error_log('HMWEvents: Foreign keys not supported on this server');
        return false;
    }

    if (defined('HMWEvents_DISABLE_FOREIGN_KEYS') && HMWEvents_DISABLE_FOREIGN_KEYS) {
        error_log('HMWEvents: Foreign keys disabled via constant');
        return false;
    }

    error_log('HMWEvents: Starting full foreign key repair and rebuild...');

    hmwevents_drop_foreign_keys();

    $cleaned = hmwevents_clean_orphaned_data();
    $total_cleaned = array_sum($cleaned);
    if ($total_cleaned > 0) {
        error_log('HMWEvents: Cleaned ' . $total_cleaned . ' total orphaned records: ' . json_encode($cleaned));
    } else {
        error_log('HMWEvents: No orphaned data found.');
    }

    hmwevents_add_foreign_keys();
    update_option('hmwevents_table_prefix', $wpdb->prefix);

    error_log('HMWEvents: Foreign key repair and rebuild complete.');
    return true;
}

/**
 * Plugin activation hook.
 *
 * @since 2.0.0
 */
function hmwevents_activation()
{
    hmwevents_install();
    hmwevents_repair_and_rebuild_foreign_keys();
    \HMWEvents\Services\DatabaseService::update_schema_version();
    update_option('hmwevents_plugin_version', HMWEvents_VERSION);
    flush_rewrite_rules();
}

/**
 * Uninstall plugin - remove all hmwevents_* tables and options.
 *
 * @since 2.0.0
 */
function hmwevents_uninstall()
{
    global $wpdb;
    $p = $wpdb->prefix . 'hmwevents_';

    // Drop in reverse dependency order
    $tables = [
        'email_attachments',
        'email_queue',
        'email_templates',
        'registration_documents',
        'coupon_usage',
        'voucher_usage',
        'booking_history',
        'booking_meta',
        'booking_details',
        'bookings',
        'payment_transactions',
        'booking_groups',
        'waitlist',
        'event_recurrence',
        'event_availability',
        'event_attendance_options',
        'event_templates',
        'saved_report_filters',
        'private_registration_tokens',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$p}{$table}");
    }

    delete_option('hmwevents_db_version');
    delete_option('hmwevents_table_prefix');
    delete_option('hmwevents_uses_foreign_keys');
    delete_option('hmwevents_plugin_version');
    wp_cache_flush();
}

/**
 * Purge legacy educator_* and bare email_* tables.
 *
 * This is a destructive operation that drops all old tables from the
 * Calmbirth/educator system. Only call this when you are certain the
 * new hmwevents_* system is fully operational.
 *
 * @since 2.0.0
 * @return array List of tables dropped
 */
function hmwevents_purge_legacy_tables()
{
    global $wpdb;
    $prefix = $wpdb->prefix;
    $dropped = [];

    $legacy_tables = [
        'educator_course_recurrence',
        'educator_booking_groups',
        'educator_bookings',
        'educator_booking_details',
        'educator_booking_meta',
        'educator_payment_transactions',
        'educator_waitlist',
        'educator_course_availability',
        'educator_voucher_usage',
        'educator_coupon_usage',
        'email_queue',
        'email_templates',
        'email_attachments',
    ];

    // Drop legacy FKs first
    hmwevents_drop_foreign_keys();

    foreach ($legacy_tables as $table) {
        $sql = "DROP TABLE IF EXISTS {$prefix}{$table}";
        $wpdb->query($sql);
        $dropped[] = "{$prefix}{$table}";
        error_log("HMWEvents: Purged legacy table {$prefix}{$table}");
    }

    delete_option('hmwevents_db_version');
    delete_option('hmwevents_uses_foreign_keys');

    return $dropped;
}
