<?php

/**
 * Event Organizer User Role.
 *
 * Replaces the legacy Educator role. Event Organizers can:
 * - Create, edit, publish, and delete their own events
 * - View and manage registrants for their events
 * - Manage coupons
 * - View reports and payment data for their events
 *
 * @package HMWEvents\Roles
 * @since 2.0.0
 */

namespace HMWEvents\Roles;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventOrganizerRole
{
    public const ROLE = 'event_organizer';

    public function __construct()
    {
        add_action('init', [$this, 'register_role']);
        add_action('init', [$this, 'add_capabilities']);
    }

    /**
     * Register the Event Organizer role.
     */
    public function register_role(): void
    {
        if (get_role(self::ROLE)) {
            return;
        }

        add_role(
            self::ROLE,
            __('Event Organizer', 'hmw-events'),
            [
                'read'         => true,
                'edit_posts'   => true,
                'upload_files' => true,
                'delete_posts' => true,
                'level_0'      => true,
            ]
        );
    }

    /**
     * Add custom capabilities to the Event Organizer role.
     */
    public function add_capabilities(): void
    {
        $role = get_role(self::ROLE);
        if (!$role) {
            return;
        }

        // Event capabilities
        $event_caps = [
            'edit_hmw_event',
            'read_hmw_event',
            'delete_hmw_event',
            'edit_hmw_events',
            'edit_published_hmw_events',
            'publish_hmw_events',
            'read_private_hmw_events',
            'delete_hmw_events',
            'delete_published_hmw_events',
            'edit_private_hmw_events',
            'edit_published_hmw_events',
        ];

        // Registrant capabilities (view/manage own)
        $registrant_caps = [
            'read_hmw_registrant',
            'edit_hmw_registrant',
            'edit_hmw_registrants',
            'read_private_hmw_registrants',
            'edit_private_hmw_registrants',
        ];

        // Coupon capabilities
        $coupon_caps = [
            'read_hmw_coupon',
            'edit_hmw_coupon',
            'edit_hmw_coupons',
            'edit_published_hmw_coupons',
            'publish_hmw_coupons',
            'delete_hmw_coupon',
            'delete_hmw_coupons',
            'delete_published_hmw_coupons',
        ];

        // Venue capabilities
        $venue_caps = [
            'read_event_location',
            'edit_event_location',
            'edit_event_locations',
            'edit_others_event_locations',
            'edit_published_event_locations',
            'publish_event_locations',
            'delete_event_location',
            'delete_event_locations',
            'delete_others_event_locations',
            'delete_published_event_locations',
        ];

        foreach (array_merge($event_caps, $registrant_caps, $coupon_caps, $venue_caps) as $cap) {
            $role->add_cap($cap);
        }
    }

    /**
     * Remove custom capabilities from the Event Organizer role.
     * Called on plugin deactivation.
     */
    public function remove_capabilities(): void
    {
        $role = get_role(self::ROLE);
        if (!$role) {
            return;
        }

        $caps = [
            'edit_hmw_event',
            'read_hmw_event',
            'delete_hmw_event',
            'edit_hmw_events',
            'edit_published_hmw_events',
            'publish_hmw_events',
            'read_private_hmw_events',
            'delete_hmw_events',
            'delete_published_hmw_events',
            'edit_private_hmw_events',
            'edit_published_hmw_events',
            'read_hmw_registrant',
            'edit_hmw_registrant',
            'edit_hmw_registrants',
            'read_private_hmw_registrants',
            'edit_private_hmw_registrants',
            'read_hmw_coupon',
            'edit_hmw_coupon',
            'edit_hmw_coupons',
            'edit_published_hmw_coupons',
            'publish_hmw_coupons',
            'delete_hmw_coupon',
            'delete_hmw_coupons',
            'delete_published_hmw_coupons',
            'read_event_location',
            'edit_event_location',
            'edit_event_locations',
            'edit_others_event_locations',
            'edit_published_event_locations',
            'publish_event_locations',
            'delete_event_location',
            'delete_event_locations',
            'delete_others_event_locations',
            'delete_published_event_locations',
        ];

        foreach ($caps as $cap) {
            $role->remove_cap($cap);
        }
    }
}
