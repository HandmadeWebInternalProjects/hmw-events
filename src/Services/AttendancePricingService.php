<?php

namespace HMWEvents\Services;

use HMWEvents\Helpers\EventHelper;

defined('ABSPATH') || die('Don\'t run this file directly!');

class AttendancePricingService
{
    public const ROLE_ADULT = 'adult';
    public const ROLE_CHILD = 'child';
    public const ROLE_ANY = 'any';

    public const MODE_FLAT = 'flat';
    public const MODE_PER_ATTENDEE = 'per_attendee';
    public const MODE_AGE_BAND = 'age_band';

    public const MODES = [self::MODE_FLAT, self::MODE_PER_ATTENDEE, self::MODE_AGE_BAND];

    public const DEFAULT_MAX_CHILDREN = 10;

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

    public static function default_composition(string $option_type, int $max_attendees = self::DEFAULT_MAX_CHILDREN): array
    {
        return match (sanitize_key($option_type) ?: 'individual') {
            'couple' => [
                'min_attendees' => 2,
                'max_attendees' => 2,
                'min_adults'    => 2,
                'min_children'  => 0,
                'allowed_roles' => [self::ROLE_ADULT],
            ],
            'parent_child' => [
                'min_attendees' => 2,
                'max_attendees' => max(2, $max_attendees),
                'min_adults'    => 1,
                'min_children'  => 1,
                'allowed_roles' => [self::ROLE_ADULT, self::ROLE_CHILD],
            ],
            default => [
                'min_attendees' => 1,
                'max_attendees' => 1,
                'min_adults'    => 1,
                'min_children'  => 0,
                'allowed_roles' => [self::ROLE_ADULT],
            ],
        };
    }

    public static function is_multi_options(array $options): bool
    {
        if (count($options) > 1) {
            return true;
        }

        foreach ($options as $option) {
            if (!is_array($option)) {
                continue;
            }

            $composition = $option['composition'] ?? self::default_composition((string) ($option['option_type'] ?? 'individual'));
            if ((int) ($composition['min_attendees'] ?? 1) > 1
                || (int) ($composition['max_attendees'] ?? 1) > 1
                || (int) ($composition['min_children'] ?? 0) > 0
            ) {
                return true;
            }
        }

        return false;
    }

    public static function normalize_mode(string $mode, array $rules = []): string
    {
        $mode = sanitize_key($mode);

        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        return !empty($rules) ? self::MODE_AGE_BAND : self::MODE_FLAT;
    }

    public static function decode_rules($raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    public static function encode_rules(?array $rules): ?string
    {
        if (empty($rules)) {
            return null;
        }

        $json = wp_json_encode($rules);

        return $json === false ? null : $json;
    }

    public static function insert_attendance_option(int $event_post_id, array $preset, int $sort_order = 0): void
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');

        $raw_capacity = $preset['capacity'] ?? null;
        $capacity = ($raw_capacity === null || $raw_capacity === '')
            ? null
            : max(0, (int) $raw_capacity);

        $type  = sanitize_key($preset['option_type'] ?? 'individual') ?: 'individual';
        $label = sanitize_text_field($preset['label'] ?? 'Individual');
        $rules = $preset['pricing_rules'] ?? [];
        $mode  = self::normalize_mode((string) ($preset['price_mode'] ?? ''), $rules);

        $key = sanitize_key((string) ($preset['option_key'] ?? ''));
        if ($key === '') {
            $key = sanitize_key(str_replace(' ', '-', $label));
        }
        if ($key === '') {
            $key = $type;
        }
        $key = self::generate_unique_option_key($event_post_id, $key);

        $wpdb->insert($table, [
            'event_post_id' => $event_post_id,
            'option_type'   => $type,
            'option_key'    => $key,
            'label'         => $label,
            'description'   => wp_kses_post((string) ($preset['description'] ?? '')),
            'price'         => (float) ($preset['price'] ?? 0),
            'price_mode'    => $mode,
            'pricing_rules' => self::encode_rules($rules),
            'capacity'      => $capacity,
            'sort_order'    => $sort_order,
            'is_active'     => 1,
            'created_at'    => current_time('mysql'),
            'updated_at'    => current_time('mysql'),
        ], ['%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s', '%s']);
    }

