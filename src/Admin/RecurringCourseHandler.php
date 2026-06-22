<?php
/**
 * Recurring Course Handler.
 *
 * Handles ACF save post hooks for recurring course creation and updates.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Admin;

use HMWEvents\Helpers\Course;
use HMWEvents\Helpers\RecurringCourse;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Recurring Course Handler.
 */
class RecurringCourseHandler
{
    /**
     * Initialize hooks.
     *
     * @since 1.0.0
     */
    public function register()
    {
        add_action('acf/save_post', [$this, 'handle_recurring_course_save'], 20);
        add_action('admin_notices', [$this, 'show_recurring_course_notices']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    /**
     * Handle course cloning when post is saved.
     *
     * @since 1.0.0
     * @param int $post_id The post ID being saved.
     */
    public function handle_recurring_course_save($post_id)
    {
        // Prevent infinite loops
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        // Only process educator_course posts
        if (get_post_type($post_id) !== 'educator_course') {
            return;
        }

        // Check if cloning is enabled
        $is_cloning = get_field('course_is_recurring', $post_id);
        
        if (!$is_cloning) {
            // Cloning disabled, nothing to do
            return;
        }

        // Check if clones already exist (to prevent duplicate cloning on every save)
        $already_cloned = get_post_meta($post_id, '_clones_created', true);
        
        if ($already_cloned) {
            // Clones already exist — check if any were manually deleted and re-create only those.
            $this->handle_reclone_deleted($post_id);
            return;
        }

        // New clone request - create standalone clones
        $this->handle_course_cloning($post_id);
    }

    /**
     * Create standalone clones of the course for specified dates.
     *
     * @since 1.0.0
     * @param int $post_id Source course post ID.
     */
    private function handle_course_cloning($post_id)
    {
        // Get start date and calculate duration
        $start_date = get_field('course_start_date', $post_id);
        $end_date = get_field('course_end_date', $post_id);
        
        if (!$start_date || !$end_date) {
            $this->set_notice($post_id, 'error', __('Cannot clone course: Start date and end date are required.', 'hmw-events'));
            return;
        }

        // Calculate course duration
        $start_dt = new \DateTime($start_date);
        $end_dt = new \DateTime($end_date);
        $duration = $start_dt->diff($end_dt);

        // Get custom dates
        $custom_dates = get_field('course_custom_dates', $post_id);
        
        if (empty($custom_dates) || !is_array($custom_dates)) {
            $this->set_notice($post_id, 'error', __('Cannot clone course: No clone dates specified.', 'hmw-events'));
            return;
        }

        // Get source course data
        $source_post = get_post($post_id);
        $cloned_count = 0;
        $clone_ids_map = []; // date_suffix => clone_id

        // Create clones for each custom date
        foreach ($custom_dates as $date_row) {
            if (empty($date_row['date'])) {
                continue;
            }

            // Calculate new dates for this clone
            $clone_start = new \DateTime($date_row['date']);
            
            // Preserve the original start time on the clone date
            $clone_start->setTime(
                (int)$start_dt->format('H'),
                (int)$start_dt->format('i'),
                (int)$start_dt->format('s')
            );
            
            // Calculate end date by adding duration
            $clone_end = clone $clone_start;
            $clone_end->add($duration);

            // Build taxonomy input so wp_insert_post sets them before save_post hooks fire.
            // This prevents validate_course_type_required from force-drafting clones
            // that have course_type terms on the source course.
            $tax_input = [];
            $course_taxonomies = get_object_taxonomies('educator_course');
            foreach ($course_taxonomies as $taxonomy) {
                $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $tax_input[$taxonomy] = $terms;
                }
            }

            // Create the clone with date appended to title.
            // Clones always start as draft so the educator can review them first.
            // No explicit post_name — WP auto-generates it from the unique title.
            $date_suffix = $clone_start->format('d-m-Y');
            $clone_id = wp_insert_post([
                'post_title'   => $source_post->post_title . ' - ' . $date_suffix,
                'post_content' => $source_post->post_content,
                'post_excerpt' => $source_post->post_excerpt,
                'post_status'  => 'draft',
                'post_type'    => 'educator_course',
                'post_author'  => $source_post->post_author,
                'tax_input'    => $tax_input,
            ]);

            if (!$clone_id || is_wp_error($clone_id)) {
                continue;
            }

            // Copy all ACF fields except cloning-related fields
            $fields_to_copy = [
                'course_start_date' => $clone_start->format('Y-m-d H:i:s'),
                'course_end_date' => $clone_end->format('Y-m-d H:i:s'),
                'course_full_cost' => get_field('course_full_cost', $post_id),
                'course_deposit_cost' => get_field('course_deposit_cost', $post_id),
                'booking_notes' => get_field('booking_notes', $post_id),
                'course_capacity' => get_field('course_capacity', $post_id),
                'course_location_address' => get_field('course_location_address', $post_id),
                'course_location_suburb' => get_field('course_location_suburb', $post_id),
                'course_location_state' => get_field('course_location_state', $post_id),
                'course_location_postcode' => get_field('course_location_postcode', $post_id),
                'course_is_external' => get_field('course_is_external', $post_id),
                'course_external_url' => get_field('course_external_url', $post_id),
                'course_status' => get_field('course_status', $post_id) ?: 'draft',
                'course_educator_id' => get_field('course_educator_id', $post_id),
            ];

            // Update all fields on the clone
            foreach ($fields_to_copy as $field_name => $value) {
                if ($value !== null) {
                    update_field($field_name, $value, $clone_id);
                }
            }

            // Mark that this is NOT a recurring instance (it's independent)
            update_field('course_is_recurring', false, $clone_id);

            // Copy featured image if exists
            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                set_post_thumbnail($clone_id, $thumbnail_id);
            }

            // Initialize availability cache for the new clone.
            Course::ensure_course_availability_row($clone_id);

            // Track clone ID by date suffix for re-clone detection.
            $clone_ids_map[$date_suffix] = $clone_id;

            $cloned_count++;
        }

        if ($cloned_count > 0) {
            // Store map of date_suffix => clone_id so we can detect deletions later.
            update_post_meta($post_id, '_clone_ids_map', $clone_ids_map);
            
            // Mark source course as having created clones
            update_post_meta($post_id, '_clones_created', true);
            
            // Disable the clone toggle so it doesn't run again
            update_field('course_is_recurring', false, $post_id);

            $this->set_notice(
                $post_id,
                'success',
                sprintf(
                    __('Successfully created %d course clone(s). Each clone is an independent course.', 'hmw-events'),
                    $cloned_count
                )
            );
        } else {
            $this->set_notice($post_id, 'error', __('Failed to create course clones.', 'hmw-events'));
        }
    }

