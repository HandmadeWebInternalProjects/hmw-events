<?php

/**
 * Event Type Registry.
 *
 * Defines the behaviour, field visibility, requiredness, workflows,
 * and communication template keys for each event type archetype.
 *
 * 7 required archetypes:
 *   parenting-webinar, professional-webinar, parent-one-off-free,
 *   parent-walk-in, parent-course, professional-online, professional-in-person
 *
 * @package HMWEvents\Registry
 * @since 2.0.0
 */

namespace HMWEvents\Registry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventTypeRegistry
{
    public const RECURRENCE_FIELD_KEYS = [
        'event_is_recurring',
        'event_recurrence_interval',
        'event_recurrence_unit',
        'event_recurrence_days',
        'event_recurrence_end_type',
        'event_recurrence_end_date',
        'event_recurrence_max_occurrences',
        'event_recurrence_custom_dates',
    ];

    /**
     * Cached archetype definitions.
     *
     * @var array<string, array>|null
     */
    private static ?array $archetypes = null;

    /**
     * Get the configuration for a specific event type slug.
     *
     * Returns defaults merged with any custom overrides.
     */
    public static function get(string $type_slug): array
    {
        $archetypes = self::all();
        return $archetypes[$type_slug] ?? self::defaults();
    }

    /**
     * Get all registered archetype configurations.
     *
     * @return array<string, array>
     */
    public static function all(): array
    {
        if (self::$archetypes !== null) {
            return self::$archetypes;
        }

        self::$archetypes = self::build();
        return self::$archetypes;
    }

    /**
     * Check if a given field should be visible for an event type.
     */
    public static function is_field_visible(string $type_slug, string $field_key): bool
    {
        $config = self::get($type_slug);
        $hidden = $config['hidden_fields'] ?? [];
        return !in_array($field_key, $hidden, true);
    }

    /**
     * Check if a given field is required for an event type.
     */
    public static function is_field_required(string $type_slug, string $field_key): bool
    {
        $config = self::get($type_slug);
        $required = $config['required_fields'] ?? [];
        return in_array($field_key, $required, true);
    }

    /**
     * Get the communication template keys for a given event type and trigger.
     */
    public static function get_comm_template(string $type_slug, string $trigger): ?string
    {
        $config = self::get($type_slug);
        return $config['comm_templates'][$trigger] ?? null;
    }

    /**
     * Get the workflow (allowed status transitions) for an event type.
     *
     * @return array<string, string[]> Map of status => allowed next statuses
     */
    public static function get_workflow(string $type_slug): array
    {
        $config = self::get($type_slug);
        return $config['workflow'] ?? self::defaults()['workflow'];
    }

    /**
     * Get available attendance option presets for an event type.
     */
    public static function get_attendance_option_presets(string $type_slug): array
    {
        $config = self::get($type_slug);
        return $config['attendance_option_presets'] ?? [];
    }

    /**
     * Get default meta values for a new event of this type.
     */
    public static function get_default_meta(string $type_slug): array
    {
        $config = self::get($type_slug);
        return $config['default_meta'] ?? [];
    }

