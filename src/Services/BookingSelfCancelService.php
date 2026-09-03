<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class BookingSelfCancelService
{
    private string $bookings_table;

    public function __construct()
    {
        $this->bookings_table = DatabaseService::get_table_name('bookings');
    }

    public function register(): void
    {
        add_action('init', [$this, 'maybe_handle_cancel']);
    }

    public function generate_cancel_link(int $booking_id): string
    {
        global $wpdb;

        $token = bin2hex(random_bytes(32));

        $wpdb->update(
            $this->bookings_table,
            ['cancel_token' => $token],
            ['id' => $booking_id],
            ['%s'],
            ['%d']
        );

        $url = add_query_arg([
            'hmw_cancel' => $token,
            'booking_id' => $booking_id,
        ], home_url('/'));
        return "<a href='" . esc_url($url) . "' target='_blank'>" . esc_html__('Cancel Booking', 'hmw-events') . '</a>';
    }

    public function maybe_handle_cancel(): void
    {
        $token      = $_GET['hmw_cancel'] ?? '';
        $booking_id = (int) ($_GET['booking_id'] ?? 0);

        if (empty($token) || !$booking_id) {
            return;
        }

        $stored = $this->get_stored_token($booking_id);
        if (!$stored || !hash_equals($stored, $token)) {
            wp_die(__('Invalid cancellation link.', 'hmw-events'));
        }

        $booking = $this->get_booking($booking_id);
        if (!$booking) {
            wp_die(__('Booking not found.', 'hmw-events'));
        }

        $group = $this->get_group_cancel_options($booking_id);

        if (!isset($_POST['hmw_cancel_confirm'])) {
            $this->render_confirmation_page($booking, $token, $group);
        }

        global $wpdb;

        $scope = sanitize_key($_POST['hmw_cancel_scope'] ?? 'session');

        if ($scope === 'group' && $group) {
            $count = 0;
            foreach ($group['booking_ids'] as $row_id) {
                $this->cancel_row((int) $row_id);
                do_action('hmwevents_booking_cancelled', (int) $row_id, 'Self-cancelled by registrant', []);
                $count++;
            }

            wp_die(
                '<h2>' . __('Booking Cancelled', 'hmw-events') . '</h2>' .
                '<p>' . esc_html(sprintf(
                    _n(
                        'Your booking has been cancelled (%d session).',
                        'Your booking has been cancelled (%d sessions).',
                        $count,
                        'hmw-events'
                    ),
                    $count
                )) . '</p>' .
                '<p>' . __('A confirmation email will be sent shortly.', 'hmw-events') . '</p>',
                __('Booking Cancelled', 'hmw-events'),
                ['response' => 200]
            );
        }

        $this->cancel_row($booking_id);

        do_action('hmwevents_booking_cancelled', $booking_id, 'Self-cancelled by registrant', (array) $booking);

        wp_die(
            '<h2>' . __('Booking Cancelled', 'hmw-events') . '</h2>' .
            '<p>' . __('Your booking has been cancelled. A confirmation email will be sent shortly.', 'hmw-events') . '</p>',
            __('Booking Cancelled', 'hmw-events'),
            ['response' => 200]
        );
    }

    /**
     * Describe the multi-session group behind a booking, when the booking
     * belongs to one with more than one active session.
     *
     * @return array|null {count, booking_ids, sessions, reference}
     */
    public function get_group_cancel_options(int $booking_id): ?array
    {
        global $wpdb;

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT booking_group_id FROM {$this->bookings_table} WHERE id = %d",
            $booking_id
        ));

        if (!$booking || !(int) $booking->booking_group_id) {
            return null;
        }

        $groups_table = DatabaseService::get_table_name('booking_groups');
        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT id, booking_type, booking_reference FROM {$groups_table} WHERE id = %d",
            (int) $booking->booking_group_id
        ));

        if (!$group || $group->booking_type === 'single') {
            return null;
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, event_post_id FROM {$this->bookings_table}
             WHERE booking_group_id = %d AND status = 'confirmed' AND deleted_at IS NULL
             ORDER BY id",
            (int) $group->id
        ));

        if (empty($rows) || count($rows) < 2) {
            return null;
        }

        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = [
                'booking_id'    => (int) $row->id,
                'event_post_id' => (int) $row->event_post_id,
                'title'         => (string) get_the_title((int) $row->event_post_id),
                'start'         => (string) get_post_meta((int) $row->event_post_id, '_event_start_date', true),
                'is_current'    => (int) $row->id === $booking_id,
            ];
        }

        usort($sessions, fn($a, $b) => strcmp($a['start'], $b['start']));

        return [
            'count'        => count($rows),
            'booking_ids'  => array_map(fn($r) => (int) $r->id, $rows),
            'sessions'     => $sessions,
            'reference'    => (string) $group->booking_reference,
        ];
    }

    private function cancel_row(int $booking_id): void
    {
        global $wpdb;

        $wpdb->update(
            $this->bookings_table,
            ['status' => 'cancelled', 'cancelled_at' => current_time('mysql'), 'cancel_token' => null],
            ['id' => $booking_id, 'status' => 'confirmed'],
            ['%s', '%s', '%s'],
            ['%d', '%s']
        );
    }

    private function render_confirmation_page(object $booking, string $token, ?array $group): void
    {
        $action_url  = add_query_arg([
            'hmw_cancel' => $token,
            'booking_id' => (int) $booking->id,
        ], home_url('/'));

        $event_title = (string) get_the_title((int) $booking->event_post_id);

        $session_rows = '';
        if ($group) {
            foreach ($group['sessions'] as $session) {
                $session_rows .= '<li' . ($session['is_current'] ? ' style="font-weight:600;"' : '') . '>' .
                    esc_html($session['title']) .
                    ($session['is_current'] ? ' (' . __('this booking', 'hmw-events') . ')' : '') .
                    '</li>';
            }
        }

        if ($group) {
            wp_die(
                '<h2>' . esc_html__('Cancel Your Booking', 'hmw-events') . '</h2>' .
                '<p>' . esc_html__('What would you like to cancel? This cannot be undone.', 'hmw-events') . '</p>' .
                '<ul>' .
                '<li><strong>' . esc_html__('Series:', 'hmw-events') . '</strong> ' . esc_html($event_title) . '</li>' .
                '<li><strong>' . esc_html__('Booking Reference:', 'hmw-events') . '</strong> ' . esc_html($group['reference']) . '</li>' .
                '</ul>' .
                '<h3>' . esc_html__('Your sessions', 'hmw-events') . '</h3>' .
                '<ul>' . $session_rows . '</ul>' .
                '<form method="post" action="' . esc_url($action_url) . '">' .
                '<input type="hidden" name="hmw_cancel_confirm" value="1">' .
                '<p><button type="submit" name="hmw_cancel_scope" value="session" style="background:#b32d2e;color:#fff;border:0;padding:10px 22px;font-size:15px;cursor:pointer;">' .
                esc_html__('Cancel Only This Session', 'hmw-events') .
                '</button> ' .
                '<button type="submit" name="hmw_cancel_scope" value="group" style="background:#b32d2e;color:#fff;border:0;padding:10px 22px;font-size:15px;cursor:pointer;">' .
                esc_html(sprintf(__('Cancel All %d Sessions', 'hmw-events'), $group['count'])) .
                '</button></p>' .
                '</form>' .
                '<p style="margin-top:16px;"><a href="' . esc_url(home_url('/')) . '">' . esc_html__('No, Keep My Booking', 'hmw-events') . '</a></p>',
                __('Confirm Booking Cancellation', 'hmw-events'),
                ['response' => 200]
            );
        }

        wp_die(
            '<h2>' . esc_html__('Cancel Your Booking', 'hmw-events') . '</h2>' .
            '<p>' . esc_html__('Are you sure you want to cancel this booking? This cannot be undone.', 'hmw-events') . '</p>' .
            '<ul>' .
            '<li><strong>' . esc_html__('Event:', 'hmw-events') . '</strong> ' . esc_html($event_title) . '</li>' .
            '<li><strong>' . esc_html__('Booking Number:', 'hmw-events') . '</strong> ' . esc_html((string) $booking->booking_number) . '</li>' .
            '</ul>' .
            '<form method="post" action="' . esc_url($action_url) . '">' .
            '<input type="hidden" name="hmw_cancel_confirm" value="1">' .
            '<button type="submit" style="background:#b32d2e;color:#fff;border:0;padding:10px 22px;font-size:15px;cursor:pointer;">' .
            esc_html__('Yes, Cancel My Booking', 'hmw-events') .
            '</button>' .
            '</form>' .
            '<p style="margin-top:16px;"><a href="' . esc_url(home_url('/')) . '">' . esc_html__('No, Keep My Booking', 'hmw-events') . '</a></p>',
            __('Confirm Booking Cancellation', 'hmw-events'),
            ['response' => 200]
        );
    }

    private function get_stored_token(int $booking_id): string
    {
        global $wpdb;
        return (string) $wpdb->get_var($wpdb->prepare(
            "SELECT cancel_token FROM {$this->bookings_table} WHERE id = %d",
            $booking_id
        ));
    }

    private function get_booking(int $booking_id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->bookings_table} WHERE id = %d AND deleted_at IS NULL AND status = 'confirmed'",
            $booking_id
        ));
    }
}
