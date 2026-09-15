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

    private ?EventDataService $event_data_service = null;
    private bool $suppress_deleted_session_exclusion = false;

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

    /**
     * Fields that can be overridden on a per-session basis.
     */
    private const OVERRIDEABLE_FIELDS = [
        'event_start_date',
        'event_end_date',
        'event_venue',
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
        add_action('wp_trash_post', [$this, 'record_deleted_session']);
        add_action('before_delete_post', [$this, 'record_deleted_session']);
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
     *     start_date: Y-m-d or Y-m-d H:i:s,
     *     end_date: Y-m-d|null,
     *     recurrence_interval: int (default 1),
     *     recurrence_days: string e.g. "1,3,5" (Mon=1...Sun=7),
     *     max_occurrences: int|null,
     *     custom_dates: string[] Y-m-d, or array[] {date, time?} (required when type=custom),
     *     times: string[] H:i[:s] start times for multiple same-day sessions (optional),
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
            'times'               => [],
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
            $date_key = $this->session_identity_key($date);
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

        $session_date = $this->event_data()->get_start_date($session_id);
        if ($session_date) {
            $this->add_excluded_date((int) $session->post_parent, $session_date);
        }

        return (bool) wp_delete_post($session_id, $permanent);
    }

    public function record_deleted_session(int $session_id): void
    {
        $session = get_post($session_id);
        if (!$session || $session->post_type !== 'hmw_event') {
            return;
        }

        if ($session->post_parent || get_post_meta($session_id, '_cloned_from', true)) {
            $this->session_booking()->cancel_bookings_for_session((int) $session_id);
        }

        if ($this->suppress_deleted_session_exclusion) {
            return;
        }

        if (!$session->post_parent) {
            return;
        }

        $session_date = $this->event_data()->get_start_date($session_id);
        if ($session_date) {
            $this->add_excluded_date((int) $session->post_parent, $session_date);
        }
    }

    private ?SessionBookingService $session_booking_service = null;

    private function session_booking(): SessionBookingService
    {
        if ($this->session_booking_service === null) {
            $this->session_booking_service = new SessionBookingService();
        }
        return $this->session_booking_service;
    }

    private function remove_generated_session(int $session_id): void
    {
        $this->suppress_deleted_session_exclusion = true;
        wp_delete_post($session_id, true);
        $this->suppress_deleted_session_exclusion = false;
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
                'times'               => [],
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
                'times'               => $this->parse_stored_times($recurrence->recurrence_times ?? null),
            ];

            // The recurrence row stores the date only; restore the parent's
            // time-of-day so default (non-timed) slots resolve correctly.
            $parent_start = $this->event_data()->get_start_date($parent_id);
            if ($parent_start) {
                $config['start_date'] = $parent_start;
            }
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
            $date_set[] = $this->session_identity_key($date);
        }

        $excluded_dates = $this->get_excluded_dates($parent_id);
        $default_time = (new \DateTime($config['start_date']))->format('H:i:s');
        $current_dates = [];
        foreach ($dates as $date) {
            $current_dates[] = $this->resolve_slot_datetime($date, $default_time);
            $current_dates[] = $date['start'];
        }
        $current_dates = array_values(array_unique($current_dates));
        $excluded_dates = array_values(array_intersect($excluded_dates, $current_dates));
        if (empty($excluded_dates)) {
            delete_post_meta($parent_id, '_excluded_session_dates');
        } else {
            update_post_meta($parent_id, '_excluded_session_dates', $excluded_dates);
        }

        if (!empty($excluded_dates)) {
            $date_set = array_filter($date_set, function ($key, $index) use ($dates, $excluded_dates, $default_time) {
                $date = $dates[$index];
                $datetime = $this->resolve_slot_datetime($date, $default_time);
                return !in_array($datetime, $excluded_dates, true)
                    && !in_array($date['start'], $excluded_dates, true);
            }, ARRAY_FILTER_USE_BOTH);
        }

        // Check existing children
        foreach ($existing as $child) {
            $child_start = $this->event_data()->get_start_date($child->ID) ?? '';
            $child_end   = $this->event_data()->get_end_date($child->ID) ?? '';
            $child_key = $this->child_identity_key($child_start);
            $has_overrides = $this->session_has_overrides($child->ID);

            if ($this->child_is_excluded($child_start, $excluded_dates)) {
                $this->remove_generated_session($child->ID);
                $removed[] = $child->ID;
            } elseif (in_array($child_key, $date_set, true)) {
                $kept[] = $child->ID;
                // Remove from date_set so we don't recreate
                unset($date_set[array_search($child_key, $date_set, true)]);
            } elseif ($has_overrides) {
                $kept[] = $child->ID;
            } else {
                $this->remove_generated_session($child->ID);
                $removed[] = $child->ID;
            }
        }

        // Create remaining dates
        $parent = get_post($parent_id);
        if ($parent) {
            $sort_order = count($kept) + 1;
            foreach ($date_set as $index => $date_key) {
                $date = $dates[$index] ?? null;
                if (!$date) {
                    continue;
                }
                $child_id = $this->create_child_session($parent, $date, $sort_order);
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
            'recurrence_times'    => $this->format_stored_times($config['times'] ?? []),
            'start_date'          => substr((string) $config['start_date'], 0, 10),
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
    // EXCLUDED DATES
    // ================================================================

    private function get_excluded_dates(int $parent_id): array
    {
        $raw = get_post_meta($parent_id, '_excluded_session_dates', true);
        return is_array($raw) ? $raw : [];
    }

    private function add_excluded_date(int $parent_id, string $date): void
    {
        $dates = $this->get_excluded_dates($parent_id);
        if (!in_array($date, $dates, true)) {
            $dates[] = $date;
            sort($dates);
            update_post_meta($parent_id, '_excluded_session_dates', $dates);
        }
    }

    public function clear_excluded_dates(int $parent_id): void
    {
        delete_post_meta($parent_id, '_excluded_session_dates');
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
     * When config['times'] contains multiple start times, each occurrence
     * date is expanded into one entry per time — multiple same-day sessions.
     * The parent's own date then yields the additional slots too (minus the
     * parent's own time); without times it yields none.
     *
     * The parent's own slot (date + its start time) is always excluded —
     * the parent is the first occurrence and children represent the rest.
     *
     * @return array[] List of ['start' => Y-m-d, 'end' => Y-m-d, 'time' => H:i:s|null]
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
        $times = $this->normalize_times($config['times'] ?? []);
        $parent_start_date = $start->format('Y-m-d');
        $parent_start_time = $start->format('H:i:s');

        // Without configured times, each date yields one session at the
        // parent's own start time (legacy behaviour).
        $slot_times = $times ?: [$parent_start_time];

        // Hard safety cap to prevent runaway generation (per session, not date).
        $safety_cap = 500;

        $end_date_str = $end ? $end->format('Y-m-d') : null;

        if ($type === 'custom') {
            $custom_dates = $config['custom_dates'] ?? [];
            if (!is_array($custom_dates)) {
                return [];
            }
            $seen = [];
            foreach ($custom_dates as $row) {
                if (is_array($row)) {
                    $date_str = (string) ($row['date'] ?? '');
                    $time = !empty($row['time']) ? $this->normalize_time((string) $row['time']) : null;
                } else {
                    $date_str = (string) $row;
                    $time = strlen($date_str) > 10 && str_contains($date_str, ':')
                        ? $this->normalize_time(substr($date_str, 11))
                        : null;
                }
                if (empty($date_str)) {
                    continue;
                }
                $formatted = date('Y-m-d', strtotime($date_str));
                if (!$formatted || $formatted === '1970-01-01') {
                    continue;
                }
                if ($formatted === $parent_start_date) {
                    if ($time === null || $time === $parent_start_time) {
                        continue;
                    }
                }
                $key = $formatted . '|' . ($time ?? '');
                if (isset($seen[$key])) {
                    continue;
                }
                if (count($dates) >= $safety_cap) {
                    break;
                }
                $seen[$key] = true;
                $dates[] = ['start' => $formatted, 'end' => $formatted, 'time' => $time];
            }
            return $this->sort_occurrences($dates);
        }

        if ($type === 'daily') {
            $count = 0;

            // The parent is the first occurrence. Without multi-slot times the
            // parent's date yields no children; with times, its date still
            // yields the additional slots (minus the parent's own time).
            if (!empty($times)) {
                foreach (array_values(array_diff($slot_times, [$parent_start_time])) as $time) {
                    if (count($dates) >= $safety_cap) {
                        break;
                    }
                    $dates[] = [
                        'start' => $parent_start_date,
                        'end'   => $parent_start_date,
                        'time'  => $time,
                    ];
                }
                $count++;
            }

            $current = clone $start;
            $current->modify("+{$interval} days");
            while (true) {
                if ($max && $count >= $max) {
                    break;
                }
                if ($end_date_str && $current->format('Y-m-d') > $end_date_str) {
                    break;
                }
                if (count($dates) >= $safety_cap) {
                    break;
                }
                foreach ($slot_times as $time) {
                    $dates[] = [
                        'start' => $current->format('Y-m-d'),
                        'end'   => $current->format('Y-m-d'),
                        'time'  => $time,
                    ];
                }
                $count++;
                $current->modify("+{$interval} days");
            }
            return $this->sort_occurrences($dates);
        }

        if ($type === 'monthly') {
            $count = 0;

            if (!empty($times)) {
                foreach (array_values(array_diff($slot_times, [$parent_start_time])) as $time) {
                    if (count($dates) >= $safety_cap) {
                        break;
                    }
                    $dates[] = [
                        'start' => $parent_start_date,
                        'end'   => $parent_start_date,
                        'time'  => $time,
                    ];
                }
                $count++;
            }

            $current = clone $start;
            $current->modify("+{$interval} months");
            while (true) {
                if ($max && $count >= $max) {
                    break;
                }
                if ($end_date_str && $current->format('Y-m-d') > $end_date_str) {
                    break;
                }
                if (count($dates) >= $safety_cap) {
                    break;
                }
                foreach ($slot_times as $time) {
                    $dates[] = [
                        'start' => $current->format('Y-m-d'),
                        'end'   => $current->format('Y-m-d'),
                        'time'  => $time,
                    ];
                }
                $count++;
                $current->modify("+{$interval} months");
            }
            return $this->sort_occurrences($dates);
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
                if ($end_date_str && $current->format('Y-m-d') > $end_date_str) {
                    break;
                }
                if (count($dates) >= $safety_cap) {
                    break;
                }

                $is_active_week = ($week_index % $interval) === 0;

                if ($is_active_week) {
                    foreach ($days as $day) {
                        $session_date = clone $current;
                        $session_date->modify('+' . ($day - 1) . ' days');
                        $session_date_str = $session_date->format('Y-m-d');

                        if ($session_date_str < $parent_start_date) {
                            continue;
                        }
                        if ($max && $count >= $max) {
                            break 2;
                        }
                        if ($end_date_str && $session_date_str > $end_date_str) {
                            break 2;
                        }

                        if ($session_date_str === $parent_start_date && empty($times)) {
                            continue;
                        }

                        $occurrence_times = $session_date_str === $parent_start_date
                            ? array_values(array_diff($slot_times, [$parent_start_time]))
                            : $slot_times;

                        foreach ($occurrence_times as $time) {
                            if (count($dates) >= $safety_cap) {
                                break 3;
                            }
                            $dates[] = [
                                'start' => $session_date_str,
                                'end'   => $session_date_str,
                                'time'  => $time,
                            ];
                        }
                        $count++;
                    }
                }

                $current->modify('+1 week');
                $week_index++;
            }
            return $this->sort_occurrences($dates);
        }

        return $dates;
    }

    /**
     * Sort occurrence entries chronologically by date then time.
     */
    private function sort_occurrences(array $dates): array
    {
        usort($dates, function ($a, $b) {
            $cmp = strcmp($a['start'], $b['start']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['time'] ?? '', $b['time'] ?? '');
        });
        return $dates;
    }

    /**
     * Normalize an array of time strings to unique, sorted H:i:s values.
     *
     * @return string[]
     */
    public function normalize_times($times): array
    {
        if (!is_array($times)) {
            return [];
        }
        $normalized = [];
        foreach ($times as $time) {
            $time = $this->normalize_time((string) $time);
            if ($time !== null) {
                $normalized[] = $time;
            }
        }
        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        return $normalized;
    }

    /**
     * Normalize a single time string (H:i, H:i:s, g:i a, etc.) to H:i:s.
     */
    private function normalize_time(string $time): ?string
    {
        $time = trim($time);
        if ($time === '') {
            return null;
        }
        $timestamp = strtotime($time);
        if ($timestamp === false) {
            return null;
        }
        return date('H:i:s', $timestamp);
    }

    /**
     * Encode times for the recurrence_times column.
     */
    private function format_stored_times(array $times): ?string
    {
        $times = $this->normalize_times($times);
        return $times ? implode(',', $times) : null;
    }

    /**
     * Decode the recurrence_times column value.
     *
     * @return string[]
     */
    public function parse_stored_times($raw): array
    {
        if (empty($raw) || !is_string($raw)) {
            return [];
        }
        return $this->normalize_times(explode(',', $raw));
    }

    /**
     * Identity key for a calculated occurrence (date + slot time).
     */
    private function session_identity_key(array $date): string
    {
        return $date['start'] . '|' . ($date['time'] ?? '');
    }

    /**
     * Identity key for an existing child session, derived from its
     * stored start datetime.
     */
    private function child_identity_key(string $start_datetime): string
    {
        if (strlen($start_datetime) > 10) {
            return substr($start_datetime, 0, 10) . '|' . substr($start_datetime, 11);
        }
        return $start_datetime . '|';
    }

    /**
     * Full start datetime for a calculated occurrence.
     */
    private function resolve_slot_datetime(array $date, string $default_time): string
    {
        return $date['start'] . ' ' . ($date['time'] ?? $default_time);
    }

    /**
     * Whether a child's start datetime matches an exclusion (exact datetime
     * or legacy date-only exclusion).
     */
    private function child_is_excluded(string $child_start, array $excluded): bool
    {
        if ($child_start === '') {
            return false;
        }
        if (in_array($child_start, $excluded, true)) {
            return true;
        }
        return in_array(substr($child_start, 0, 10), $excluded, true);
    }

    // ================================================================
    // CHILD MANAGEMENT
    // ================================================================

    /**
     * Create a single child session post.
     *
     * The child's date is set to the calculated occurrence date. When the
     * occurrence carries a slot time it wins; otherwise the time-of-day is
     * preserved from the parent event's start datetime. The duration between
     * parent start and end is always preserved.
     */
    private function create_child_session(\WP_Post $parent, array $date, int $sort_order): ?int
    {
        // Preserve the parent's time-of-day and duration.
        $parent_start_raw = $this->event_data()->get_start_date($parent->ID);
        $parent_end_raw   = $this->event_data()->get_end_date($parent->ID);

        $child_start = $date['start'];
        $child_end   = $date['end'];

        if ($parent_start_raw) {
            $parent_start = new \DateTime($parent_start_raw);
            $occurrence = new \DateTime($date['start']);
            if (!empty($date['time'])) {
                [$h, $i, $s] = array_map('intval', explode(':', $date['time']));
                $occurrence->setTime($h, $i, $s);
            } else {
                $occurrence->setTime(
                    (int) $parent_start->format('H'),
                    (int) $parent_start->format('i'),
                    (int) $parent_start->format('s')
                );
            }
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

        $title_date = date('D j M Y', strtotime($date['start']));
        if (!empty($date['time'])) {
            $title_date .= ', ' . date('g:i a', strtotime($date['time']));
        }

        $child_id = wp_insert_post([
            'post_type'    => 'hmw_event',
            'post_parent'  => $parent->ID,
            'post_title'   => sprintf(
                '%s — %s',
                $parent->post_title,
                $title_date
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
            '_event_recurrence_times',
            '_event_recurrence_end_type',
            '_event_recurrence_end_date',
            '_event_recurrence_max_occurrences',
            '_event_template_override',
            '_event_template_override_apply_to_children',
        ];
        foreach ($parent_meta as $key => $values) {
            if (in_array($key, $skip_keys, true)) {
                continue;
            }
            if (str_starts_with($key, '_event_')) {
                update_post_meta($child_id, $key, maybe_unserialize($values[0] ?? ''));
            }
        }

        // Copy parent taxonomies
        $taxonomies = ['hmw_event_type', 'hmw_event_audience', 'hmw_event_delivery_mode'];
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

        $template_override = get_post_meta($parent->ID, '_event_template_override', true);
        $apply_to_children = get_post_meta($parent->ID, '_event_template_override_apply_to_children', true);
        if ($template_override && $apply_to_children) {
            update_post_meta($child_id, '_event_template_override', $template_override);
            update_post_meta($child_id, '_event_template_override_apply_to_children', $apply_to_children);
        }

        do_action('hmwevents_session_created', $child_id, $parent->ID);

        return $child_id;
    }

    /**
     * Get existing child session identities (date|time) to avoid duplicates.
     */
    private function get_existing_child_dates(int $parent_id): array
    {
        $children = $this->get_sessions($parent_id);
        $dates = [];
        foreach ($children as $child) {
            $start = $this->event_data()->get_start_date($child->ID) ?? '';
            if ($start === '') {
                continue;
            }
            $dates[] = $this->child_identity_key($start);
        }
        return $dates;
    }

    /**
     * Get custom dates from the ACF repeater field on the parent event.
     *
     * Reads the _event_recurrence_custom_dates repeater field, including the
     * optional per-row start time.
     *
     * @return array[] Array of ['date' => Y-m-d, 'time' => string]
     */
    private function get_custom_dates_from_acf(int $parent_id): array
    {
        $rows = [];

        if (function_exists('get_field')) {
            $repeater = get_field('_event_recurrence_custom_dates', $parent_id);
            if (is_array($repeater)) {
                foreach ($repeater as $row) {
                    if (!empty($row['date'])) {
                        $rows[] = [
                            'date' => $row['date'],
                            'time' => $row['time'] ?? '',
                        ];
                    }
                }
            }
        }

        return $rows;
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

        $this->cascade_to_children($post_id, [
            'event_venue'       => $this->event_data()->get_venue_id($post_id),
            'event_capacity'    => $this->event_data()->get_capacity($post_id),
            'event_webinar_url' => $this->event_data()->get_webinar_url($post_id) ?? '',
        ]);

        $this->sync_event_type_to_children($post_id);
    }

    /**
     * Propagate the parent's hmw_event_type to child sessions.
     */
    private function sync_event_type_to_children(int $parent_id): void
    {
        $terms = wp_get_object_terms($parent_id, 'hmw_event_type', ['fields' => 'slugs']);
        if (is_wp_error($terms) || empty($terms)) {
            return;
        }

        foreach ($this->get_sessions($parent_id) as $child) {
            $child_terms = wp_get_object_terms($child->ID, 'hmw_event_type', ['fields' => 'slugs']);
            if (is_wp_error($child_terms) || $child_terms === $terms) {
                continue;
            }
            wp_set_object_terms($child->ID, array_values($terms), 'hmw_event_type', false);
        }
    }
}
