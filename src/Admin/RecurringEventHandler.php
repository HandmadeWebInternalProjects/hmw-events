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

use HMWEvents\Helpers\EventHelper;
use HMWEvents\Helpers\RecurringEvent;
use HMWEvents\Services\SessionService;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Recurring Course Handler.
 */
class RecurringEventHandler
{
    /**
     * Lazy-loaded SessionService instance.
     */
    private ?SessionService $session_service = null;

    /**
     * Get the SessionService instance (lazy-loaded).
     */
    private function session_service(): SessionService
    {
        if ($this->session_service === null) {
            $this->session_service = new SessionService();
        }
        return $this->session_service;
    }

    /**
     * Get a field value from ACF (preferred) or post meta (fallback for hmw_event).
     */
    private function get_field(string $key, int $post_id, string $post_type = 'educator_course'): mixed
    {
        if ($post_type === 'hmw_event') {
            $meta_key = match ($key) {
                'course_start_date'       => '_event_start_date',
                'course_end_date'         => '_event_end_date',
                '_event_price'        => '_event_price',
                '_event_deposit'     => '_event_deposit',
                '_event_capacity'         => '_event_capacity',
                'course_is_recurring'     => '_event_is_recurring',
                'course_educator_id'      => '_organizer_id',
                'course_location_address' => '_event_venue_name',
                default                   => '_' . $key,
            };
            return get_post_meta($post_id, $meta_key, true);
        }

        if (function_exists('get_field')) {
            return get_field($key, $post_id);
        }

        return get_post_meta($post_id, '_' . $key, true);
    }

