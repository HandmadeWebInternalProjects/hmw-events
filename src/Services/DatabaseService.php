<?php

namespace HMWEvents\Services;

/**
 * Database Service for HMWEvents plugin.
 *
 * Handles database schema creation and upgrades with version tracking
 * to prevent running migrations on every page load.
 *
 * @package HMWEvents\Services
 * @since 1.0.0
 */

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Database Service Class.
 */
class DatabaseService
{
  /**
   * Database version option name.
   */
  const DB_VERSION_OPTION = 'hmwevents_db_version';

  /**
   * Current database schema version.
   * Increment this when schema changes are made.
   *
   * @since 2.0.0 Reset to 2.0 for rebuild.
   */
  const CURRENT_DB_VERSION = '2.0';

  /**
   * Get the WordPress database instance.
   *
   * @since 1.0.0
   * @return \wpdb
   */
  public static function get_wpdb()
  {
    global $wpdb;
    return $wpdb;
  }

  /**
   * Initialize database service.
   * Only runs upgrade checks on admin requests.
   *
   * @since 1.0.0
   */
  public static function init()
  {
    // Only run upgrade check on admin requests or during WP-CLI
    if (is_admin() || (defined('WP_CLI') && constant('WP_CLI'))) {
      self::maybe_upgrade();
    }
  }

  /**
   * Get table name with hmwevents_ prefix.
   *
   * @since 2.0.0
   * @param string $table_name The table name without prefix (e.g. 'bookings').
   * @return string The full table name with wp and hmwevents prefix.
   */
  public static function get_table_name($table_name)
  {
    global $wpdb;
    return $wpdb->prefix . 'hmwevents_' . $table_name;
  }

  /**
   * Get all hmwevents_ table names with full prefix.
   *
   * @since 2.0.0
   * @return array<string, string> Short name => full table name
   */
  public static function get_all_table_names()
  {
    $names = [
      'event_recurrence',
      'booking_groups',
      'event_availability',
      'event_attendance_options',
      'event_templates',
      'saved_report_filters',
      'email_queue',
      'email_templates',
      'private_registration_tokens',
      'bookings',
      'payment_transactions',
      'booking_details',
      'booking_meta',
      'booking_history',
      'voucher_usage',
      'coupon_usage',
      'registration_documents',
      'waitlist',
      'email_attachments',
    ];

    return array_combine($names, array_map([self::class, 'get_table_name'], $names));
  }

  /**
   * Check if database needs upgrading and run if needed.
   *
   * @since 1.0.0
   */
  public static function maybe_upgrade()
  {
    $installed_version = get_option(self::DB_VERSION_OPTION);

    // Only run if version doesn't match or no version set
    if ($installed_version !== self::CURRENT_DB_VERSION) {
      self::upgrade($installed_version);
    }
  }

  /**
   * Run database upgrade.
   *
   * @since 1.0.0
   * @param string|false $from_version Version upgrading from.
   */
  private static function upgrade($from_version)
  {
    global $wpdb;

    // Prevent concurrent upgrades
    $lock_option = 'hmwevents_db_upgrade_lock';
    $lock_value = time();
    
    // Try to acquire lock (valid for 60 seconds)
    $locked = get_transient($lock_option);
    if ($locked && (time() - $locked) < 60) {
      error_log('HMWEvents: Database upgrade already in progress, skipping.');
      return;
    }

    // Set lock
    set_transient($lock_option, $lock_value, 60);

    try {
      require_once ABSPATH . 'wp-admin/includes/upgrade.php';

      // Run the installation/upgrade
      require_once HMWEvents_ABSPATH . 'includes/install.php';
      hmwevents_install();

      // Update version after successful upgrade
      update_option(self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION);

      error_log('HMWEvents: Database upgraded from version ' . ($from_version ?: 'none') . ' to ' . self::CURRENT_DB_VERSION);

    } catch (\Exception $e) {
      error_log('HMWEvents: Database upgrade failed: ' . $e->getMessage());
    } finally {
      // Release lock
      delete_transient($lock_option);
    }
  }

  /**
   * Force database upgrade (for manual triggering).
   *
   * @since 1.0.0
   */
  public static function force_upgrade()
  {
    delete_option(self::DB_VERSION_OPTION);
    self::maybe_upgrade();
  }

  /**
   * Update the stored schema version to match CURRENT_DB_VERSION.
   * Called after activation/install to align version tracking.
   *
   * @since 2.0.0
   */
  public static function update_schema_version()
  {
    update_option(self::DB_VERSION_OPTION, self::CURRENT_DB_VERSION);
  }
}
