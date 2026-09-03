<?php
/**
 * Booking Confirmation Email Handler.
 *
 * Sends confirmation emails when bookings are confirmed.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails\Handlers;

use HMWEvents\Services\Emails\AbstractEmailHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Booking Confirmation Email Handler.
 */
class BookingConfirmationHandler extends AbstractEmailHandler
{
    /**
     * Template key.
     *
     * @var string
     */
    protected $template_key = 'booking_confirmation';

    /**
     * Email type.
     *
     * @var string
     */
    protected $email_type = 'booking_confirmation';

    /**
     * Queue booking confirmation email.
     *
     * @param int   $booking_id Booking ID.
     * @param array $booking_data Booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_for_booking($booking_id, $booking_data = [])
    {
        global $wpdb;

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
                error_log('Booking not found: ' . $booking_id);
                return false;
            }

            $booking_data = (array) $booking;
        }

        $currency      = $booking_data['currency'] ?? 'AUD';
        $customer_name = $this->resolve_customer_display_name(
            $booking_data['customer_post_id'] ?? null,
            $booking_data['customer_name'] ?? ''
        );

        // Get customer email
        $registrant_email = $this->get_registrant_email($booking_data['customer_post_id'] ?? null);
        if (!$registrant_email) {
            error_log('Customer email not found for booking: ' . $booking_id);
            return false;
        }

        // Get course details
        $course = get_post($booking_data['event_post_id'] ?? null);
        if (!$course) {
            error_log('Course not found for booking: ' . $booking_id);
            return false;
        }

        $course_location = $this->get_course_location($course->ID);

        // Prepare template data.
        // NOTE: payment_type_label and payment_status are intentionally omitted here.
        // They are volatile (payment may be confirmed after the email is queued) so they
        // are always computed fresh by prepare_fresh_template_data() at send time.
        $template_data = $this->prepare_template_data(array_merge(
            $this->build_universal_variables($booking_id, $course_location),
            [
                'booking_id'        => $booking_id,
                'booking_number'    => $booking_data['booking_number'] ?? '',
                'customer_name'     => $customer_name,
                'course_name'       => $course->post_title,
                'course_date'       => $this->get_course_date($course->ID),
                'course_start_time' => $this->get_course_time($course->ID),
                'booking_amount'    => $this->format_currency($booking_data['booking_amount'] ?? 0, $currency),
            ]
        ));

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
     * Validate booking confirmation data.
     *
     * @param array $data Booking data.
     * @return bool|string True if valid, error message if invalid.
     */
    public function validate($data)
    {
        $validation = parent::validate($data);
        if ($validation !== true) {
            return $validation;
        }

        if (empty($data['booking_number'])) {
            return 'Booking number is required';
        }

        if (empty($data['course_name'])) {
            return 'Course name is required';
        }

        return true;
    }

    private function get_registrant_email($customer_post_id)
    {
        return $this->get_registrant_email_address($customer_post_id);
    }


    /**
     * Get course date.
     *
     * @param int $course_id Course post ID.
     * @return string Course date formatted.
     */
    private function get_course_date($course_id)
    {
        $date = $this->event_data()->get_start_date($course_id);
        if ($date) {
            return $this->format_date($date);
        }

        return get_the_date('F j, Y', $course_id);
    }

    /**
     * Get course time.
     *
     * @param int $course_id Course post ID.
     * @return string Course start time.
     */
    private function get_course_time($course_id)
    {
        $datetime = $this->event_data()->get_start_date($course_id);
        if ($datetime) {
            $timestamp = strtotime($datetime);
            if ($timestamp !== false) {
                return date('g:i A', $timestamp);
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

    private function format_date($date)
    {
        return $this->format_event_date($date);
    }


    private function format_currency($amount, $currency = 'AUD')
    {
        return $this->format_money($amount, $currency);
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

        $currency      = $booking_data['currency'] ?? 'AUD';
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

        $course_location = $this->get_course_location($course->ID);

        $payment_type       = $booking_data['ticket_type'] ?? ($booking_data['payment_type'] ?? 'full');
        $payment_status     = $booking_data['payment_status'] ?? 'pending';
        if ($payment_type === 'net_terms') {
            $payment_type_label = 'Pay by Invoice';
        } elseif ($payment_status !== 'paid') {
            $payment_type_label = 'Awaiting Payment';
        } else {
            $payment_type_label = ($payment_type === 'deposit') ? 'Deposit' : 'Full Payment';
        }

        // Return fresh template data
        $template_data = $this->prepare_template_data(array_merge(
            $this->build_universal_variables($booking_id, $course_location),
            [
                'booking_id'         => $booking_id,
                'booking_number'     => $booking_data['booking_number'] ?? '',
                'customer_name'      => $customer_name,
                'course_name'        => $course->post_title,
                'course_date'        => $this->get_course_date($course->ID),
                'course_start_time'  => $this->get_course_time($course->ID),
                'booking_amount'     => $this->format_currency($booking_data['booking_amount'] ?? 0, $currency),
                'payment_type_label' => $payment_type_label,
                'payment_status'     => $payment_status,
            ]
        ));

        $event_id     = (int) ($booking_data['event_post_id'] ?? 0);
        $is_free_event = $event_id && (
            get_post_meta($event_id, '_event_is_free', true) ||
            (float) get_post_meta($event_id, '_event_price', true) <= 0
        );

        if ($is_free_event) {
            $cancel_service = new \HMWEvents\Services\BookingSelfCancelService();
            $template_data['cancel_link'] = $cancel_service->generate_cancel_link((int) $booking_id);
        }

        return $template_data;
    }

    /**
     * Get template variables description.
     *
     * @return array Variables description.
     */
    public function get_template_variables_description()
    {
        return array_merge(parent::get_template_variables_description(), [
            'booking_amount'     => 'Booking amount (formatted)',
            'payment_type_label' => 'Payment type label ("Full Payment", "Deposit", or "Awaiting Payment")',
            'payment_status'     => 'Raw payment status (pending, paid, refunded, failed)',
        ]);
    }
}
