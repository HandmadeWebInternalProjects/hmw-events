<?php

/**
 * Event Type Registry.
 *
 * Defines the behaviour, field visibility, requiredness, workflows,
 * and communication template keys for each event type archetype.
 *
 * 7 required archetypes:
 *   webinar, workshop, course, seminar, conference, parent-education, professional-dev
 *
 * @package HMWEvents\Registry
 * @since 2.0.0
 */

namespace HMWEvents\Registry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventTypeRegistry
{
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
                'draft'         => ['publish', 'by_invitation', 'trash'],
                'publish'       => ['fully_booked', 'cancelled', 'draft'],
                'fully_booked'  => ['publish', 'cancelled'],
                'cancelled'     => ['publish', 'archived'],
                'by_invitation' => ['publish', 'cancelled', 'archived'],
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
            // 1. Webinar
            // ─────────────────────────────────────────────
            'webinar' => array_replace_recursive($defaults, [
                'hidden_fields' => [
                    'event_venue_name',
                    'event_venue_address',
                    'event_venue_capacity',
                    'event_venue_room',
                    'event_catering',
                ],
                'required_fields' => ['event_start_date', 'event_end_date', 'event_webinar_url'],
                'attendance_option_presets' => [
                    ['label' => 'Individual', 'price' => 0, 'option_type' => 'individual'],
                ],
                'default_meta' => [
                    'event_delivery_mode' => 'online',
                    'event_capacity'      => 500,
                ],
            ]),

            // ─────────────────────────────────────────────
            // 2. Workshop
            // ─────────────────────────────────────────────
            'workshop' => array_replace_recursive($defaults, [
                'hidden_fields' => [
                    'event_webinar_url',
                ],
                'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_address'],
                'attendance_option_presets' => [
                    ['label' => 'Individual',       'price' => 0,    'option_type' => 'individual'],
                    ['label' => 'Parent + Child',   'price' => 0,    'option_type' => 'parent_child'],
                    ['label' => 'Couple',           'price' => 0,    'option_type' => 'couple'],
                ],
                'default_meta' => [
                    'event_capacity' => 30,
                ],
            ]),

            // ─────────────────────────────────────────────
            // 3. Course
            // ─────────────────────────────────────────────
            'course' => array_replace_recursive($defaults, [
                'hidden_fields' => [
                    'event_webinar_url',
                ],
                'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_address'],
                'attendance_option_presets' => [
                    ['label' => 'Individual',     'price' => 350, 'option_type' => 'individual'],
                    ['label' => 'Couple',         'price' => 650, 'option_type' => 'couple'],
                    ['label' => 'Professional',   'price' => 450, 'option_type' => 'professional'],
                ],
                'default_meta' => [
                    'event_capacity'     => 12,
                    'event_is_recurring' => 1,
                ],
            ]),

            // ─────────────────────────────────────────────
            // 4. Seminar
            // ─────────────────────────────────────────────
            'seminar' => array_replace_recursive($defaults, [
                'hidden_fields' => [
                    'event_webinar_url',
                ],
                'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_address'],
                'attendance_option_presets' => [
                    ['label' => 'Individual',  'price' => 0,   'option_type' => 'individual'],
                    ['label' => 'Professional', 'price' => 0,  'option_type' => 'professional'],
                ],
                'default_meta' => [
                    'event_capacity' => 100,
                ],
            ]),

            // ─────────────────────────────────────────────
            // 5. Conference
            // ─────────────────────────────────────────────
            'conference' => array_replace_recursive($defaults, [
                'hidden_fields' => [],
                'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_name', 'event_venue_address'],
                'attendance_option_presets' => [
                    ['label' => 'Individual',     'price' => 200, 'option_type' => 'individual'],
                    ['label' => 'Professional',   'price' => 350, 'option_type' => 'professional'],
                    ['label' => 'Student',        'price' => 100, 'option_type' => 'individual'],
                ],
                'default_meta' => [
                    'event_capacity' => 500,
                ],
            ]),

            // ─────────────────────────────────────────────
            // 6. Parent Education
            // ─────────────────────────────────────────────
            'parent-education' => array_replace_recursive($defaults, [
                'hidden_fields' => [
                    'event_webinar_url',
                ],
                'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_address'],
                'attendance_option_presets' => [
                    ['label' => 'Parent',         'price' => 50,  'option_type' => 'parent'],
                    ['label' => 'Parent + Child', 'price' => 75,  'option_type' => 'parent_child'],
                    ['label' => 'Couple',         'price' => 90,  'option_type' => 'couple'],
                ],
                'default_meta' => [
                    'event_capacity' => 20,
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
            // 7. Professional Development
            // ─────────────────────────────────────────────
            'professional-dev' => array_replace_recursive($defaults, [
                'hidden_fields' => [],
                'required_fields' => ['event_start_date', 'event_end_date', 'event_venue_address'],
                'attendance_option_presets' => [
                    ['label' => 'Professional',   'price' => 250, 'option_type' => 'professional'],
                    ['label' => 'Early Bird',     'price' => 200, 'option_type' => 'professional'],
                    ['label' => 'Group (3+)',     'price' => 500, 'option_type' => 'professional'],
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
}