    /**
     * Update a field via ACF or post meta.
     */
    private function update_field(string $key, mixed $value, int $post_id, string $post_type = 'educator_course'): void
    {
        if ($post_type === 'hmw_event') {
            $meta_key = match ($key) {
                'course_start_date'       => '_event_start_date',
                'course_end_date'         => '_event_end_date',
                '_event_price'        => '_event_price',
                '_event_deposit'     => '_event_deposit',
                '_event_capacity'         => '_event_capacity',
                'course_is_recurring'     => '_event_is_recurring',
                'course_educator_id'      => '_organizer_id',
                'course_location_address' => '_event_venue_name',
                default                   => '_' . $key,
            };
            update_post_meta($post_id, $meta_key, $value);
            return;
        }

        if (function_exists('update_field')) {
            update_field($key, $value, $post_id);
            return;
        }

        update_post_meta($post_id, '_' . $key, $value);
    }
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
        add_action('add_meta_boxes_hmw_event', [$this, 'add_session_schedule_meta_box']);
        add_action('wp_ajax_hmwevents_bulk_session_action', [$this, 'handle_bulk_session_action']);
    }

    /**
     * Handle course cloning when post is saved.
     *
     * Dispatches to either pattern-based recurrence (daily/weekly/monthly)
     * via SessionService or custom-dates cloning based on the configured
     * recurrence unit.
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

        // Only process event/course posts
        $post_type = get_post_type($post_id);
        if (!in_array($post_type, ['educator_course', 'hmw_event'], true)) {
            return;
        }

        // Check if cloning is enabled
        $is_cloning = $this->get_field('course_is_recurring', $post_id, $post_type);

        if (!$is_cloning) {
            // Cloning disabled, nothing to do
            return;
        }

        // Check if clones already exist (to prevent duplicate cloning on every save)
        $already_cloned = get_post_meta($post_id, '_clones_created', true);

        if ($already_cloned) {
            // Clones already exist — regenerate based on the recurrence type.
            $this->handle_reclone_deleted($post_id);
            return;
        }

        // New clone request - dispatch based on recurrence type.
        $recurrence_unit = $this->get_recurrence_unit($post_id, $post_type);

        if ($recurrence_unit === 'custom') {
            // Custom dates: use the existing clone logic (standalone posts).
            $this->handle_course_cloning($post_id);
        } else {
            // Pattern-based (daily/weekly/monthly): use SessionService parent/child model.
            $this->handle_pattern_recurrence($post_id);
        }
    }

    /**
     * Get the recurrence unit for a post.
     *
     * Falls back to 'custom' for legacy posts that only have course_custom_dates.
     *
     * @param int    $post_id   Post ID.
     * @param string $post_type Post type.
     * @return string 'daily'|'weekly'|'monthly'|'custom'
     */
    private function get_recurrence_unit(int $post_id, string $post_type): string
    {
        // Try ACF first (handles formatting/unserialization), then fall back to raw meta.
        if (function_exists('get_field')) {
            $unit = get_field('_event_recurrence_unit', $post_id);
            if ($unit) {
                return $unit;
            }
        }

        $unit = get_post_meta($post_id, '_event_recurrence_unit', true);
        if ($unit) {
            return $unit;
        }

        // Default to custom (legacy behavior).
        return 'custom';
    }

    /**
     * Handle pattern-based recurrence (daily/weekly/monthly).
     *
     * Uses SessionService to create child session posts with post_parent
     * set to the master event. The recurrence config is stored in the
     * hmwevents_event_recurrence table.
     *
     * @since 2.1.0
     * @param int $post_id Master event post ID.
     */
    private function handle_pattern_recurrence(int $post_id): void
    {
        $post_type = get_post_type($post_id);

        // Pattern-based recurrence is only supported for hmw_event.
        if ($post_type !== 'hmw_event') {
            $this->set_notice(
                $post_id,
                'error',
                __('Pattern-based recurrence requires the hmw_event post type.', 'hmw-events')
            );
            return;
        }

        // Get the master event's start date.
        $start_date = get_post_meta($post_id, '_event_start_date', true);
        if (!$start_date) {
            $this->set_notice(
                $post_id,
                'error',
                __('Cannot create recurring series: Start date is required.', 'hmw-events')
            );
            return;
        }

        // Build the recurrence config from ACF/post meta fields.
        $unit = get_field('_event_recurrence_unit', $post_id) ?: get_post_meta($post_id, '_event_recurrence_unit', true) ?: 'weekly';
        $interval = (int) (get_field('_event_recurrence_interval', $post_id) ?: get_post_meta($post_id, '_event_recurrence_interval', true) ?: 1);
        $days_raw = get_field('_event_recurrence_days', $post_id);
        if ($days_raw === null || $days_raw === false) {
            $days_raw = get_post_meta($post_id, '_event_recurrence_days', true);
        }
        $end_type = get_field('_event_recurrence_end_type', $post_id) ?: get_post_meta($post_id, '_event_recurrence_end_type', true) ?: 'never';
        $end_date = get_field('_event_recurrence_end_date', $post_id) ?: get_post_meta($post_id, '_event_recurrence_end_date', true) ?: null;
        $max_occurrences = (int) (get_field('_event_recurrence_max_occurrences', $post_id) ?: get_post_meta($post_id, '_event_recurrence_max_occurrences', true) ?: 0) ?: null;

        // Normalize days to a comma-separated string.
        if (is_array($days_raw)) {
            $days = implode(',', array_map('intval', $days_raw));
        } else {
            $days = $days_raw ?: '';
        }

        // Convert end_date from ACF format to Y-m-d if needed.
        if ($end_date) {
            $end_date = date('Y-m-d', strtotime($end_date));
        }

        // If end_type is not 'date', clear end_date.
        if ($end_type !== 'date') {
            $end_date = null;
        }
        // If end_type is not 'count', clear max_occurrences.
        if ($end_type !== 'count') {
            $max_occurrences = null;
        }

        $config = [
            'recurrence_type'     => $unit,
            'start_date'          => date('Y-m-d', strtotime($start_date)),
            'end_date'            => $end_date,
            'recurrence_interval' => max(1, $interval),
            'recurrence_days'     => $days,
            'max_occurrences'     => $max_occurrences,
        ];

        // Generate child sessions.
        $created_ids = $this->session_service()->generate_sessions($post_id, $config);
        $created_count = count($created_ids);

        if ($created_count > 0) {
            // Mark source event as having created children.
            update_post_meta($post_id, '_clones_created', true);
            update_post_meta($post_id, '_clone_ids_map', $created_ids);

            // Ensure availability rows for each child.
            foreach ($created_ids as $child_id) {
                EventHelper::ensure_course_availability_row($child_id);
            }

            $this->set_notice(
                $post_id,
                'success',
                sprintf(
                    /* translators: %d: number of sessions created */
                    _n(
                        'Successfully created %d recurring session.',
                        'Successfully created %d recurring sessions.',
                        $created_count,
                        'hmw-events'
                    ),
                    $created_count
                )
            );
        } else {
            $this->set_notice(
                $post_id,
                'error',
                __('No recurring sessions were created. Please check your recurrence settings.', 'hmw-events')
            );
        }
    }

    /**
     * Create standalone clones of the course for specified dates.
     *
     * @since 1.0.0
     * @param int $post_id Source course post ID.
     */
    private function handle_course_cloning($post_id)
    {
        $post_type = get_post_type($post_id);

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

        // Get custom dates (check new field name first, then legacy field)
        $custom_dates = get_field('_event_recurrence_custom_dates', $post_id);
        if (empty($custom_dates) || !is_array($custom_dates)) {
            $custom_dates = get_field('course_custom_dates', $post_id);
        }

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
            $course_taxonomies = get_object_taxonomies($post_type);
            foreach ($course_taxonomies as $taxonomy) {
                $terms = wp_get_post_terms($post_id, $taxonomy, ['fields' => 'ids']);
                if (!is_wp_error($terms) && !empty($terms)) {
                    $tax_input[$taxonomy] = $terms;
                }
            }

            $is_event = $post_type === 'hmw_event';

            // Create the clone with date appended to title.
            // Clones always start as draft so the educator can review them first.
            // No explicit post_name — WP auto-generates it from the unique title.
            $date_suffix = $clone_start->format('d-m-Y');
            $clone_id = wp_insert_post([
                'post_title'   => $source_post->post_title . ' - ' . $date_suffix,
                'post_content' => $source_post->post_content,
                'post_excerpt' => $source_post->post_excerpt,
                'post_status'  => 'draft',
                'post_type'    => $post_type,
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
                '_event_price' => get_field('_event_price', $post_id),
                '_event_deposit' => get_field('_event_deposit', $post_id),
                'booking_notes' => get_field('booking_notes', $post_id),
                '_event_capacity' => get_field('_event_capacity', $post_id),
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
            $this->update_field('course_is_recurring', false, $clone_id, $post_type);

            // Copy featured image if exists
            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                set_post_thumbnail($clone_id, $thumbnail_id);
            }

            // Initialize availability cache for the new clone.
            EventHelper::ensure_course_availability_row($clone_id);

            update_post_meta($clone_id, '_cloned_from', $post_id);

            $clone_ids_map[$date_suffix] = $clone_id;

            $cloned_count++;
        }

        if ($cloned_count > 0) {
            // Store map of date_suffix => clone_id so we can detect deletions later.
            update_post_meta($post_id, '_clone_ids_map', $clone_ids_map);
            
            // Mark source course as having created clones
            update_post_meta($post_id, '_clones_created', true);

            $this->set_notice(
                $post_id,
                'success',
                sprintf(
                    /* translators: %d: number of clones */
                    __('Successfully created %d course clone(s). Each clone is an independent course.', 'hmw-events'),
                    $cloned_count
                )
            );
        } else {
            $this->set_notice($post_id, 'error', __('Failed to create course clones.', 'hmw-events'));
        }
    }

    /**
     * Re-create clones for deleted children, or regenerate sessions when
     * the recurrence pattern has changed.
     *
     * Called when the toggle is re-enabled on a post that already has
     * _clones_created set. For pattern-based recurrence (daily/weekly/monthly),
     * delegates to SessionService::regenerate_sessions(). For custom dates,
     * only creates clones for dates whose original clone no longer exists.
     *
     * @since 1.0.0
     * @param int $post_id Source course post ID.
     */
    private function handle_reclone_deleted($post_id)
    {
        $post_type = get_post_type($post_id);
        $recurrence_unit = $this->get_recurrence_unit($post_id, $post_type);

        if ($recurrence_unit !== 'custom' && $post_type === 'hmw_event') {
            $this->handle_pattern_reclone($post_id);
            return;
        }

        $this->handle_custom_dates_reclone($post_id);
    }

    /**
     * Regenerate pattern-based child sessions via SessionService.
     *
     * @since 2.1.0
     * @param int $post_id Master event post ID.
     */
    private function handle_pattern_reclone(int $post_id): void
    {
        // Build the config from current ACF/post meta fields.
        $start_date = get_post_meta($post_id, '_event_start_date', true);
        if (!$start_date) {
            $this->set_notice(
                $post_id,
                'error',
                __('Cannot regenerate sessions: Start date is required.', 'hmw-events')
            );
            return;
        }

        $unit = get_field('_event_recurrence_unit', $post_id) ?: get_post_meta($post_id, '_event_recurrence_unit', true) ?: 'weekly';
        $interval = (int) (get_field('_event_recurrence_interval', $post_id) ?: get_post_meta($post_id, '_event_recurrence_interval', true) ?: 1);
        $days_raw = get_field('_event_recurrence_days', $post_id);
        if ($days_raw === null || $days_raw === false) {
            $days_raw = get_post_meta($post_id, '_event_recurrence_days', true);
        }
        $end_type = get_field('_event_recurrence_end_type', $post_id) ?: get_post_meta($post_id, '_event_recurrence_end_type', true) ?: 'never';
        $end_date = get_field('_event_recurrence_end_date', $post_id) ?: get_post_meta($post_id, '_event_recurrence_end_date', true) ?: null;
        $max_occurrences = (int) (get_field('_event_recurrence_max_occurrences', $post_id) ?: get_post_meta($post_id, '_event_recurrence_max_occurrences', true) ?: 0) ?: null;

        if (is_array($days_raw)) {
            $days = implode(',', array_map('intval', $days_raw));
        } else {
            $days = $days_raw ?: '';
        }

        if ($end_date) {
            $end_date = date('Y-m-d', strtotime($end_date));
        }
        if ($end_type !== 'date') {
            $end_date = null;
        }
        if ($end_type !== 'count') {
            $max_occurrences = null;
        }

        $config = [
            'recurrence_type'     => $unit,
            'start_date'          => date('Y-m-d', strtotime($start_date)),
            'end_date'            => $end_date,
            'recurrence_interval' => max(1, $interval),
            'recurrence_days'     => $days,
            'max_occurrences'     => $max_occurrences,
        ];

        $stored = $this->session_service()->get_recurrence($post_id);
        $config_unchanged = $stored && $this->recurrence_configs_equal($config, $stored);

        if ($config_unchanged) {
            $this->set_notice(
                $post_id,
                'info',
                __('Recurrence settings unchanged. No sessions were created or removed.', 'hmw-events')
            );
            return;
        }

        $result = $this->session_service()->regenerate_sessions($post_id, $config);

        // Ensure availability rows for newly created children.
        foreach ($result['created'] as $child_id) {
            EventHelper::ensure_course_availability_row($child_id);
        }

        $created_count = count($result['created']);
        $removed_count = count($result['removed']);
        $kept_count = count($result['kept']);

        if ($created_count > 0 || $removed_count > 0) {
            $parts = [];
            if ($created_count > 0) {
                $parts[] = sprintf(
                    _n('Created %d new session', 'Created %d new sessions', $created_count, 'hmw-events'),
                    $created_count
                );
            }
            if ($removed_count > 0) {
                $parts[] = sprintf(
                    _n('Removed %d obsolete session', 'Removed %d obsolete sessions', $removed_count, 'hmw-events'),
                    $removed_count
                );
            }
            if ($kept_count > 0) {
                $parts[] = sprintf(
                    _n('Kept %d existing session', 'Kept %d existing sessions', $kept_count, 'hmw-events'),
                    $kept_count
                );
            }
            $this->set_notice($post_id, 'success', implode('. ', $parts) . '.');
        } else {
            $this->set_notice(
                $post_id,
                'info',
                $kept_count > 0
                    ? __('All sessions already exist. No changes needed.', 'hmw-events')
                    : __('No sessions were created.', 'hmw-events')
            );
        }
    }

    /**
     * Re-create clones for custom dates whose previously-created clones
     * were manually deleted.
     *
     * @since 1.0.0
     * @param int $post_id Source course post ID.
     */
    private function handle_custom_dates_reclone($post_id)
    {
        $post_type = get_post_type($post_id);

        $start_date = get_field('course_start_date', $post_id);
        $end_date   = get_field('course_end_date', $post_id);

        if (!$start_date || !$end_date) {
            $this->set_notice($post_id, 'error', __('Cannot re-clone: Start date and end date are required.', 'hmw-events'));
            return;
        }

        $start_dt = new \DateTime($start_date);
        $end_dt   = new \DateTime($end_date);
        $duration = $start_dt->diff($end_dt);

        $custom_dates = get_field('_event_recurrence_custom_dates', $post_id);
        if (empty($custom_dates) || !is_array($custom_dates)) {
            $custom_dates = get_field('course_custom_dates', $post_id);
        }
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
                if (get_post_type($existing_id) === $post_type && get_post_status($existing_id) !== false) {
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
            foreach (get_object_taxonomies($post_type) as $taxonomy) {
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
                'post_type'    => $post_type,
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
                '_event_price'       => get_field('_event_price', $post_id),
                '_event_deposit'    => get_field('_event_deposit', $post_id),
                'booking_notes'          => get_field('booking_notes', $post_id),
                '_event_capacity'        => get_field('_event_capacity', $post_id),
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

            $this->update_field('course_is_recurring', false, $clone_id, $post_type);

            $thumbnail_id = get_post_thumbnail_id($post_id);
            if ($thumbnail_id) {
                set_post_thumbnail($clone_id, $thumbnail_id);
            }

            EventHelper::ensure_course_availability_row($clone_id);

            update_post_meta($clone_id, '_cloned_from', $post_id);

            $new_map[$date_suffix] = $clone_id;
            $cloned_count++;
        }

        // Persist the updated clone map.
        update_post_meta($post_id, '_clone_ids_map', $new_map);

        if ($cloned_count > 0) {
            $parts = [];
            $parts[] = sprintf(
                _n('Created %d new clone', 'Created %d new clones', $cloned_count, 'hmw-events'),
                $cloned_count
            );
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

    /**
     * Add meta box showing session schedule on parent events
     * and a "Recurring Series" link on child/clone events.
     *
     * @since 2.1.0
     */
    public function add_session_schedule_meta_box(): void
    {
        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$post_id) {
            return;
        }

        $is_child  = (bool) get_post_meta($post_id, '_is_child_session', true);
        $is_clone  = (bool) get_post_meta($post_id, '_cloned_from', true);
        $has_children = (bool) get_post_meta($post_id, '_clones_created', true);

        if ($is_child) {
            add_meta_box(
                'hmwevents_recurring_series',
                __('Recurring Series', 'hmw-events'),
                [$this, 'render_child_session_meta_box'],
                'hmw_event',
                'side',
                'default',
                ['post_id' => $post_id]
            );
        } elseif ($is_clone) {
            add_meta_box(
                'hmwevents_cloned_event',
                __('Cloned Event', 'hmw-events'),
                [$this, 'render_clone_meta_box'],
                'hmw_event',
                'side',
                'default',
                ['post_id' => $post_id]
            );
        } elseif ($has_children) {
            add_meta_box(
                'hmwevents_session_schedule',
                __('Session Schedule', 'hmw-events'),
                [$this, 'render_session_schedule_meta_box'],
                'hmw_event',
                'normal',
                'high',
                ['post_id' => $post_id]
            );
        }
    }

    /**
     * Render meta box for a child session — links back to the parent.
     *
     * @since 2.1.0
     * @param \WP_Post $post
     * @param array    $args
     */
    public function render_child_session_meta_box(\WP_Post $post, array $args): void
    {
        $parent_id = $post->post_parent;
        $parent = $parent_id ? get_post($parent_id) : null;
        if (!$parent) {
            echo '<p>' . esc_html__('Parent event not found.', 'hmw-events') . '</p>';
            return;
        }
        echo '<p>' . esc_html__('This event is part of a recurring series.', 'hmw-events') . '</p>';
        echo '<p><strong>' . esc_html__('Parent:', 'hmw-events') . '</strong> ';
        echo '<a href="' . esc_url(get_edit_post_link($parent_id)) . '">' . esc_html($parent->post_title) . '</a></p>';
    }

    /**
     * Render meta box for a cloned event — links back to the source.
     *
     * @since 2.1.0
     * @param \WP_Post $post
     * @param array    $args
     */
    public function render_clone_meta_box(\WP_Post $post, array $args): void
    {
        $source_id = (int) get_post_meta($post->ID, '_cloned_from', true);
        $source = $source_id ? get_post($source_id) : null;
        if (!$source) {
            echo '<p>' . esc_html__('Source event not found.', 'hmw-events') . '</p>';
            return;
        }
        echo '<p>' . esc_html__('This event was cloned from another event.', 'hmw-events') . '</p>';
        echo '<p><strong>' . esc_html__('Source:', 'hmw-events') . '</strong> ';
        echo '<a href="' . esc_url(get_edit_post_link($source_id)) . '">' . esc_html($source->post_title) . '</a></p>';
    }

    /**
     * Render the session schedule meta box on a parent event.
     *
     * Shows a table of all child sessions with status counts,
     * batch action buttons, and individual edit/view links.
     *
     * @since 2.1.0
     * @param \WP_Post $post
     * @param array    $args
     */
    public function render_session_schedule_meta_box(\WP_Post $post, array $args): void
    {
        $children = $this->get_child_events($post->ID);

        if (empty($children)) {
            echo '<p>' . esc_html__('No sessions found.', 'hmw-events') . '</p>';
            return;
        }

        // Build status counts.
        $statuses = [
            'publish'      => 0,
            'draft'        => 0,
            'cancelled'    => 0,
            'fully_booked' => 0,
            'pending'      => 0,
            'trash'        => 0,
        ];

        foreach ($children as $child) {
            $st = $child->post_status;
            $statuses[$st] = ($statuses[$st] ?? 0) + 1;
        }

        $parts = [];
        foreach ($statuses as $st => $count) {
            if ($count === 0) {
                continue;
            }
            $status_obj = get_post_status_object($st);
            $label = $status_obj ? $status_obj->label : ucfirst($st);
            $parts[] = sprintf('%d %s', $count, $label);
        }

        $total = count($children);
        echo '<p class="hmwevents-schedule-summary">';
        printf(
            /* translators: 1: total count, 2: status breakdown */
            esc_html__('%1$d sessions: %2$s', 'hmw-events'),
            $total,
            implode(', ', $parts)
        );
        echo '</p>';

        // Batch action buttons.
        $draft_count = $statuses['draft'] ?? 0;
        $non_draft   = $total - ($statuses['trash'] ?? 0);
        $nonce       = wp_create_nonce('hmwevents_bulk_session_action');
        echo '<p class="hmwevents-batch-actions">';
        if ($draft_count > 0) {
            printf(
                '<button type="button" class="button hmwevents-bulk-btn" data-action="publish_drafts" data-parent="%d" data-nonce="%s">%s</button> ',
                $post->ID,
                esc_attr($nonce),
                esc_html__('Publish All Drafts', 'hmw-events')
            );
        }
        if ($non_draft > 0) {
            printf(
                '<button type="button" class="button hmwevents-bulk-btn" data-action="trash_all" data-parent="%d" data-nonce="%s" style="color:#b32d2e">%s</button>',
                $post->ID,
                esc_attr($nonce),
                esc_html__('Trash All Sessions', 'hmw-events')
            );
        }
        echo '</p>';

        // Table.
        echo '<table class="widefat striped hmwevents-schedule-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Date', 'hmw-events') . '</th>';
        echo '<th>' . esc_html__('Title', 'hmw-events') . '</th>';
        echo '<th>' . esc_html__('Status', 'hmw-events') . '</th>';
        echo '<th>' . esc_html__('Actions', 'hmw-events') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($children as $child) {
            $child_start = get_post_meta($child->ID, '_event_start_date', true);
            $child_end   = get_post_meta($child->ID, '_event_end_date', true);
            $date_display = $child_start ? date('D j M Y', strtotime($child_start)) : '—';
            if ($child_end && $child_end !== $child_start) {
                $date_display .= ' – ' . date('D j M Y', strtotime($child_end));
            }

            $edit_url    = get_edit_post_link($child->ID);
            $view_url    = get_permalink($child->ID);
            $status_obj  = get_post_status_object($child->post_status);
            $status_label = $status_obj ? $status_obj->label : ucfirst($child->post_status);

            echo '<tr>';
            echo '<td>' . esc_html($date_display) . '</td>';
            echo '<td><strong>' . esc_html($child->post_title) . '</strong></td>';
            echo '<td>' . esc_html($status_label) . '</td>';
            echo '<td>';
            if ($edit_url) {
                echo '<a href="' . esc_url($edit_url) . '">' . esc_html__('Edit', 'hmw-events') . '</a>';
            }
            if ($view_url && $child->post_status === 'publish') {
                echo ' | <a href="' . esc_url($view_url) . '">' . esc_html__('View', 'hmw-events') . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Compare a newly-built recurrence config against a stored DB row.
     *
     * Returns true if the config has not changed — no regeneration needed.
     *
     * @since 2.3.0
     * @param array  $config  Built from ACF fields.
     * @param object $stored  Row from hmwevents_event_recurrence.
     * @return bool
     */
    private function recurrence_configs_equal(array $config, object $stored): bool
    {
        return ($config['recurrence_type'] ?? '') === $stored->recurrence_type
            && ($config['recurrence_interval'] ?? 1) == ($stored->recurrence_interval ?? 1)
            && ($config['recurrence_days'] ?? '') === ($stored->recurrence_days ?? '')
            && ($config['end_date'] ?? null) === ($stored->end_date ?? null)
            && ($config['max_occurrences'] ?? null) == ($stored->max_occurrences ?? null)
            && ($config['start_date'] ?? '') === $stored->start_date;
    }

    /**
     * Get child events for a parent, from either SessionService or clone map.
     *
     * @since 2.1.0
     * @param int $parent_id
     * @return \WP_Post[]
     */
    private function get_child_events(int $parent_id): array
    {
        $sessions = $this->session_service()->get_sessions($parent_id);
        if (!empty($sessions)) {
            return $sessions;
        }

        $map = get_post_meta($parent_id, '_clone_ids_map', true);
        if (empty($map) || !is_array($map)) {
            return [];
        }

        $children = [];
        foreach ($map as $clone_id) {
            $clone_id = (int) $clone_id;
            $clone = get_post($clone_id);
            if ($clone && $clone->post_type === 'hmw_event') {
                $children[] = $clone;
            }
        }

        usort($children, function ($a, $b) {
            $a_date = get_post_meta($a->ID, '_event_start_date', true);
            $b_date = get_post_meta($b->ID, '_event_start_date', true);
            return strcmp($a_date, $b_date);
        });

        return $children;
    }

    /**
     * AJAX handler for bulk session actions (publish drafts, trash all).
     *
     * @since 2.1.0
     */
    public function handle_bulk_session_action(): void
    {
        check_ajax_referer('hmwevents_bulk_session_action');

        if (!current_user_can('edit_hmw_events')) {
            wp_send_json_error(['message' => __('Permission denied.', 'hmw-events')], 403);
        }

        $parent_id   = isset($_POST['parent_id']) ? (int) $_POST['parent_id'] : 0;
        $action_type = sanitize_text_field($_POST['action_type'] ?? '');

        if (!$parent_id || !in_array($action_type, ['publish_drafts', 'trash_all'], true)) {
            wp_send_json_error(['message' => __('Invalid request.', 'hmw-events')], 400);
        }

        $children = $this->get_child_events($parent_id);
        $affected = 0;

        foreach ($children as $child) {
            if ($action_type === 'publish_drafts' && $child->post_status !== 'draft') {
                continue;
            }
            if ($action_type === 'trash_all' && $child->post_status === 'trash') {
                continue;
            }

            if ($action_type === 'publish_drafts') {
                $result = wp_update_post(['ID' => $child->ID, 'post_status' => 'publish'], true);
            } else {
                $result = wp_trash_post($child->ID);
            }

            if (!is_wp_error($result)) {
                $affected++;
            }
        }

        wp_send_json_success([
            'count'   => $affected,
            'message' => $action_type === 'publish_drafts'
                ? sprintf(
                    /* translators: %d: number of sessions published */
                    _n('%d session published.', '%d sessions published.', $affected, 'hmw-events'),
                    $affected
                )
                : sprintf(
                    /* translators: %d: number of sessions trashed */
                    _n('%d session moved to trash.', '%d sessions moved to trash.', $affected, 'hmw-events'),
                    $affected
                ),
        ]);
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
        $is_new = (get_post_type($post_id) === 'hmw_event');
        $table_name = $is_new
            ? $wpdb->prefix . 'hmwevents_event_recurrence'
            : $wpdb->prefix . 'educator_course_recurrence';
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
            '_event_price',
            '_event_deposit',
            '_event_capacity',
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
        if (!$screen || !in_array($screen->post_type, ['educator_course', 'hmw_event'], true)) {
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
    public function enqueue_admin_scripts($hook): void
    {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'hmw_event') {
            return;
        }

        wp_enqueue_script(
            'hmwevents-schedule-admin',
            plugins_url('assets/js/schedule-admin.js', HMWEvents_PLUGIN_FILE),
            [],
            HMWEvents_VERSION,
            true
        );
        wp_localize_script('hmwevents-schedule-admin', 'hmwScheduleAdmin', [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'confirmMsg' => __('Are you sure you want to trash all sessions?', 'hmw-events'),
        ]);
    }
}
