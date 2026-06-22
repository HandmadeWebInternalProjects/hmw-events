<?php
/**
 * Email Queue Processor.
 *
 * Handles background processing of queued emails using ActionScheduler
 * with WP-Cron fallback.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Email Queue Processor Class.
 */
class EmailQueueProcessor
{
    /**
     * Queue processing hook.
     */
    const CRON_HOOK = 'hmwevents_process_email_queue';

    /**
     * Cleanup hook.
     */
    const CLEANUP_HOOK = 'hmwevents_email_cleanup';

    /**
     * Queue interval (seconds).
     */
    const INTERVAL = 300; // 5 minutes

    /**
     * Email service instance.
     *
     * @var EmailService
     */
    private $email_service;

    /**
     * Constructor.
     */
    public function __construct()
    {
        $this->email_service = new EmailService();
    }

    /**
     * Register hooks and schedules.
     *
     * @return void
     */
    public function register()
    {
        // ActionScheduler hooks
        add_action(self::CRON_HOOK, [$this, 'process_queue']);
        add_action(self::CLEANUP_HOOK, [$this, 'cleanup_old_emails']);

        // Schedule on activation and clear on deactivation
        add_action('hmwevents_activation', [$this, 'schedule']);
        add_action('hmwevents_activation', [$this, 'create_default_templates']);
        add_action('hmwevents_deactivation', [$this, 'clear_schedule']);

        // WP-Cron fallback interval
        add_filter('cron_schedules', [$this, 'add_custom_schedule']);

        // Ensure schedule exists (after Action Scheduler initialization or init fallback)
        if (function_exists('as_next_scheduled_action')) {
            add_action('action_scheduler_init', [$this, 'schedule']);
        } else {
            add_action('init', [$this, 'schedule']);
        }
    }

    /**
     * Schedule recurring ActionScheduler actions.
     *
     * @return void
     */
    public function add_custom_schedule($schedules)
    {
        if (!isset($schedules['every-5-minutes'])) {
            $schedules['every-5-minutes'] = [
                'interval' => self::INTERVAL,
                'display'  => 'Every 5 minutes',
            ];
        }

        return $schedules;
    }

    /**
     * Schedule recurring actions.
     *
     * @return void
     */
    public function schedule()
    {
        if (function_exists('as_next_scheduled_action')) {
            if (!as_next_scheduled_action(self::CRON_HOOK)) {
                as_schedule_recurring_action(time(), self::INTERVAL, self::CRON_HOOK);
            }

            if (!as_next_scheduled_action(self::CLEANUP_HOOK)) {
                as_schedule_recurring_action(time() + 86400, 24 * 60 * 60, self::CLEANUP_HOOK);
            }
        } else {
            if (!wp_next_scheduled(self::CRON_HOOK)) {
                wp_schedule_event(time(), 'every-5-minutes', self::CRON_HOOK);
            }

            if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
                wp_schedule_event(time() + 86400, 'daily', self::CLEANUP_HOOK);
            }
        }
    }

    /**
     * Clear scheduled actions.
     *
     * @return void
     */
    public function clear_schedule()
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions(self::CRON_HOOK);
            as_unschedule_all_actions(self::CLEANUP_HOOK);
        }

        $queue_timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($queue_timestamp) {
            wp_unschedule_event($queue_timestamp, self::CRON_HOOK);
        }

        $cleanup_timestamp = wp_next_scheduled(self::CLEANUP_HOOK);
        if ($cleanup_timestamp) {
            wp_unschedule_event($cleanup_timestamp, self::CLEANUP_HOOK);
        }
    }

    /**
     * Process pending emails from the queue.
     *
     * This is the main entry point called by ActionScheduler.
     *
     * @param int $limit Number of emails to process.
     * @return void
     */
    public function process_queue($limit = 10)
    {
        $result = $this->email_service->process_queue($limit);

        // Log results
        if ($result['sent'] > 0 || $result['failed'] > 0) {
            do_action(
                'hmwevents_email_queue_processed',
                $result['sent'],
                $result['failed'],
                $result['processed']
            );

            error_log(sprintf(
                'HMWEvents Email Queue: Processed %d, Sent %d, Failed %d',
                $result['processed'],
                $result['sent'],
                $result['failed']
            ));
        }

        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                error_log('HMWEvents Email Queue Error: ' . $error);
            }
        }
    }

    /**
     * Send reminder email for a booking.
     *
     * @param array $args Action arguments.
     * @return void
     */
    public function schedule_reminders($args = [])
    {
        $booking_id = is_array($args) ? ($args['booking_id'] ?? null) : $args;
        if (empty($booking_id)) {
            return;
        }

        $hooks = new EmailEventHooks();
        $hooks->schedule_reminders($booking_id);
    }

    /**
     * Cleanup old sent emails.
     *
     * @param int $days Delete emails older than X days.
     * @return void
     */
    public function cleanup_old_emails($days = 90)
    {
        $deleted = $this->email_service->cleanup_old_emails($days);

        error_log(sprintf(
            'HMWEvents Email: Cleaned up %d old sent emails',
            $deleted
        ));
    }

    /**
     * Create default system templates on activation.
     *
     * @return void
     */
    public function create_default_templates()
    {
        $this->email_service->create_default_templates();
    }
}

/**
 * Get email service instance (helper function).
 *
 * @return EmailService Email service.
 */
function hmwevents_get_email_service()
{
    return new EmailService();
}

/**
 * Queue booking confirmation email (helper function).
 *
 * @param int   $booking_id Booking ID.
 * @param array $booking_data Booking data.
 * @return int|false Email ID or false.
 */
function hmwevents_queue_booking_confirmation($booking_id, $booking_data = [])
{
    return hmwevents_get_email_service()->queue_booking_confirmation($booking_id, $booking_data);
}

/**
 * Queue course reminder email (helper function).
 *
 * @param int $booking_id Booking ID.
 * @param int $days_before Days before course.
 * @return int|false Email ID or false.
 */
function hmwevents_queue_course_reminder($booking_id, $days_before = 7)
{
    return hmwevents_get_email_service()->queue_course_reminder($booking_id, $days_before);
}

/**
 * Queue status change email (helper function).
 *
 * @param int    $booking_id Booking ID.
 * @param string $status_type Status type.
 * @param array  $additional_data Additional data.
 * @return int|false Email ID or false.
 */
function hmwevents_queue_status_change_email($booking_id, $status_type, $additional_data = [])
{
    return hmwevents_get_email_service()->queue_status_change_email($booking_id, $status_type, $additional_data);
}
