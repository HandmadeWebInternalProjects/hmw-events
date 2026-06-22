<?php
/**
 * Reminder Email Handler.
 *
 * Sends reminder emails before courses start.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails\Handlers;

use HMWEvents\Services\Emails\AbstractEmailHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Reminder Email Handler.
 */
class ReminderHandler extends AbstractEmailHandler
{
    /**
     * Template key.
     *
     * @var string
     */
    protected $template_key = 'course_reminder';

    /**
     * Email type.
     *
     * @var string
     */
    protected $email_type = 'course_reminder';

    /**
     * Queue reminder email.
     *
     * @param int   $booking_id Booking ID.
     * @param int   $days_before Days before course to send reminder.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_for_booking($booking_id, $days_before = 7, $booking_data = [])
    {
        global $wpdb;

        // Get booking details
        if (empty($booking_data)) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT b.*, c.post_title as customer_name
                 FROM {$wpdb->prefix}educator_bookings b
                 INNER JOIN {$wpdb->posts} c ON b.customer_post_id = c.ID
                 WHERE b.id = %d",
                $booking_id
            ));

            if (!$booking) {
                error_log('Booking not found for reminder: ' . $booking_id);
                return false;
            }

            $booking_data = (array) $booking;
        }

        $customer_name = $this->resolve_customer_display_name(
            $booking_data['customer_post_id'] ?? null,
            $booking_data['customer_name'] ?? ''
        );

        // Get customer email
        $customer_email = $this->get_customer_email($booking_data['customer_post_id'] ?? null);
        if (!$customer_email) {
            error_log('Customer email not found for reminder: ' . $booking_id);
            return false;
        }

        // Get course details
        $course = get_post($booking_data['course_post_id'] ?? null);
        if (!$course) {
            error_log('Course not found for reminder: ' . $booking_id);
            return false;
        }

        // Get course date
        $course_date = $this->get_course_date($course->ID);
        if (!$course_date) {
            error_log('Course date not found for reminder: ' . $booking_id);
            return false;
        }

        $course_location = $this->get_course_location($course->ID);

        // Prepare template data
        $template_data = $this->prepare_template_data(array_merge(
            $this->build_universal_variables($booking_id, $course_location),
            [
                'booking_id'        => $booking_id,
                'customer_name'     => $customer_name,
                'course_name'       => $course->post_title,
                'course_date'       => $this->format_date($course_date),
                'days_until'        => $days_before,
                'course_start_time' => $this->get_course_time($course->ID),
            ]
        ));

        // Schedule for the right time
        $scheduled_at = $this->calculate_scheduled_time($course_date, $days_before);

        // Queue the email
        return $this->queue([
            'booking_id'      => $booking_id,
            'educator_id'     => $course->post_author,
            'recipient_email' => $customer_email,
            'recipient_name'  => $customer_name,
            'template_data'   => $template_data,
            'scheduled_at'    => $scheduled_at,
        ]);
    }

    /**
     * Get customer email from customer post.
     *
     * @param int $customer_post_id Customer post ID.
     * @return string|false Email address or false.
     */
    private function get_customer_email($customer_post_id)
    {
        if (!$customer_post_id) {
            return false;
        }

        $email = get_post_meta($customer_post_id, 'customer_email', true);
        if ($email) {
            return $email;
        }

        if (function_exists('get_field')) {
            $email = get_field('customer_email', $customer_post_id);
            if ($email) {
                return $email;
            }
        }

        return false;
    }

    /**
     * Get course date.
     *
     * @param int $course_id Course post ID.
     * @return string|false Course date or false.
     */
    private function get_course_date($course_id)
    {
        if (function_exists('get_field')) {
            $date = get_field('course_start_date', $course_id);
            if ($date) {
                return $date;
            }
        }

        return false;
    }

    /**
     * Get course time.
     *
     * @param int $course_id Course post ID.
     * @return string Course start time.
     */
    private function get_course_time($course_id)
    {
        if (function_exists('get_field')) {
            $datetime = get_field('course_start_date', $course_id);
            if ($datetime) {
                $timestamp = strtotime($datetime);
                if ($timestamp !== false) {
                    return date('g:i A', $timestamp);
                }
            }
        }

        return '';
    }

    /**
     * Get course location.
     *
     * @param int $course_id Course post ID.
     * @return string Course location.
     */
    private function get_course_location($course_id)
    {
        return $this->get_course_location_address($course_id);
    }

    /**
     * Format date from various formats.
     *
     * @param string $date Date string.
     * @return string Formatted date.
     */
    private function format_date($date)
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        return date('l, F j, Y', $timestamp);
    }

    /**
     * Calculate scheduled time to send reminder.
     *
     * @param string $course_date Course date.
     * @param int    $days_before Days before to send.
     * @return string Scheduled time.
     */
    private function calculate_scheduled_time($course_date, $days_before)
    {
        $timestamp = strtotime($course_date);
        $reminder_time = $timestamp - ($days_before * 24 * 60 * 60);

        // Set to 9 AM on that day
        $reminder_time = strtotime('09:00:00', $reminder_time);

        return gmdate('Y-m-d H:i:s', $reminder_time);
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
            "SELECT b.*, c.post_title as customer_name
             FROM {$wpdb->prefix}educator_bookings b
             INNER JOIN {$wpdb->posts} c ON b.customer_post_id = c.ID
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
        $course = get_post($booking_data['course_post_id'] ?? null);
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

        // Calculate days until course from current time
        $timestamp = strtotime($course_date);
        $now = current_time('timestamp');
        $days_until = max(0, ceil(($timestamp - $now) / (24 * 60 * 60)));

        $course_location = $this->get_course_location($course->ID);

        // Return fresh template data
        return $this->prepare_template_data(array_merge(
            $this->build_universal_variables($booking_id, $course_location),
            [
                'booking_id'        => $booking_id,
                'customer_name'     => $customer_name,
                'course_name'       => $course->post_title,
                'course_date'       => $this->format_date($course_date),
                'days_until'        => $days_until,
                'course_start_time' => $this->get_course_time($course->ID),
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
            'days_until'           => 'Number of days until course',
            'course_location'      => 'Course location address',
            'course_start_time'    => 'Course start time',
            'download_audio_track'  => 'Download link to audio file (if configured)',
            'free_pre_course_audio_track' => 'Free pre-course audio track link',
        ]);
    }
}
