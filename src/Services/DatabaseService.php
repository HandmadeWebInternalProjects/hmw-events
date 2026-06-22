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
   * Current database version.
   * Increment this when schema changes are made.
   */
  const CURRENT_DB_VERSION = '1.3';

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
   * Get table name with prefix.
   *
   * @since 1.0.0
   * @param string $table_name The table name without prefix.
   * @return string The full table name with prefix.
   */
  public static function get_table_name($table_name)
  {
    global $wpdb;
    return $wpdb->prefix . $table_name;
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
}
