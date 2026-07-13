<?php

/**
 * Booking Helper.
 *
 * Utility methods for working with booking data across views and endpoints.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Helpers;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Booking Helper.
 */
class Booking
{
    /**
     * Determine whether a "Send Remaining Payment Link" action should be shown.
     *
     * Returns true when:
     *  - The booking is a deposit (not full),
     *  - The deposit payment has succeeded,
     *  - AND no remaining-balance payment has been recorded yet.
     *
     * Callers can pass an object or array with any of these property names:
     *   Payment type:     ticket_type | payment_type | group_payment_type
     *   Payment status:   payment_status
     *   Remaining paid:   remaining_paid (optional; skips DB query if present)
     *   Booking group ID: booking_group_id (required if remaining_paid absent)
     *
     * @since 1.0.0
     * @param object|array $booking Booking row from a DB query.
     * @return bool
     */
    public static function needs_remaining_payment_link($booking): bool
    {
        $booking = (object) $booking;

        // Normalise the payment-type property — views use different column aliases.
        $payment_type = $booking->ticket_type
            ?? $booking->payment_type
            ?? $booking->group_payment_type
            ?? '';

        if ($payment_type !== 'deposit') {
            return false;
        }

        if (($booking->payment_status ?? '') !== 'paid') {
            return false;
        }

        // If the caller already computed remaining_paid, trust it.
        if (isset($booking->remaining_paid)) {
            return (float) $booking->remaining_paid == 0;
        }

        // Otherwise, query the database for any successful remaining-payment transaction.
        $booking_group_id = $booking->booking_group_id ?? null;
        if (!$booking_group_id) {
            return false;
        }

        global $wpdb;
        $remaining = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
             FROM {$wpdb->prefix}hmwevents_payment_transactions
             WHERE booking_group_id = %d
               AND status = 'succeeded'
               AND metadata LIKE %s",
            $booking_group_id,
            '%remaining%'
        ));

        return (int) $remaining === 0;
    }

    /**
     * Build and send a payment link email (pending or remaining balance).
     *
     * Generates a recovery token, builds the appropriate HTML email, and
     * queues it for delivery. Both the course view and payments dashboard
     * call this method instead of building their own emails.
     *
     * @since 1.0.0
     * @param object $booking        Booking row with id, booking_number, currency,
     *                               event_post_id, booking_group_id, customer_post_id,
     *                               total_amount, post_author, course_name.
     * @param bool   $is_remaining   Use remaining-balance email variant.
     * @return bool|\WP_Error True if queued, WP_Error on failure.
     */
    public static function send_payment_link_email($booking, bool $is_remaining = false)
    {
        $booking_id       = (int) ($booking->id ?? 0);
        $course_id        = (int) ($booking->event_post_id ?? 0);
        $booking_group_id = (int) ($booking->booking_group_id ?? 0);

        $registrant_email = get_post_meta((int) $booking->customer_post_id, 'registrant_email', true);
        $customer_name  = get_the_title((int) $booking->customer_post_id);

        if (empty($registrant_email) || !is_email($registrant_email)) {
            return new \WP_Error('no_email', __('Customer email not found.', 'hmw-events'));
        }

        $gateway     = new \HMWEvents\Services\PaymentGateway();
        $token       = $gateway->generate_recovery_token($booking_group_id);
        $payment_url = $gateway->get_recovery_url($token);

        $currency        = !empty($booking->currency) ? $booking->currency : 'AUD';
        $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);

        if ($is_remaining) {
            $course_full_price = floatval(get_field('_event_price', $course_id));
            $amount_due        = $course_full_price > 0
                ? max(0, $course_full_price - floatval($booking->total_amount))
                : floatval($booking->total_amount);

            $subject = sprintf(
                __('Complete Your Remaining Payment — %s', 'hmw-events'),
                get_bloginfo('name')
            );

            $message = sprintf(
                '<p>%s %s,</p>
                <p>%s</p>
                <p><strong>%s:</strong> %s<br>
                <strong>%s:</strong> %s<br>
                <strong>%s:</strong> %s%s</p>
                <p style="text-align:center; margin:30px 0;">
                    <a href="%s" style="background:#2271b1;color:#fff;padding:12px 28px;text-decoration:none;border-radius:4px;display:inline-block;font-weight:bold;">%s</a>
                </p>
                <p><small>%s</small></p>',
                esc_html__('Hi', 'hmw-events'),
                esc_html($customer_name),
                esc_html__('Thank you for your deposit payment. Please use the secure link below to pay the remaining balance.', 'hmw-events'),
                esc_html__('Booking', 'hmw-events'), esc_html($booking->booking_number),
                esc_html__('Course', 'hmw-events'), esc_html($booking->course_name),
                esc_html__('Remaining Balance', 'hmw-events'), esc_html($currency_symbol), esc_html(number_format($amount_due, 2)),
                esc_url($payment_url),
                esc_html__('Complete Payment Now', 'hmw-events'),
                esc_html__('This secure link expires in 24 hours. If you have any questions, please contact us directly.', 'hmw-events')
            );
        } else {
            $amount_due = floatval($booking->total_amount);

            $subject = sprintf(
                __('Complete Your Booking Payment — %s', 'hmw-events'),
                get_bloginfo('name')
            );

            $message = sprintf(
                '<p>%s %s,</p>
                <p>%s</p>
                <p><strong>%s:</strong> %s<br>
                <strong>%s:</strong> %s<br>
                <strong>%s:</strong> %s%s</p>
                <p style="text-align:center; margin:30px 0;">
                    <a href="%s" style="background:#2271b1;color:#fff;padding:12px 28px;text-decoration:none;border-radius:4px;display:inline-block;font-weight:bold;">%s</a>
                </p>
                <p><small>%s</small></p>',
                esc_html__('Hi', 'hmw-events'),
                esc_html($customer_name),
                esc_html__('Please use the secure link below to complete your booking payment.', 'hmw-events'),
                esc_html__('Booking', 'hmw-events'), esc_html($booking->booking_number),
                esc_html__('Course', 'hmw-events'), esc_html($booking->course_name),
                esc_html__('Amount Due', 'hmw-events'), esc_html($currency_symbol), esc_html(number_format($amount_due, 2)),
                esc_url($payment_url),
                esc_html__('Complete Payment Now', 'hmw-events'),
                esc_html__('This secure link expires in 24 hours. If you have any questions, please contact us directly.', 'hmw-events')
            );
        }

        $email_service = new \HMWEvents\Services\Emails\EmailService();
        return $email_service->queue_payment_link(
            $booking_id,
            (int) ($booking->post_author ?? 0),
            $registrant_email,
            $customer_name,
            $subject,
            $message
        );
    }
}