    /**
     * Return a selection key unique among the event's attendance options,
     * appending a numeric suffix when the base key is taken.
     */
    public static function generate_unique_option_key(int $event_post_id, string $key): string
    {
        global $wpdb;
        $table = DatabaseService::get_table_name('event_attendance_options');

        $base = sanitize_key($key) ?: 'individual';

        $taken = $wpdb->get_col($wpdb->prepare(
            "SELECT option_key FROM {$table} WHERE event_post_id = %d AND option_key LIKE %s",
            $event_post_id,
            $wpdb->esc_like($base) . '%'
        ));

        if (empty($taken) || !in_array($base, $taken, true)) {
            return $base;
        }

        $taken = array_flip($taken);

        for ($i = 2; $i < 100; $i++) {
            $candidate = $base . '-' . $i;
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return $base . '-' . substr((string) time(), -6);
    }

    public function resolve_all(int $event_id): array
    {
        $options = EventHelper::get_active_attendance_options($event_id);
        $defaults = $this->event_defaults_attendance_options($event_id);
        $multi_max = $this->multi_booking_max($event_id);

        $result = [];

        foreach ($options as $row) {
            $type = sanitize_key($row->option_type ?? 'individual') ?: 'individual';
            $default = $this->find_default($defaults, $type);

            $db_rules = self::decode_rules($row->pricing_rules ?? null);
            $default_rules = $default['pricing_rules'] ?? [];

            $result[] = [
                'id'            => (int) ($row->id ?? 0),
                'option_type'   => $type,
                'option_key'    => sanitize_key((string) ($row->option_key ?? '')) ?: $type,
                'label'         => sanitize_text_field($row->label ?? ''),
                'description'   => (string) ($row->description ?? ''),
                'base_price'    => (float) ($row->price ?? 0),
                'capacity'      => $row->capacity !== null && $row->capacity !== '' ? max(0, (int) $row->capacity) : null,
                'composition'   => $default['composition'] ?? self::default_composition($type, $multi_max),
                'price_mode'    => $this->effective_mode((string) ($row->price_mode ?? ''), $db_rules, $default['price_mode'] ?? '', $default_rules),
                'pricing_rules' => $this->effective_rules($db_rules, $default_rules),
            ];
        }

        if (empty($result) && !empty($defaults)) {
            foreach ($defaults as $default) {
                $type = sanitize_key($default['option_type'] ?? 'individual') ?: 'individual';

                $result[] = [
                    'id'            => 0,
                    'option_type'   => $type,
                    'option_key'    => sanitize_key((string) ($default['option_key'] ?? '')) ?: $type,
                    'label'         => sanitize_text_field($default['label'] ?? ''),
                    'description'   => (string) ($default['description'] ?? ''),
                    'base_price'    => (float) ($default['price'] ?? 0),
                    'capacity'      => isset($default['capacity']) && $default['capacity'] !== '' && $default['capacity'] !== null
                        ? max(0, (int) $default['capacity'])
                        : null,
                    'composition'   => $default['composition'] ?? self::default_composition($type, $multi_max),
                    'price_mode'    => self::normalize_mode((string) ($default['price_mode'] ?? ''), $default['pricing_rules'] ?? []),
                    'pricing_rules' => $default['pricing_rules'] ?? [],
                ];
            }
        }

        return $result;
    }

    public function resolve_configuration(int $event_id, string $option_key): array
    {
        $requested = sanitize_key($option_key) ?: 'individual';
        $options = $this->resolve_all($event_id);

        foreach ($options as $option) {
            if ((string) ($option['option_key'] ?? '') !== '' && $option['option_key'] === $requested) {
                return $option;
            }
        }

        foreach ($options as $option) {
            if ((string) ($option['option_type'] ?? '') === $requested) {
                return $option;
            }
        }

        return [
            'id'            => 0,
            'option_type'   => $requested,
            'option_key'    => $requested,
            'label'         => $requested,
            'description'   => '',
            'base_price'    => $this->event_data()->get_price($event_id) ?? 0.0,
            'capacity'      => null,
            'composition'   => self::default_composition($requested, $this->multi_booking_max($event_id)),
            'price_mode'    => self::MODE_FLAT,
            'pricing_rules' => [],
        ];
    }

    /**
     * Resolve the default selection key for a set of raw option rows.
     *
     * Returns the requested value when it matches a row's option_key (or its
     * option_type for legacy rows without a key); otherwise the first row's
     * key, falling back to its type.
     */
    public static function resolve_default_option_key(array $option_rows, string $requested): string
    {
        $requested = sanitize_key($requested);

        if (empty($option_rows)) {
            return $requested !== '' ? $requested : 'individual';
        }

        foreach ($option_rows as $row) {
            $key = sanitize_key((string) ($row->option_key ?? ''));
            if ($key !== '' && $key === $requested) {
                return $requested;
            }
        }

        foreach ($option_rows as $row) {
            $type = sanitize_key((string) ($row->option_type ?? ''));
            if ($type !== '' && $type === $requested) {
                return $requested;
            }
        }

        foreach ($option_rows as $row) {
            $key = sanitize_key((string) ($row->option_key ?? ''));
            if ($key !== '') {
                return $key;
            }
        }

        return sanitize_key((string) ($option_rows[0]->option_type ?? '')) ?: 'individual';
    }

    /**
     * Build the shared attendance-option selection context consumed by both
     * the frontend v3 registration form and the admin manual booking modal.
     *
     * @param int         $event_id          Event post ID.
     * @param string      $requested_default Requested default option type.
     * @param array|null  $multi_booking     Multi-booking config (baseline min/max).
     * @param bool        $multi_enabled     Whether multi-booking is enabled in config.
     * @return array{
     *   options: array<int, array>,
     *   default_option_type: string,
     *   default_option_key: string,
     *   base_price: float,
     *   surcharge: float,
     *   surcharge_mode: string,
     *   surcharge_rate: float,
     *   is_free: bool,
     *   default_display_price: float,
     *   has_paid_option: bool,
     *   has_age_pricing: bool,
     *   effective_multi: bool,
     *   min_attendees: int,
     *   max_attendees: int,
     *   selected_composition: array,
     *   allowed_roles: string[]
     * }
     */
    public function build_selection_context(int $event_id, string $requested_default = '', ?array $multi_booking = null, bool $multi_enabled = false): array
    {
        $multi_booking = $multi_booking ?? [];
        $is_free = $this->event_data()->get_is_free($event_id);
        $base_price = $is_free ? 0.0 : (float) $this->event_data()->get_price($event_id);

        $option_rows = EventHelper::get_active_attendance_options($event_id);
        $default_option_key = self::resolve_default_option_key($option_rows, $requested_default);

        $config = $this->resolve_all($event_id);
        $capacity_service = new CapacityService();

        $payload = [];
        $has_paid_option = false;
        $has_age_pricing = false;
        $effective_multi = $multi_enabled;
        $max_attendees = max(1, (int) ($multi_booking['max'] ?? 10));
        $selected_composition = self::default_composition('individual');
        $default_option_type = '';

        foreach ($config as $option) {
            $mode = (string) ($option['price_mode'] ?? self::MODE_FLAT);
            $option_price = $is_free ? 0.0 : (float) $option['base_price'];
            $display_price = $is_free ? 0.0 : $this->representative_price($option);
            $option_key = (string) ($option['option_key'] ?? '') ?: (string) $option['option_type'];

            if (!$is_free && $this->has_positive_price($option)) {
                $has_paid_option = true;
            }

            if ($mode === self::MODE_AGE_BAND && !empty($option['pricing_rules'])) {
                $has_age_pricing = true;
            }

            $composition = $option['composition'];

            if ($option_key === $default_option_key) {
                $selected_composition = $composition;
                $default_option_type = (string) $option['option_type'];
            }

            if ((int) ($composition['min_attendees'] ?? 1) > 1) {
                $effective_multi = true;
            }

            $max_attendees = max($max_attendees, (int) ($composition['max_attendees'] ?? 1));

            $max_bookings = $option['capacity'];

            $payload[] = [
                'id'                 => (int) ($option['id']),
                'option_type'        => $option['option_type'],
                'option_key'         => $option_key,
                'label'              => $option['label'],
                'description'        => (string) ($option['description'] ?? ''),
                'price'              => $option_price,
                'display_price'      => $display_price,
                'price_mode'         => $mode,
                'composition'        => $composition,
                'pricing_rules'      => $option['pricing_rules'],
                'max_bookings'       => $max_bookings,
                'remaining_bookings' => $max_bookings !== null ? $capacity_service->get_option_remaining((int) $option['id']) : null,
            ];
        }

        if ($default_option_type === '') {
            $default_option_type = sanitize_key($default_option_key) ?: 'individual';
        }

        $default_display_price = $base_price;
        foreach ($payload as $option) {
            if ($option['option_key'] === $default_option_key) {
                $default_display_price = $is_free ? 0.0 : (float) $option['display_price'];
                break;
            }
        }

        $surcharge = $is_free ? 0.0 : $this->event_data()->calculate_surcharge($event_id, $default_display_price);
        $surcharge_mode = $is_free ? 'flat' : $this->event_data()->get_surcharge_type($event_id);
        $surcharge_rate = $is_free ? 0.0 : $this->event_data()->get_surcharge($event_id);

        $allowed_roles = array_values(array_intersect(
            [self::ROLE_ADULT, self::ROLE_CHILD],
            (array) ($selected_composition['allowed_roles'] ?? [self::ROLE_ADULT])
        ));
        if (empty($allowed_roles)) {
            $allowed_roles = [self::ROLE_ADULT];
        }

        return [
            'options'               => $payload,
            'default_option_type'   => $default_option_type,
            'default_option_key'    => $default_option_key,
            'base_price'            => $base_price,
            'surcharge'             => $surcharge,
            'surcharge_mode'        => $surcharge_mode,
            'surcharge_rate'        => $surcharge_rate,
            'is_free'               => $is_free,
            'default_display_price' => $default_display_price,
            'has_paid_option'       => $has_paid_option,
            'has_age_pricing'       => $has_age_pricing,
            'effective_multi'       => $effective_multi,
            'min_attendees'         => max(1, (int) ($selected_composition['min_attendees'] ?? 1)),
            'max_attendees'         => $max_attendees,
            'selected_composition'  => $selected_composition,
            'allowed_roles'         => $allowed_roles,
        ];
    }

    public function validate_composition(array $composition, array $attendees): true|\WP_Error
    {
        $min = max(0, (int) ($composition['min_attendees'] ?? 1));
        $max = max($min, (int) ($composition['max_attendees'] ?? $min));
        $min_adults = max(0, (int) ($composition['min_adults'] ?? 0));
        $min_children = max(0, (int) ($composition['min_children'] ?? 0));

        $allowed = array_values(array_filter(
            array_map('sanitize_key', (array) ($composition['allowed_roles'] ?? [self::ROLE_ADULT]))
        ));
        if (empty($allowed)) {
            $allowed = [self::ROLE_ADULT];
        }

        $count = count($attendees);

        if ($count < $min || $count > $max) {
            /* translators: 1: minimum attendees, 2: maximum attendees */
            return new \WP_Error(
                'invalid_attendee_count',
                sprintf(
                    __('This booking requires between %1$d and %2$d attendees.', 'hmw-events'),
                    $min,
                    $max
                )
            );
        }

        $adults = 0;
        $children = 0;

        foreach ($attendees as $index => $attendee) {
            $role = $this->attendee_role($attendee);

            if (!in_array($role, $allowed, true)) {
                /* translators: %d is the attendee number */
                return new \WP_Error(
                    'invalid_attendee_role',
                    sprintf(__('Attendee %d has an invalid role for this booking.', 'hmw-events'), $index + 1)
                );
            }

            if ($role === self::ROLE_ADULT) {
                $adults++;
            } elseif ($role === self::ROLE_CHILD) {
                $children++;
            }
        }

        if ($adults < $min_adults) {
            return new \WP_Error(
                'not_enough_adults',
                sprintf(
                    /* translators: %d is the minimum number of adults */
                    _n('This booking requires at least %d adult.', 'This booking requires at least %d adults.', $min_adults, 'hmw-events'),
                    $min_adults
                )
            );
        }

        if ($children < $min_children) {
            return new \WP_Error(
                'not_enough_children',
                sprintf(
                    /* translators: %d is the minimum number of children */
                    _n('This booking requires at least %d child.', 'This booking requires at least %d children.', $min_children, 'hmw-events'),
                    $min_children
                )
            );
        }

        return true;
    }

    public function calculate_total(int $event_id, string $option_type, array $attendees, ?array $composition_override = null): array|\WP_Error
    {
        $config = $this->resolve_configuration($event_id, $option_type);
        $composition = $composition_override ?? $config['composition'];
        $rules = $config['pricing_rules'];
        $mode = $config['price_mode'];
        $currency = $this->event_data()->get_currency($event_id);

        $composition_check = $this->validate_composition($composition, $attendees);
        if (is_wp_error($composition_check)) {
            return $composition_check;
        }

        $is_free = $this->event_data()->get_is_free($event_id);

        if ($is_free) {
            return [
                'total'        => 0.0,
                'base_amount'  => 0.0,
                'surcharge'    => 0.0,
                'currency'     => $currency,
                'per_attendee' => [],
                'mode'         => 'free',
            ];
        }

        if ($mode === self::MODE_PER_ATTENDEE) {
            return $this->calculate_per_attendee($rules, $attendees, $currency, $event_id);
        }

        if ($mode === self::MODE_AGE_BAND) {
            return $this->calculate_age_band($event_id, $rules, $attendees, $currency);
        }

        $base = (float) $config['base_price'];
        $surcharge = $this->event_data()->calculate_surcharge($event_id, $base);

        return [
            'total'        => $base + $surcharge,
            'base_amount'  => $base,
            'surcharge'    => $surcharge,
            'currency'     => $currency,
            'per_attendee' => [],
            'mode'         => self::MODE_FLAT,
        ];
    }

    public function has_age_pricing(int $event_id, string $option_type): bool
    {
        return ($this->resolve_configuration($event_id, $option_type)['price_mode'] ?? self::MODE_FLAT) === self::MODE_AGE_BAND;
    }

    public function uses_attendee_pricing(int $event_id, string $option_type): bool
    {
        $mode = $this->resolve_configuration($event_id, $option_type)['price_mode'] ?? self::MODE_FLAT;

        return $mode === self::MODE_PER_ATTENDEE || $mode === self::MODE_AGE_BAND;
    }

    public function requires_date_of_birth(int $event_id, string $option_type): bool
    {
        return $this->has_age_pricing($event_id, $option_type);
    }

    public function representative_price(array $option): float
    {
        $mode = $option['price_mode'] ?? self::MODE_FLAT;

        if ($mode === self::MODE_FLAT) {
            return (float) ($option['base_price'] ?? 0);
        }

        foreach ($option['pricing_rules'] ?? [] as $rule) {
            if (is_array($rule) && isset($rule['price']) && is_numeric($rule['price'])) {
                return (float) $rule['price'];
            }
        }

        return 0.0;
    }

    public function has_positive_price(array $option): bool
    {
        if ((float) ($option['base_price'] ?? 0) > 0) {
            return true;
        }

        foreach ($option['pricing_rules'] ?? [] as $rule) {
            if (is_array($rule) && isset($rule['price']) && (float) $rule['price'] > 0) {
                return true;
            }
        }

        return false;
    }

    public function age_at_event(?string $dob, ?string $event_date): ?int
    {
        if (empty($dob) || empty($event_date)) {
            return null;
        }

        $dob_timestamp = strtotime($dob);
        $event_timestamp = strtotime($event_date);

        if ($dob_timestamp === false || $event_timestamp === false) {
            return null;
        }

        if ($event_timestamp < $dob_timestamp) {
            return null;
        }

        $age = (int) date('Y', $event_timestamp) - (int) date('Y', $dob_timestamp);
        $month_diff = (int) date('n', $event_timestamp) - (int) date('n', $dob_timestamp);
        $day_diff = (int) date('j', $event_timestamp) - (int) date('j', $dob_timestamp);

        if ($month_diff < 0 || ($month_diff === 0 && $day_diff < 0)) {
            $age--;
        }

        return $age;
    }

    public function price_for_attendee(array $rules, string $role, ?int $age): ?float
    {
        if ($age === null) {
            return null;
        }

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $rule_role = sanitize_key((string) ($rule['role'] ?? self::ROLE_ANY)) ?: self::ROLE_ANY;

            if ($rule_role !== self::ROLE_ANY && $rule_role !== $role) {
                continue;
            }

            $min_age = (int) ($rule['min_age'] ?? 0);
            $max_age = $rule['max_age'] ?? null;

            if ($age < $min_age) {
                continue;
            }

            if ($max_age !== null && $max_age !== '' && $age > (int) $max_age) {
                continue;
            }

            return (float) ($rule['price'] ?? 0);
        }

        return null;
    }