    /**
     * Get the default configuration (fallback for unknown types).
     */
    public static function defaults(): array
    {
        return [
            'hidden_fields'              => [],
            'required_fields'            => ['event_start_date', 'event_end_date'],
            'default_meta'               => [
                'event_capacity' => 20,
            ],
            'attendance_option_presets'  => [
                ['label' => 'Individual', 'price' => 0, 'option_type' => 'individual'],
            ],
            'workflow'                   => [
                'draft'         => ['publish', 'trash'],
                'publish'       => ['fully_booked', 'cancelled', 'draft'],
                'fully_booked'  => ['publish', 'cancelled'],
                'cancelled'     => ['publish', 'archived'],
                'archived'      => ['publish'],
            ],
            'comm_templates'             => [
                'booking_confirmed'  => 'booking_confirmed',
                'booking_cancelled'  => 'booking_cancelled',
                'reminder_7_days'    => 'reminder_7_days',
                'reminder_1_day'     => 'reminder_1_day',
                'post_event'         => 'post_event',
                'waitlist_promotion' => 'waitlist_promotion',
            ],
            'show_child_sessions'        => false,
        ];
    }

/**
 * Build the complete archetype registry.
 */
private static function build(): array
{
    $defaults = self::defaults();

    return [

        // ─────────────────────────────────────────────
        // 1. Parenting Webinar (free, external reg)
        // ─────────────────────────────────────────────
        'parenting-webinar' => array_replace_recursive($defaults, [
            'hidden_fields' => [
                'event_venue_name',
                'event_venue_address',
                'event_price',
                'event_deposit',
                'event_surcharge',
                'event_max_per_registrant',
                'event_allow_net_terms',
            ],
            'required_fields' => ['event_start_date', 'event_end_date', 'event_webinar_url'],
            'attendance_option_presets' => [
                ['label' => 'Individual', 'price' => 0, 'option_type' => 'individual'],
            ],
            'default_meta' => [
                'event_delivery_mode'    => 'online',
                'event_capacity'         => 500,
                'event_is_free'          => 1,
            ],
            'external_registration' => true,
            'comm_templates' => [
                'booking_confirmed'  => 'parent_booking_confirmed',
                'booking_cancelled'  => 'parent_booking_cancelled',
                'reminder_7_days'    => 'parent_reminder_7_days',
                'reminder_1_day'     => 'parent_reminder_1_day',
                'post_event'         => 'parent_post_event',
                'waitlist_promotion' => 'parent_waitlist_promotion',
            ],
        ]),

        // ─────────────────────────────────────────────
        // 2. Professional Webinar (free, external reg)
        // ─────────────────────────────────────────────
        'professional-webinar' => array_replace_recursive($defaults, [
            'hidden_fields' => [
                'event_venue_name',
                'event_venue_address',
                'event_price',
                'event_deposit',
                'event_surcharge',
                'event_max_per_registrant',
                'event_allow_net_terms',
            ],
            'required_fields' => ['event_start_date', 'event_end_date', 'event_webinar_url'],
            'attendance_option_presets' => [
                ['label' => 'Individual', 'price' => 0, 'option_type' => 'individual'],
            ],
            'default_meta' => [
                'event_delivery_mode'    => 'online',
                'event_capacity'         => 500,
                'event_is_free'          => 1,
            ],
            'external_registration' => true,
            'comm_templates' => [
                'booking_confirmed'  => 'prodev_booking_confirmed',
                'booking_cancelled'  => 'prodev_booking_cancelled',
                'reminder_7_days'    => 'prodev_reminder_7_days',
                'reminder_1_day'     => 'prodev_reminder_1_day',
                'post_event'         => 'prodev_certificate',
                'waitlist_promotion' => 'prodev_waitlist_promotion',
            ],
        ]),

        // ─────────────────────────────────────────────
        // 3. Parent One-Off Free Event
        // ─────────────────────────────────────────────
        'parent-one-off-free' => array_replace_recursive($defaults, [
            'hidden_fields' => [
                'event_webinar_url',
                'event_price',
                'event_deposit',
                'event_surcharge',
                'event_max_per_registrant',
                'event_allow_net_terms',
            ],
            'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_address'],
            'attendance_option_presets' => [
                ['label' => 'Parent',         'price' => 0,    'option_type' => 'parent'],
                ['label' => 'Parent + Child', 'price' => 0,    'option_type' => 'parent_child'],
                ['label' => 'Couple',         'price' => 0,    'option_type' => 'couple'],
            ],
            'default_meta' => [
                'event_capacity' => 30,
                'event_is_free'  => 1,
            ],
            'comm_templates' => [
                'booking_confirmed'  => 'parent_booking_confirmed',
                'booking_cancelled'  => 'parent_booking_cancelled',
                'reminder_7_days'    => 'parent_reminder_7_days',
                'reminder_1_day'     => 'parent_reminder_1_day',
                'post_event'         => 'parent_post_event',
                'waitlist_promotion' => 'parent_waitlist_promotion',
            ],
        ]),

        // ─────────────────────────────────────────────
        // 4. Parent Recurring Walk-In Event
        // ─────────────────────────────────────────────
        'parent-walk-in' => array_replace_recursive($defaults, [
            'hidden_fields' => [
                'event_webinar_url',
                'event_capacity',
                'event_price',
                'event_deposit',
                'event_surcharge',
                'event_max_per_registrant',
                'event_allow_net_terms',
                'event_is_free',
            ],
            'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_address'],
            'attendance_option_presets' => [
                ['label' => 'Walk-In', 'price' => 0, 'option_type' => 'individual'],
            ],
            'default_meta' => [
                'event_is_recurring' => 1,
            ],
            'registration_disabled' => true,
            'show_child_sessions'  => true,
            'comm_templates' => [
                'booking_confirmed'  => 'parent_booking_confirmed',
                'booking_cancelled'  => 'parent_booking_cancelled',
                'reminder_7_days'    => 'parent_reminder_7_days',
                'reminder_1_day'     => 'parent_reminder_1_day',
                'post_event'         => 'parent_post_event',
                'waitlist_promotion' => 'parent_waitlist_promotion',
            ],
        ]),

