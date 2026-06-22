<?php

/**
 * Recurring Course Helper.
 *
 * Handles creation and management of recurring educator courses.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Helpers;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Recurring Course Helper.
 */
class Course
{

  /**
   * Calculate course availability values from source data.
   *
   * @since 1.0.0
   * @param int $course_id Course post ID.
   * @return array|null Availability payload for DB upsert or null for invalid course.
   */
  public static function calculate_course_availability($course_id)
  {
    global $wpdb;

    $course = get_post($course_id);

    if (!$course || $course->post_type !== 'educator_course') {
      return null;
    }

    $capacity = (int) (get_field('course_capacity', $course_id) ?: 0);

    $booked_count = (int) ($wpdb->get_var($wpdb->prepare(
      "SELECT COALESCE(SUM(
                CASE
                  WHEN ticket_quantity IS NULL OR ticket_quantity < 1 THEN 1
                  /* Guard against known bad migration outliers like 111121 seats. */
                  WHEN booking_source = 'migration' AND ticket_quantity > 100 THEN 1
                  ELSE ticket_quantity
                END
              ), 0)
            FROM {$wpdb->prefix}educator_bookings
            WHERE course_post_id = %d
              AND deleted_at IS NULL
              AND status = 'confirmed'",
      $course_id
    )) ?: 0);

    return [
      'course_post_id' => (int) $course_id,
      'capacity' => max(0, $capacity),
      'booked_count' => max(0, $booked_count),
      'available_count' => max(0, $capacity - $booked_count),
    ];
  }

  /**
   * Ensure the availability cache row exists and is up to date.
   *
   * @since 1.0.0
   * @param int $course_id Course post ID.
   * @return bool
   */
  public static function ensure_course_availability_row($course_id)
  {
    global $wpdb;

    $availability = self::calculate_course_availability($course_id);

    if (!$availability) {
      return false;
    }

    $result = $wpdb->replace(
      $wpdb->prefix . 'educator_course_availability',
      $availability,
      ['%d', '%d', '%d', '%d']
    );

    return $result !== false;
  }

  /**
   * Get course details including pricing.
   *
   * @since 1.0.0
   * @param int $course_id Course post ID.
   * @return array|null Course details or null if not found.
   */
  public static function get_course_details($course_id)
  {
    $course = get_post($course_id);

    if (!$course || $course->post_type !== 'educator_course') {
      return null;
    }

    // Correct field names from ACF JSON
    $cost = get_field('course_full_cost', $course_id);
    $course_deposit_cost = get_field('course_deposit_cost', $course_id); // ← Changed from course_deposit_amount
    $capacity = get_field('course_capacity', $course_id);
    $educator_id = get_field('course_educator_id', $course_id);
    $start_date = get_field('course_start_date', $course_id);
    $end_date = get_field('course_end_date', $course_id);

    // Location fields
    $address = get_field('course_location_address', $course_id);
    
    // Handle both old text format and new Google Map format
    if (is_array($address) && isset($address['address'])) {
      // New Google Map format
      $address_string = $address['address'];
    } elseif (is_string($address)) {
      // Old text format (backward compatibility)
      $address_string = $address;
    } else {
      $address_string = '';
    }
    
    // Ensure address_string is always a string (handle nested arrays or unexpected formats)
    $address_string = is_string($address_string) ? $address_string : '';
    
    $suburb = get_field('course_location_suburb', $course_id);
    $state = get_field('course_location_state', $course_id);
    $postcode = get_field('course_location_postcode', $course_id);

    // Build location string - ensure all values are strings
    $location_parts = array_filter([
      is_string($address_string) ? $address_string : '',
      is_string($suburb) ? $suburb : '',
      is_string($state) ? $state : '',
      is_string($postcode) ? $postcode : ''
    ]);
    $location = implode(', ', $location_parts);

    return [
      'id' => $course_id,
      'title' => $course->post_title,
      'course_full_cost' => (float) ($cost ?: 0),
      'course_deposit_cost' => (float) ($course_deposit_cost ?: 0),
      'course_capacity' => (int) ($capacity ?: 0),
      'course_educator_id' => (int) ($educator_id ?: 0),
      'course_start_date' => $start_date,
      'course_end_date' => $end_date,
      'course_location_address' => $address, // Keep original format (array or string)
      'course_location_address_string' => $address_string, // Add string version for convenience
      'course_location_suburb' => $suburb,
      'course_location_state' => $state,
      'course_location_postcode' => $postcode,
      'course_status' => get_field('course_status', $course_id) ?: 'draft',
      'course_is_external' => (bool) get_field('course_is_external', $course_id),
      'course_external_url' => get_field('course_external_url', $course_id),
    ];
  }

  /**
   * Check course availability.
   *
   * @since 1.0.0
   * @param int $course_id Course post ID.
   * @return bool
   */
  public static function check_course_availability($course_id)
  {
    global $wpdb;

    $availability = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}educator_course_availability
            WHERE course_post_id = %d
        ", $course_id));

    if (!$availability) {
      // Lazily initialize missing cache rows for older courses.
      if (!self::ensure_course_availability_row($course_id)) {
        return false;
      }

      $availability = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}educator_course_availability
            WHERE course_post_id = %d
        ", $course_id));

      if (!$availability) {
        return false;
      }
    }

    return $availability->available_count > 0;
  }
}