    /**
     * Re-create clones for dates whose previously-created clones were manually deleted.
     *
     * Called when the toggle is re-enabled on a post that already has _clones_created set.
     * Only clones courses for dates in the custom dates repeater whose original clone
     * no longer exists in the database. Skips dates that still have a live clone.
     *
     * @since 1.0.0
     * @param int $post_id Source course post ID.
     */
    private function handle_reclone_deleted($post_id)
    {
        $start_date = get_field('course_start_date', $post_id);
        $end_date   = get_field('course_end_date', $post_id);

        if (!$start_date || !$end_date) {
            $this->set_notice($post_id, 'error', __('Cannot re-clone: Start date and end date are required.', 'hmw-events'));
            return;
        }

        $start_dt = new \DateTime($start_date);
        $end_dt   = new \DateTime($end_date);
        $duration = $start_dt->diff($end_dt);

        $custom_dates = get_field('course_custom_dates', $post_id);
        if (empty($custom_dates) || !is_array($custom_dates)) {
            $this->set_notice($post_id, 'info', __('No custom dates specified. No clones to create.', 'hmw-events'));
            return;
        }

        $source_post      = get_post($post_id);
        $previous_map     = get_post_meta($post_id, '_clone_ids_map', true) ?: [];
        $cloned_count     = 0;
        $new_map          = $previous_map;
        $skipped_count    = 0;

        foreach ($custom_dates as $date_row) {
            if (empty($date_row['date'])) {
                continue;
            }

            $date_suffix = (new \DateTime($date_row['date']))->format('d-m-Y');

            // Check if a clone for this date already exists and is still alive.
            if (isset($previous_map[$date_suffix])) {
                $existing_id = (int) $previous_map[$date_suffix];
                if (get_post_type($existing_id) === 'educator_course' && get_post_status($existing_id) !== false) {
                    // Clone still exists — skip it.
                    $skipped_count++;
                    // Keep the existing ID in the map.
                    $new_map[$date_suffix] = $existing_id;
                    continue;
                }
            }

            // This date needs a new clone.
            $clone_start = new \DateTime($date_row['date']);
            $clone_start->setTime(
                (int) $start_dt->format('H'),
                (int) $start_dt->format('i'),
                (int) $start_dt->format('s')
            );
            $clone_end = clone $clone_start;
            $clone_end->add($duration);

            // Build taxonomy input.
            $tax_input = [];
            foreach (get_object_taxonomies('educator_course') as $taxonomy) {
                $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $tax_input[$taxonomy] = $terms;
                }
            }

            $clone_id = wp_insert_post([
                'post_title'   => $source_post->post_title . ' - ' . $date_suffix,
                'post_content' => $source_post->post_content,
                'post_excerpt' => $source_post->post_excerpt,
                'post_status'  => 'draft',
                'post_type'    => 'educator_course',
                'post_author'  => $source_post->post_author,
                'tax_input'    => $tax_input,
            ]);

            if (!$clone_id || is_wp_error($clone_id)) {
                continue;
            }

            // Copy ACF fields.
            $fields_to_copy = [
                'course_start_date'      => $clone_start->format('Y-m-d H:i:s'),
                'course_end_date'        => $clone_end->format('Y-m-d H:i:s'),
                'course_full_cost'       => get_field('course_full_cost', $post_id),
                'course_deposit_cost'    => get_field('course_deposit_cost', $post_id),
                'booking_notes'          => get_field('booking_notes', $post_id),
                'course_capacity'        => get_field('course_capacity', $post_id),
                'course_location_address' => get_field('course_location_address', $post_id),
                'course_location_suburb'  => get_field('course_location_suburb', $post_id),
                'course_location_state'   => get_field('course_location_state', $post_id),
                'course_location_postcode' => get_field('course_location_postcode', $post_id),
                'course_is_external'     => get_field('course_is_external', $post_id),
                'course_external_url'    => get_field('course_external_url', $post_id),
                'course_status'          => get_field('course_status', $post_id) ?: 'draft',
                'course_educator_id'     => get_field('course_educator_id', $post_id),
            ];

            foreach ($fields_to_copy as $field_name => $value) {
                if ($value !== null) {
                    update_field($field_name, $value, $clone_id);
                }
            }

            update_field('course_is_recurring', false, $clone_id);

            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                set_post_thumbnail($clone_id, $thumbnail_id);
            }