        // ─────────────────────────────────────────────
        // 5. Parent Multi-Week Commitment Course
        // ─────────────────────────────────────────────
        'parent-course' => array_replace_recursive($defaults, [
            'hidden_fields' => [
                'event_webinar_url',
            ],
            'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_address'],
            'attendance_option_presets' => [
                ['label' => 'Parent',         'price' => 350, 'option_type' => 'parent'],
                ['label' => 'Parent + Child', 'price' => 450, 'option_type' => 'parent_child'],
                ['label' => 'Couple',         'price' => 650, 'option_type' => 'couple'],
            ],
            'default_meta' => [
                'event_capacity'     => 12,
                'event_is_recurring' => 1,
            ],
            'comm_templates' => [
                'booking_confirmed'  => 'parent_booking_confirmed',
                'booking_cancelled'  => 'parent_booking_cancelled',
                'reminder_7_days'    => 'parent_reminder_7_days',
                'reminder_1_day'     => 'parent_reminder_1_day',
                'post_event'         => 'parent_post_event',
                'waitlist_promotion' => 'parent_waitlist_promotion',
            ],
        ]),

        // ─────────────────────────────────────────────
        // 6. Professional Paid Online Event
        // ─────────────────────────────────────────────
        'professional-online' => array_replace_recursive($defaults, [
            'hidden_fields' => [
                'event_venue_name',
                'event_venue_address',
            ],
            'required_fields' => ['event_start_date', 'event_end_date'],
            'attendance_option_presets' => [
                ['label' => 'Professional',   'price' => 250, 'option_type' => 'professional'],
                ['label' => 'Early Bird',     'price' => 200, 'option_type' => 'professional'],
                ['label' => 'Group (3+)',     'price' => 600, 'option_type' => 'professional'],
            ],
            'default_meta' => [
                'event_delivery_mode' => 'online',
                'event_capacity'      => 100,
            ],
            'comm_templates' => [
                'booking_confirmed'  => 'prodev_booking_confirmed',
                'booking_cancelled'  => 'prodev_booking_cancelled',
                'reminder_7_days'    => 'prodev_reminder_7_days',
                'reminder_1_day'     => 'prodev_reminder_1_day',
                'post_event'         => 'prodev_certificate',
                'waitlist_promotion' => 'prodev_waitlist_promotion',
            ],
        ]),

        // ─────────────────────────────────────────────
        // 7. Professional Paid In-Person Event
        // ─────────────────────────────────────────────
        'professional-in-person' => array_replace_recursive($defaults, [
            'hidden_fields' => [
                'event_webinar_url',
            ],
            'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_name', 'event_venue_address'],
            'attendance_option_presets' => [
                ['label' => 'Professional',   'price' => 250, 'option_type' => 'professional'],
                ['label' => 'Early Bird',     'price' => 200, 'option_type' => 'professional'],
                ['label' => 'Group (3+)',     'price' => 600, 'option_type' => 'professional'],
            ],
            'default_meta' => [
                'event_capacity' => 50,
            ],
            'comm_templates' => [
                'booking_confirmed'  => 'prodev_booking_confirmed',
                'booking_cancelled'  => 'prodev_booking_cancelled',
                'reminder_7_days'    => 'prodev_reminder_7_days',
                'reminder_1_day'     => 'prodev_reminder_1_day',
                'post_event'         => 'prodev_certificate',
                'waitlist_promotion' => 'prodev_waitlist_promotion',
            ],
        ]),
    ];
}

/**
 * Check if an event type uses external registration (bypasses internal booking form).
 */
public static function is_external_registration(string $type_slug): bool
{
    $config = self::get($type_slug);
    return (bool) ($config['external_registration'] ?? false);
}

/**
 * Check if an event type has public registration disabled (manual bookings only).
 */
    public static function is_registration_disabled(string $type_slug): bool
    {
        $config = self::get($type_slug);
        return (bool) ($config['registration_disabled'] ?? false);
    }

    /**
     * Check if child sessions should appear in public event listings for an event type.
     */
    public static function should_show_child_sessions(string $type_slug): bool
    {
        $config = self::get($type_slug);
        return (bool) ($config['show_child_sessions'] ?? false);
    }

/**
 * Build a type-slug => hidden-fields mapping for all archetypes.
 *
 * @return array<string, string[]>
 */
public static function get_hidden_fields_map(): array
{
    $map = [];
    foreach (self::all() as $slug => $config) {
        $map[$slug] = array_values((array) ($config['hidden_fields'] ?? []));
    }

    return $map;
}

/**
 * Collect every unique hideable field across all archetypes.
 *
 * @return string[]
 */
public static function get_all_hideable_fields(): array
{
    $all = [];
    foreach (self::all() as $config) {
        foreach ($config['hidden_fields'] ?? [] as $field) {
            if (is_string($field) && $field !== '') {
                $all[] = $field;
            }
        }
    }

    return array_values(array_unique($all));
}
}
