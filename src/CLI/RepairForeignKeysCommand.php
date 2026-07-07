<?php
/**
 * WP-CLI commands for HMWEvents database management (Rebuild v2).
 *
 * Usage:
 *   wp hmwevents repair-foreign-keys
 *   wp hmwevents purge-legacy-tables
 *   wp hmwevents force-db-upgrade
 *   wp hmwevents db-status
 *
 * @package HMWEvents
 * @since 2.0.0
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
 *     wp hmwevents repair-foreign-keys
 *
 * @when after_wp_load
 */
WP_CLI::add_command('hmwevents repair-foreign-keys', function () {
    WP_CLI::log('Starting foreign key repair for HMWEvents...');

    require_once HMWEvents_ABSPATH . 'includes/install.php';

    $result = hmwevents_repair_and_rebuild_foreign_keys();

    if ($result) {
        WP_CLI::success('Foreign keys repaired successfully.');
    } else {
        WP_CLI::error('Foreign key repair failed. Check error log for details.');
    }
});

/**
 * Purge legacy educator_* and bare email_* tables.
 *
 * This is a destructive operation that drops all old tables from the
 * Calmbirth/educator system. Only use when the new hmwevents_* system
 * is fully operational and verified.
 *
 * ## OPTIONS
 *
 * [--yes]
 * : Skip confirmation prompt.
 *
 * ## EXAMPLES
 *
 *     wp hmwevents purge-legacy-tables --yes
 *
 * @when after_wp_load
 */
WP_CLI::add_command('hmwevents purge-legacy-tables', function ($args, $assoc_args) {
    global $wpdb;

    require_once HMWEvents_ABSPATH . 'includes/install.php';

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

    // Check which legacy tables actually exist
    $existing = [];
    foreach ($legacy_tables as $table) {
        $full = $wpdb->prefix . $table;
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s",
            DB_NAME, $full
        ));
        if ($exists) {
            $existing[] = $full;
        }
    }

    if (empty($existing)) {
        WP_CLI::success('No legacy tables found to purge.');
        return;
    }

    WP_CLI::log('The following legacy tables will be permanently dropped:');
    foreach ($existing as $table) {
        WP_CLI::log("  - {$table}");
    }

    if (empty($assoc_args['yes'])) {
        WP_CLI::confirm('Are you sure you want to drop these tables? This cannot be undone.');
    }

    $dropped = hmwevents_purge_legacy_tables();

    WP_CLI::success('Purged ' . count($dropped) . ' legacy tables:');
    foreach ($dropped as $table) {
        WP_CLI::log("  ✓ {$table}");
    }
});

/**
 * Force a database upgrade to the current schema version.
 *
 * Drops the version option and re-runs the install/upgrade pipeline.
 *
 * ## EXAMPLES
 *
 *     wp hmwevents force-db-upgrade
 *
 * @when after_wp_load
 */
WP_CLI::add_command('hmwevents force-db-upgrade', function () {
    WP_CLI::log('Forcing database upgrade...');

    \HMWEvents\Services\DatabaseService::force_upgrade();

    WP_CLI::success('Database upgrade completed. Version: ' . \HMWEvents\Services\DatabaseService::CURRENT_DB_VERSION);
});

/**
 * Show current database status.
 *
 * Displays schema version, table count, FK status, and prefix info.
 *
 * ## EXAMPLES
 *
 *     wp hmwevents db-status
 *
 * @when after_wp_load
 */
WP_CLI::add_command('hmwevents db-status', function () {
    global $wpdb;

    require_once HMWEvents_ABSPATH . 'includes/install.php';

    $version = get_option('hmwevents_db_version', 'none');
    $prefix = get_option('hmwevents_table_prefix', $wpdb->prefix);
    $uses_fk = get_option('hmwevents_uses_foreign_keys', false);

    $expected = hmwevents_get_foreign_key_constraints();
    $existing_count = 0;
    foreach ($expected as $constraint) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = %s AND CONSTRAINT_NAME = %s AND CONSTRAINT_TYPE = 'FOREIGN KEY'",
            DB_NAME, $constraint
        ));
        if ($exists) {
            $existing_count++;
        }
    }

    $hmw_tables = $wpdb->get_col($wpdb->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s",
        DB_NAME, $wpdb->prefix . 'hmwevents\_%'
    ));
    $legacy_tables = $wpdb->get_col($wpdb->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND (TABLE_NAME LIKE %s OR TABLE_NAME LIKE %s)",
        DB_NAME, $wpdb->prefix . 'educator\_%', $wpdb->prefix . 'email\_%'
    ));

    WP_CLI::log('=== HMWEvents Database Status ===');
    WP_CLI::log("Schema Version:     {$version}");
    WP_CLI::log("Target Version:     " . \HMWEvents\Services\DatabaseService::CURRENT_DB_VERSION);
    WP_CLI::log("Table Prefix:       {$prefix}");
    WP_CLI::log("Foreign Keys:       {$existing_count}/" . count($expected) . ' (enabled: ' . ($uses_fk ? 'yes' : 'no') . ')');
    WP_CLI::log("New Tables:         " . count($hmw_tables));
    WP_CLI::log("Legacy Tables:      " . count($legacy_tables));

    if (!empty($legacy_tables)) {
        WP_CLI::log('');
        WP_CLI::log('Legacy tables still present:');
        foreach ($legacy_tables as $t) {
            WP_CLI::log("  - {$t}");
        }
        WP_CLI::log('Run `wp hmwevents purge-legacy-tables` to remove them.');
    }
});

