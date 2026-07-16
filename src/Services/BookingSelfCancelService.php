<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class BookingSelfCancelService
{
    public function register(): void
    {
        add_action('init', [$this, 'maybe_handle_cancel']);
        add_filter('hmwevents_booking_confirmation_email_data', [$this, 'add_cancel_link'], 10, 2);
    }

    public function generate_cancel_token(int $booking_id): string
    {
        $token = bin2hex(random_bytes(32));
        update_post_meta($booking_id, '_cancel_token', $token);
        return add_query_arg([
            'hmw_cancel' => $token,
            'booking_id' => $booking_id,
        ], home_url('/'));
    }

    public function add_cancel_link(array $template_data, int $booking_id): array
    {
        $event_id = (int) ($template_data['event_post_id'] ?? 0);
        $is_free = $event_id && (
            get_post_meta($event_id, '_event_is_free', true) === '1'
            || (float) get_post_meta($event_id, '_event_price', true) <= 0
        );

        if ($is_free) {
            $template_data['cancel_link'] = $this->generate_cancel_token($booking_id);
        }

        return $template_data;
    }

    public function maybe_handle_cancel(): void
    {
        $token      = $_GET['hmw_cancel'] ?? '';
        $booking_id = (int) ($_GET['booking_id'] ?? 0);

        if (empty($token) || !$booking_id) {
            return;
        }

        $stored = get_post_meta($booking_id, '_cancel_token', true);
        if (!$stored || !hash_equals($stored, $token)) {
            wp_die(__('Invalid cancellation link.', 'hmw-events'));
        }

        $booking = $this->get_booking($booking_id);
        if (!$booking) {
            wp_die(__('Booking not found.', 'hmw-events'));
        }

        global $wpdb;
        $bookings_table = $wpdb->prefix . 'hmwevents_bookings';

        $wpdb->update(
            $bookings_table,
            ['status' => 'cancelled', 'cancelled_at' => current_time('mysql')],
            ['id' => $booking_id, 'status' => 'confirmed'],
            ['%s', '%s'],
            ['%d', '%s']
        );

        delete_post_meta($booking_id, '_cancel_token');

        do_action('hmwevents_booking_cancelled', $booking_id, 'Self-cancelled by registrant', (array) $booking);

        wp_die(
            '<h2>' . __('Booking Cancelled', 'hmw-events') . '</h2>' .
            '<p>' . __('Your booking has been cancelled. A confirmation email will be sent shortly.', 'hmw-events') . '</p>',
            __('Booking Cancelled', 'hmw-events'),
            ['response' => 200]
        );
    }

    private function get_booking(int $booking_id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}hmwevents_bookings WHERE id = %d AND deleted_at IS NULL AND status = 'confirmed'",
            $booking_id
        ));
    }
}
