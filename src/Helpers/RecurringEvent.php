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
class RecurringEvent
{
    /**
     * Create a recurring course pattern and generate instances.
     *
     * @since 1.0.0
     * @param int   $template_post_id The educator_course post that serves as the template.
     * @param array $recurrence_config Recurrence configuration.
     * @return int|false Recurrence ID on success, false on failure.
     */
    public static function create_recurring_series($template_post_id, $recurrence_config)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'educator_course_recurrence';

        // Validate template post exists
        $template = get_post($template_post_id);
        if (!$template || $template->post_type !== 'educator_course') {
            return false;
        }

        // Set template ID on the template post
        update_post_meta($template_post_id, 'course_template_id', $template_post_id);

        // Insert recurrence pattern
        $result = $wpdb->insert(
            $table_name,
            [
                'course_template_id' => $template_post_id,
                'recurrence_type' => $recurrence_config['type'] ?? 'weekly',
                'recurrence_days' => $recurrence_config['days'] ?? '',
                'start_date' => $recurrence_config['start_date'] ?? date('Y-m-d'),
                'end_date' => $recurrence_config['end_date'] ?? null,
                'max_occurrences' => $recurrence_config['max_occurrences'] ?? null,
                'is_active' => 1,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%d', '%d']
        );

        if (!$result) {
            return false;
        }

        $recurrence_id = $wpdb->insert_id;

        // Store recurrence_id on template post
        update_post_meta($template_post_id, 'course_recurrence_id', $recurrence_id);

        // Generate course instances
        self::generate_instances($recurrence_id);

        return $recurrence_id;
    }

    public static function delete_recurrence_pattern($recurrence_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'educator_course_recurrence';

        return $wpdb->delete(
            $table_name,
            ['id' => $recurrence_id],
            ['%d']
        );
    }

