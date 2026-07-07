<?php

/**
 * Registrant Custom Post Type.
 *
 * Stores individuals who register for events. Replaces the legacy
 * edu_customer CPT. Not publicly queryable — accessed via admin UI only.
 *
 * @package HMWEvents\PostTypes
 * @since 2.0.0
 */

namespace HMWEvents\PostTypes;

defined('ABSPATH') || die('Don\'t run this file directly!');

class Registrant
{
    public const POST_TYPE = 'hmw_registrant';

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'grant_admin_capabilities'], 11);
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'                  => _x('Registrants', 'Post type general name', 'hmw-events'),
            'singular_name'         => _x('Registrant', 'Post type singular name', 'hmw-events'),
            'menu_name'             => _x('Registrants', 'Admin Menu text', 'hmw-events'),
            'name_admin_bar'        => _x('Registrant', 'Add New on Toolbar', 'hmw-events'),
            'add_new'               => __('Add New', 'hmw-events'),
            'add_new_item'          => __('Add New Registrant', 'hmw-events'),
            'new_item'              => __('New Registrant', 'hmw-events'),
            'edit_item'             => __('Edit Registrant', 'hmw-events'),
            'view_item'             => __('View Registrant', 'hmw-events'),
            'all_items'             => __('All Registrants', 'hmw-events'),
            'search_items'          => __('Search Registrants', 'hmw-events'),
            'parent_item_colon'     => __('Parent Registrants:', 'hmw-events'),
            'not_found'             => __('No registrants found.', 'hmw-events'),
            'not_found_in_trash'    => __('No registrants found in Trash.', 'hmw-events'),
            'filter_items_list'     => _x('Filter registrants list', 'Screen reader text', 'hmw-events'),
            'items_list_navigation' => _x('Registrants list navigation', 'Screen reader text', 'hmw-events'),
            'items_list'            => _x('Registrants list', 'Screen reader text', 'hmw-events'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => true,
            'show_in_rest'        => true,
            'query_var'           => false,
            'capability_type'     => ['hmw_registrant', 'hmw_registrants'],
            'map_meta_cap'        => true,
            'has_archive'         => false,
            'hierarchical'        => false,
            'menu_position'       => 21,
            'menu_icon'           => 'dashicons-groups',
            'supports'            => ['title', 'editor'],
            'delete_with_user'    => false,
        ];

        register_post_type(self::POST_TYPE, $args);
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
            'edit_hmw_registrant',
            'read_hmw_registrant',
            'delete_hmw_registrant',
            'edit_hmw_registrants',
            'edit_others_hmw_registrants',
            'delete_hmw_registrants',
            'delete_others_hmw_registrants',
            'read_private_hmw_registrants',
            'edit_private_hmw_registrants',
            'delete_private_hmw_registrants',
        ];

        foreach ($capabilities as $cap) {
            $admin->add_cap($cap);
        }
    }
}
