<?php

namespace HMWEvents\Services;

use HMWEvents\Helpers\EventHelper;

defined('ABSPATH') || die('Don\'t run this file directly!');

class CapacityService
{
    private ?EventDataService $event_data_service = null;

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }

        return $this->event_data_service;
    }

    public function register(): void
    {
    }

    public function get_capacity(int $event_id): int
    {
        return (int) ($this->event_data()->get_capacity($event_id) ?? 0);
    }

    public function get_booked_people(int $event_id): int
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');

        return (int) ($wpdb->get_var($wpdb->prepare(
            "SELECT {$this->booked_people_expression()}
                FROM {$bookings_table} b
                WHERE b.event_post_id = %d
                    AND b.deleted_at IS NULL
                    AND b.status = 'confirmed'",
            $event_id
        )) ?: 0);
    }

    public function get_booked_people_for_events(array $event_ids): array
    {
        global $wpdb;

        $event_ids = array_values(array_unique(array_filter(array_map('intval', $event_ids))));

        if (empty($event_ids)) {
            return [];
        }

        $bookings_table = DatabaseService::get_table_name('bookings');
        $placeholders = implode(',', array_fill(0, count($event_ids), '%d'));

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT b.event_post_id, {$this->booked_people_expression()} AS booked_people
                FROM {$bookings_table} b
                WHERE b.event_post_id IN ({$placeholders})
                    AND b.deleted_at IS NULL
                    AND b.status = 'confirmed'
                GROUP BY b.event_post_id",
            ...$event_ids
        )) ?: [];

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row->event_post_id] = (int) $row->booked_people;
        }

        return $counts;
    }

    private function booked_people_expression(): string
    {
        return "COALESCE(SUM(
                        CASE
                            WHEN b.ticket_quantity IS NULL OR b.ticket_quantity < 1 THEN 1
                            WHEN b.booking_source = 'migration' AND b.ticket_quantity > 100 THEN 1
                            ELSE b.ticket_quantity
                        END
                    ), 0)";
    }

    public function get_remaining_places(int $event_id): int
    {
        $capacity = $this->get_capacity($event_id);
        if ($capacity <= 0) {
            return 0;
        }

        return max(0, $capacity - $this->get_booked_people($event_id));
    }

    public function get_option_capacity(int $attendance_option_id): ?int
    {
        global $wpdb;

        $options_table = DatabaseService::get_table_name('event_attendance_options');

        $capacity = $wpdb->get_var($wpdb->prepare(
            "SELECT capacity FROM {$options_table} WHERE id = %d",
            $attendance_option_id
        ));

        if ($capacity === null || $capacity === '') {
            return null;
        }

        return max(0, (int) $capacity);
    }

    public function get_option_booked(int $attendance_option_id): int
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
                FROM {$bookings_table}
                WHERE attendance_option_id = %d
                    AND status IN ('pending', 'confirmed')
                    AND deleted_at IS NULL",
            $attendance_option_id
        )) ?: 0;
    }

    public function get_option_remaining(int $attendance_option_id): ?int
    {
        $capacity = $this->get_option_capacity($attendance_option_id);
        if ($capacity === null) {
            return null;
        }

        return max(0, $capacity - $this->get_option_booked($attendance_option_id));
    }

    public function check_event_places(int $event_id, int $people_requested): true|\WP_Error
    {
        $capacity = $this->get_capacity($event_id);
        if ($capacity <= 0) {
            return true;
        }

        $people_requested = max(0, $people_requested);
        $remaining = max(0, $capacity - $this->get_booked_people($event_id));

        if ($people_requested > $remaining) {
            /* translators: %d is the number of remaining places */
            $message = sprintf(_n('%d place remaining for this event.', '%d places remaining for this event.', $remaining, 'hmw-events'), $remaining);

            return new \WP_Error('event_full', $message);
        }

        return true;
    }

    public function check_option_bookings(int $attendance_option_id, int $bookings_requested = 1): true|\WP_Error
    {
        global $wpdb;

        $options_table = DatabaseService::get_table_name('event_attendance_options');

        $option = $wpdb->get_row($wpdb->prepare(
            "SELECT capacity, label FROM {$options_table} WHERE id = %d",
            $attendance_option_id
        ));

        if (!$option || $option->capacity === null) {
            return true;
        }

        $capacity = max(0, (int) $option->capacity);
        $bookings_requested = max(1, $bookings_requested);
        $booked = $this->get_option_booked($attendance_option_id);

        if ($booked + $bookings_requested > $capacity) {
            $remaining = max(0, $capacity - $booked);
            /* translators: 1: option label, 2: number of bookings remaining */
            $message = sprintf(
                __('The "%1$s" option has %2$s remaining.', 'hmw-events'),
                $option->label,
                sprintf(_n('%d booking', '%d bookings', $remaining, 'hmw-events'), $remaining)
            );

            return new \WP_Error('attendance_option_full', $message);
        }

        return true;
    }

    public function check(int $event_id, string $attendance_type, int $bookings_requested = 1, ?int $people_requested = null): true|\WP_Error
    {
        $attendance_type = sanitize_key($attendance_type) ?: 'individual';

        $people = $people_requested ?? max(1, $bookings_requested);

        $event_check = $this->check_event_places($event_id, $people);
        if (is_wp_error($event_check)) {
            return $event_check;
        }

        $option_id = EventHelper::resolve_attendance_option_id($event_id, $attendance_type);
        if ($option_id) {
            return $this->check_option_bookings((int) $option_id, $bookings_requested);
        }

        return true;
    }
}
