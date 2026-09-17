<?php

namespace HMWEvents\Helpers;

defined('ABSPATH') || die('Don\'t run this file directly!');

use HMWEvents\PostTypes\Event;
use HMWEvents\Services\CapacityService;
use HMWEvents\Services\DatabaseService;
use HMWEvents\Services\EventDataService;

class EventHelper
{
    private static function is_event(\WP_Post $post): bool
    {
        return $post->post_type === Event::POST_TYPE;
    }

    private static function availability_table(string $post_type): string
    {
        return DatabaseService::get_table_name('event_availability');
    }

    private static function bookings_table(string $post_type): string
    {
        return DatabaseService::get_table_name('bookings');
    }

    private static function post_id_column(string $post_type): string
    {
        return 'event_post_id';
    }

    private static function capacity(): CapacityService
    {
        static $service = null;

        if ($service === null) {
            $service = new CapacityService();
        }

        return $service;
    }

    /**
     * Calculate event availability values from source data.
     *
     * @param int $post_id Post ID.
     * @return array|null Availability payload or null for invalid post.
     */
    public static function calculate_course_availability($post_id)
    {
        global $wpdb;

        $post = get_post($post_id);

        if (!$post || !self::is_event($post)) {
            return null;
        }

        $event_data = new EventDataService();
        $capacity   = $event_data->get_capacity($post_id) ?? 0;
        $booked_count = self::capacity()->get_booked_people((int) $post_id);

        $col = self::post_id_column($post->post_type);

        return [
            $col          => (int) $post_id,
            'capacity'     => max(0, $capacity),
            'booked_count' => max(0, $booked_count),
            'available_count' => max(0, $capacity - $booked_count),
        ];
    }

    /**
     * Ensure the availability cache row exists and is up to date.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function ensure_course_availability_row($post_id)
    {
        global $wpdb;

        $availability = self::calculate_course_availability($post_id);

        if (!$availability) {
            return false;
        }

        $post = get_post($post_id);
        $table = self::availability_table($post->post_type);

        $result = $wpdb->replace($table, $availability, ['%d', '%d', '%d', '%d']);

        return $result !== false;
    }

    /**
     * Get event details including pricing.
     *
     * @param int $post_id Post ID.
     * @return array|null Details or null if not found.
     */
    public static function get_event_details($post_id)
    {
        $event = get_post($post_id);

        if (!$event || !self::is_event($event)) {
            return null;
        }

        $event_data   = new EventDataService();
        $start_date   = $event_data->get_start_date($event->ID) ?? '';
        $end_date     = $event_data->get_end_date($event->ID) ?? '';
        $price        = $event_data->get_price($event->ID) ?? 0.0;
        $deposit      = $event_data->get_deposit($event->ID) ?? 0.0;
        $capacity     = $event_data->get_capacity($event->ID) ?? 0;
        $organizer_id = $event_data->get_organizer_id($event->ID) ?? 0;
        $venue_addr   = $event_data->get_venue_address_string($event->ID);

        return [
            'id'                    => $event->ID,
            'title'                 => $event->post_title,
            'price'                 => $price,
            'deposit'               => $deposit,
            'capacity'              => $capacity,
            'organizer_id'          => $organizer_id,
            'start_date'            => $start_date,
            'end_date'              => $end_date,
            'venue_address'         => $venue_addr,
            'venue_address_string'  => $venue_addr,
            'status'                => $event->post_status,
            'is_external'           => false,
            'external_url'          => '',
        ];
    }