/**
 * Migrate data from legacy educator_* tables to new hmwevents_* tables.
 *
 * Runs all migration steps in sequence. Each step is idempotent — it's
 * safe to run this multiple times.
 *
 * ## OPTIONS
 *
 * [--step=<step>]
 * : Run a single migration step: events, registrants, taxonomy_terms, booking_groups, bookings, transactions, booking_details, booking_meta, waitlist, voucher_usage, coupon_usage, user_meta.
 *
 * ## EXAMPLES
 *
 *     wp hmwevents migrate
 *     wp hmwevents migrate --step=events
 *     wp hmwevents migrate --step=user_meta
 *
 * @when after_wp_load
 */
WP_CLI::add_command('hmwevents migrate', function ($args, $assoc_args) {
    // Prevent timeouts and increase memory for large datasets
    set_time_limit(0);
    \WP_CLI::log('Memory: ' . size_format(memory_get_usage(true)));

    $service = new \HMWEvents\Services\MigrateService();
    $step = $assoc_args['step'] ?? null;
    $batch = (int) ($assoc_args['batch'] ?? 50);

    $steps = [
        'events'           => 'migrate_events',
        'registrants'      => 'migrate_registrants',
        'taxonomy_terms'   => 'migrate_taxonomy_terms',
        'booking_groups'   => 'migrate_booking_groups',
        'bookings'         => 'migrate_bookings',
        'transactions'     => 'migrate_payment_transactions',
        'booking_details'  => 'migrate_booking_details',
        'booking_meta'     => 'migrate_booking_meta',
        'waitlist'         => 'migrate_waitlist',
        'voucher_usage'    => 'migrate_voucher_usage',
        'coupon_usage'     => 'migrate_coupon_usage',
        'user_meta'        => 'migrate_user_meta',
    ];

    if ($step) {
        if (!isset($steps[$step])) {
            WP_CLI::error("Unknown step: {$step}. Available: " . implode(', ', array_keys($steps)));
        }
        $method = $steps[$step];
        \WP_CLI::log("Running migration step: {$step}...");
        $count = $service->$method($batch);
        \WP_CLI::success("Migrated {$count} {$step}.");
        return;
    }

    \WP_CLI::log('Running full migration (batch size: ' . $batch . ')...');
    $stats = $service->run_full($batch);

    \WP_CLI::success('Migration complete.');
    foreach ($stats as $key => $count) {
        \WP_CLI::log("  {$key}: {$count}");
    }
});

/**
 * Backfill missing template snapshots on events created from templates.
 *
 * ## OPTIONS
 *
 * [--batch=<number>]
 * : Number of events to process per loop. Default 100.
 *
 * [--max-batches=<number>]
 * : Stop after this many batches (safety guard). Default 1000.
 *
 * ## EXAMPLES
 *
 *     wp hmwevents backfill-template-snapshots
 *     wp hmwevents backfill-template-snapshots --batch=50
 *
 * @when after_wp_load
 */
WP_CLI::add_command('hmwevents backfill-template-snapshots', function ($args, $assoc_args) {
    $batch = max(1, (int) ($assoc_args['batch'] ?? 100));
    $max_batches = max(1, (int) ($assoc_args['max-batches'] ?? 1000));

    $service = new \HMWEvents\Services\EventTemplateService();

    $after_post_id = 0;
    $total_processed = 0;
    $total_updated = 0;
    $total_skipped = 0;

    for ($i = 1; $i <= $max_batches; $i++) {
        $result = $service->backfill_template_snapshots($batch, $after_post_id);

        $total_processed += (int) $result['processed'];
        $total_updated += (int) $result['updated'];
        $total_skipped += (int) $result['skipped'];
        $after_post_id = (int) $result['last_post_id'];

        WP_CLI::log(sprintf(
            'Batch %d: processed=%d updated=%d skipped=%d last_post_id=%d',
            $i,
            (int) $result['processed'],
            (int) $result['updated'],
            (int) $result['skipped'],
            $after_post_id
        ));

        if (!empty($result['done'])) {
            WP_CLI::success(sprintf(
                'Backfill complete. processed=%d updated=%d skipped=%d',
                $total_processed,
                $total_updated,
                $total_skipped
            ));
            return;
        }
    }

    WP_CLI::warning(sprintf(
        'Stopped at max-batches=%d. processed=%d updated=%d skipped=%d last_post_id=%d',
        $max_batches,
        $total_processed,
        $total_updated,
        $total_skipped,
        $after_post_id
    ));
});
