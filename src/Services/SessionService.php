<?php

/**
 * Session Service.
 *
 * Manages parent/child session relationships for recurring and multi-session
 * events. Replaces the legacy clone-style recurrence with a deterministic
 * parent/child model.
 *
 * Supports:
 * - Weekly recurrence (every N weeks on specific days)
 * - Multi-day / multi-week sessions
 * - Custom date sets
 * - Per-session override behaviour (date, time, venue, capacity)
 * - Session regeneration with preserved overrides
 *
 * Children are hmw_event CPT posts with post_parent set to the parent event.
 * Recurrence config is stored in hmwevents_event_recurrence table.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class SessionService
{
    private string $recurrence_table;

    /**
     * Fields that can be overridden on a per-session basis.
     */
    private const OVERRIDEABLE_FIELDS = [
        'event_start_date',
        'event_end_date',
        'event_venue_name',
        'event_venue_address',
        'event_capacity',
        'event_webinar_url',
    ];

    public function __construct()
    {
        $this->recurrence_table = DatabaseService::get_table_name('event_recurrence');
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('save_post_hmw_event', [$this, 'sync_child_sessions_on_parent_save'], 20, 3);
    }

    // ================================================================
    // SESSION GENERATION
    // ================================================================

    /**
     * Generate child session posts from a parent event with recurrence config.
     *
     * @param int   $parent_id
     * @param array $config {
     *     recurrence_type: 'daily'|'weekly'|'monthly'|'custom',
     *     start_date: Y-m-d,
     *     end_date: Y-m-d|null,
     *     recurrence_interval: int (default 1),
     *     recurrence_days: string e.g. "1,3,5" (Mon=1...Sun=7),
     *     max_occurrences: int|null,
     *     custom_dates: string[] Array of Y-m-d dates (required when type=custom),
     * }
     * @return int[] Array of created child post IDs.
     */
    public function generate_sessions(int $parent_id, array $config): array
    {
        $config = array_merge([
            'recurrence_type'     => 'weekly',
            'start_date'          => '',
            'end_date'            => null,
            'recurrence_interval' => 1,
            'recurrence_days'     => '',
            'max_occurrences'     => null,
            'custom_dates'        => [],
            'is_active'           => 1,
        ], $config);

        if (empty($config['start_date'])) {
            return [];
        }

        $dates = $this->calculate_dates($config);
        $parent = get_post($parent_id);
        if (!$parent) {
            return [];
        }

        // Get existing children to avoid duplicates
        $existing_dates = $this->get_existing_child_dates($parent_id);

        $created = [];
        $sort_order = count($existing_dates) + 1;

        foreach ($dates as $date) {
            $date_key = $date['start'] . '|' . $date['end'];
            if (in_array($date_key, $existing_dates, true)) {
                continue;
            }

            $child_id = $this->create_child_session($parent, $date, $sort_order);
            if ($child_id) {
                $created[] = $child_id;
                $sort_order++;
            }
        }

        // Store recurrence config
        if (!empty($created)) {
            $this->store_recurrence($parent_id, $config);
        }

        return $created;
    }

    /**
     * Get all child sessions for a parent event.
     *
     * @param string $post_status  Post status filter. Default: any except trash.
     * @return \WP_Post[]
     */
    public function get_sessions(int $parent_id, string $post_status = 'any'): array
    {
        return get_posts([
            'post_type'      => 'hmw_event',
            'post_parent'    => $parent_id,
            'post_status'    => $post_status,
            'posts_per_page' => -1,
            'orderby'        => 'meta_value',
            'meta_key'       => '_session_start_date',
            'order'          => 'ASC',
        ]) ?: [];
    }

    /**
     * Update an individual child session with overrides.
     *
     * @param int   $session_id
     * @param array $overrides  Key-value pairs of overridden fields.
     * @return bool
     */
    public function update_session(int $session_id, array $overrides): bool
    {
        $session = get_post($session_id);
        if (!$session || $session->post_type !== 'hmw_event' || !$session->post_parent) {
            return false;
        }

        $valid_overrides = array_intersect_key($overrides, array_flip(self::OVERRIDEABLE_FIELDS));

        if (empty($valid_overrides)) {
            return false;
        }

        foreach ($valid_overrides as $key => $value) {
            update_post_meta($session_id, '_' . $key, $value);

            // Track that this field is an override
            update_post_meta($session_id, '_override_' . $key, 1);
        }

        return true;
    }

    /**
     * Delete a child session.
     *
     * @param int  $session_id
     * @param bool $permanent  True = force delete, false = trash.
     * @return bool
     */
    public function delete_session(int $session_id, bool $permanent = false): bool
    {
        $session = get_post($session_id);
        if (!$session || $session->post_type !== 'hmw_event' || !$session->post_parent) {
            return false;
        }

        return (bool) wp_delete_post($session_id, $permanent);
    }

    /**
     * Regenerate child sessions from the parent's recurrence config.
     *
     * Existing children with overridden fields are preserved.
     * Children without overrides that no longer match the recurring dates
     * are removed.
     *
     * @param int   $parent_id
     * @param array $override_config Optional config to use instead of the stored recurrence row.
     * @return array {created: int[], removed: int[], kept: int[]}
     */
    public function regenerate_sessions(int $parent_id, array $override_config = []): array
    {
        $recurrence = $this->get_recurrence($parent_id);
        if (!$recurrence && empty($override_config)) {
            return ['created' => [], 'removed' => [], 'kept' => []];
        }

        if (!empty($override_config)) {
            $config = array_merge([
                'recurrence_type'     => 'weekly',
                'start_date'          => '',
                'end_date'            => null,
                'recurrence_interval' => 1,
                'recurrence_days'     => '',
                'max_occurrences'     => null,
                'custom_dates'        => [],
            ], $override_config);
        } else {
            $config = [
                'recurrence_type'     => $recurrence->recurrence_type,
                'start_date'          => $recurrence->start_date,
                'end_date'            => $recurrence->end_date,
                'recurrence_interval' => (int) $recurrence->recurrence_interval,
                'recurrence_days'     => $recurrence->recurrence_days,
                'max_occurrences'     => $recurrence->max_occurrences ? (int) $recurrence->max_occurrences : null,
                'custom_dates'        => [],
            ];
        }

        // For custom recurrence without override config, fetch dates from ACF repeater.
        if ($config['recurrence_type'] === 'custom' && empty($config['custom_dates'])) {
            $config['custom_dates'] = $this->get_custom_dates_from_acf($parent_id);
        }

        $dates = $this->calculate_dates($config);
        $existing = $this->get_sessions($parent_id);

        $created = [];
        $removed = [];
        $kept    = [];
        $date_set = [];

        foreach ($dates as $date) {
            $date_set[] = $date['start'] . '|' . $date['end'];
        }

        // Check existing children
        foreach ($existing as $child) {
            $child_start = get_post_meta($child->ID, '_event_start_date', true);
            $child_end   = get_post_meta($child->ID, '_event_end_date', true);
            $child_start_date = strlen($child_start) >= 10 ? substr($child_start, 0, 10) : $child_start;
            $child_end_date   = strlen($child_end) >= 10 ? substr($child_end, 0, 10) : $child_end;
            $child_key = $child_start_date . '|' . $child_end_date;
            $has_overrides = $this->session_has_overrides($child->ID);

            if (in_array($child_key, $date_set, true)) {
                $kept[] = $child->ID;
                // Remove from date_set so we don't recreate
                unset($date_set[array_search($child_key, $date_set, true)]);
            } elseif ($has_overrides) {
                $kept[] = $child->ID;
            } else {
                $this->delete_session($child->ID, true);
                $removed[] = $child->ID;
            }
        }

        // Create remaining dates
        $parent = get_post($parent_id);
        if ($parent) {
            $sort_order = count($kept) + 1;
            foreach ($date_set as $date_key) {
                [$start, $end] = explode('|', $date_key);
                $child_id = $this->create_child_session($parent, ['start' => $start, 'end' => $end], $sort_order);
                if ($child_id) {
                    $created[] = $child_id;
                    $sort_order++;
                }
            }
        }

        $this->store_recurrence($parent_id, $config);

        return compact('created', 'removed', 'kept');
    }

    /**
     * Cascade a parent change to all children.
     *
     * By default, only propagates to children that don't have an override
     * for that specific field.
     *
     * @param int   $parent_id
     * @param array $changes
     * @param bool  $force  If true, overwrite even overridden fields.
     * @return int Number of children updated.
     */
    public function cascade_to_children(int $parent_id, array $changes, bool $force = false): int
    {
        $children = $this->get_sessions($parent_id);
        $updated = 0;

        foreach ($children as $child) {
            foreach ($changes as $key => $value) {
                if (!$force && $this->field_is_overridden($child->ID, $key)) {
                    continue;
                }
                update_post_meta($child->ID, '_' . $key, $value);
            }
            $updated++;
        }

        return $updated;
    }

    // ================================================================
    // RECURRENCE CONFIG
    // ================================================================

    /**
     * Store recurrence config for a parent event.
     */
    private function store_recurrence(int $parent_id, array $config): void
    {
        global $wpdb;

        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->recurrence_table} WHERE event_post_id = %d",
            $parent_id
        ));

        $data = [
            'recurrence_type'     => $config['recurrence_type'],
            'recurrence_interval' => $config['recurrence_interval'],
            'recurrence_days'     => $config['recurrence_days'],
            'start_date'          => $config['start_date'],
            'end_date'            => $config['end_date'],
            'max_occurrences'    => $config['max_occurrences'],
            'is_active'           => $config['is_active'] ?? 1,
            'updated_at'          => current_time('mysql'),
        ];

        if ($existing) {
            $wpdb->update($this->recurrence_table, $data, ['id' => $existing]);
        } else {
            $data['event_post_id'] = $parent_id;
            $data['created_at']    = current_time('mysql');
            $wpdb->insert($this->recurrence_table, $data);
        }
    }

    /**
     * Get recurrence config for a parent event.
     *
     * @since 2.3.0 Made public for config-change detection.
     * @return object|null DB row or null if not found.
     */
    public function get_recurrence(int $parent_id): ?object
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->recurrence_table} WHERE event_post_id = %d AND is_active = 1",
            $parent_id
        ));
    }

    // ================================================================
    // DATE CALCULATION
    // ================================================================

    /**
     * Calculate dates from recurrence config.
     *
     * Supports daily, weekly, monthly, and custom recurrence types.
     * For weekly, the recurrence_days field (comma-separated Mon=1..Sun=7)
     * selects which days of the week to run on. For daily and monthly, the
     * master event's start day is used.
     *
     * The master event's own start date is excluded — the parent is the
     * first occurrence and children represent subsequent recurrences.
     *
     * @return array[] List of ['start' => Y-m-d, 'end' => Y-m-d]
     */
    private function calculate_dates(array $config): array
    {
        $dates = [];

        $start = new \DateTime($config['start_date']);
        $end   = $config['end_date'] ? new \DateTime($config['end_date']) : null;
        $interval = max(1, (int) ($config['recurrence_interval'] ?? 1));
        $days = array_filter(array_map('intval', explode(',', $config['recurrence_days'] ?? '')));
        $max = $config['max_occurrences'] ? (int) $config['max_occurrences'] : null;
        $type = $config['recurrence_type'];
        $parent_start_date = $start->format('Y-m-d');

        // Hard safety cap to prevent runaway generation.
        $safety_cap = 500;

        if ($type === 'custom') {
            $custom_dates = $config['custom_dates'] ?? [];
            if (!is_array($custom_dates)) {
                return [];
            }
            $seen = [];
            foreach ($custom_dates as $date_str) {
                if (empty($date_str)) {
                    continue;
                }
                $formatted = date('Y-m-d', strtotime($date_str));
                if (!$formatted || $formatted === '1970-01-01') {
                    continue;
                }
                if ($formatted === $parent_start_date) {
                    continue;
                }
                if (in_array($formatted, $seen, true)) {
                    continue;
                }
                $seen[] = $formatted;
                $dates[] = ['start' => $formatted, 'end' => $formatted];
            }
            usort($dates, fn($a, $b) => strcmp($a['start'], $b['start']));
            return $dates;
        }

        if ($type === 'daily') {
            $current = clone $start;
            $current->modify("+{$interval} days");
            $count = 0;
            while (true) {
                if ($max && $count >= $max) {
                    break;
                }
                if ($end && $current > $end) {
                    break;
                }
                if ($count >= $safety_cap) {
                    break;
                }
                $dates[] = [
                    'start' => $current->format('Y-m-d'),
                    'end'   => $current->format('Y-m-d'),
                ];
                $count++;
                $current->modify("+{$interval} days");
            }
            return $dates;
        }

        if ($type === 'monthly') {
            $current = clone $start;
            $current->modify("+{$interval} months");
            $count = 0;
            while (true) {
                if ($max && $count >= $max) {
                    break;
                }
                if ($end && $current > $end) {
                    break;
                }
                if ($count >= $safety_cap) {
                    break;
                }
                $dates[] = [
                    'start' => $current->format('Y-m-d'),
                    'end'   => $current->format('Y-m-d'),
                ];
                $count++;
                $current->modify("+{$interval} months");
            }
            return $dates;
        }

        if ($type === 'weekly') {
            if (empty($days)) {
                $days = [(int) $start->format('N')];
            }
            sort($days);

            // Anchor week: the Monday of the start date's week.
            $week_anchor = clone $start;
            $week_anchor->modify('this week monday');
            $current = clone $week_anchor;
            $count = 0;
            $week_index = 0;

            while (true) {
                if ($max && $count >= $max) {
                    break;
                }
                if ($count >= $safety_cap) {
                    break;
                }
                if ($end && $current > $end) {
                    break;
                }

                $is_active_week = ($week_index % $interval) === 0;

                if ($is_active_week) {
                    foreach ($days as $day) {
                        $session_date = clone $current;
                        $session_date->modify('+' . ($day - 1) . ' days');

                        if ($session_date <= $start) {
                            continue;
                        }
                        if ($max && $count >= $max) {
                            break 2;
                        }
                        if ($end && $session_date > $end) {
                            break 2;
                        }

                        $dates[] = [
                            'start' => $session_date->format('Y-m-d'),
                            'end'   => $session_date->format('Y-m-d'),
                        ];
                        $count++;
                    }
                }

                $current->modify('+1 week');
                $week_index++;
            }
            return $dates;
        }

        return $dates;
    }

    // ================================================================
    // CHILD MANAGEMENT
    // ================================================================

    /**
     * Create a single child session post.
     *
     * The child's date is set to the calculated occurrence date, but the
     * time-of-day is preserved from the parent event's start/end datetime.
     * The duration between parent start and end is also preserved.
     */
    private function create_child_session(\WP_Post $parent, array $date, int $sort_order): ?int
    {
        // Preserve the parent's time-of-day and duration.
        $parent_start_raw = get_post_meta($parent->ID, '_event_start_date', true);
        $parent_end_raw   = get_post_meta($parent->ID, '_event_end_date', true);

        $child_start = $date['start'];
        $child_end   = $date['end'];

        if ($parent_start_raw) {
            $parent_start = new \DateTime($parent_start_raw);
            $occurrence = new \DateTime($date['start']);
            $occurrence->setTime(
                (int) $parent_start->format('H'),
                (int) $parent_start->format('i'),
                (int) $parent_start->format('s')
            );
            $child_start = $occurrence->format('Y-m-d H:i:s');

            if ($parent_end_raw) {
                $parent_end = new \DateTime($parent_end_raw);
                $duration = $parent_start->diff($parent_end);
                $occurrence_end = clone $occurrence;
                $occurrence_end->add($duration);
                $child_end = $occurrence_end->format('Y-m-d H:i:s');
            } else {
                $child_end = $child_start;
            }
        }

        $child_id = wp_insert_post([
            'post_type'    => 'hmw_event',
            'post_parent'  => $parent->ID,
            'post_title'   => sprintf(
                '%s — %s',
                $parent->post_title,
                date('D j M Y', strtotime($date['start']))
            ),
            'post_status'  => $parent->post_status,
            'post_author'  => $parent->post_author,
            'menu_order'   => $sort_order,
            'meta_input'   => [
                '_session_start_date' => $date['start'],
                '_session_end_date'   => $date['end'],
                '_is_child_session'   => 1,
                '_event_start_date'   => $child_start,
                '_event_end_date'     => $child_end,
            ],
        ], true);

        if (is_wp_error($child_id)) {
            error_log('HMWEvents: Failed to create child session: ' . $child_id->get_error_message());
            return null;
        }

        // Copy parent meta to child, excluding date fields (already set above)
        // and recurrence-related fields (children are not recurring themselves).
        $parent_meta = get_post_meta($parent->ID);
        $skip_keys = [
            '_is_child_session',
            '_event_start_date',
            '_event_end_date',
            '_event_is_recurring',
            '_event_recurrence_interval',
            '_event_recurrence_unit',
            '_event_recurrence_days',
            '_event_recurrence_end_type',
            '_event_recurrence_end_date',
            '_event_recurrence_max_occurrences',
        ];
        foreach ($parent_meta as $key => $values) {
            if (in_array($key, $skip_keys, true)) {
                continue;
            }
            if (str_starts_with($key, '_event_')) {
                update_post_meta($child_id, $key, $values[0] ?? '');
            }
        }

        // Copy parent taxonomies
        $taxonomies = ['hmw_event_type', 'hmw_event_audience', 'hmw_event_delivery_mode', 'hmw_event_state'];
        foreach ($taxonomies as $tax) {
            $terms = wp_get_object_terms($parent->ID, $tax, ['fields' => 'slugs']);
            if (!empty($terms) && !is_wp_error($terms)) {
                wp_set_object_terms($child_id, $terms, $tax);
            }
        }

        // Copy featured image if the parent has one.
        $thumbnail_id = get_post_thumbnail_id($parent->ID);
        if ($thumbnail_id) {
            set_post_thumbnail($child_id, $thumbnail_id);
        }

        return $child_id;
    }

    /**
     * Get existing child session dates to avoid duplicates.
     */
    private function get_existing_child_dates(int $parent_id): array
    {
        $children = $this->get_sessions($parent_id);
        $dates = [];
        foreach ($children as $child) {
            $start = get_post_meta($child->ID, '_event_start_date', true);
            $end   = get_post_meta($child->ID, '_event_end_date', true);
            $start_date = strlen($start) >= 10 ? substr($start, 0, 10) : $start;
            $end_date   = strlen($end) >= 10 ? substr($end, 0, 10) : $end;
            $dates[] = $start_date . '|' . $end_date;
        }
        return $dates;
    }

    /**
     * Get custom dates from the ACF repeater field on the parent event.
     *
     * Supports both the new _event_recurrence_custom_dates field and the
     * legacy course_custom_dates field for backward compatibility.
     *
     * @return string[] Array of Y-m-d date strings.
     */
    private function get_custom_dates_from_acf(int $parent_id): array
    {
        $dates = [];

        if (function_exists('get_field')) {
            $repeater = get_field('_event_recurrence_custom_dates', $parent_id);
            if (empty($repeater)) {
                $repeater = get_field('course_custom_dates', $parent_id);
            }
            if (is_array($repeater)) {
                foreach ($repeater as $row) {
                    if (!empty($row['date'])) {
                        $dates[] = $row['date'];
                    }
                }
                return $dates;
            }
        }

        return $dates;
    }

    /**
     * Check if a child session has any field overrides.
     */
    private function session_has_overrides(int $session_id): bool
    {
        foreach (self::OVERRIDEABLE_FIELDS as $field) {
            if ($this->field_is_overridden($session_id, $field)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a specific field is overridden on a session.
     */
    private function field_is_overridden(int $session_id, string $field): bool
    {
        return (bool) get_post_meta($session_id, '_override_' . $field, true);
    }

    /**
     * Sync child sessions when a parent event is saved.
     *
     * Hooked to save_post_hmw_event.
     */
    public function sync_child_sessions_on_parent_save(int $post_id, \WP_Post $post, bool $update): void
    {
        if ($post->post_parent || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }

        if (!$update) {
            return;
        }

        // Cascade status change to children
        $this->cascade_to_children($post_id, ['post_status' => $post->post_status], true);
    }
}
