<?php
/**
 * Status Change Email Handler.
 *
 * Sends emails when booking/payment status changes.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails\Handlers;

use HMWEvents\Services\Emails\AbstractEmailHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Status Change Email Handler.
 */
class StatusChangeHandler extends AbstractEmailHandler
{
    /**
     * Status change types.
     */
    const TYPE_BOOKING_CANCELLED = 'booking_cancelled';
    const TYPE_BOOKING_CONFIRMED = 'booking_confirmed';
    const TYPE_PAYMENT_RECEIVED = 'payment_received';
    const TYPE_REFUND_ISSUED = 'refund_issued';
    const TYPE_COURSE_CHANGED = 'course_changed';

    /**
     * Template key (varies by status).
     *
     * @var string
     */
    protected $template_key = '';

    /**
     * Email type (varies by status).
     *
     * @var string
     */
    protected $email_type = '';

    /**
     * Queue status change email.
     *
     * @param int    $booking_id Booking ID.
     * @param string $status_type Type of status change.
     * @param array  $additional_data Additional data for the email.
     * @return int|false Email ID or false on failure.
     */
    public function queue_for_status_change($booking_id, $status_type, $additional_data = [])
    {
        global $wpdb;

        // Set template and type based on status
        $this->set_template_for_status($status_type);

        if (!$this->email_type) {
            error_log('Unknown status change type: ' . $status_type);
            return false;
        }

        // Get booking details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, c.post_title as customer_name, bg.total_amount
             FROM {$wpdb->prefix}hmwevents_bookings b
             INNER JOIN {$wpdb->posts} c ON b.customer_post_id = c.ID
             INNER JOIN {$wpdb->prefix}hmwevents_booking_groups bg ON b.booking_group_id = bg.id
             WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking) {
            error_log('Booking not found for status change: ' . $booking_id);
            return false;
        }

        $currency      = $booking->currency ?? 'AUD';
        $customer_name = $this->resolve_customer_display_name(
            $booking->customer_post_id,
            $booking->customer_name ?: ''
        );

        // Get customer email
        $registrant_email = $this->get_registrant_email($booking->customer_post_id);
        if (!$registrant_email) {
            error_log('Customer email not found for status change: ' . $booking_id);
            return false;
        }

        // Get course details
        $course = get_post($booking->event_post_id);
        if (!$course) {
            error_log('Course not found for status change: ' . $booking_id);
            return false;
        }

        $course_location = $this->get_course_location($course->ID);

        // Prepare template data
        $template_data = array_merge(
            $this->prepare_template_data(array_merge(
                $this->build_universal_variables($booking_id, $course_location),
                [
                    'booking_id'     => $booking_id,
                    'booking_number' => $booking->booking_number,
                    'customer_name'  => $customer_name,
                    'course_name'    => $course->post_title,
                    'course_date'    => $this->get_course_date($course->ID),
                    'amount'         => $this->format_currency($booking->total_amount, $currency),
                    'status_change'  => $status_type,
                ]
            )),
            $additional_data
        );

        // Queue the email
        return $this->queue([
            'booking_id'      => $booking_id,
            'educator_id'     => $course->post_author,
            'recipient_email' => $registrant_email,
            'recipient_name'  => $customer_name,
            'template_data'   => $template_data,
            'scheduled_at'    => current_time('mysql'),
        ]);
    }

    /**
     * Set template and email type based on status change.
     *
     * @param string $status_type Status change type.
     * @return void
     */
    private function set_template_for_status($status_type)
    {
        $mapping = [
            self::TYPE_BOOKING_CANCELLED => 'booking_cancelled',
            self::TYPE_BOOKING_CONFIRMED => 'booking_confirmed',
            self::TYPE_PAYMENT_RECEIVED  => 'payment_received',
            self::TYPE_REFUND_ISSUED     => 'refund_issued',
            self::TYPE_COURSE_CHANGED    => 'course_changed',
        ];

        $this->template_key = $mapping[$status_type] ?? '';
        $this->email_type = $status_type;
    }

    /**
     * Get customer email.
     *
     * @param int $customer_post_id Customer post ID.
     * @return string|false Email address or false.
     */
    private function get_registrant_email($customer_post_id)
    {
        if (!$customer_post_id) {
            return false;
        }

        $email = get_post_meta($customer_post_id, 'registrant_email', true);
        if ($email) {
            return $email;
        }

        if (function_exists('get_field')) {
            $email = get_field('registrant_email', $customer_post_id);
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
     * @return string Course date formatted.
     */
    private function get_course_date($course_id)
    {
        if (function_exists('get_field')) {
            $date = get_field('course_start_date', $course_id);
            if ($date) {
                return $this->format_date($date);
            }
        }

        return get_the_date('F j, Y', $course_id);
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

        return date('F j, Y', $timestamp);
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
     * Validate status change data.
     *
     * @param array $data Status change data.
     * @return bool|string True if valid, error message if invalid.
     */
    public function validate($data)
    {
        if (empty($data['status_type'])) {
            return 'Status type is required';
        }

        if (empty($data['recipient_email'])) {
            return 'Recipient email is required';
        }

        if (!is_email($data['recipient_email'])) {
            return 'Invalid email address: ' . $data['recipient_email'];
        }

        return true;
    }

    /**
     * Get template variables description.
     *
     * @return array Variables description.
    /**
     * Prepare fresh template data from booking ID.
     *
     * Note: Status change emails may have additional data that was provided at queue time.
     * This method only refreshes the core booking/course data.
     *
     * @param int $booking_id Booking ID.
     * @return array Template data.
     */
    protected function prepare_fresh_template_data($booking_id)
    {
        global $wpdb;

        // Get fresh booking details
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.*, c.post_title as customer_name, bg.total_amount
             FROM {$wpdb->prefix}hmwevents_bookings b
             INNER JOIN {$wpdb->posts} c ON b.customer_post_id = c.ID
             INNER JOIN {$wpdb->prefix}hmwevents_booking_groups bg ON b.booking_group_id = bg.id
             WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking) {
            error_log('Booking not found: ' . $booking_id);
            return [];
        }

        $currency      = $booking->currency ?? 'AUD';
        $customer_name = $this->resolve_customer_display_name(
            $booking->customer_post_id,
            $booking->customer_name ?: ''
        );

        // Get fresh course details
        $course = get_post($booking->event_post_id);
        if (!$course) {
            error_log('Course not found for booking: ' . $booking_id);
            return [];
        }

        $course_location = $this->get_course_location($course->ID);

        // Return fresh template data
        return $this->prepare_template_data(array_merge(
            $this->build_universal_variables($booking_id, $course_location),
            [
                'booking_id'     => $booking_id,
                'booking_number' => $booking->booking_number,
                'customer_name'  => $customer_name,
                'course_name'    => $course->post_title,
                'course_date'    => $this->get_course_date($course->ID),
                'amount'         => $this->format_currency($booking->total_amount, $currency),
                'status_change'  => $this->email_type,
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
            'booking_number'       => 'Booking confirmation number',
            'amount'               => 'Amount (formatted)',
            'status_change'        => 'Type of status change',
            'course_location'      => 'Course location address',
            'download_audio_track'  => 'Download link to audio file (if configured)',
            'free_pre_course_audio_track' => 'Free pre-course audio track link',
            'refund_amount'        => 'Refund amount (for refund emails)',
            'new_course_date'      => 'New course date (for course change emails)',
        ]);
    }
}
