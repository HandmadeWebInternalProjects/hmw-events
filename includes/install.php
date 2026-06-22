<?php

/**
 * Installation functions for HMWEvents plugin.
 *
 * Handles database table creation and foreign key management with support for
 * WP Migrate DB and other migration tools that change table prefixes.
 *
 * Features:
 * - Dynamic foreign key recreation when table prefixes change
 * - Graceful handling of hosting environments that don't support foreign keys
 * - Option to disable foreign keys via HMWEvents_DISABLE_FOREIGN_KEYS constant
 * - Automatic cleanup and recreation during migrations
 *
 * To disable foreign keys, add this to wp-config.php:
 * define('HMWEvents_DISABLE_FOREIGN_KEYS', true);
 *
 * @package HMWEvents
 * @since 1.0.0
 * @updated 1.0.17 Added migration-aware foreign key management
 */


defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Install plugin - create database tables.
 *
 * @since 1.0.0
 */
function hmwevents_install()
{
    global $wpdb;

    // Include WordPress database upgrade functions
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    // Get the correct table prefix
    $table_prefix = $wpdb->prefix;

    // ============================================================
    // EDUCATOR BOOKING SYSTEM TABLES
    // ============================================================

    // Recurrence Rules (complex scheduling logic)
    $sql_educator_course_recurrence = "CREATE TABLE {$table_prefix}educator_course_recurrence (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        course_template_id bigint UNSIGNED NOT NULL,
        recurrence_type ENUM('none', 'daily', 'weekly', 'monthly', 'custom') DEFAULT 'none',
        recurrence_interval int UNSIGNED DEFAULT 1,
        recurrence_days varchar(50),
        start_date date NOT NULL,
        end_date date,
        max_occurrences int UNSIGNED,
        is_active tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_template (course_template_id),
        KEY idx_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Booking Groups (for multi-course/recurring purchases)
    $sql_educator_booking_groups = "CREATE TABLE {$table_prefix}educator_booking_groups (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        customer_post_id bigint UNSIGNED NOT NULL,
        booking_reference varchar(50) NOT NULL,
        booking_type ENUM('single', 'recurring', 'package') DEFAULT 'single',
        payment_type ENUM('full', 'deposit') NOT NULL DEFAULT 'full',
        total_courses int UNSIGNED DEFAULT 1,
        total_amount decimal(10,2) NOT NULL DEFAULT 0.00,
        currency varchar(3) DEFAULT 'AUD',
        payment_status ENUM('pending', 'partial', 'paid', 'refunded', 'failed') DEFAULT 'pending',
        metadata JSON DEFAULT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_reference (booking_reference),
        KEY idx_customer (customer_post_id),
        KEY idx_payment_status (payment_status),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Individual Bookings
    $sql_educator_bookings = "CREATE TABLE {$table_prefix}educator_bookings (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_group_id bigint UNSIGNED NOT NULL,
        course_post_id bigint UNSIGNED NOT NULL,
        customer_post_id bigint UNSIGNED NOT NULL,
        booking_number varchar(50) NOT NULL,
        ticket_type ENUM('full', 'deposit') NOT NULL DEFAULT 'full',
        ticket_quantity int UNSIGNED DEFAULT 1,
        booking_amount decimal(10,2) NOT NULL DEFAULT 0.00,
        currency varchar(3) DEFAULT 'AUD',
        coupon_code varchar(100),
        discount_amount decimal(10,2) DEFAULT 0.00,
        status ENUM('pending', 'confirmed', 'cancelled', 'attended', 'no_show') DEFAULT 'pending',
        payment_status ENUM('pending', 'paid', 'refunded', 'failed') DEFAULT 'pending',
        booking_source varchar(50) DEFAULT 'website',
        cancelled_at datetime NULL,
        deleted_at datetime NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_number (booking_number),
        KEY idx_group (booking_group_id),
        KEY idx_course (course_post_id),
        KEY idx_customer (customer_post_id),
        KEY idx_status (status, deleted_at),
        KEY idx_payment (payment_status),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Booking Details (flexible JSON storage for questionnaire responses)
    $sql_educator_booking_details = "CREATE TABLE {$table_prefix}educator_booking_details (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id bigint UNSIGNED NOT NULL,
        form_data JSON NOT NULL COMMENT 'All form responses stored as JSON',
        form_version varchar(20) DEFAULT '1.0' COMMENT 'Track which form version was used',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_booking (booking_id),
        KEY idx_form_version (form_version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Booking Metadata (for searchable fields from booking details)
    $sql_educator_booking_meta = "CREATE TABLE {$table_prefix}educator_booking_meta (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id bigint UNSIGNED NOT NULL,
        meta_key varchar(255) NOT NULL,
        meta_value longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_booking (booking_id),
        KEY idx_meta_key (meta_key),
        KEY idx_meta_key_value (meta_key, meta_value(191))
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Payment Transactions (audit trail)
    $sql_educator_payment_transactions = "CREATE TABLE {$table_prefix}educator_payment_transactions (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_group_id bigint UNSIGNED NOT NULL,
        transaction_type ENUM('charge', 'refund', 'partial_refund') NOT NULL,
        amount decimal(10,2) NOT NULL,
        currency varchar(3) DEFAULT 'AUD',
        payment_gateway varchar(50) DEFAULT 'stripe',
        gateway varchar(50) DEFAULT 'stripe',
        gateway_transaction_id varchar(255),
        gateway_customer_id varchar(255),
        gateway_payment_method_id varchar(255),
        status ENUM('pending', 'processing', 'succeeded', 'failed', 'cancelled', 'refunded') DEFAULT 'pending',
        error_message text,
        refund_id varchar(255),
        refunded_amount decimal(10,2),
        refunded_at datetime,
        metadata longtext,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_booking_group (booking_group_id),
        KEY idx_gateway_transaction (gateway_transaction_id),
        KEY idx_status (status),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Waitlist for full courses
    $sql_educator_waitlist = "CREATE TABLE {$table_prefix}educator_waitlist (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        course_post_id bigint UNSIGNED NOT NULL,
        customer_post_id bigint UNSIGNED NOT NULL,
        position int UNSIGNED NOT NULL,
        notified_at datetime NULL,
        expires_at datetime NULL,
        status ENUM('waiting', 'notified', 'converted', 'expired', 'cancelled') DEFAULT 'waiting',
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_course_position (course_post_id, position),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Course Availability Cache (performance optimization)
    $sql_educator_course_availability = "CREATE TABLE {$table_prefix}educator_course_availability (
        course_post_id bigint UNSIGNED NOT NULL,
        capacity int UNSIGNED NOT NULL,
        booked_count int UNSIGNED DEFAULT 0,
        available_count int UNSIGNED DEFAULT 0,
        last_calculated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (course_post_id),
        KEY idx_calculated (last_calculated)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // ============================================================
    // EMAIL SYSTEM TABLES
    // ============================================================

    // Email Queue (stores all pending/sent emails)
    $sql_email_queue = "CREATE TABLE {$table_prefix}email_queue (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id bigint UNSIGNED,
        educator_id bigint UNSIGNED,
        recipient_email varchar(255) NOT NULL,
        recipient_name varchar(255),
        email_type varchar(50) NOT NULL,
        subject varchar(255),
        template_key varchar(100),
        template_data longtext COMMENT 'JSON serialized template variables',
        html_body longtext,
        status ENUM('pending', 'processing', 'sent', 'failed', 'dead_letter', 'cancelled') DEFAULT 'pending',
        attempts int UNSIGNED DEFAULT 0,
        max_attempts int UNSIGNED DEFAULT 5,
        last_error text,
        scheduled_at datetime,
        sent_at datetime,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_status (status),
        KEY idx_scheduled (scheduled_at),
        KEY idx_recipient (recipient_email),
        KEY idx_type (email_type),
        KEY idx_booking (booking_id),
        KEY idx_educator (educator_id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Email Templates (customizable by educator)
    $sql_email_templates = "CREATE TABLE {$table_prefix}email_templates (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        educator_id bigint UNSIGNED,
        template_key varchar(100) NOT NULL,
        subject varchar(255),
        body longtext,
        variables longtext COMMENT 'JSON array of available variables',
        is_active tinyint(1) DEFAULT 1,
        version int UNSIGNED DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY idx_educator_template (educator_id, template_key),
        KEY idx_active (is_active),
        KEY idx_template_key (template_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Email Attachments (for files sent with emails)
    $sql_email_attachments = "CREATE TABLE {$table_prefix}email_attachments (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        email_queue_id bigint UNSIGNED NOT NULL,
        file_path varchar(255) NOT NULL,
        file_name varchar(255),
        mime_type varchar(100),
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_email (email_queue_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Voucher Usage Tracking
    $sql_educator_voucher_usage = "CREATE TABLE {$table_prefix}educator_voucher_usage (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        booking_id bigint UNSIGNED NOT NULL,
        voucher_code varchar(100) NOT NULL,
        voucher_id bigint UNSIGNED,
        voucher_type varchar(50) DEFAULT 'single',
        redeemed_value decimal(10,2) NOT NULL,
        original_amount decimal(10,2) NOT NULL,
        discounted_amount decimal(10,2) NOT NULL,
        applied_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        KEY idx_booking (booking_id),
        KEY idx_voucher_code (voucher_code),
        KEY idx_applied (applied_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Coupon Usage Tracking
    $sql_educator_coupon_usage = "CREATE TABLE {$table_prefix}educator_coupon_usage (
        id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
        coupon_id bigint UNSIGNED NOT NULL,
        coupon_code varchar(50) NOT NULL,
        booking_id bigint UNSIGNED NOT NULL,
        customer_email varchar(255) NOT NULL,
        discount_amount decimal(10,2) NOT NULL,
        original_amount decimal(10,2) NOT NULL,
        used_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_coupon_id (coupon_id),
        KEY idx_coupon_code (coupon_code),
        KEY idx_booking (booking_id),
        KEY idx_customer (customer_email),
        KEY idx_used_at (used_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    // Execute SQL statements in correct order: Parent tables FIRST, then child tables
    
    // ============================================================
    // EDUCATOR BOOKING SYSTEM - Create tables in dependency order
    // ============================================================
    
    // 1. Tables with no dependencies (reference wp_posts which already exists)
    dbDelta($sql_educator_course_recurrence);     // References wp_posts (course templates)
    dbDelta($sql_educator_booking_groups);       // References wp_posts (customers)
    dbDelta($sql_educator_course_availability);   // References wp_posts (courses)
    
    // 2. Tables that reference booking groups
    dbDelta($sql_educator_bookings);             // References booking_groups, wp_posts (courses & customers)
    dbDelta($sql_educator_payment_transactions); // References booking_groups
    
    // 3. Tables that reference bookings
    dbDelta($sql_educator_booking_details);      // References bookings
    dbDelta($sql_educator_booking_meta);         // References bookings
    dbDelta($sql_educator_voucher_usage);        // References bookings
    dbDelta($sql_educator_coupon_usage);         // References bookings
    
    // 4. Waitlist references wp_posts
    dbDelta($sql_educator_waitlist);             // References wp_posts (courses & customers)

    // ============================================================
    // EMAIL SYSTEM - Create tables
    // ============================================================
    dbDelta($sql_email_queue);        // Email queue (no dependencies)
    dbDelta($sql_email_templates);    // Email templates (no dependencies)
    dbDelta($sql_email_attachments);  // Email attachments (references email_queue)

    // 5. Add foreign keys separately since dbDelta doesn't handle them
    hmwevents_ensure_foreign_keys();

    // Add version option to track plugin database version
    add_option('hmwevents_db_version', '1.0.0');

    // Check if there were any errors during table creation
    if ($wpdb->last_error) {
        error_log('HMWEvents install error: ' . $wpdb->last_error);
        return new WP_Error('hmwevents_install_error', $wpdb->last_error, ['status' => 500]);
    }

    // ✅ UPDATE THE DATABASE VERSION
    update_option('hmwevents_db_version', '1.0.0');

    // Flush rewrite rules
    flush_rewrite_rules();
}

/**
 * Get list of all foreign key constraint names
 * Centralized list to avoid duplication (DRY principle)
 *
 * NOTE: When adding new foreign keys, add the constraint name to this list
 * AND add the full constraint definition to hmwevents_add_foreign_keys()
 *
 * @since 1.0.0
 * @return array List of foreign key constraint names
 */
function hmwevents_get_foreign_key_constraints()
{
    return [
        'fk_voucher_usage_booking',
      // Educator booking system foreign keys
      'fk_educator_booking_groups_customer',
      'fk_educator_bookings_group',
      'fk_educator_bookings_course',
      'fk_educator_bookings_customer',
      'fk_educator_booking_details_booking',
      'fk_educator_booking_meta_booking',
      'fk_educator_payment_transactions_group',
      'fk_educator_waitlist_course',
      'fk_educator_waitlist_customer',
      'fk_educator_course_availability_course',
      'fk_educator_course_recurrence_template',
            // Email system foreign keys
            'fk_email_attachments_queue',
    ];
}

/**
 * Drop all foreign keys (used before migration)
 *
 * @since 1.0.0
 */
function hmwevents_drop_foreign_keys()
{
    global $wpdb;

    $constraints = hmwevents_get_foreign_key_constraints();

    foreach ($constraints as $constraint) {
        // Find which table has this constraint
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
 * Check and recreate foreign keys after migration
 * This should run on plugin activation and after detecting prefix changes
 *
 * @since 1.0.0
 */
function hmwevents_ensure_foreign_keys()
{
    global $wpdb;

    // Check if foreign keys are supported
    $supports_fk = $wpdb->get_var("SELECT @@foreign_key_checks");
    if (!$supports_fk) {
        error_log('HMWEvents: Foreign keys not supported on this server');
        return false;
    }

    // Add option to disable foreign keys via constant
    if (defined('HMWEvents_DISABLE_FOREIGN_KEYS') && HMWEvents_DISABLE_FOREIGN_KEYS) {
        error_log('HMWEvents: Foreign keys disabled via constant');
        return false;
    }

    // Store current table prefix in option for migration detection
    $table_prefix = $wpdb->prefix;
    $stored_prefix = get_option('hmwevents_table_prefix', '');
    
    $should_recreate = false;

    if ($stored_prefix && $stored_prefix !== $table_prefix) {
        // Prefix changed - drop ALL foreign keys and recreate
        error_log('HMWEvents: Table prefix changed from ' . $stored_prefix . ' to ' . $table_prefix . ', dropping all foreign keys...');
        hmwevents_drop_foreign_keys();
        $should_recreate = true;
    }
    
    update_option('hmwevents_table_prefix', $table_prefix);

    // If prefix changed, recreate all foreign keys
    if ($should_recreate) {
        error_log('HMWEvents: Recreating all foreign keys with new prefix...');
        hmwevents_add_foreign_keys();
        return;
    }

    // Otherwise, check if any are missing
    $expected_constraints = hmwevents_get_foreign_key_constraints();

    // Check how many of our expected constraints actually exist
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
        // Drop all and recreate to ensure consistency
        error_log('HMWEvents: Dropping all foreign keys to ensure consistency...');
        hmwevents_drop_foreign_keys();
        hmwevents_add_foreign_keys();
    } else {
        error_log('HMWEvents: All ' . $total_expected . ' foreign keys are present and correct.');
    }
}

/**
 * Add foreign keys separately since dbDelta doesn't handle them
 * Enhanced with automatic orphan cleanup on FK creation failure.
 *
 * @since 1.0.0
 * @updated 1.0.17 Added cleanup SQL and automatic retry on referential errors
 */
function hmwevents_add_foreign_keys()
{
    global $wpdb;
    $table_prefix = $wpdb->prefix;

    // Check and add foreign keys
    $foreign_keys = [
      // ============================================================
      // EDUCATOR BOOKING SYSTEM FOREIGN KEYS
      // ============================================================
      [
        'table' => "{$table_prefix}educator_booking_groups",
        'constraint' => 'fk_educator_booking_groups_customer',
        'sql' => "ALTER TABLE {$table_prefix}educator_booking_groups 
                     ADD CONSTRAINT fk_educator_booking_groups_customer 
                     FOREIGN KEY (customer_post_id) REFERENCES {$table_prefix}posts(ID) ON DELETE CASCADE",
        'cleanup' => "DELETE bg FROM {$table_prefix}educator_booking_groups bg LEFT JOIN {$wpdb->posts} p ON bg.customer_post_id = p.ID WHERE p.ID IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_bookings",
        'constraint' => 'fk_educator_bookings_group',
        'sql' => "ALTER TABLE {$table_prefix}educator_bookings 
                     ADD CONSTRAINT fk_educator_bookings_group 
                     FOREIGN KEY (booking_group_id) REFERENCES {$table_prefix}educator_booking_groups(id) ON DELETE CASCADE",
        'cleanup' => "DELETE eb FROM {$table_prefix}educator_bookings eb LEFT JOIN {$table_prefix}educator_booking_groups bg ON eb.booking_group_id = bg.id WHERE bg.id IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_bookings",
        'constraint' => 'fk_educator_bookings_course',
        'sql' => "ALTER TABLE {$table_prefix}educator_bookings 
                     ADD CONSTRAINT fk_educator_bookings_course 
                     FOREIGN KEY (course_post_id) REFERENCES {$table_prefix}posts(ID) ON DELETE CASCADE",
        'cleanup' => "DELETE eb FROM {$table_prefix}educator_bookings eb LEFT JOIN {$wpdb->posts} p ON eb.course_post_id = p.ID WHERE p.ID IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_bookings",
        'constraint' => 'fk_educator_bookings_customer',
        'sql' => "ALTER TABLE {$table_prefix}educator_bookings 
                     ADD CONSTRAINT fk_educator_bookings_customer 
                     FOREIGN KEY (customer_post_id) REFERENCES {$table_prefix}posts(ID) ON DELETE CASCADE",
        'cleanup' => "DELETE eb FROM {$table_prefix}educator_bookings eb LEFT JOIN {$wpdb->posts} p ON eb.customer_post_id = p.ID WHERE p.ID IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_booking_details",
        'constraint' => 'fk_educator_booking_details_booking',
        'sql' => "ALTER TABLE {$table_prefix}educator_booking_details 
                     ADD CONSTRAINT fk_educator_booking_details_booking 
                     FOREIGN KEY (booking_id) REFERENCES {$table_prefix}educator_bookings(id) ON DELETE CASCADE",
        'cleanup' => "DELETE bd FROM {$table_prefix}educator_booking_details bd LEFT JOIN {$table_prefix}educator_bookings b ON bd.booking_id = b.id WHERE b.id IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_booking_meta",
        'constraint' => 'fk_educator_booking_meta_booking',
        'sql' => "ALTER TABLE {$table_prefix}educator_booking_meta 
                     ADD CONSTRAINT fk_educator_booking_meta_booking 
                     FOREIGN KEY (booking_id) REFERENCES {$table_prefix}educator_bookings(id) ON DELETE CASCADE",
        'cleanup' => "DELETE bm FROM {$table_prefix}educator_booking_meta bm LEFT JOIN {$table_prefix}educator_bookings b ON bm.booking_id = b.id WHERE b.id IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_voucher_usage",
        'constraint' => 'fk_voucher_usage_booking',
        'sql' => "ALTER TABLE {$table_prefix}educator_voucher_usage 
                     ADD CONSTRAINT fk_voucher_usage_booking 
                     FOREIGN KEY (booking_id) REFERENCES {$table_prefix}educator_bookings(id) ON DELETE CASCADE",
        'cleanup' => "DELETE vu FROM {$table_prefix}educator_voucher_usage vu LEFT JOIN {$table_prefix}educator_bookings b ON vu.booking_id = b.id WHERE b.id IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_payment_transactions",
        'constraint' => 'fk_educator_payment_transactions_group',
        'sql' => "ALTER TABLE {$table_prefix}educator_payment_transactions 
                     ADD CONSTRAINT fk_educator_payment_transactions_group 
                     FOREIGN KEY (booking_group_id) REFERENCES {$table_prefix}educator_booking_groups(id) ON DELETE CASCADE",
        'cleanup' => "DELETE pt FROM {$table_prefix}educator_payment_transactions pt LEFT JOIN {$table_prefix}educator_booking_groups bg ON pt.booking_group_id = bg.id WHERE bg.id IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_waitlist",
        'constraint' => 'fk_educator_waitlist_course',
        'sql' => "ALTER TABLE {$table_prefix}educator_waitlist 
                     ADD CONSTRAINT fk_educator_waitlist_course 
                     FOREIGN KEY (course_post_id) REFERENCES {$table_prefix}posts(ID) ON DELETE CASCADE",
        'cleanup' => "DELETE w FROM {$table_prefix}educator_waitlist w LEFT JOIN {$wpdb->posts} p ON w.course_post_id = p.ID WHERE p.ID IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_waitlist",
        'constraint' => 'fk_educator_waitlist_customer',
        'sql' => "ALTER TABLE {$table_prefix}educator_waitlist 
                     ADD CONSTRAINT fk_educator_waitlist_customer 
                     FOREIGN KEY (customer_post_id) REFERENCES {$table_prefix}posts(ID) ON DELETE CASCADE",
        'cleanup' => "DELETE w FROM {$table_prefix}educator_waitlist w LEFT JOIN {$wpdb->posts} p ON w.customer_post_id = p.ID WHERE p.ID IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_course_availability",
        'constraint' => 'fk_educator_course_availability_course',
        'sql' => "ALTER TABLE {$table_prefix}educator_course_availability 
                     ADD CONSTRAINT fk_educator_course_availability_course 
                     FOREIGN KEY (course_post_id) REFERENCES {$table_prefix}posts(ID) ON DELETE CASCADE",
        'cleanup' => "DELETE ca FROM {$table_prefix}educator_course_availability ca LEFT JOIN {$wpdb->posts} p ON ca.course_post_id = p.ID WHERE p.ID IS NULL",
      ],
      [
        'table' => "{$table_prefix}educator_course_recurrence",
        'constraint' => 'fk_educator_course_recurrence_template',
        'sql' => "ALTER TABLE {$table_prefix}educator_course_recurrence 
                     ADD CONSTRAINT fk_educator_course_recurrence_template 
                     FOREIGN KEY (course_template_id) REFERENCES {$table_prefix}posts(ID) ON DELETE CASCADE",
        'cleanup' => "DELETE cr FROM {$table_prefix}educator_course_recurrence cr LEFT JOIN {$wpdb->posts} p ON cr.course_template_id = p.ID WHERE p.ID IS NULL",
            ],
            // ============================================================
            // EMAIL SYSTEM FOREIGN KEYS
            // ============================================================
            [
                'table' => "{$table_prefix}email_attachments",
                'constraint' => 'fk_email_attachments_queue',
                'sql' => "ALTER TABLE {$table_prefix}email_attachments
                                         ADD CONSTRAINT fk_email_attachments_queue
                                         FOREIGN KEY (email_queue_id) REFERENCES {$table_prefix}email_queue(id) ON DELETE CASCADE",
                'cleanup' => "DELETE ea FROM {$table_prefix}email_attachments ea LEFT JOIN {$table_prefix}email_queue eq ON ea.email_queue_id = eq.id WHERE eq.id IS NULL",
            ]
    ];

    foreach ($foreign_keys as $fk) {
        // Check if foreign key already exists
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
                // Check if it's a referential integrity error (errno 1452)
                $is_fk_error = strpos($wpdb->last_error, '1452') !== false 
                    || strpos($wpdb->last_error, 'foreign key constraint fails') !== false
                    || strpos($wpdb->last_error, 'Cannot add or update a child row') !== false;

                if ($is_fk_error && !empty($fk['cleanup'])) {
                    error_log('HMWEvents: FK ' . $fk['constraint'] . ' failed with referential error. Cleaning orphans and retrying...');
                    $cleaned = $wpdb->query($fk['cleanup']);
                    error_log('HMWEvents: Cleaned ' . ($cleaned ?: 0) . ' orphaned rows for ' . $fk['constraint']);
                    
                    // Retry
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

    // Store that we're using foreign keys with this prefix
    update_option('hmwevents_uses_foreign_keys', true);

    return true;
}

/**
 * Clean up orphaned data in child tables before recreating foreign keys.
 * 
 * This ensures FK creation won't fail due to orphaned records left behind
 * after data migration, prefix changes, or manual DB manipulation.
 * 
 * IMPORTANT: Foreign keys should be dropped BEFORE calling this function
 * to avoid FK constraint errors during cleanup.
 *
 * @since 1.0.17
 * @return array Summary of cleaned records per table/column
 */
function hmwevents_clean_orphaned_data()
{
    global $wpdb;
    $table_prefix = $wpdb->prefix;

    $cleaned = [];

    // ============================================================
    // EDUCATOR BOOKING SYSTEM - Orphan cleanup (child→parent order)
    // ============================================================

    // 1. booking_details → bookings (orphaned booking_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_booking_details bd
        LEFT JOIN {$table_prefix}educator_bookings b ON bd.booking_id = b.id
        WHERE b.id IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE bd FROM {$table_prefix}educator_booking_details bd
            LEFT JOIN {$table_prefix}educator_bookings b ON bd.booking_id = b.id
            WHERE b.id IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned booking_details (missing booking)");
        $cleaned['educator_booking_details.booking_id'] = (int) $orphaned;
    }

    // 2. booking_meta → bookings (orphaned booking_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_booking_meta bm
        LEFT JOIN {$table_prefix}educator_bookings b ON bm.booking_id = b.id
        WHERE b.id IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE bm FROM {$table_prefix}educator_booking_meta bm
            LEFT JOIN {$table_prefix}educator_bookings b ON bm.booking_id = b.id
            WHERE b.id IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned booking_meta (missing booking)");
        $cleaned['educator_booking_meta.booking_id'] = (int) $orphaned;
    }

    // 3. voucher_usage → bookings (orphaned booking_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_voucher_usage vu
        LEFT JOIN {$table_prefix}educator_bookings b ON vu.booking_id = b.id
        WHERE b.id IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE vu FROM {$table_prefix}educator_voucher_usage vu
            LEFT JOIN {$table_prefix}educator_bookings b ON vu.booking_id = b.id
            WHERE b.id IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned voucher_usage (missing booking)");
        $cleaned['educator_voucher_usage.booking_id'] = (int) $orphaned;
    }

    // 4. bookings → booking_groups (orphaned booking_group_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_bookings eb
        LEFT JOIN {$table_prefix}educator_booking_groups bg ON eb.booking_group_id = bg.id
        WHERE bg.id IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE eb FROM {$table_prefix}educator_bookings eb
            LEFT JOIN {$table_prefix}educator_booking_groups bg ON eb.booking_group_id = bg.id
            WHERE bg.id IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned bookings (missing booking_group)");
        $cleaned['educator_bookings.booking_group_id'] = (int) $orphaned;
    }

    // 5. bookings → wp_posts (orphaned course_post_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_bookings eb
        LEFT JOIN {$wpdb->posts} p ON eb.course_post_id = p.ID
        WHERE p.ID IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE eb FROM {$table_prefix}educator_bookings eb
            LEFT JOIN {$wpdb->posts} p ON eb.course_post_id = p.ID
            WHERE p.ID IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned bookings (missing course_post)");
        $cleaned['educator_bookings.course_post_id'] = (int) $orphaned;
    }

    // 6. bookings → wp_posts (orphaned customer_post_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_bookings eb
        LEFT JOIN {$wpdb->posts} p ON eb.customer_post_id = p.ID
        WHERE p.ID IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE eb FROM {$table_prefix}educator_bookings eb
            LEFT JOIN {$wpdb->posts} p ON eb.customer_post_id = p.ID
            WHERE p.ID IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned bookings (missing customer_post)");
        $cleaned['educator_bookings.customer_post_id'] = (int) $orphaned;
    }

    // 7. payment_transactions → booking_groups (orphaned booking_group_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_payment_transactions pt
        LEFT JOIN {$table_prefix}educator_booking_groups bg ON pt.booking_group_id = bg.id
        WHERE bg.id IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE pt FROM {$table_prefix}educator_payment_transactions pt
            LEFT JOIN {$table_prefix}educator_booking_groups bg ON pt.booking_group_id = bg.id
            WHERE bg.id IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned payment_transactions (missing booking_group)");
        $cleaned['educator_payment_transactions.booking_group_id'] = (int) $orphaned;
    }

    // 8. booking_groups → wp_posts (orphaned customer_post_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_booking_groups bg
        LEFT JOIN {$wpdb->posts} p ON bg.customer_post_id = p.ID
        WHERE p.ID IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE bg FROM {$table_prefix}educator_booking_groups bg
            LEFT JOIN {$wpdb->posts} p ON bg.customer_post_id = p.ID
            WHERE p.ID IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned booking_groups (missing customer_post)");
        $cleaned['educator_booking_groups.customer_post_id'] = (int) $orphaned;
    }

    // 9. waitlist → wp_posts (orphaned course_post_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_waitlist w
        LEFT JOIN {$wpdb->posts} p ON w.course_post_id = p.ID
        WHERE p.ID IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE w FROM {$table_prefix}educator_waitlist w
            LEFT JOIN {$wpdb->posts} p ON w.course_post_id = p.ID
            WHERE p.ID IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned waitlist (missing course_post)");
        $cleaned['educator_waitlist.course_post_id'] = (int) $orphaned;
    }

    // 10. waitlist → wp_posts (orphaned customer_post_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_waitlist w
        LEFT JOIN {$wpdb->posts} p ON w.customer_post_id = p.ID
        WHERE p.ID IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE w FROM {$table_prefix}educator_waitlist w
            LEFT JOIN {$wpdb->posts} p ON w.customer_post_id = p.ID
            WHERE p.ID IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned waitlist (missing customer_post)");
        $cleaned['educator_waitlist.customer_post_id'] = (int) $orphaned;
    }

    // 11. course_availability → wp_posts (orphaned course_post_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_course_availability ca
        LEFT JOIN {$wpdb->posts} p ON ca.course_post_id = p.ID
        WHERE p.ID IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE ca FROM {$table_prefix}educator_course_availability ca
            LEFT JOIN {$wpdb->posts} p ON ca.course_post_id = p.ID
            WHERE p.ID IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned course_availability (missing course_post)");
        $cleaned['educator_course_availability.course_post_id'] = (int) $orphaned;
    }

    // 12. course_recurrence → wp_posts (orphaned course_template_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}educator_course_recurrence cr
        LEFT JOIN {$wpdb->posts} p ON cr.course_template_id = p.ID
        WHERE p.ID IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE cr FROM {$table_prefix}educator_course_recurrence cr
            LEFT JOIN {$wpdb->posts} p ON cr.course_template_id = p.ID
            WHERE p.ID IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned course_recurrence (missing course_template)");
        $cleaned['educator_course_recurrence.course_template_id'] = (int) $orphaned;
    }

    // ============================================================
    // EMAIL SYSTEM - Orphan cleanup
    // ============================================================

    // 13. email_attachments → email_queue (orphaned email_queue_id)
    $orphaned = $wpdb->get_var("
        SELECT COUNT(*) FROM {$table_prefix}email_attachments ea
        LEFT JOIN {$table_prefix}email_queue eq ON ea.email_queue_id = eq.id
        WHERE eq.id IS NULL
    ");
    if ($orphaned > 0) {
        $wpdb->query("
            DELETE ea FROM {$table_prefix}email_attachments ea
            LEFT JOIN {$table_prefix}email_queue eq ON ea.email_queue_id = eq.id
            WHERE eq.id IS NULL
        ");
        error_log("HMWEvents: Cleaned $orphaned orphaned email_attachments (missing email_queue)");
        $cleaned['email_attachments.email_queue_id'] = (int) $orphaned;
    }

    return $cleaned;
}

/**
 * Full repair: drop FKs, clean orphans, rebuild FKs.
 * 
 * This is the safest approach for data migration scenarios:
 * 1. Drop all foreign keys (so no FK errors during cleanup)
 * 2. Remove orphaned child records
 * 3. Recreate all foreign keys
 * 
 * Designed to be called on plugin activation or manually via WP-CLI.
 *
 * @since 1.0.17
 * @return bool True on success
 */
function hmwevents_repair_and_rebuild_foreign_keys()
{
    global $wpdb;

    // Check if foreign keys are supported
    $supports_fk = $wpdb->get_var("SELECT @@foreign_key_checks");
    if (!$supports_fk) {
        error_log('HMWEvents: Foreign keys not supported on this server');
        return false;
    }

    // Add option to disable foreign keys via constant
    if (defined('HMWEvents_DISABLE_FOREIGN_KEYS') && HMWEvents_DISABLE_FOREIGN_KEYS) {
        error_log('HMWEvents: Foreign keys disabled via constant');
        return false;
    }

    error_log('HMWEvents: Starting full foreign key repair and rebuild...');

    // Step 1: Drop all existing foreign keys
    hmwevents_drop_foreign_keys();

    // Step 2: Clean orphaned data
    $cleaned = hmwevents_clean_orphaned_data();
    $total_cleaned = array_sum($cleaned);
    if ($total_cleaned > 0) {
        error_log('HMWEvents: Cleaned ' . $total_cleaned . ' total orphaned records: ' . json_encode($cleaned));
    } else {
        error_log('HMWEvents: No orphaned data found.');
    }

    // Step 3: Rebuild all foreign keys
    hmwevents_add_foreign_keys();

    // Step 4: Store current prefix for future migration detection
    update_option('hmwevents_table_prefix', $wpdb->prefix);

    error_log('HMWEvents: Foreign key repair and rebuild complete.');
    return true;
}

/**
 * Plugin activation hook - ensure foreign keys are properly set up
 * Call this on plugin activation to handle migration scenarios.
 * 
 * Uses the full repair-and-rebuild cycle to handle any orphaned data
 * that may exist after table prefix changes or data migrations.
 *
 * @since 1.0.0
 * @updated 1.0.17 Uses repair_and_rebuild for robust migration handling
 */
function hmwevents_activation()
{
    // Run installation to ensure tables exist
    hmwevents_install();

    // Full repair cycle: drop FKs, clean orphans, rebuild FKs
    hmwevents_repair_and_rebuild_foreign_keys();

    // Add capabilities to educator role
    $educator_role = new \HMWEvents\Roles\EducatorRole();
    $educator_role->add_capabilities();

    // Ensure foreign keys are correct for current prefix
    hmwevents_ensure_foreign_keys();

    // Flush rewrite rules to ensure custom post types work
    flush_rewrite_rules();
}

/**
 * Uninstall plugin - remove database tables and options.
 *
 * @since 1.0.0
 */
function hmwevents_uninstall()
{
    global $wpdb;

    $table_prefix = $wpdb->prefix;

    // Drop all tables in reverse order (due to foreign key constraints)
    $tables = [
      // Email system tables
      'email_attachments',
      'email_queue',
      'email_templates',
      // Educator booking system tables
      'educator_booking_details',
      'educator_payment_transactions',
      'educator_bookings',
      'educator_booking_groups',
      'educator_waitlist',
      'educator_course_availability',
      'educator_course_recurrence',
    ];

    foreach ($tables as $table) {
        $wpdb->query("DROP TABLE IF EXISTS {$table_prefix}{$table}");
    }

    // Remove options
    delete_option('hmwevents_db_version');
    delete_option('hmwevents_table_prefix');
    delete_option('hmwevents_uses_foreign_keys');

    // Clear any cached data
    wp_cache_flush();
}
