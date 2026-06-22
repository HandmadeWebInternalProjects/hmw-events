<?php
/**
 * Email Event Hooks.
 *
 * Integrates email system with booking lifecycle events.
 * Listens to booking status changes and triggers appropriate emails.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails;

use HMWEvents\Services\Emails\Handlers\StatusChangeHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Email Event Hooks Class.
 */
class EmailEventHooks
{
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
     * Register all hooks.
     *
     * @return void
     */
    public function register()
    {
        // Booking lifecycle events
        add_action('hmwevents_booking_created', [$this, 'on_booking_created'], 10, 2);
        add_action('hmwevents_booking_cancelled', [$this, 'on_booking_cancelled'], 10, 3);
        add_action('hmwevents_refund_issued', [$this, 'on_refund_issued'], 10, 3);
        add_action('hmwevents_course_changed', [$this, 'on_course_changed'], 10, 3);

        // Async scheduling for reminders
        add_action('hmwevents_email_schedule_reminders', [$this, 'schedule_reminders'], 10, 1);
    }

    /**
     * Fired when a booking is created.
     *
     * @param int   $booking_id Booking ID.
     * @param array $booking_data Booking data.
     * @return void
     */
    public function on_booking_created($booking_id, $booking_data = [])
    {
        // Queue booking confirmation email (to customer)
        $this->email_service->queue_booking_confirmation($booking_id, $booking_data);

        // Queue new-booking notification (to educator)
        $this->email_service->queue_educator_new_booking($booking_id, $booking_data);

        /**
         * Schedule reminder emails:
         * - 7 days before course
         * - 1 day before course
         */
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(
                'hmwevents_email_schedule_reminders',
                ['booking_id' => $booking_id]
            );
        } else {
            $this->schedule_reminders($booking_id);
        }

        // Log email action
        do_action('hmwevents_email_event', 'booking_created', $booking_id);
    }

    /**
     * Fired when a booking is cancelled.
     *
     * @param int    $booking_id Booking ID.
     * @param string $reason Cancellation reason.
     * @param array  $booking_data Optional booking data.
     * @return void
     */
    public function on_booking_cancelled($booking_id, $reason = '', $booking_data = [])
    {
        // Cancel any pending emails that no longer apply
        $this->email_service->cancel_pending_emails(
            $booking_id,
            ['booking_cancelled'],
            'Booking cancelled'
        );

        $this->email_service->queue_status_change_email(
            $booking_id,
            StatusChangeHandler::TYPE_BOOKING_CANCELLED,
            ['reason' => $reason]
        );

        do_action('hmwevents_email_event', 'booking_cancelled', $booking_id);
    }

    /**
     * Fired when a refund is issued.
     *
     * @param int    $booking_id Booking ID.
     * @param float  $amount Refund amount.
     * @param string $reason Refund reason.
     * @return void
     */
    public function on_refund_issued($booking_id, $amount, $reason = '')
    {
        global $wpdb;
        
        // Get booking currency
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT currency FROM {$wpdb->prefix}educator_bookings WHERE id = %d",
            $booking_id
        ));
        $currency = $booking->currency ?? 'AUD';
        
        // Cancel any pending emails that no longer apply
        $this->email_service->cancel_pending_emails(
            $booking_id,
            ['refund_issued'],
            'Refund issued'
        );

        $this->email_service->queue_status_change_email(
            $booking_id,
            StatusChangeHandler::TYPE_REFUND_ISSUED,
            [
                'refund_amount' => $this->format_currency($amount, $currency),
                'reason'        => $reason,
            ]
        );

        do_action('hmwevents_email_event', 'refund_issued', $booking_id);
    }

    /**
     * Fired when a course date/details change.
     *
     * @param int    $course_id Course post ID.
     * @param array  $changed_fields Changed fields.
     * @param array  $old_values Old values.
     * @return void
     */
    public function on_course_changed($course_id, $changed_fields = [], $old_values = [])
    {
        global $wpdb;

        // Get all bookings for this course
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}educator_bookings 
             WHERE course_post_id = %d AND status IN ('pending', 'confirmed')",
            $course_id
        ));

        // Queue email for each booking
        foreach ($bookings as $booking) {
            $this->email_service->queue_status_change_email(
                $booking->id,
                StatusChangeHandler::TYPE_COURSE_CHANGED,
                [
                    'changed_fields' => implode(', ', array_keys($changed_fields)),
                    'old_values'     => json_encode($old_values),
                ]
            );
        }

        do_action('hmwevents_email_event', 'course_changed', $course_id);
    }

    /**
     * Schedule reminder emails for a booking.
     *
     * @param int $booking_id Booking ID.
     * @return void
     */
    public function schedule_reminders($booking_id)
    {
        $booking_id = is_array($booking_id) ? ($booking_id['booking_id'] ?? null) : $booking_id;
        if (empty($booking_id)) {
            return;
        }

        // Queue reminders (scheduled_at calculated based on course date)
        $this->email_service->queue_course_reminder($booking_id, 7);
        $this->email_service->queue_course_reminder($booking_id, 1);

        // Queue post-course emails (scheduled_at calculated based on course date)
        $this->email_service->queue_post_course_email($booking_id, 1);
        // $this->email_service->queue_post_course_email($booking_id, 30);
    }

    /**
     * Format currency value.
     *
     * @param float $amount Amount.
     * @param string $currency Currency code (defaults to AUD).
     * @return string Formatted currency.
     */
    private function format_currency($amount, $currency = 'AUD')
    {
        $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
        return $currency_symbol . number_format($amount, 2);
    }

    /**
     * Get email service instance.
     *
     * @return EmailService Email service.
     */
    public function get_email_service()
    {
        return $this->email_service;
    }
}