    public function price_for_role(array $rules, string $role): ?float
    {
        $role = $role === self::ROLE_CHILD ? self::ROLE_CHILD : self::ROLE_ADULT;

        foreach ($rules as $rule) {
            if (!is_array($rule)) {
                continue;
            }

            $rule_role = sanitize_key((string) ($rule['role'] ?? self::ROLE_ANY)) ?: self::ROLE_ANY;

            if ($rule_role !== self::ROLE_ANY && $rule_role !== $role) {
                continue;
            }

            return (float) ($rule['price'] ?? 0);
        }

        return null;
    }

    public function attendee_role(array $attendee): string
    {
        $role = sanitize_key((string) ($attendee['role'] ?? self::ROLE_ADULT));

        return $role === self::ROLE_CHILD ? self::ROLE_CHILD : self::ROLE_ADULT;
    }

    public function attendee_count(array $attendees): int
    {
        return count($attendees);
    }

    private function calculate_per_attendee(array $rules, array $attendees, string $currency, int $event_id): array|\WP_Error
    {
        $base = 0.0;
        $per_attendee = [];

        foreach ($attendees as $index => $attendee) {
            $role = $this->attendee_role($attendee);
            $price = $this->price_for_role($rules, $role);

            if ($price === null) {
                /* translators: %d is the attendee number */
                return new \WP_Error(
                    'no_price_for_attendee',
                    sprintf(__('No price is configured for attendee %d.', 'hmw-events'), $index + 1)
                );
            }

            $base += $price;
            $per_attendee[] = [
                'role'  => $role,
                'age'   => null,
                'price' => $price,
            ];
        }

        $surcharge = $this->event_data()->calculate_surcharge($event_id, $base);

        return [
            'total'        => $base + $surcharge,
            'base_amount'  => $base,
            'surcharge'    => $surcharge,
            'currency'     => $currency,
            'per_attendee' => $per_attendee,
            'mode'         => self::MODE_PER_ATTENDEE,
        ];
    }

