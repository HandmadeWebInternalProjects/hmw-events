<?php
/**
 * Example: How to use the migration script programmatically.
 *
 * This file demonstrates various ways to interact with the migration script.
 * DO NOT run this file directly - it's for reference only.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    die('This is an example file - do not run directly!');
}

// ============================================================
// EXAMPLE 1: Basic Migration
// ============================================================

function example_run_basic_migration()
{
    // Load the migration class
    require_once HMWEvents_ABSPATH . 'includes/migrate-hmw-educators.php';
    
    // Create migration instance
    $migration = new HMWEvents_HMW_Educators_Migration();
    
    // Run the migration
    $result = $migration->migrate();
    
    // Get the log
    $log = $migration->get_log();
    $errors = $migration->get_errors();
    
    // Display results
    echo "Migration completed!\n";
    echo "Log entries: " . count($log) . "\n";
    echo "Errors: " . count($errors) . "\n";
    
    return $result;
}

// ============================================================
// EXAMPLE 2: Migration with Custom Logging
// ============================================================

function example_migration_with_logging()
{
    require_once HMWEvents_ABSPATH . 'includes/migrate-hmw-educators.php';
    
    $migration = new HMWEvents_HMW_Educators_Migration();
    
    // Run migration
    $migration->migrate();
    
    // Save log to file
    $log_file = WP_CONTENT_DIR . '/migration-log-' . date('Y-m-d-His') . '.txt';
    file_put_contents($log_file, implode("\n", $migration->get_log()));
    
    // Save errors separately if any
    if (!empty($migration->get_errors())) {
        $error_file = WP_CONTENT_DIR . '/migration-errors-' . date('Y-m-d-His') . '.txt';
        file_put_contents($error_file, implode("\n", $migration->get_errors()));
    }
    
    echo "Log saved to: {$log_file}\n";
}

// ============================================================
// EXAMPLE 3: Pre-Flight Checks Only
// ============================================================

function example_preflight_checks_only()
{
    global $wpdb;
    
    $checks = [];
    
    // Check old tables
    $old_tables = ['educator', 'educator_classes', 'educator_class_bookings'];
    foreach ($old_tables as $table) {
        $full_table = $wpdb->prefix . $table;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'") === $full_table;
        $checks[$table] = $exists ? 'OK' : 'MISSING';
    }
    
    // Check new tables
    $new_tables = ['educator_booking_groups', 'educator_bookings'];
    foreach ($new_tables as $table) {
        $full_table = $wpdb->prefix . $table;
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$full_table}'") === $full_table;
        $checks[$table] = $exists ? 'OK' : 'MISSING';
    }
    
    // Get data counts
    $checks['educator_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}educator");
    $checks['courses_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}educator_classes");
    $checks['bookings_count'] = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}educator_class_bookings");
    
    return $checks;
}

// ============================================================
// EXAMPLE 4: Verify Migration Results
// ============================================================

function example_verify_migration()
{
    global $wpdb;
    
    $results = [];
    
    // Count educators migrated to users
    $educator_users = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->users} u
        INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
        WHERE um.meta_key = '{$wpdb->prefix}capabilities'
        AND um.meta_value LIKE '%educator%'
    ");
    $results['educator_users'] = $educator_users;
    
    // Count courses migrated to CPT
    $course_posts = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type = 'educator_course'
    ");
    $results['course_posts'] = $course_posts;
    
    // Count customers created
    $customer_posts = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts}
        WHERE post_type = 'edu_customer'
    ");
    $results['customer_posts'] = $customer_posts;
    
    // Count booking groups
    $booking_groups = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}educator_booking_groups
    ");
    $results['booking_groups'] = $booking_groups;
    
    // Count bookings
    $bookings = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}educator_bookings
    ");
    $results['bookings'] = $bookings;
    
    // Count payment transactions
    $transactions = $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->prefix}educator_payment_transactions
    ");
    $results['transactions'] = $transactions;
    
    return $results;
}

// ============================================================
// EXAMPLE 5: Get Specific Educator's Migrated Data
// ============================================================

function example_get_educator_data($old_educator_id)
{
    global $wpdb;
    
    // Find the WordPress user
    $user_id = get_users([
        'meta_key' => 'educator_id',
        'meta_value' => $old_educator_id,
        'number' => 1,
        'fields' => 'ID'
    ]);
    
    if (empty($user_id)) {
        return null;
    }
    
    $user_id = $user_id[0];
    
    // Get user data
    $user = get_user_by('ID', $user_id);
    
    // Get educator meta
    $meta = [
        'name' => get_user_meta($user_id, 'educator_name', true),
        'phone' => get_user_meta($user_id, 'educator_phone', true),
        'email' => $user->user_email,
        'bio' => get_user_meta($user_id, 'educator_bio', true),
        'social_media' => get_user_meta($user_id, 'educator_social_media', true),
        'testimonials' => get_user_meta($user_id, 'educator_testimonials', true),
    ];
    
    // Get courses
    $classes = get_posts([
        'post_type' => 'educator_course',
        'author' => $user_id,
        'posts_per_page' => -1,
    ]);
    
    return [
        'user' => $user,
        'meta' => $meta,
        'courses' => $classes,
    ];
}

// ============================================================
// EXAMPLE 6: Find Booking by Old Booking ID
// ============================================================

function example_find_booking_by_old_id($old_booking_id)
{
    global $wpdb;
    
    // Bookings are migrated with reference like MIG-00000123
    $booking_reference = 'MIG-' . str_pad($old_booking_id, 8, '0', STR_PAD_LEFT);
    
    $booking_group = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}educator_booking_groups
        WHERE booking_reference = %s
    ", $booking_reference));
    
    if (!$booking_group) {
        return null;
    }
    
    // Get the individual booking
    $booking = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}educator_bookings
        WHERE booking_group_id = %d
    ", $booking_group->id));
    
    // Get booking details
    $details = $wpdb->get_row($wpdb->prepare("
        SELECT * FROM {$wpdb->prefix}educator_booking_details
        WHERE booking_id = %d
    ", $booking->id));
    
    return [
        'group' => $booking_group,
        'booking' => $booking,
        'details' => $details,
    ];
}

// ============================================================
// EXAMPLE 7: Compare Old vs New Data
// ============================================================

function example_compare_data()
{
    global $wpdb;
    
    $comparison = [];
    
    // OLD SYSTEM
    $comparison['old'] = [
        'educators' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}educator"),
        'courses' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}educator_classes"),
        'bookings' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}educator_class_bookings"),
    ];
    
    // NEW SYSTEM
    $comparison['new'] = [
        'educators' => $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->users} u
            INNER JOIN {$wpdb->usermeta} um ON u.ID = um.user_id
            WHERE um.meta_key = '{$wpdb->prefix}capabilities'
            AND um.meta_value LIKE '%educator%'
        "),
        'courses' => $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'educator_course'
        "),
        'customers' => $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts}
            WHERE post_type = 'edu_customer'
        "),
        'booking_groups' => $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}educator_booking_groups
        "),
        'bookings' => $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}educator_bookings
        "),
    ];
    
    // Calculate differences
    $comparison['match'] = [
        'educators' => $comparison['old']['educators'] === $comparison['new']['educators'],
        'courses' => $comparison['old']['courses'] === $comparison['new']['courses'],
        'bookings' => $comparison['old']['bookings'] === $comparison['new']['bookings'],
    ];
    
    return $comparison;
}

// ============================================================
// USAGE EXAMPLES (commented out - for reference)
// ============================================================

/*
// Run basic migration
example_run_basic_migration();

// Run with logging
example_migration_with_logging();

// Check before migration
$checks = example_preflight_checks_only();
print_r($checks);

// Verify after migration
$results = example_verify_migration();
print_r($results);

// Get specific educator's data
$educator_data = example_get_educator_data(123);
print_r($educator_data);

// Find booking by old ID
$booking_data = example_find_booking_by_old_id(456);
print_r($booking_data);

// Compare old vs new
$comparison = example_compare_data();
print_r($comparison);
*/
