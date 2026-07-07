<?php

/**
 * Course / Event Helper.
 *
 * Handles availability and details for both the legacy educator_course
 * CPT and the new hmw_event CPT. Uses the correct database table
 * depending on which post type is detected.
 *
 * @package HMWEvents
 * @since 1.0.0
 * @updated 2.0.0 Added hmw_event CPT support.
 */

namespace HMWEvents\Helpers;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventHelper
{
    /**
     * Determine if a post is an event (old or new).
     */
    private static function is_event(\WP_Post $post): bool
    {
        return in_array($post->post_type, ['educator_course', 'hmw_event'], true);
    }

    /**
     * Get the correct availability table name for a post type.
     */
    private static function availability_table(string $post_type): string
    {
        global $wpdb;
        return $post_type === 'hmw_event'
            ? $wpdb->prefix . 'hmwevents_event_availability'
            : $wpdb->prefix . 'educator_course_availability';
    }

    /**
     * Get the correct bookings table name for a post type.
     */
    private static function bookings_table(string $post_type): string
    {
        global $wpdb;
        return $post_type === 'hmw_event'
            ? $wpdb->prefix . 'hmwevents_bookings'
            : $wpdb->prefix . 'hmwevents_bookings';
    }

    /**
     * Get the FK column name for the event/course ID.
     */
    private static function post_id_column(string $post_type): string
    {
        return $post_type === 'hmw_event' ? 'event_post_id' : 'course_post_id';
    }

    /**
     * Calculate course/event availability values from source data.
     *
     * @since 1.0.0
     * @updated 2.0.0 Supports hmw_event CPT.
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

        $is_new = $post->post_type === 'hmw_event';
        $capacity = $is_new
            ? (int) (get_post_meta($post_id, '_event_capacity', true) ?: 0)
            : (int) (get_field('_event_capacity', $post_id) ?: 0);

        $table = self::bookings_table($post->post_type);
        $col   = self::post_id_column($post->post_type);

        $booked_count = (int) ($wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(SUM(
                      CASE
                        WHEN ticket_quantity IS NULL OR ticket_quantity < 1 THEN 1
                        WHEN booking_source = 'migration' AND ticket_quantity > 100 THEN 1
                        ELSE ticket_quantity
                      END
                    ), 0)
                  FROM {$table}
                  WHERE {$col} = %d
                    AND deleted_at IS NULL
                    AND status = 'confirmed'",
            $post_id
        )) ?: 0);

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
     * @since 1.0.0
     * @updated 2.0.0 Supports hmw_event CPT.
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
     * Get event/course details including pricing.
     *
     * @since 1.0.0
     * @updated 2.0.0 Supports hmw_event CPT.
     * @param int $post_id Post ID.
     * @return array|null Details or null if not found.
     */
    public static function get_course_details($post_id)
    {
        $post = get_post($post_id);

        if (!$post || !self::is_event($post)) {
            return null;
        }

        $is_new = $post->post_type === 'hmw_event';

        if ($is_new) {
            return self::get_event_details($post);
        }

        return self::get_legacy_course_details($post);
    }

    /**
     * Get details for a new hmw_event post.
     */
    private static function get_event_details(\WP_Post $event): array
    {
        $start_date = get_post_meta($event->ID, '_event_start_date', true);
        $end_date   = get_post_meta($event->ID, '_event_end_date', true);
        $price      = (float) get_post_meta($event->ID, '_event_price', true);
        $deposit    = (float) get_post_meta($event->ID, '_event_deposit', true);
        $capacity   = (int) get_post_meta($event->ID, '_event_capacity', true);
        $organizer_id = (int) get_post_meta($event->ID, '_organizer_id', true);
        $venue      = get_post_meta($event->ID, '_event_venue_name', true);
        $venue_addr = get_post_meta($event->ID, '_event_venue_address', true);

        return [
            'id'                           => $event->ID,
            'title'                        => $event->post_title,
            '_event_price'             => $price,
            '_event_deposit'          => $deposit,
            '_event_capacity'              => $capacity,
            'course_educator_id'           => $organizer_id,
            'course_start_date'            => $start_date,
            'course_end_date'              => $end_date,
            'course_location_address'      => $venue_addr,
            'course_location_address_string' => $venue_addr,
            'course_location_suburb'       => '',
            'course_location_state'        => '',
            'course_location_postcode'     => '',
            'course_status'                => $event->post_status,
            'course_is_external'           => false,
            'course_external_url'          => '',
        ];
    }

    /**
     * Get details for a legacy educator_course post.
     */
    private static function get_legacy_course_details(\WP_Post $course): array
    {
        $cost = get_field('_event_price', $course->ID);
        $course_deposit_cost = get_field('_event_deposit', $course->ID);
        $capacity = get_field('_event_capacity', $course->ID);
        $educator_id = get_field('course_educator_id', $course->ID);
        $start_date = get_field('course_start_date', $course->ID);
        $end_date = get_field('course_end_date', $course->ID);

        $address = get_field('course_location_address', $course->ID);

        if (is_array($address) && isset($address['address'])) {
            $address_string = $address['address'];
        } elseif (is_string($address)) {
            $address_string = $address;
        } else {
            $address_string = '';
        }

        $address_string = is_string($address_string) ? $address_string : '';
        $suburb = get_field('course_location_suburb', $course->ID);
        $state = get_field('course_location_state', $course->ID);
        $postcode = get_field('course_location_postcode', $course->ID);

        $location_parts = array_filter([
            is_string($address_string) ? $address_string : '',
            is_string($suburb) ? $suburb : '',
            is_string($state) ? $state : '',
            is_string($postcode) ? $postcode : ''
        ]);
        $location = implode(', ', $location_parts);

        return [
            'id'                           => $course->ID,
            'title'                        => $course->post_title,
            '_event_price'             => (float) ($cost ?: 0),
            '_event_deposit'          => (float) ($course_deposit_cost ?: 0),
            '_event_capacity'              => (int) ($capacity ?: 0),
            'course_educator_id'           => (int) ($educator_id ?: 0),
            'course_start_date'            => $start_date,
            'course_end_date'              => $end_date,
            'course_location_address'      => $address,
            'course_location_address_string' => $address_string,
            'course_location_suburb'       => $suburb,
            'course_location_state'        => $state,
            'course_location_postcode'     => $postcode,
            'course_status'                => get_field('course_status', $course->ID) ?: 'draft',
            'course_is_external'           => (bool) get_field('course_is_external', $course->ID),
            'course_external_url'          => get_field('course_external_url', $course->ID),
        ];
    }

    /**
     * Check event/course availability.
     *
     * @since 1.0.0
     * @updated 2.0.0 Supports hmw_event CPT.
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
}