            Course::ensure_course_availability_row($clone_id);

            $new_map[$date_suffix] = $clone_id;
            $cloned_count++;
        }

        // Persist the updated clone map.
        update_post_meta($post_id, '_clone_ids_map', $new_map);

        if ($cloned_count > 0) {
            // Disable the toggle so it doesn't run again on every save.
            update_field('course_is_recurring', false, $post_id);

            $parts = [];
            if ($cloned_count > 0) {
                $parts[] = sprintf(
                    _n('Created %d new clone', 'Created %d new clones', $cloned_count, 'hmw-events'),
                    $cloned_count
                );
            }
            if ($skipped_count > 0) {
                $parts[] = sprintf(
                    _n('Skipped %d existing clone', 'Skipped %d existing clones', $skipped_count, 'hmw-events'),
                    $skipped_count
                );
            }

            $this->set_notice($post_id, 'success', implode('. ', $parts) . '.');
        } else {
            $this->set_notice(
                $post_id,
                'info',
                $skipped_count > 0
                    ? __('All clones already exist. No new clones needed.', 'hmw-events')
                    : __('No clones were created.', 'hmw-events')
            );
        }
    }

    // ============================================================================
    // LEGACY RECURRING COURSE METHODS (Preserved for future use)
    // ============================================================================
    // The methods below are kept intact for potential future recurring functionality

    /**
     * Create a new recurring series.
     * LEGACY METHOD - Preserved for future use.
     *
     * @since 1.0.0
     * @param int $post_id Template post ID.
     */
    private function handle_recurring_creation($post_id)
    {
        // Get start date
        $start_date = get_field('course_start_date', $post_id);
        if (!$start_date) {
            // No start date set, can't create recurrence
            $this->set_notice($post_id, 'error', __('Cannot create recurring series: Start date is required.', 'hmw-events'));
            return;
        }

        // Get recurrence days (for weekly)
        $days_array = get_field('course_recurrence_days', $post_id);
        $days_string = is_array($days_array) ? implode(',', $days_array) : '';

        // Check for multi-day courses with multiple selected days
        $end_date = get_field('course_end_date', $post_id);
        $recurrence_type = get_field('course_recurrence_type', $post_id) ?: 'weekly';
        
        if ($start_date && $end_date && in_array($recurrence_type, ['weekly', 'fortnightly'])) {
            $start_dt = new \DateTime($start_date);
            $end_dt = new \DateTime($end_date);
            $duration_days = (int)$start_dt->diff($end_dt)->format('%a');
            
            if ($duration_days > 0 && is_array($days_array) && count($days_array) > 1) {
                $this->set_notice(
                    $post_id,
                    'warning',
                    sprintf(
                        __('Note: This is a %d-day course and you have selected %d recurrence days. Each selected day will create a separate %d-day course instance. If you want the course to repeat weekly on the same start day, select only one day.', 'hmw-events'),
                        $duration_days,
                        count($days_array),
                        $duration_days
                    )
                );
            }
        }

        // Build recurrence configuration
        $recurrence_config = [
            'type' => $recurrence_type,
            'days' => $days_string,
            'start_date' => date('Y-m-d', strtotime($start_date)),
            'end_date' => get_field('course_recurrence_end_date', $post_id) ?: null,
            'max_occurrences' => get_field('course_max_occurrences', $post_id) ?: 52,
        ];

        // Create recurring series
        $recurrence_id = RecurringCourse::create_recurring_series($post_id, $recurrence_config);

        if ($recurrence_id) {
            // Get instance count
            $instances = RecurringCourse::get_series_instances($recurrence_id, false);
            $count = count($instances);
            
            $this->set_notice(
                $post_id,
                'success',
                sprintf(
                    __('Successfully created recurring series with %d course instances.', 'hmw-events'),
                    $count
                )
            );
        } else {
            $this->set_notice($post_id, 'error', __('Failed to create recurring series.', 'hmw-events'));
        }
    }

    /**
     * Handle update to existing recurring series.
     * LEGACY METHOD - Preserved for future use.
     *
     * @since 1.0.0
     * @param int $post_id Template post ID.
     * @param int $recurrence_id Recurrence pattern ID.
     */
    private function handle_recurring_update($post_id, $recurrence_id)
    {
        global $wpdb;

        // Check if user wants to update future instances
        $update_instances = get_field('course_update_future_instances', $post_id);
        
        if (!$update_instances) {
            // User didn't check the box - only update this template, not instances
            return;
        }

        // Get current recurrence pattern from database
        $table_name = $wpdb->prefix . 'educator_course_recurrence';
        $current_pattern = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d",
            $recurrence_id
        ));

        if (!$current_pattern) {
            return;
        }

        // Get new configuration from ACF fields
        $days_array = get_field('course_recurrence_days', $post_id);
        $new_days = is_array($days_array) ? implode(',', $days_array) : '';
        
        $new_config = [
            'type' => get_field('course_recurrence_type', $post_id) ?: 'weekly',
            'days' => $new_days,
            'end_date' => get_field('course_recurrence_end_date', $post_id) ?: null,
            'max_occurrences' => get_field('course_max_occurrences', $post_id) ?: 52,
        ];

        // For custom dates, always regenerate since we need to handle added/removed dates
        if ($new_config['type'] === 'custom' || $current_pattern->recurrence_type === 'custom') {
            $this->handle_pattern_change($post_id, $recurrence_id, $new_config);
            $this->reset_checkbox_after_processing($post_id);
            return;
        }

        // Check if recurrence pattern changed (days, type, end date, or max occurrences)
        $pattern_changed = (
            $current_pattern->recurrence_type !== $new_config['type'] ||
            $current_pattern->recurrence_days !== $new_config['days'] ||
            $current_pattern->end_date !== $new_config['end_date'] ||
            $current_pattern->max_occurrences != $new_config['max_occurrences']
        );

        if ($pattern_changed) {
            // Pattern changed - need to regenerate instances
            $this->handle_pattern_change($post_id, $recurrence_id, $new_config);
        } else {
            // Pattern unchanged - just update instance details
            $this->handle_details_update($post_id, $recurrence_id);
        }

        

        // Reset the checkbox after processing
        $this->reset_checkbox_after_processing($post_id);
    }

    /**
     * Reset the "update future instances" checkbox after processing.
     * LEGACY METHOD - Preserved for future use.
     *
     * @since 1.0.0
     * @param int $post_id Template post ID.
     */
    private function reset_checkbox_after_processing($post_id)
    {
        // Update the ACF field to false
        update_field('course_update_future_instances', true, $post_id);
    }

    /**
     * Handle recurrence pattern change (regenerate instances).
     * LEGACY METHOD - Preserved for future use.
     *
     * @since 1.0.0
     * @param int   $post_id Template post ID.
     * @param int   $recurrence_id Recurrence pattern ID.
     * @param array $new_config New recurrence configuration.
     */
    private function handle_pattern_change($post_id, $recurrence_id, $new_config)
    {
        // Regenerate the series
        $created_count = RecurringCourse::regenerate_series($recurrence_id, $new_config);

        if ($created_count > 0) {
            $this->set_notice(
                $post_id,
                'success',
                sprintf(
                    __('Recurrence pattern updated! Regenerated %d future course instances with the new schedule.', 'hmw-events'),
                    $created_count
                )
            );
        } else {
            $this->set_notice(
                $post_id,
                'warning',
                __('Recurrence pattern updated, but no new instances were created. Existing courses with bookings were preserved.', 'hmw-events')
            );
        }
    }

    /**
     * Handle update to course details (no pattern change).
     * LEGACY METHOD - Preserved for future use.
     *
     * @since 1.0.0
     * @param int $post_id Template post ID.
     * @param int $recurrence_id Recurrence pattern ID.
     */
    private function handle_details_update($post_id, $recurrence_id)
    {
        // Get all field values to sync
        $updates = [];
        
        $fields_to_sync = [
            'course_full_cost',
            'course_deposit_cost',
            'course_capacity',
            'course_location_address',
            'course_location_suburb',
            'course_location_state',
            'course_location_postcode',
            'course_is_external',
            'course_external_url',
        ];

        foreach ($fields_to_sync as $field) {
            $value = get_field($field, $post_id);
            if ($value !== null) {
                $updates[$field] = $value;
            }
        }

        // Update all future instances
        $updated_count = RecurringCourse::update_series($recurrence_id, $updates, true);

        if ($updated_count > 0) {
            $this->set_notice(
                $post_id,
                'success',
                sprintf(
                    __('Updated %d future course instances with your changes.', 'hmw-events'),
                    $updated_count
                )
            );
        } else {
            $this->set_notice(
                $post_id,
                'info',
                __('No future instances to update. Template saved successfully.', 'hmw-events')
            );
        }
    }

    /**
     * Handle recurrence deactivation.
     * LEGACY METHOD - Preserved for future use.
     *
     * @since 1.0.0
     * @param int $post_id Template post ID.
     * @param int $recurrence_id Recurrence pattern ID.
     */
    private function handle_recurrence_deactivation($post_id, $recurrence_id)
    {
        // Mark future instances as non-recurring
        $deactivated_count = RecurringCourse::deactivate_series($recurrence_id);

        // delete course_recurrence_id meta from the template
        delete_post_meta($post_id, 'course_recurrence_id');

        // delete recurrence pattern from the database
        RecurringCourse::delete_recurrence_pattern($recurrence_id);

    if ($deactivated_count > 0) {
            $this->set_notice(
                $post_id,
                'success',
                sprintf(
                    __('Recurrence deactivated. %d future course instances were marked as non-recurring.', 'hmw-events'),
                    $deactivated_count
                )
            );
        } else {
            $this->set_notice(
                $post_id,
                'info',
                __('Recurrence deactivated. No future instances were affected.', 'hmw-events')
            );
        }
    }

    /**
     * Set a notice to display after redirect.
     *
     * @since 1.0.0
     * @param int    $post_id Post ID.
     * @param string $type Notice type (success, error, warning, info).
     * @param string $message Notice message.
     */
    private function set_notice($post_id, $type, $message)
    {
        set_transient(
            'hmwevents_recurring_course_notice_' . $post_id,
            [
                'type' => $type,
                'message' => $message,
            ],
            60
        );
    }

    /**
     * Show admin notices for course cloning operations.
     *
     * @since 1.0.0
     */
    public function show_recurring_course_notices()
    {
        // Only show on course edit screens
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'educator_course') {
            return;
        }

        // Check for notice transient
        $post_id = isset($_GET['post']) ? intval($_GET['post']) : 0;
        if (!$post_id) {
            return;
        }

        $notice = get_transient('hmwevents_recurring_course_notice_' . $post_id);
        if ($notice && is_array($notice)) {
            $type = isset($notice['type']) ? $notice['type'] : 'info';
            $message = isset($notice['message']) ? $notice['message'] : '';
            
            printf(
                '<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
                esc_attr($type),
                esc_html($message)
            );
            
            delete_transient('hmwevents_recurring_course_notice_' . $post_id);
        }
    }

    /**
     * Enqueue admin scripts for course cloning.
     * LEGACY METHOD - No longer needed but preserved for future use.
     *
     * @since 1.0.0
     */
    public function enqueue_admin_scripts($hook)
    {
        // No scripts needed for clone functionality
        // This method is preserved for future use if recurring functionality is restored
        return;
    }
}