    /**
     * Check event availability.
     *
     * @param int $post_id Post ID.
     * @return bool
     */
    public static function check_course_availability($post_id)
    {
        global $wpdb;

        $post = get_post($post_id);
        if (!$post || !self::is_event($post)) {
            return false;
        }

        $table = self::availability_table($post->post_type);
        $col   = self::post_id_column($post->post_type);

        $availability = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$col} = %d",
            $post_id
        ));

        if (!$availability) {
            if (!self::ensure_course_availability_row($post_id)) {
                return false;
            }

            $availability = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table} WHERE {$col} = %d",
                $post_id
            ));

            if (!$availability) {
                return false;
            }
        }

        return $availability->available_count > 0;
    }

    /**
     * @param int    $event_id Event post ID.
     * @param string $email    Registrant email.
     * @return true|\WP_Error True if within cap, WP_Error if exceeded.
     */
    public static function check_registrant_cap(int $event_id, string $email): true|\WP_Error
    {
        $event_data = new EventDataService();
        $max        = $event_data->get_max_per_registrant($event_id);
        if ($max <= 0) {
            return true;
        }

        global $wpdb;
        $bookings_table = DatabaseService::get_table_name('bookings');

        $existing = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*)
            FROM {$bookings_table} b
            INNER JOIN {$wpdb->postmeta} pm ON b.registrant_post_id = pm.post_id
                AND pm.meta_key = 'registrant_email'
                AND pm.meta_value = %s
            WHERE b.event_post_id = %d
                AND b.status IN ('pending', 'confirmed')
                AND b.deleted_at IS NULL",
            $email,
            $event_id
        ));

        if ($existing >= $max) {
            /* translators: %d is the maximum allowed bookings per person */
            return new \WP_Error(
                'registrant_cap_exceeded',
                sprintf(__('You have reached the maximum of %d booking(s) per person for this event.', 'hmw-events'), $max)
            );
        }

        return true;
    }

    /**
     * @return int|null Attendance option ID, or null if not found.
     */
    public static function resolve_attendance_option_id(int $event_id, string $attendance_type): ?int
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');

        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table}
            WHERE event_post_id = %d
              AND is_active = 1
              AND (option_key = %s OR (option_key = '' AND option_type = %s))
            ORDER BY sort_order ASC
            LIMIT 1",
            $event_id,
            $attendance_type,
            $attendance_type
        )) ?: null;
    }

    /**
     * Return all active attendance options for an event, ordered for display.
     *
     * @return object[] Rows with id, option_type, option_key, label, description, price, capacity, sort_order.
     */
    public static function get_active_attendance_options(int $event_id): array
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, option_type, option_key, label, description, price, price_mode, pricing_rules, capacity, sort_order
            FROM {$table}
            WHERE event_post_id = %d
              AND is_active = 1
            ORDER BY sort_order ASC, id ASC",
            $event_id
        ));

        if (is_array($rows) && count($rows) === 1 && ($rows[0]->option_type ?? '') === 'individual') {
            $event_price = get_post_meta($event_id, '_event_price', true);
            if ($event_price !== '') {
                $rows[0]->price = (float) $event_price;
            }
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * Resolve the full active attendance option row for an event + selection key.
     *
     * Matches option_key first; rows without a key still resolve by
     * option_type for backwards compatibility.
     *
     * @return object|null Row with id, option_type, option_key, label, description, price, capacity.
     */
    public static function resolve_attendance_option(int $event_id, string $attendance_type): ?object
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT id, option_type, option_key, label, description, price, price_mode, pricing_rules, capacity
            FROM {$table}
            WHERE event_post_id = %d
              AND is_active = 1
              AND (option_key = %s OR (option_key = '' AND option_type = %s))
            ORDER BY sort_order ASC
            LIMIT 1",
            $event_id,
            $attendance_type,
            $attendance_type
        ));

        return $row ?: null;
    }

    /**
     * Resolve the price of an active attendance option.
     *
     * Returns null when no active option matches, so callers can fall back to
     * the event-level price. A matching option with price 0.0 returns 0.0.
     *
     * @return float|null Option price, or null when no active option matches.
     */
    public static function resolve_attendance_option_price(int $event_id, string $attendance_type): ?float
    {
        $option = self::resolve_attendance_option($event_id, $attendance_type);

        if ($option === null) {
            return null;
        }

        return (float) $option->price;
    }

    /**
     * @param int $attendance_option_id Attendance option ID.
     * @param int $bookings_requested   Number of bookings being made.
     * @return true|\WP_Error True if the option has bookings remaining, WP_Error if full.
     */
    public static function check_attendance_option_capacity(int $attendance_option_id, int $bookings_requested = 1): true|\WP_Error
    {
        return self::capacity()->check_option_bookings($attendance_option_id, $bookings_requested);
    }

    /**
     * Combined capacity check for a booking: event places plus option bookings.
     *
     * @param int      $event_id          Event post ID.
     * @param string   $attendance_type   Attendance option type.
     * @param int      $bookings_requested Number of bookings being made.
     * @param int|null $people_requested  Optional explicit people count override.
     * @return true|\WP_Error
     */
    public static function check_booking_capacity(int $event_id, string $attendance_type, int $bookings_requested = 1, ?int $people_requested = null): true|\WP_Error
    {
        return self::capacity()->check($event_id, $attendance_type, $bookings_requested, $people_requested);
    }
}