    private function calculate_age_band(int $event_id, array $rules, array $attendees, string $currency): array|\WP_Error
    {
        $event_date = $this->event_data()->get_start_date($event_id);
        if (empty($event_date)) {
            return new \WP_Error(
                'missing_event_date',
                __('The event date is not set, so attendee pricing cannot be calculated.', 'hmw-events')
            );
        }

        $base = 0.0;
        $per_attendee = [];

        foreach ($attendees as $index => $attendee) {
            $role = $this->attendee_role($attendee);
            $dob = (string) ($attendee['date_of_birth'] ?? '');

            if (trim($dob) === '') {
                /* translators: %d is the attendee number */
                return new \WP_Error(
                    'missing_date_of_birth',
                    sprintf(__('Please provide a date of birth for attendee %d.', 'hmw-events'), $index + 1)
                );
            }

            $age = $this->age_at_event($dob, $event_date);
            if ($age === null) {
                /* translators: %d is the attendee number */
                return new \WP_Error(
                    'invalid_date_of_birth',
                    sprintf(__('The date of birth for attendee %d is invalid.', 'hmw-events'), $index + 1)
                );
            }

            $price = $this->price_for_attendee($rules, $role, $age);
            if ($price === null) {
                /* translators: %d is the attendee number */
                return new \WP_Error(
                    'no_price_for_attendee',
                    sprintf(__('No price is configured for attendee %d.', 'hmw-events'), $index + 1)
                );
            }

            $base += $price;
            $per_attendee[] = [
                'role'  => $role,
                'age'   => $age,
                'price' => $price,
            ];
        }

        $surcharge = $this->event_data()->calculate_surcharge($event_id, $base);

        return [
            'total'        => $base + $surcharge,
            'base_amount'  => $base,
            'surcharge'    => $surcharge,
            'currency'     => $currency,
            'per_attendee' => $per_attendee,
            'mode'         => self::MODE_AGE_BAND,
        ];
    }

