<?php

namespace HMWEvents\PostTypes;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventLocation
{
    public const POST_TYPE = 'event_location';

    public const META_ADDRESS = '_venue_address';

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'grant_admin_capabilities'], 11);
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'                  => _x('Venues', 'Post type general name', 'hmw-events'),
            'singular_name'         => _x('Venue', 'Post type singular name', 'hmw-events'),
            'menu_name'             => _x('Venues', 'Admin Menu text', 'hmw-events'),
            'name_admin_bar'        => _x('Venue', 'Add New on Toolbar', 'hmw-events'),
            'add_new'               => __('Add New', 'hmw-events'),
            'add_new_item'          => __('Add New Venue', 'hmw-events'),
            'new_item'              => __('New Venue', 'hmw-events'),
            'edit_item'             => __('Edit Venue', 'hmw-events'),
            'view_item'             => __('View Venue', 'hmw-events'),
            'all_items'             => __('All Venues', 'hmw-events'),
            'search_items'          => __('Search Venues', 'hmw-events'),
            'parent_item_colon'     => __('Parent Venues:', 'hmw-events'),
            'not_found'             => __('No venues found.', 'hmw-events'),
            'not_found_in_trash'    => __('No venues found in Trash.', 'hmw-events'),
            'filter_items_list'     => _x('Filter venues list', 'Screen reader text', 'hmw-events'),
            'items_list_navigation' => _x('Venues list navigation', 'Screen reader text', 'hmw-events'),
            'items_list'            => _x('Venues list', 'Screen reader text', 'hmw-events'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => 'edit.php?post_type=hmw_event',
            'show_in_rest'        => true,
            'query_var'           => false,
            'rewrite'             => false,
            'capability_type'     => ['event_location', 'event_locations'],
            'map_meta_cap'        => true,
            'has_archive'         => false,
            'hierarchical'        => false,
            'supports'            => ['title'],
            'delete_with_user'    => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    public function grant_admin_capabilities(): void
    {
        $admin = get_role('administrator');
        if (!$admin) {
            return;
        }

        $capabilities = [
            'edit_event_location',
            'read_event_location',
            'delete_event_location',
            'edit_event_locations',
            'edit_others_event_locations',
            'delete_event_locations',
            'delete_others_event_locations',
            'read_private_event_locations',
            'edit_private_event_locations',
            'delete_private_event_locations',
            'publish_event_locations',
        ];

        foreach ($capabilities as $cap) {
            $admin->add_cap($cap);
        }
    }
}
