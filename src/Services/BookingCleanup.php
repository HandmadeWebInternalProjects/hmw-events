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

    /**
     * Initialize the service.
     *
     * @since 1.0.0
     */
    public function register()
    {
        // Schedule cron event on plugin activation
        add_action('hmwevents_activation', [$this, 'schedule_cleanup']);
        
        // Clear cron event on plugin deactivation
        add_action('hmwevents_deactivation', [$this, 'clear_schedule']);
        
        // Hook into the cron event
        add_action(self::CRON_HOOK, [$this, 'cleanup_abandoned_bookings']);
    }

    /**
     * Schedule the cleanup cron job.
     *
     * @since 1.0.0
     */
    public function schedule_cleanup()
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            // Schedule to run daily at 3 AM
            wp_schedule_event(strtotime('tomorrow 3:00 AM'), 'daily', self::CRON_HOOK);
        }
    }

    /**
     * Clear the scheduled cleanup job.
     *
     * @since 1.0.0
     */
    public function clear_schedule()
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
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
            FROM {$wpdb->prefix}educator_booking_groups
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
                SELECT * FROM {$wpdb->prefix}educator_bookings
                WHERE booking_group_id = %d
                AND status != 'cancelled'
            ", $group->id));

            if (empty($bookings)) {
                continue;
            }

            // Update booking group status
            $wpdb->update(
                $wpdb->prefix . 'educator_booking_groups',
                ['payment_status' => 'cancelled'],
                ['id' => $group->id],
                ['%s'],
                ['%d']
            );

            // Update each booking and restore course availability
            foreach ($bookings as $booking) {
                // Update booking status
                $wpdb->update(
                    $wpdb->prefix . 'educator_bookings',
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
                    UPDATE {$wpdb->prefix}educator_course_availability
                    SET booked_count = GREATEST(0, booked_count - %d),
                        available_count = available_count + %d
                    WHERE course_post_id = %d
                ", $booking->ticket_quantity, $booking->ticket_quantity, $booking->course_post_id));

                // Add to booking history
                $wpdb->insert(
                    $wpdb->prefix . 'educator_booking_history',
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
}
