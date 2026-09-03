<?php
/**
 * Booking Cleanup Service.
 *
 * Handles automatic cleanup of abandoned bookings.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Booking Cleanup Class.
 */
class BookingCleanup
{
    /**
     * Cron hook name.
     *
     * @var string
     */
    const CRON_HOOK = 'hmwevents_cleanup_abandoned_bookings';

    const PII_CRON_HOOK = 'hmwevents_pii_retention_cleanup';

    private string $groups_table;
    private string $bookings_table;
    private string $availability_table;
    private string $history_table;

    public function __construct()
    {
        $this->groups_table = DatabaseService::get_table_name('booking_groups');
        $this->bookings_table = DatabaseService::get_table_name('bookings');
        $this->availability_table = DatabaseService::get_table_name('event_availability');
        $this->history_table = DatabaseService::get_table_name('booking_history');
    }

    /**
     * Initialize the service.
     *
     * @since 1.0.0
     */
    public function register()
    {
        // Hook into the cron events
        add_action(self::CRON_HOOK, [$this, 'cleanup_abandoned_bookings']);

        add_action(self::PII_CRON_HOOK, [$this, 'purge_archived_pii']);

        if (function_exists('as_next_scheduled_action')) {
            add_action('action_scheduler_init', [$this, 'schedule_cleanup']);
        } else {
            add_action('init', [$this, 'schedule_cleanup']);
        }
    }

    /**
     * Schedule the cleanup cron jobs.
     *
     * @since 1.0.0
     */
    public function schedule_cleanup()
    {
        $this->schedule_daily(self::CRON_HOOK, strtotime('tomorrow 3:00 AM'));
        $this->schedule_daily(self::PII_CRON_HOOK, strtotime('tomorrow 4:00 AM'));
    }

    private function schedule_daily(string $hook, int $start): void
    {
        if (function_exists('as_next_scheduled_action')) {
            if (!as_next_scheduled_action($hook, [], 'hmw-events')) {
                as_schedule_recurring_action($start, DAY_IN_SECONDS, $hook, [], 'hmw-events');
            }
            return;
        }

        if (!wp_next_scheduled($hook)) {
            wp_schedule_event($start, 'daily', $hook);
        }
    }

    /**
     * Clear the scheduled cleanup jobs.
     *
     * @since 1.0.0
     */
    public function clear_schedule()
    {
        foreach ([self::CRON_HOOK, self::PII_CRON_HOOK] as $hook) {
            if (function_exists('as_unschedule_all_actions')) {
                as_unschedule_all_actions($hook, [], 'hmw-events');
            }

            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
        }
    }

    /**
     * Cleanup abandoned bookings older than 24 hours.
     *
     * @since 1.0.0
     */
    public function cleanup_abandoned_bookings()
    {
        global $wpdb;

        // Find booking groups with pending/failed payment older than 24 hours
        $cutoff_time = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));

        $abandoned_groups = $wpdb->get_results($wpdb->prepare("
            SELECT id, customer_post_id
            FROM {$this->groups_table}
            WHERE payment_status IN ('pending', 'failed')
            AND created_at < %s
        ", $cutoff_time));

        if (empty($abandoned_groups)) {
            error_log('HMWEvents: No abandoned bookings to cleanup');
            return;
        }

        $cleanup_count = 0;

        foreach ($abandoned_groups as $group) {
            // Get all bookings in this group
            $bookings = $wpdb->get_results($wpdb->prepare("
                SELECT * FROM {$this->bookings_table}
                WHERE booking_group_id = %d
                AND status != 'cancelled'
            ", $group->id));

            if (empty($bookings)) {
                continue;
            }

            // Update booking group status
            $wpdb->update(
                $this->groups_table,
                ['payment_status' => 'cancelled'],
                ['id' => $group->id],
                ['%s'],
                ['%d']
            );

            // Update each booking and restore course availability
            foreach ($bookings as $booking) {
                // Update booking status
                $wpdb->update(
                    $this->bookings_table,
                    [
                        'status' => 'cancelled',
                        'cancelled_at' => current_time('mysql'),
                    ],
                    ['id' => $booking->id],
                    ['%s', '%s'],
                    ['%d']
                );

                // Restore course availability
                $wpdb->query($wpdb->prepare("
                    UPDATE {$this->availability_table}
                    SET booked_count = GREATEST(0, booked_count - %d),
                        available_count = available_count + %d
                    WHERE event_post_id = %d
                ", $booking->ticket_quantity, $booking->ticket_quantity, $booking->event_post_id));

                // Add to booking history
                $wpdb->insert(
                    $this->history_table,
                    [
                        'booking_id' => $booking->id,
                        'previous_status' => $booking->status,
                        'new_status' => 'cancelled',
                        'change_reason' => 'Automatic cancellation - payment not completed within 24 hours',
                        'created_at' => current_time('mysql'),
                    ],
                    ['%d', '%s', '%s', '%s', '%s']
                );
            }

            $cleanup_count++;
            error_log(sprintf(
                'HMWEvents: Cancelled abandoned booking group #%d (%d bookings)',
                $group->id,
                count($bookings)
            ));
        }

        error_log(sprintf('HMWEvents: Cleaned up %d abandoned booking groups', $cleanup_count));
    }

    /**
     * Manually trigger cleanup (for testing/admin use).
     *
     * @since 1.0.0
     */
    public function manual_cleanup()
    {
        $this->cleanup_abandoned_bookings();
    }

    public function purge_archived_pii(): void
    {
        global $wpdb;
        $retention_days = apply_filters('hmwevents_pii_retention_days', 90);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$retention_days} days"));

        $archived_events = $wpdb->get_col($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'hmw_event'
              AND post_status = 'archived'
              AND post_modified < %s
        ", $cutoff));

        if (empty($archived_events)) {
            return;
        }

        $bookings_table = DatabaseService::get_table_name('bookings');
        $event_ids = array_map('intval', $archived_events);
        $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));

        $registrant_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT registrant_post_id FROM {$bookings_table}
            WHERE event_post_id IN ({$placeholders}) AND deleted_at IS NULL",
            ...$event_ids
        ));

        if (empty($registrant_ids)) {
            return;
        }

        $pii_meta_keys = [
            'registrant_first_name', 'registrant_last_name',
            'registrant_email', 'registrant_phone',
            'registrant_address', 'registrant_suburb',
            'registrant_state', 'registrant_postcode',
        ];

        foreach ($registrant_ids as $r_id) {
            foreach ($pii_meta_keys as $key) {
                delete_post_meta($r_id, $key);
            }

            wp_update_post([
                'ID' => $r_id,
                'post_title' => __('[Expired]', 'hmw-events'),
            ]);
        }
    }
}
