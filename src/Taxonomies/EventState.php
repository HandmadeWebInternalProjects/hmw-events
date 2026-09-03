<?php

/**
 * Event State Taxonomy.
 *
 * Non-hierarchical taxonomy for Australian states/territories,
 * used to filter events by location.
 *
 * @package HMWEvents\Taxonomies
 * @since 2.0.0
 */

namespace HMWEvents\Taxonomies;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventState
{
    public const TAXONOMY = 'hmw_event_state';

    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomy']);
        add_action('init', [$this, 'populate_states'], 20);
    }

    public function register_taxonomy(): void
    {
        $labels = [
            'name'          => __('States', 'hmw-events'),
            'singular_name' => __('State', 'hmw-events'),
            'search_items'  => __('Search States', 'hmw-events'),
            'all_items'     => __('All States', 'hmw-events'),
            'edit_item'     => __('Edit State', 'hmw-events'),
            'update_item'   => __('Update State', 'hmw-events'),
            'add_new_item'  => __('Add New State', 'hmw-events'),
            'new_item_name' => __('New State Name', 'hmw-events'),
            'menu_name'     => __('States', 'hmw-events'),
        ];

        register_taxonomy(self::TAXONOMY, ['hmw_event'], [
            'labels'            => $labels,
            'hierarchical'      => true,
            'public'            => true,
            'publicly_queryable' => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_rest'      => true,
            'show_admin_column' => true,
            'query_var'         => true,
            'rewrite'           => ['slug' => 'event-state', 'with_front' => false],
            'capabilities'      => [
                'manage_terms' => 'manage_categories',
                'edit_terms'   => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ],
            'meta_box_cb'       => false,
        ]);
    }

    /**
     * Pre-populate Australian states and territories.
     */
    public function populate_states(): void
    {
        $states = [
            'nsw' => 'New South Wales',
            'vic' => 'Victoria',
            'qld' => 'Queensland',
            'wa'  => 'Western Australia',
            'sa'  => 'South Australia',
            'tas' => 'Tasmania',
            'act' => 'Australian Capital Territory',
            'nt'  => 'Northern Territory',
        ];

        foreach ($states as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
            }
        }
    }
}
