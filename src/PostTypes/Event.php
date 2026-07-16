<?php

/**
 * Event Custom Post Type.
 *
 * Registers the hmw_event CPT which is the core domain object of the
 * HMW Events system. Replaces the legacy educator_course CPT.
 *
 * Custom statuses: Draft, Published, Fully Booked, Cancelled, Archived, By Invitation
 *
 * @package HMWEvents\PostTypes
 * @since 2.0.0
 */

namespace HMWEvents\PostTypes;

defined('ABSPATH') || die('Don\'t run this file directly!');

class Event
{
    public const POST_TYPE = 'hmw_event';

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_statuses']);
        add_action('init', [$this, 'grant_admin_capabilities']);
        add_filter('display_post_states', [$this, 'display_custom_states'], 10, 2);
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'                  => _x('Events', 'Post type general name', 'hmw-events'),
            'singular_name'         => _x('Event', 'Post type singular name', 'hmw-events'),
            'menu_name'             => _x('Events', 'Admin Menu text', 'hmw-events'),
            'name_admin_bar'        => _x('Event', 'Add New on Toolbar', 'hmw-events'),
            'add_new'               => __('Add New', 'hmw-events'),
            'add_new_item'          => __('Add New Event', 'hmw-events'),
            'new_item'              => __('New Event', 'hmw-events'),
            'edit_item'             => __('Edit Event', 'hmw-events'),
            'view_item'             => __('View Event', 'hmw-events'),
            'all_items'             => __('All Events', 'hmw-events'),
            'search_items'          => __('Search Events', 'hmw-events'),
            'parent_item_colon'     => __('Parent Events:', 'hmw-events'),
            'not_found'             => __('No events found.', 'hmw-events'),
            'not_found_in_trash'    => __('No events found in Trash.', 'hmw-events'),
            'filter_items_list'     => _x('Filter events list', 'Screen reader text', 'hmw-events'),
            'items_list_navigation' => _x('Events list navigation', 'Screen reader text', 'hmw-events'),
            'items_list'            => _x('Events list', 'Screen reader text', 'hmw-events'),
            'archives'              => _x('Event archives', 'Post type archive label', 'hmw-events'),
            'attributes'            => _x('Event Attributes', 'Post type attributes label', 'hmw-events'),
            'item_published'        => __('Event published.', 'hmw-events'),
            'item_updated'          => __('Event updated.', 'hmw-events'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => true,
            'publicly_queryable'  => true,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_nav_menus'   => true,
            'show_in_rest'        => true,
            'query_var'           => true,
            'rewrite'             => ['slug' => 'events', 'with_front' => false],
            'capability_type'     => ['hmw_event', 'hmw_events'],
            'map_meta_cap'        => true,
            'has_archive'         => true,
            'hierarchical'        => true,
            'menu_position'       => 20,
            'menu_icon'           => 'dashicons-calendar-alt',
            'supports'            => ['title', 'editor', 'author', 'thumbnail', 'excerpt', 'custom-fields', 'page-attributes'],
            'taxonomies'          => ['category', 'post_tag'],
            'delete_with_user'    => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Register custom post statuses.
     */
    public function register_post_statuses(): void
    {
        register_post_status('fully_booked', [
            'label'                     => _x('Fully Booked', 'post status', 'hmw-events'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Fully Booked <span class="count">(%s)</span>',
                'Fully Booked <span class="count">(%s)</span>',
                'hmw-events'
            ),
        ]);

        register_post_status('cancelled', [
            'label'                     => _x('Cancelled', 'post status', 'hmw-events'),
            'public'                    => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Cancelled <span class="count">(%s)</span>',
                'Cancelled <span class="count">(%s)</span>',
                'hmw-events'
            ),
        ]);

        register_post_status('archived', [
            'label'                     => _x('Archived', 'post status', 'hmw-events'),
            'public'                    => false,
            'exclude_from_search'       => true,
            'publicly_queryable'        => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Archived <span class="count">(%s)</span>',
                'Archived <span class="count">(%s)</span>',
                'hmw-events'
            ),
        ]);

        register_post_status('by_invitation', [
            'label'                     => _x('By Invitation', 'post status', 'hmw-events'),
            'public'                    => true,
            'exclude_from_search'       => false,
            'publicly_queryable'        => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'By Invitation <span class="count">(%s)</span>',
                'By Invitation <span class="count">(%s)</span>',
                'hmw-events'
            ),
        ]);
    }

    /**
     * Display custom post states in the admin list.
     */
    public function display_custom_states(array $post_states, \WP_Post $post): array
    {
        if ($post->post_type !== self::POST_TYPE) {
            return $post_states;
        }

        $status_labels = [
            'fully_booked'  => __('Fully Booked', 'hmw-events'),
            'cancelled'     => __('Cancelled', 'hmw-events'),
            'archived'      => __('Archived', 'hmw-events'),
            'by_invitation' => __('By Invitation', 'hmw-events'),
        ];

        if (isset($status_labels[$post->post_status])) {
            $post_states[$post->post_status] = $status_labels[$post->post_status];
        }

        return $post_states;
    }

    /**
     * Grant custom capabilities to the admin role.
     */
    public function grant_admin_capabilities(): void
    {
        $admin = get_role('administrator');
        if (!$admin) {
            return;
        }

        $capabilities = [
            'edit_hmw_event',
            'read_hmw_event',
            'delete_hmw_event',
            'edit_hmw_events',
            'edit_others_hmw_events',
            'publish_hmw_events',
            'read_private_hmw_events',
            'delete_hmw_events',
            'delete_private_hmw_events',
            'delete_published_hmw_events',
            'delete_others_hmw_events',
            'edit_private_hmw_events',
            'edit_published_hmw_events',
        ];

        foreach ($capabilities as $cap) {
            $admin->add_cap($cap);
        }
    }
}
