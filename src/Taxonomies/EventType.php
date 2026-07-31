<?php

/**
 * Event Type Taxonomy.
 *
 * Hierarchical taxonomy for categorising events by archetype
 * (e.g. workshop, webinar, course, seminar, conference, etc.).
 *
 * @package HMWEvents\Taxonomies
 * @since 2.0.0
 */

namespace HMWEvents\Taxonomies;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventType
{
    public const TAXONOMY = 'hmw_event_type';

    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomy']);
        add_action('init', [$this, 'insert_default_terms'], 20);
    }

    public function register_taxonomy(): void
    {
        $labels = [
            'name'                       => _x('Event Types', 'taxonomy general name', 'hmw-events'),
            'singular_name'              => _x('Event Type', 'taxonomy singular name', 'hmw-events'),
            'search_items'               => __('Search Event Types', 'hmw-events'),
            'popular_items'              => __('Popular Event Types', 'hmw-events'),
            'all_items'                  => __('All Event Types', 'hmw-events'),
            'parent_item'                => __('Parent Event Type', 'hmw-events'),
            'parent_item_colon'          => __('Parent Event Type:', 'hmw-events'),
            'edit_item'                  => __('Edit Event Type', 'hmw-events'),
            'update_item'                => __('Update Event Type', 'hmw-events'),
            'add_new_item'               => __('Add New Event Type', 'hmw-events'),
            'new_item_name'              => __('New Event Type Name', 'hmw-events'),
            'separate_items_with_commas' => __('Separate event types with commas', 'hmw-events'),
            'add_or_remove_items'        => __('Add or remove event types', 'hmw-events'),
            'choose_from_most_used'      => __('Choose from most used event types', 'hmw-events'),
            'not_found'                  => __('No event types found.', 'hmw-events'),
            'menu_name'                  => __('Event Types', 'hmw-events'),
            'back_to_items'              => __('← Back to Event Types', 'hmw-events'),
        ];

        $args = [
            'labels'            => $labels,
            'description'       => __('Categories for different types of events', 'hmw-events'),
            'hierarchical'      => true,
            'public'            => true,
            'publicly_queryable' => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_nav_menus' => true,
            'show_in_rest'      => true,
            'show_tagcloud'     => false,
            'show_in_quick_edit' => true,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'event-type', 'with_front' => false, 'hierarchical' => true],
            'query_var'         => true,
            'capabilities'      => [
                'manage_terms' => 'manage_categories',
                'edit_terms'   => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ],
        ];

        register_taxonomy(self::TAXONOMY, ['hmw_event'], $args);
    }

    /**
     * Insert default event type terms.
     */
    public function insert_default_terms(): void
    {
        if (get_option('hmwevents_event_types_inserted_v2')) {
            return;
        }

        $defaults = [
            'parenting-webinar'      => __('Parenting Webinar', 'hmw-events'),
            'professional-webinar'   => __('Professional Webinar', 'hmw-events'),
            'parent-one-off-free'    => __('Parent One-Off Free Event', 'hmw-events'),
            'parent-walk-in'         => __('Parent Recurring Walk-In', 'hmw-events'),
            'parent-course'          => __('Parent Multi-Week Course', 'hmw-events'),
            'professional-online'    => __('Professional Online Event', 'hmw-events'),
            'professional-in-person' => __('Professional In-Person Event', 'hmw-events'),
        ];

        foreach ($defaults as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
            }
        }

        update_option('hmwevents_event_types_inserted_v2', true);
    }
}
