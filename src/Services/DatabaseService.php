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
  const CURRENT_DB_VERSION = '2.7';

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
   *
   * @since 1.0.0
   */
  public static function init()
  {
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

      // Data migrations (versioned separately from schema).
      self::migrate_by_invitation_status();
      self::migrate_new_status();
      self::migrate_educator_template_keys();

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
   * Migrate legacy 'by_invitation' post status to the '_event_is_invitation_only'
   * post meta flag (introduced in the invitation-only refactor).
   *
   * Events that still carry the removed 'by_invitation' status no longer
   * appear in the admin list because the status is no longer registered.
   * Convert them to 'publish' and set the invitation-only meta.
   *
   * @since 2.4.0
   */
  public static function migrate_by_invitation_status(): int
  {
    global $wpdb;

    $option = 'hmwevents_migrated_by_invitation_status';
    if (get_option($option)) {
      return 0;
    }

    $post_type = \HMWEvents\PostTypes\Event::POST_TYPE;
    $meta_key  = \HMWEvents\PostTypes\Event::META_INVITATION_ONLY;

    $ids = $wpdb->get_col($wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
      $post_type,
      'by_invitation'
    ));

    $migrated = 0;
    foreach ($ids as $id) {
      $updated = $wpdb->update(
        $wpdb->posts,
        ['post_status' => 'publish'],
        ['ID' => (int) $id],
        ['%s'],
        ['%d']
      );

      if ($updated !== false) {
        update_post_meta((int) $id, $meta_key, '1');
        $migrated++;
      }
    }

    update_option($option, '1');
    error_log('HMWEvents: Migrated ' . $migrated . " 'by_invitation' events to invitation-only meta.");

    return $migrated;
  }

  /**
   * Migrate events stuck in the WordPress core 'new' status sentinel to 'draft'.
   *
   * The WorkflowEnforcer previously reverted blocked transitions to the
   * 'new' sentinel status, writing it into the database. 'new' is not a
   * registered post status, so affected events were counted in the admin
   * list but not displayed.
   *
   * @since 2.5.0
   */
  public static function migrate_new_status(): int
  {
    global $wpdb;

    $option = 'hmwevents_migrated_new_status';
    if (get_option($option)) {
      return 0;
    }

    $post_type = \HMWEvents\PostTypes\Event::POST_TYPE;

    $ids = $wpdb->get_col($wpdb->prepare(
      "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
      $post_type,
      'new'
    ));

    $migrated = 0;
    foreach ($ids as $id) {
      $updated = $wpdb->update(
        $wpdb->posts,
        ['post_status' => 'draft'],
        ['ID' => (int) $id],
        ['%s'],
        ['%d']
      );

      if ($updated !== false) {
        clean_post_cache((int) $id);
        $migrated++;
      }
    }

    update_option($option, '1');
    error_log('HMWEvents: Migrated ' . $migrated . " events stuck in 'new' status to draft.");

    return $migrated;
  }

  /**
   * Rename the legacy 'educator_new_booking' email template key to
   * 'organizer_new_booking' across the email templates and email queue tables.
   *
   * @since 2.7.0
   */
  public static function migrate_educator_template_keys(): int
  {
    global $wpdb;

    $option = 'hmwevents_migrated_educator_template_keys';
    if (get_option($option)) {
      return 0;
    }

    $templates_table = self::get_table_name('email_templates');
    $queue_table     = self::get_table_name('email_queue');

    $templates = $wpdb->query($wpdb->prepare(
      "UPDATE {$templates_table} SET template_key = %s WHERE template_key = %s",
      'organizer_new_booking',
      'educator_new_booking'
    ));

    $queue = $wpdb->query($wpdb->prepare(
      "UPDATE {$queue_table} SET email_type = %s, template_key = %s WHERE email_type = %s OR template_key = %s",
      'organizer_new_booking',
      'organizer_new_booking',
      'educator_new_booking',
      'educator_new_booking'
    ));

    update_option($option, '1');
    error_log('HMWEvents: Renamed educator_new_booking email template keys to organizer_new_booking (' . (int) $templates . ' templates, ' . (int) $queue . ' queue rows).');

    return (int) $templates + (int) $queue;
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
