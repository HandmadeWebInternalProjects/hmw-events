<?php
/**
 * Organizer New Booking Notification Handler.
 *
 * Sends the event organizer an email when a customer books their event.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Emails\Handlers;

use HMWEvents\Services\Emails\AbstractEmailHandler;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Organizer New Booking Notification Handler.
 */
class OrganizerNewBookingHandler extends AbstractEmailHandler
{
    /**
     * Template key.
     *
     * @var string
     */
    protected $template_key = 'organizer_new_booking';

    /**
     * Email type.
     *
     * @var string
     */
    protected $email_type = 'organizer_new_booking';

    /**
     * Queue organizer new-booking notification email.
     *
     * @param int   $booking_id   Booking ID.
     * @param array $booking_data Optional booking data.
     * @return int|false Email ID or false on failure.
     */
    public function queue_for_booking($booking_id, $booking_data = [])
    {
        global $wpdb;

        $organizer_id = null;
        $event_post_id = !empty($booking_data['event_post_id']) ? (int) $booking_data['event_post_id'] : 0;

        if (!empty($booking_data['organizer_id'])) {
            $organizer_id = (int) $booking_data['organizer_id'];
        } else {
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT b.event_post_id FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b WHERE b.id = %d",
                $booking_id
            ));
            if ($row) {
                $event_post_id = (int) $row->event_post_id;
                $event = get_post($row->event_post_id);
                if ($event) {
                    $organizer_id = (int) $event->post_author;
                }
            }
        }

        if (!$organizer_id) {
            error_log('OrganizerNewBookingHandler: Could not resolve organizer for booking ' . $booking_id);
            return false;
        }

        $organizer_user = get_userdata($organizer_id);
        if (!$organizer_user || !$organizer_user->user_email) {
            error_log('OrganizerNewBookingHandler: No email address for organizer ' . $organizer_id);
            return false;
        }

        $organizer_email = $organizer_user->user_email;
        $organizer_name  = $organizer_user->display_name ?: $organizer_user->user_login;

        if ($event_post_id) {
            $override = get_post_meta($event_post_id, '_event_notification_email', true);
            if ($override && is_email($override)) {
                $organizer_email = $override;
            }
        }

        return $this->queue([
            'booking_id'      => $booking_id,
            'organizer_id'    => $organizer_id,
            'recipient_email' => $organizer_email,
            'recipient_name'  => $organizer_name,
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
            "SELECT b.* FROM " . \HMWEvents\Services\DatabaseService::get_table_name('bookings') . " b WHERE b.id = %d",
            $booking_id
        ));

        if (!$booking) {
            error_log('OrganizerNewBookingHandler: Booking not found: ' . $booking_id);
            return [];
        }

        $booking_data = (array) $booking;
        $currency     = $booking_data['currency'] ?? 'AUD';

        // Customer details.
        $customer_post_id = $booking_data['registrant_post_id'] ?? ($booking_data['customer_post_id'] ?? null);
        $customer_name    = $this->get_customer_name($customer_post_id);
        $registrant_email   = $customer_post_id
            ? (get_post_meta($customer_post_id, 'registrant_email', true) ?: '')
            : '';
        $customer_phone   = $customer_post_id
            ? (get_post_meta($customer_post_id, 'registrant_phone', true) ?: get_post_meta($customer_post_id, 'customer_phone', true))
            : '';

        // Admin link to the customer CPT.
        $customer_link = '';
        if ($customer_post_id) {
            $edit_url      = admin_url('post.php?post=' . (int) $customer_post_id . '&action=edit');
            $customer_link = '<a href="' . esc_url($edit_url) . '">View ' . esc_html($customer_name) . ' in admin</a>';
        }

        // Course details.
        $course = get_post($booking_data['event_post_id'] ?? null);
        if (!$course) {
            error_log('OrganizerNewBookingHandler: Event not found for booking: ' . $booking_id);
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
        $date = $this->event_data()->get_start_date($course_id);
        if ($date) {
            $timestamp = strtotime($date);
            return $timestamp ? date('F j, Y', $timestamp) : $date;
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
     * Get template variables description.
     *
     * @return array Variables description.
     */
    public function get_template_variables_description()
    {
        return array_merge(parent::get_template_variables_description(), [
            'registrant_email' => 'Customer email address',
            'customer_phone'   => 'Customer phone number',
            'customer_link'    => 'Clickable admin link to the customer record',
            'booking_amount'   => 'Booking amount (formatted)',
            'payment_type'     => 'Payment type label ("Full Payment", "Deposit", or "Awaiting Payment")',
            'payment_status'   => 'Raw payment status (pending, paid, refunded, failed)',
        ]);
    }
}
