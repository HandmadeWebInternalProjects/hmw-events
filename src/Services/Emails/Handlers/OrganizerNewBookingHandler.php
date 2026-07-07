<?php
/**
 * Educator New Booking Notification Handler.
 *
 * Sends the educator an email when a customer books their course.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails\Handlers;

use HMWEvents\Services\Emails\AbstractEmailHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Educator New Booking Notification Handler.
 */
class OrganizerNewBookingHandler extends AbstractEmailHandler
{
    /**
     * Template key.
     *
     * @var string
     */
    protected $template_key = 'educator_new_booking';

    /**
     * Email type.
     *
     * @var string
     */
    protected $email_type = 'educator_new_booking';

    /**
     * Queue educator new-booking notification email.
     *
     * @param int   $booking_id   Booking ID.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_for_booking($booking_id, $booking_data = [])
    {
        global $wpdb;

        // Resolve the educator for this booking.
        $educator_id = null;

        if (!empty($booking_data['educator_id'])) {
            $educator_id = (int) $booking_data['educator_id'];
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT b.course_post_id FROM {$wpdb->prefix}hmwevents_bookings b WHERE b.id = %d",
                $booking_id
            ));
            if ($row) {
                $course = get_post($row->course_post_id);
                if ($course) {
                    $educator_id = (int) $course->post_author;
                }
            }
        }

        if (!$educator_id) {
            error_log('EducatorNewBookingHandler: Could not resolve educator for booking ' . $booking_id);
            return false;
        }

        $educator_user = get_userdata($educator_id);
        if (!$educator_user || !$educator_user->user_email) {
            error_log('EducatorNewBookingHandler: No email address for educator ' . $educator_id);
            return false;
        }

        $educator_email = $educator_user->user_email;
        $educator_name  = $educator_user->display_name ?: $educator_user->user_login;

        return $this->queue([
            'booking_id'      => $booking_id,
            'educator_id'     => $educator_id,
            'recipient_email' => $educator_email,
            'recipient_name'  => $educator_name,
            'scheduled_at'    => current_time('mysql'),
        ]);
    }

    /**
     * Prepare fresh template data from booking ID.
     *
     * @param int $booking_id Booking ID.
     * @return array Template variables.
     */
    protected function prepare_fresh_template_data($booking_id)
    {
        global $wpdb;

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT b.* FROM {$wpdb->prefix}hmwevents_bookings b WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking) {
            error_log('EducatorNewBookingHandler: Booking not found: ' . $booking_id);
            return [];
        }

        $booking_data = (array) $booking;
        $currency     = $booking_data['currency'] ?? 'AUD';

        // Customer details.
        $customer_post_id = $booking_data['customer_post_id'] ?? null;
        $customer_name    = $this->get_customer_name($customer_post_id);
        $registrant_email   = $customer_post_id
            ? (get_post_meta($customer_post_id, 'registrant_email', true) ?: '')
            : '';
        $customer_phone   = $customer_post_id
            ? (get_post_meta($customer_post_id, 'customer_phone', true) ?: '')
            : '';

        // Admin link to the customer CPT.
        $customer_link = '';
        if ($customer_post_id) {
            $edit_url      = admin_url('post.php?post=' . (int) $customer_post_id . '&action=edit');
            $customer_link = '<a href="' . esc_url($edit_url) . '">View ' . esc_html($customer_name) . ' in admin</a>';
        }

        // Course details.
        $course = get_post($booking_data['course_post_id'] ?? null);
        if (!$course) {
            error_log('EducatorNewBookingHandler: Course not found for booking: ' . $booking_id);
            return [];
        }

        $course_location = $this->get_course_location_address($course->ID);

        $payment_type       = $booking_data['ticket_type'] ?? ($booking_data['payment_type'] ?? 'full');
        $payment_status     = $booking_data['payment_status'] ?? 'pending';
        if ($payment_status !== 'paid') {
            $payment_type_label = 'Awaiting Payment';
        } else {
            $payment_type_label = ($payment_type === 'deposit') ? 'Deposit' : 'Full Payment';
        }
        $booking_amount     = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency)
            . number_format((float) ($booking_data['booking_amount'] ?? 0), 2);

        return $this->prepare_template_data([
            'booking_id'       => $booking_id,
            'booking_number'   => $booking_data['booking_number'] ?? '',
            'customer_name'    => $customer_name,
            'registrant_email'   => $registrant_email,
            'customer_phone'   => $customer_phone,
            'customer_link'    => $customer_link,
            'course_name'      => $course->post_title,
            'course_date'      => $this->get_course_date($course->ID),
            'course_start_time'=> $this->get_course_time($course->ID),
            'course_location'  => $course_location,
            'booking_amount'   => $booking_amount,
            'payment_type'     => $payment_type_label,
            'payment_status'   => $payment_status,
        ]);
    }

    /**
     * Get course date.
     *
     * @param int $course_id Course post ID.
     * @return string Formatted course date.
     */
    private function get_course_date($course_id)
    {
        if (function_exists('get_field')) {
            $date = get_field('course_start_date', $course_id);
            if ($date) {
                $timestamp = strtotime($date);
                return $timestamp ? date('F j, Y', $timestamp) : $date;
            }
        }

        return get_the_date('F j, Y', $course_id);
    }

    /**
     * Get course start time.
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
     * Get template variables description.
     *
     * @return array Variables description.
     */
    public function get_template_variables_description()
    {
        return array_merge(parent::get_template_variables_description(), [
            'registrant_email'    => 'Customer email address',
            'customer_phone'    => 'Customer phone number',
            'customer_link'     => 'Clickable admin link to the customer record',
            'course_location'   => 'Course location address',
            'booking_amount'    => 'Booking amount (formatted)',
            'payment_type'      => 'Payment type label ("Full Payment", "Deposit", or "Awaiting Payment")',
            'payment_status'    => 'Raw payment status (pending, paid, refunded, failed)',
            'course_start_time' => 'Course start time',
        ]);
    }
}
