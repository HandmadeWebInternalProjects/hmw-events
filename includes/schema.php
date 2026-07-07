<?php

/**
 * Database schema definitions for HMWEvents plugin (rebuild).
 *
 * All tables are prefixed with 'hmwevents_' to distinguish from the
 * legacy 'educator_' and bare 'email_' tables that will be purged.
 *
 * @package HMWEvents
 * @since 2.0.0
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Returns all CREATE TABLE SQL statements in dependency order.
 *
 * Parent tables (no FKs referencing other hmwevents tables) come first,
 * followed by child tables that reference parent tables, so dbDelta()
 * can safely create them in sequence.
 *
 * @since 2.0.0
 * @return array<string, string> Table name (without prefix) => CREATE TABLE SQL
 */
function hmwevents_get_schema(): array
{
    global $wpdb;
    $prefix = $wpdb->prefix . 'hmwevents_';

    return [

        // ============================================================
        // TIER 1 — Independent tables (no FK deps on other hmwevents tables)
        // ============================================================

        'event_recurrence' => "CREATE TABLE {$prefix}event_recurrence (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            event_post_id bigint UNSIGNED NOT NULL COMMENT 'Parent event (hmw_event CPT)',
            recurrence_type ENUM('none', 'daily', 'weekly', 'biweekly', 'monthly', 'custom') DEFAULT 'none',
            recurrence_interval int UNSIGNED DEFAULT 1,
            recurrence_days varchar(50) COMMENT 'Comma-separated day numbers (1=Mon...7=Sun)',
            start_date date NOT NULL,
            end_date date,
            max_occurrences int UNSIGNED,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_event (event_post_id),
            KEY idx_active (is_active),
            KEY idx_dates (start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'booking_groups' => "CREATE TABLE {$prefix}booking_groups (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            registrant_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_registrant CPT post ID',
            booking_reference varchar(50) NOT NULL,
            booking_type ENUM('single', 'recurring', 'package') DEFAULT 'single',
            payment_type ENUM('full', 'deposit', 'net_terms') NOT NULL DEFAULT 'full',
            total_bookings int UNSIGNED DEFAULT 1,
            total_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            gst_amount decimal(10,2) DEFAULT 0.00,
            currency varchar(3) DEFAULT 'AUD',
            payment_status ENUM('pending', 'partial', 'paid', 'refunded', 'failed', 'invoiced') DEFAULT 'pending',
            metadata JSON DEFAULT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_reference (booking_reference),
            KEY idx_registrant (registrant_post_id),
            KEY idx_payment_status (payment_status),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'event_availability' => "CREATE TABLE {$prefix}event_availability (
            event_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_event CPT post ID',
            capacity int UNSIGNED NOT NULL,
            booked_count int UNSIGNED DEFAULT 0,
            waitlisted_count int UNSIGNED DEFAULT 0,
            available_count int UNSIGNED DEFAULT 0,
            last_calculated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (event_post_id),
            KEY idx_calculated (last_calculated)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'event_attendance_options' => "CREATE TABLE {$prefix}event_attendance_options (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            event_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_event CPT post ID',
            option_type ENUM('parent', 'parent_child', 'professional', 'couple', 'individual') NOT NULL DEFAULT 'individual',
            label varchar(255) NOT NULL,
            price decimal(10,2) NOT NULL DEFAULT 0.00,
            capacity int UNSIGNED DEFAULT NULL COMMENT 'NULL = uses event-level capacity',
            sort_order int UNSIGNED DEFAULT 0,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_event (event_post_id),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'event_templates' => "CREATE TABLE {$prefix}event_templates (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            title varchar(255) NOT NULL,
            event_type_slug varchar(100) NOT NULL COMMENT 'hmw_event_type taxonomy slug',
            template_data JSON NOT NULL COMMENT 'Default meta/field values',
            is_active tinyint(1) DEFAULT 1,
            is_retired tinyint(1) DEFAULT 0,
            created_by bigint UNSIGNED,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_type (event_type_slug),
            KEY idx_active (is_active, is_retired)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'saved_report_filters' => "CREATE TABLE {$prefix}saved_report_filters (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id bigint UNSIGNED NOT NULL,
            label varchar(255) NOT NULL,
            report_type varchar(50) NOT NULL COMMENT 'registrations, payments, attendees, invoices',
            filter_data JSON NOT NULL,
            is_default tinyint(1) DEFAULT 0,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_user_type (user_id, report_type),
            KEY idx_default (user_id, is_default)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'email_queue' => "CREATE TABLE {$prefix}email_queue (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint UNSIGNED,
            organizer_id bigint UNSIGNED COMMENT 'WP user ID of event organizer',
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
            KEY idx_organizer (organizer_id),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'email_templates' => "CREATE TABLE {$prefix}email_templates (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            organizer_id bigint UNSIGNED COMMENT 'NULL = system default',
            template_key varchar(100) NOT NULL,
            subject varchar(255),
            body longtext,
            variables longtext COMMENT 'JSON array of available variables',
            is_active tinyint(1) DEFAULT 1,
            version int UNSIGNED DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_organizer_template (organizer_id, template_key),
            KEY idx_active (is_active),
            KEY idx_template_key (template_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'private_registration_tokens' => "CREATE TABLE {$prefix}private_registration_tokens (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            event_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_event CPT post ID',
            token varchar(128) NOT NULL,
            recipient_email varchar(255),
            max_uses int UNSIGNED DEFAULT 1,
            use_count int UNSIGNED DEFAULT 0,
            expires_at datetime NOT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_by bigint UNSIGNED COMMENT 'WP user ID',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_token (token),
            KEY idx_event (event_post_id),
            KEY idx_email (recipient_email),
            KEY idx_active_expiry (is_active, expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ============================================================
        // TIER 2 — Tables referencing booking_groups
        // ============================================================

        'bookings' => "CREATE TABLE {$prefix}bookings (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_group_id bigint UNSIGNED NOT NULL,
            event_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_event CPT post ID',
            registrant_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_registrant CPT post ID',
            booking_number varchar(50) NOT NULL,
            attendance_option_id bigint UNSIGNED COMMENT 'Reference to event_attendance_options',
            ticket_type ENUM('full', 'deposit', 'net_terms') NOT NULL DEFAULT 'full',
            ticket_quantity int UNSIGNED DEFAULT 1,
            booking_amount decimal(10,2) NOT NULL DEFAULT 0.00,
            currency varchar(3) DEFAULT 'AUD',
            coupon_code varchar(100),
            discount_amount decimal(10,2) DEFAULT 0.00,
            status ENUM('pending', 'confirmed', 'cancelled', 'waitlisted') DEFAULT 'pending',
            attendance_status ENUM('registered', 'attended', 'no_show') DEFAULT 'registered',
            payment_status ENUM('pending', 'paid', 'refunded', 'failed', 'invoiced') DEFAULT 'pending',
            booking_source varchar(50) DEFAULT 'website',
            cancelled_at datetime NULL,
            deleted_at datetime NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_number (booking_number),
            KEY idx_group (booking_group_id),
            KEY idx_event (event_post_id),
            KEY idx_registrant (registrant_post_id),
            KEY idx_status (status, deleted_at),
            KEY idx_attendance (attendance_status),
            KEY idx_payment (payment_status),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'payment_transactions' => "CREATE TABLE {$prefix}payment_transactions (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ============================================================
        // TIER 3 — Tables referencing bookings
        // ============================================================

        'booking_details' => "CREATE TABLE {$prefix}booking_details (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint UNSIGNED NOT NULL,
            form_data JSON NOT NULL COMMENT 'All form responses stored as JSON',
            form_version varchar(20) DEFAULT '1.0' COMMENT 'Track which form version was used',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY idx_booking (booking_id),
            KEY idx_form_version (form_version)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'booking_meta' => "CREATE TABLE {$prefix}booking_meta (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint UNSIGNED NOT NULL,
            meta_key varchar(255) NOT NULL,
            meta_value longtext,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_booking (booking_id),
            KEY idx_meta_key (meta_key),
            KEY idx_meta_key_value (meta_key, meta_value(191))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'booking_history' => "CREATE TABLE {$prefix}booking_history (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint UNSIGNED NOT NULL,
            field_changed varchar(100) NOT NULL COMMENT 'Column or meta key that changed',
            old_value text,
            new_value text,
            changed_by bigint UNSIGNED COMMENT 'WP user ID',
            change_reason varchar(255),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_booking (booking_id),
            KEY idx_field (field_changed),
            KEY idx_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'voucher_usage' => "CREATE TABLE {$prefix}voucher_usage (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'coupon_usage' => "CREATE TABLE {$prefix}coupon_usage (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            coupon_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_coupon CPT post ID',
            coupon_code varchar(50) NOT NULL,
            booking_id bigint UNSIGNED NOT NULL,
            registrant_email varchar(255) NOT NULL,
            discount_amount decimal(10,2) NOT NULL,
            original_amount decimal(10,2) NOT NULL,
            used_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_coupon_post (coupon_post_id),
            KEY idx_coupon_code (coupon_code),
            KEY idx_booking (booking_id),
            KEY idx_registrant (registrant_email),
            KEY idx_used_at (used_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'registration_documents' => "CREATE TABLE {$prefix}registration_documents (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            booking_id bigint UNSIGNED NOT NULL,
            file_path varchar(500) NOT NULL,
            original_filename varchar(255) NOT NULL,
            mime_type varchar(100),
            file_size bigint UNSIGNED,
            retention_until datetime COMMENT 'PII retention expiry date',
            uploaded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_booking (booking_id),
            KEY idx_retention (retention_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        // ============================================================
        // TIER 4 — Tables referencing other hmwevents tables
        // ============================================================

        'waitlist' => "CREATE TABLE {$prefix}waitlist (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            event_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_event CPT post ID',
            registrant_post_id bigint UNSIGNED NOT NULL COMMENT 'hmw_registrant CPT post ID',
            position int UNSIGNED NOT NULL,
            notified_at datetime NULL,
            expires_at datetime NULL,
            status ENUM('waiting', 'notified', 'converted', 'expired', 'cancelled') DEFAULT 'waiting',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_event_position (event_post_id, position),
            KEY idx_registrant (registrant_post_id),
            KEY idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

        'email_attachments' => "CREATE TABLE {$prefix}email_attachments (
            id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
            email_queue_id bigint UNSIGNED NOT NULL,
            file_path varchar(500) NOT NULL,
            file_name varchar(255),
            mime_type varchar(100),
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_email (email_queue_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
}