    private function effective_mode(string $db_mode, ?array $db_rules, string $default_mode, array $default_rules): string
    {
        if (in_array(sanitize_key($db_mode), self::MODES, true)) {
            return sanitize_key($db_mode);
        }

        if (!empty($db_rules)) {
            return self::MODE_AGE_BAND;
        }

        if (in_array(sanitize_key($default_mode), self::MODES, true)) {
            return sanitize_key($default_mode);
        }

        if (!empty($default_rules)) {
            return self::MODE_AGE_BAND;
        }

        return self::MODE_FLAT;
    }

    private function effective_rules(?array $db_rules, array $default_rules): array
    {
        if (!empty($db_rules)) {
            return $db_rules;
        }

        return $default_rules;
    }

    private function event_defaults_attendance_options(int $event_id): array
    {
        $override = get_post_meta($event_id, '_event_template_override', true);
        if (is_array($override) && isset($override['attendance_options']) && is_array($override['attendance_options'])) {
            return $override['attendance_options'];
        }

        $defaults = get_post_meta($event_id, '_event_default_values', true);
        if (is_array($defaults) && isset($defaults['attendance_options']) && is_array($defaults['attendance_options'])) {
            return $defaults['attendance_options'];
        }

        return [];
    }

    private function find_default(array $defaults, string $option_type): ?array
    {
        foreach ($defaults as $default) {
            if (!is_array($default)) {
                continue;
            }

            if ((sanitize_key((string) ($default['option_type'] ?? '')) ?: 'individual') === $option_type) {
                return $default;
            }
        }

        return null;
    }

    private function multi_booking_max(int $event_id): int
    {
        $config = FormConfigResolver::resolve($event_id);
        $max = (int) ($config['multi_booking']['max'] ?? self::DEFAULT_MAX_CHILDREN);

        return max(1, $max);
    }
}
