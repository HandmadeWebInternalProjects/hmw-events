<?php

namespace HMWEvents\Taxonomies;

defined('ABSPATH') || die('Don\'t run this file directly!');

class CourseState
{
    public const TAXONOMY = 'course_state';

    public function register()
    {
        add_action('init', [$this, 'register_taxonomy']);
    }

    public function register_taxonomy()
    {
        $labels = [
            'name' => __('States', 'hmw-events'),
            'singular_name' => __('State', 'hmw-events'),
            'search_items' => __('Search States', 'hmw-events'),
            'all_items' => __('All States', 'hmw-events'),
            'edit_item' => __('Edit State', 'hmw-events'),
            'update_item' => __('Update State', 'hmw-events'),
            'add_new_item' => __('Add New State', 'hmw-events'),
            'new_item_name' => __('New State Name', 'hmw-events'),
            'menu_name' => __('States', 'hmw-events'),
        ];

        register_taxonomy(self::TAXONOMY, ['educator_course'], [
            'labels' => $labels,
            'hierarchical' => false,
            'show_ui' => true,
            'show_admin_column' => false,
            'query_var' => true,
            'rewrite' => ['slug' => 'course_state'],
            'show_in_rest' => true,
            'meta_box_cb' => false, // Radio buttons instead of checkboxes
        ]);
    }

    /**
     * Pre-populate Australian states on activation
     */
    public static function populate_states()
    {
        $states = [
            'NSW' => 'New South Wales',
            'VIC' => 'Victoria',
            'QLD' => 'Queensland',
            'WA' => 'Western Australia',
            'SA' => 'South Australia',
            'TAS' => 'Tasmania',
            'ACT' => 'Australian Capital Territory',
            'NT' => 'Northern Territory',
        ];

        foreach ($states as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => strtolower($slug)]);
            }
        }
    }
}