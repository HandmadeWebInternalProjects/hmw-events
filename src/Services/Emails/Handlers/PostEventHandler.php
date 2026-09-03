<?php
/**
 * Post-Course Email Handler.
 *
 * Sends feedback and follow-up emails after courses complete.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails\Handlers;

use HMWEvents\Services\Emails\AbstractEmailHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Post-Course Email Handler.
 */
class PostEventHandler extends AbstractEmailHandler
{
    /**
     * Email type for immediate feedback (1 day after).
     *
     * @var string
     */
    const TYPE_FEEDBACK = 'post_course_feedback';

    /**
     * Email type for follow-up (30 days after).
     *
     * @var string
     */
    const TYPE_FOLLOWUP = 'post_course_followup';

    /**
     * Template key.
     *
     * @var string
     */
    protected $template_key = 'post_course_feedback';

    /**
     * Email type.
     *
     * @var string
     */
    protected $email_type = 'post_course_feedback';

    /**
     * Queue post-course feedback email.
     *
     * @param int   $booking_id Booking ID.
     * @param int   $days_after Days after course to send email.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_for_booking($booking_id, $days_after = 1, $booking_data = [])
    {
        global $wpdb;

        // Determine email type based on days
        $this->email_type = $days_after >= 30 ? self::TYPE_FOLLOWUP : self::TYPE_FEEDBACK;
        $this->template_key = $this->email_type;

        // Get booking details
        if (empty($booking_data)) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, b.registrant_post_id AS customer_post_id, c.post_title as customer_name
                 FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b
                 INNER JOIN {$wpdb->posts} c ON b.registrant_post_id = c.ID
                 WHERE b.id = %d",
                $booking_id
            ));

            if (!$booking) {
                error_log('Booking not found for post-course email: ' . $booking_id);
                return false;
            }

            $booking_data = (array) $booking;
        }

        $customer_name = $this->resolve_customer_display_name(
            $booking_data['customer_post_id'] ?? null,
            $booking_data['customer_name'] ?? ''
        );

        // Get customer email
        $registrant_email = $this->get_registrant_email($booking_data['customer_post_id'] ?? null);
        if (!$registrant_email) {
            error_log('Customer email not found for post-course: ' . $booking_id);
            return false;
        }

        // Get course details
        $course = get_post($booking_data['event_post_id'] ?? null);
        if (!$course) {
            error_log('Course not found for post-course: ' . $booking_id);
            return false;
        }

        // Get course date
        $course_date = $this->get_course_date($course->ID);
        if (!$course_date) {
            error_log('Course date not found for post-course: ' . $booking_id);
            return false;
        }

        $course_location = $this->get_course_location_address($course->ID);

        // Prepare template data
        $template_data = $this->prepare_template_data(array_merge(
            $this->build_universal_variables($booking_id, $course_location),
            [
                'booking_id'    => $booking_id,
                'customer_name' => $customer_name,
                'course_name'   => $course->post_title,
                'course_date'   => $this->format_date($course_date),
                'days_after'    => $days_after,
            ]
        ));

        // Schedule for the right time
        $scheduled_at = $this->calculate_scheduled_time($course_date, $days_after);

        // Queue the email
        return $this->queue([
            'booking_id'      => $booking_id,
            'educator_id'     => $course->post_author,
            'recipient_email' => $registrant_email,
            'recipient_name'  => $customer_name,
            'template_data'   => $template_data,
            'scheduled_at'    => $scheduled_at,
        ]);
    }

    private function get_registrant_email($customer_post_id)
    {
        return $this->get_registrant_email_address($customer_post_id);
    }


    /**
     * Get course date.
     *
     * @param int $course_id Course post ID.
     * @return string|false Course end date or false.
     */
    private function get_course_date($course_id)
    {
        $date = $this->event_data()->get_end_date($course_id);
        if ($date) {
            return $date;
        }

        $date = $this->event_data()->get_start_date($course_id);
        if ($date) {
            return $date;
        }

        return false;
    }

    private function format_date($date)
    {
        return $this->format_event_date($date);
    }


    /**
     * Calculate scheduled time to send post-course email.
     *
     * @param string $course_date Course date.
     * @param int    $days_after Days after to send.
     * @return string Scheduled time.
     */
    private function calculate_scheduled_time($course_date, $days_after)
    {
        $timestamp = strtotime($course_date);
        $send_time = $timestamp + ($days_after * 24 * 60 * 60);

        // Set to 9 AM on that day
        $send_time = strtotime('09:00:00', $send_time);

        return gmdate('Y-m-d H:i:s', $send_time);
    }

    /**
     * Prepare fresh template data from booking ID.
     *
     * @param int $booking_id Booking ID.
     * @return array Template data.
     */
    protected function prepare_fresh_template_data($booking_id)
    {
        global $wpdb;

        // Get fresh booking details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, b.registrant_post_id AS customer_post_id, c.post_title as customer_name
             FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b
             INNER JOIN {$wpdb->posts} c ON b.registrant_post_id = c.ID
             WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking) {
            error_log('Booking not found: ' . $booking_id);
            return [];
        }

        $booking_data = (array) $booking;

        $customer_name = $this->resolve_customer_display_name(
            $booking_data['customer_post_id'] ?? null,
            $booking_data['customer_name'] ?? ''
        );

        // Get fresh course details
        $course = get_post($booking_data['event_post_id'] ?? null);
        if (!$course) {
            error_log('Course not found for booking: ' . $booking_id);
            return [];
        }

        // Get fresh course date
        $course_date = $this->get_course_date($course->ID);
        if (!$course_date) {
            error_log('Course date not found for booking: ' . $booking_id);
            return [];
        }

        // Calculate days after course from current time
        $timestamp = strtotime($course_date);
        $now = current_time('timestamp');
        $days_after = max(0, ceil(($now - $timestamp) / (24 * 60 * 60)));

        $course_location = $this->get_course_location_address($course->ID);

        // Return fresh template data
        return $this->prepare_template_data(array_merge(
            $this->build_universal_variables($booking_id, $course_location),
            [
                'booking_id'    => $booking_id,
                'customer_name' => $customer_name,
                'course_name'   => $course->post_title,
                'course_date'   => $this->format_date($course_date),
                'days_after'    => $days_after,
            ]
        ));
    }

    /**
     * Get template variables description.
     *
     * @return array Variables description.
     */
    public function get_template_variables_description()
    {
        return array_merge(parent::get_template_variables_description(), [
            'days_after' => 'Number of days after event',
        ]);
    }
}