    /**
     * Generate course instances from a recurrence pattern.
     *
     * @since 1.0.0
     * @param int $recurrence_id The recurrence pattern ID.
     * @return int Number of instances created.
     */
    public static function generate_instances($recurrence_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'educator_course_recurrence';

        // Get recurrence pattern
        $pattern = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d AND is_active = 1",
            $recurrence_id
        ));

        if (!$pattern) {
            return 0;
        }

        // Get template post
        $template = get_post($pattern->course_template_id);
        if (!$template) {
            return 0;
        }

        // Get template meta
        $template_start = get_post_meta($template->ID, 'course_start_date', true);
        $template_end = get_post_meta($template->ID, 'course_end_date', true);

        if (!$template_start) {
            return 0;
        }

        // Calculate course duration
        $start_dt = new \DateTime($template_start);
        $end_dt = $template_end ? new \DateTime($template_end) : clone $start_dt;
        $duration = $start_dt->diff($end_dt);

        // Generate occurrences
        $occurrences = self::calculate_occurrences($pattern, $start_dt);
        $created_count = 0;

        foreach ($occurrences as $occurrence_date) {
            // Skip if instance already exists for this date
            $existing = get_posts([
                'post_type' => 'educator_course',
                'meta_query' => [
                    [
                        'key' => 'course_recurrence_id',
                        'value' => $recurrence_id,
                    ],
                    [
                        'key' => 'course_start_date',
                        'value' => $occurrence_date->format('Y-m-d H:i:s'),
                    ],
                ],
                'posts_per_page' => 1,
            ]);

            if (!empty($existing)) {
                continue;
            }

            // Create instance
            $instance_start = clone $occurrence_date;
            $instance_end = clone $occurrence_date;
            $instance_end->add($duration);

            $instance_data = [
                'post_title' => $template->post_title . ' - ' . $occurrence_date->format('d-m-Y'),
                'post_content' => $template->post_content,
                'post_status' => 'publish',
                'post_type' => 'educator_course',
                'post_author' => $template->post_author,
                'post_name' => sanitize_title($template->post_name . '-' . $occurrence_date->format('d-m-Y')),
            ];

            $instance_id = wp_insert_post($instance_data);

            if ($instance_id) {
                // Copy all meta from template
                $template_meta = get_post_meta($template->ID);
                foreach ($template_meta as $key => $values) {
                    if (in_array($key, ['course_template_id', 'course_recurrence_id'])) {
                        continue; // Skip these, we'll set them specifically
                    }
                    foreach ($values as $value) {
                        update_post_meta($instance_id, $key, maybe_unserialize($value));
                    }
                }

                // Copy taxonomy terms
                $taxonomies = get_object_taxonomies('educator_course');
                foreach ($taxonomies as $taxonomy) {
                    $terms = wp_get_object_terms($template->ID, $taxonomy, ['fields' => 'ids']);
                    if (!empty($terms) && !is_wp_error($terms)) {
                        wp_set_object_terms($instance_id, $terms, $taxonomy);
                    }
                }

                // Set instance-specific meta
                update_post_meta($instance_id, 'course_template_id', $pattern->course_template_id);
                update_post_meta($instance_id, 'course_recurrence_id', $recurrence_id);
                update_post_meta($instance_id, 'course_start_date', $instance_start->format('Y-m-d H:i:s'));
                update_post_meta($instance_id, 'course_end_date', $instance_end->format('Y-m-d H:i:s'));

                // Initialize availability for this instance
                $capacity = get_post_meta($instance_id, '_event_capacity', true) ?: 20;
                $wpdb->replace(
                    $wpdb->prefix . 'educator_course_availability',
                    [
                        'course_post_id' => $instance_id,
                        'capacity' => $capacity,
                        'booked_count' => 0,
                        'available_count' => $capacity,
                    ],
                    ['%d', '%d', '%d', '%d']
                );

                $created_count++;
            }
        }

        return $created_count;
    }

    /**
     * Calculate occurrence dates based on recurrence pattern.
     *
     * @since 1.0.0
     * @param object   $pattern Recurrence pattern from database.
     * @param \DateTime $start_dt Starting date/time from template.
     * @return array Array of DateTime objects.
     */
    private static function calculate_occurrences($pattern, $start_dt)
    {
        $occurrences = [];
        
        // Handle custom dates separately
        if ($pattern->recurrence_type === 'custom') {
            return self::calculate_custom_occurrences($pattern, $start_dt);
        }
        
        $current = new \DateTime($pattern->start_date);
        $current->setTime((int)$start_dt->format('H'), (int)$start_dt->format('i'), (int)$start_dt->format('s'));

        $end_date = $pattern->end_date ? new \DateTime($pattern->end_date) : null;
        $max_count = $pattern->max_occurrences ?? 52; // Default to 1 year of weekly courses
        $count = 0;

        while ($count < $max_count) {
            // Check if we've passed end date
            if ($end_date && $current > $end_date) {
                break;
            }

            // Add this occurrence
            $occurrences[] = clone $current;
            $count++;

            // Calculate next occurrence
            switch ($pattern->recurrence_type) {
                case 'daily':
                    $current->modify('+1 day');
                    break;

                case 'weekly':
                    if (!empty($pattern->recurrence_days)) {
                        // Specific days of week (e.g., "1,3,5" for Mon, Wed, Fri)
                        $days = array_map('intval', explode(',', $pattern->recurrence_days));
                        sort($days); // Ensure days are in order
                        
                        $current_day = (int)$current->format('N');
                        $found_next = false;

                        // Try to find next occurrence within same week
                        foreach ($days as $target_day) {
                            if ($target_day > $current_day) {
                                $diff = $target_day - $current_day;
                                $current->modify('+' . $diff . ' days');
                                $found_next = true;
                                break;
                            }
                        }

                        // If no day found in current week, jump to next week's first day
                        if (!$found_next) {
                            // Calculate days until the first selected day of next week
                            $days_until_next_week = 7 - $current_day;
                            $days_to_first_occurrence = $days_until_next_week + $days[0];
                            $current->modify('+' . $days_to_first_occurrence . ' days');
                        }
                    } else {
                        // Same day each week
                        $current->modify('+1 week');
                    }
                    break;

                case 'fortnightly':
                    if (!empty($pattern->recurrence_days)) {
                        // Specific days every 2 weeks
                        $days = array_map('intval', explode(',', $pattern->recurrence_days));
                        sort($days);
                        
                        $current_day = (int)$current->format('N');
                        $found_next = false;

                        // Try to find next occurrence within same week
                        foreach ($days as $target_day) {
                            if ($target_day > $current_day) {
                                $diff = $target_day - $current_day;
                                $current->modify('+' . $diff . ' days');
                                $found_next = true;
                                break;
                            }
                        }

                        // If no day found in current week, jump 2 weeks to first day
                        if (!$found_next) {
                            // Calculate days until the first selected day 2 weeks from now
                            $days_until_next_week = 7 - $current_day;
                            $days_to_first_occurrence = $days_until_next_week + 7 + $days[0];
                            $current->modify('+' . $days_to_first_occurrence . ' days');
                        }
                    } else {
                        // Same day every 2 weeks
                        $current->modify('+2 weeks');
                    }
                    break;

                case 'monthly':
                    $current->modify('+1 month');
                    break;

                case 'bimonthly':
                    $current->modify('+2 months');
                    break;

                default:
                    // Unknown type, stop
                    break 2;
            }
        }

        return $occurrences;
    }

    /**
     * Calculate occurrences for custom dates.
     *
     * @since 1.0.0
     * @param object   $pattern Recurrence pattern from database.
     * @param \DateTime $start_dt Starting date/time from template.
     * @return array Array of DateTime objects.
     */
    private static function calculate_custom_occurrences($pattern, $start_dt)
    {
        $occurrences = [];
        
        // Get custom dates from template post meta
        $template = get_post($pattern->course_template_id);
        if (!$template) {
            return $occurrences;
        }

        $custom_dates = get_field('course_custom_dates', $template->ID);
        
        if (empty($custom_dates) || !is_array($custom_dates)) {
            return $occurrences;
        }

        // Extract dates and sort them
        $dates = [];
        foreach ($custom_dates as $row) {
            if (!empty($row['date'])) {
                $dates[] = $row['date'];
            }
        }
        
        sort($dates);

        // Convert to DateTime objects with the template's time
        foreach ($dates as $date_string) {
            $occurrence = new \DateTime($date_string);
            $occurrence->setTime(
                (int)$start_dt->format('H'),
                (int)$start_dt->format('i'),
                (int)$start_dt->format('s')
            );
            $occurrences[] = $occurrence;
        }

        return $occurrences;
    }

    /**
     * Update all instances in a recurring series.
     *
     * @since 1.0.0
     * @param int   $recurrence_id The recurrence pattern ID.
     * @param array $updates Array of meta fields to update on all instances.
     * @param bool  $future_only Only update future instances.
     * @return int Number of instances updated.
     */
    public static function update_series($recurrence_id, $updates, $future_only = true)
    {
        global $wpdb;

        $meta_query = [
            [
                'key' => 'course_recurrence_id',
                'value' => $recurrence_id,
            ],
        ];

        if ($future_only) {
            $meta_query[] = [
                'key' => 'course_start_date',
                'value' => current_time('mysql'),
                'compare' => '>=',
                'type' => 'DATETIME',
            ];
        }

        $instances = get_posts([
            'post_type' => 'educator_course',
            'meta_query' => $meta_query,
            'posts_per_page' => -1,
            'fields' => 'ids',
        ]);

        $updated_count = 0;

        foreach ($instances as $instance_id) {
            foreach ($updates as $meta_key => $meta_value) {
                update_post_meta($instance_id, $meta_key, $meta_value);
            }
            $updated_count++;
        }

        return $updated_count;
    }

    public static function deactivate_series($recurrence_id)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'educator_course_recurrence';

        // Deactivate the recurrence pattern
        $wpdb->update(
            $table_name,
            ['is_active' => 0],
            ['id' => $recurrence_id],
            ['%d'],
            ['%d']
        );

        // Delete all future instances and return count
        return self::delete_series($recurrence_id, true, true);
    }

    /**
     * Delete a recurring series and optionally its instances.
     *
     * @since 1.0.0
     * @param int  $recurrence_id The recurrence pattern ID.
     * @param bool $delete_instances Whether to delete course instances.
     * @param bool $future_only Only delete future instances.
     * @return int Number of instances deleted (hard-deleted or trashed).
     */
    public static function delete_series($recurrence_id, $delete_instances = false, $future_only = true)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'educator_course_recurrence';
        $deleted_count = 0;

        if ($delete_instances) {
            // Get the recurrence pattern to find template ID
            $pattern = $wpdb->get_row($wpdb->prepare(
                "SELECT course_template_id FROM {$table_name} WHERE id = %d",
                $recurrence_id
            ));

            $template_id = $pattern ? $pattern->course_template_id : null;
            $meta_query = [
                [
                    'key' => 'course_recurrence_id',
                    'value' => $recurrence_id,
                ],
            ];

            if ($future_only) {
                $meta_query[] = [
                    'key' => 'course_start_date',
                    'value' => current_time('mysql'),
                    'compare' => '>=',
                    'type' => 'DATETIME',
                ];
            }

            $instances = get_posts([
                'post_type' => 'educator_course',
                'meta_query' => $meta_query,
                'posts_per_page' => -1,
                'fields' => 'ids',
            ]);

            foreach ($instances as $instance_id) {
                // Skip the template post - never delete it
                if ($template_id && $instance_id == $template_id) {
                    continue;
                }

                // Check if this instance has bookings
                $has_bookings = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}hmwevents_bookings WHERE course_post_id = %d",
                    $instance_id
                ));

                if ($has_bookings) {
                    // Soft delete (trash) instances with bookings
                    if (wp_trash_post($instance_id)) {
                        $deleted_count++;
                    }
                } else {
                    // Hard delete instances without bookings
                    if (wp_delete_post($instance_id, true)) {
                        $deleted_count++;
                    }
                }
            }
        }

        // Deactivate the recurrence pattern
        $wpdb->update(
            $table_name,
            ['is_active' => 0],
            ['id' => $recurrence_id],
            ['%d'],
            ['%d']
        );

        return $deleted_count;
    }

    /**
     * Get all instances of a recurring series.
     *
     * @since 1.0.0
     * @param int  $recurrence_id The recurrence pattern ID.
     * @param bool $future_only Only get future instances.
     * @return array Array of post objects.
     */
    public static function get_series_instances($recurrence_id, $future_only = false)
    {
        $meta_query = [
            [
                'key' => 'course_recurrence_id',
                'value' => $recurrence_id,
            ],
        ];

        if ($future_only) {
            $meta_query[] = [
                'key' => 'course_start_date',
                'value' => current_time('mysql'),
                'compare' => '>=',
                'type' => 'DATETIME',
            ];
        }

        return get_posts([
            'post_type' => 'educator_course',
            'meta_query' => $meta_query,
            'posts_per_page' => -1,
            'orderby' => 'meta_value',
            'meta_key' => 'course_start_date',
            'order' => 'ASC',
        ]);
    }

    /**
     * Regenerate all future instances with updated recurrence pattern.
     *
     * This deletes all future instances and recreates them based on the new pattern.
     * Use when recurrence pattern changes (days, interval, type).
     *
     * @since 1.0.0
     * @param int   $recurrence_id The recurrence pattern ID.
     * @param array $new_config New recurrence configuration.
     * @return int Number of new instances created.
     */
    public static function regenerate_series($recurrence_id, $new_config)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'educator_course_recurrence';

        // Get the recurrence pattern
        $pattern = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $recurrence_id
        ));

        if (!$pattern) {
            return 0;
        }

        // Delete all future instances (will skip template, trash those with bookings)
        self::delete_series($recurrence_id, true, true);

        // Update the recurrence pattern in database
        $wpdb->update(
            $table_name,
            [
                'recurrence_type' => $new_config['type'] ?? $pattern->recurrence_type,
                'recurrence_days' => $new_config['days'] ?? $pattern->recurrence_days,
                'end_date' => $new_config['end_date'] ?? $pattern->end_date,
                'max_occurrences' => $new_config['max_occurrences'] ?? $pattern->max_occurrences,
                'is_active' => 1, // Reactivate since we're regenerating
            ],
            ['id' => $recurrence_id],
            ['%s', '%s', '%s', '%d', '%d'],
            ['%d']
        );

        // Regenerate instances with new pattern
        $created_count = self::generate_instances($recurrence_id);

        return $created_count;
    }

    /**
     * Update recurrence pattern in database only (no instance changes).
     *
     * @since 1.0.0
     * @param int   $recurrence_id The recurrence pattern ID.
     * @param array $config Configuration to update.
     * @return bool Success.
     */
    public static function update_pattern($recurrence_id, $config)
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'educator_course_recurrence';

        $result = $wpdb->update(
            $table_name,
            [
                'recurrence_type' => $config['type'] ?? null,
                'recurrence_days' => $config['days'] ?? null,
                'end_date' => $config['end_date'] ?? null,
                'max_occurrences' => $config['max_occurrences'] ?? null,
            ],
            ['id' => $recurrence_id],
            ['%s', '%s', '%s', '%d'],
            ['%d']
        );

        return $result !== false;
    }
}
