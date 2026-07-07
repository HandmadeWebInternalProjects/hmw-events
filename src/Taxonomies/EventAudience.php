<?php

/**
 * Event Audience Taxonomy.
 *
 * Non-hierarchical taxonomy for targeting event audiences
 * (e.g. parents, professionals, couples, individuals).
 *
 * @package HMWEvents\Taxonomies
 * @since 2.0.0
 */

namespace HMWEvents\Taxonomies;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventAudience
{
    public const TAXONOMY = 'hmw_event_audience';

    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomy']);
        add_action('init', [$this, 'insert_default_terms'], 20);
    }

    public function register_taxonomy(): void
    {
        $labels = [
            'name'                       => _x('Audiences', 'taxonomy general name', 'hmw-events'),
            'singular_name'              => _x('Audience', 'taxonomy singular name', 'hmw-events'),
            'search_items'               => __('Search Audiences', 'hmw-events'),
            'popular_items'              => __('Popular Audiences', 'hmw-events'),
            'all_items'                  => __('All Audiences', 'hmw-events'),
            'edit_item'                  => __('Edit Audience', 'hmw-events'),
            'update_item'                => __('Update Audience', 'hmw-events'),
            'add_new_item'               => __('Add New Audience', 'hmw-events'),
            'new_item_name'              => __('New Audience Name', 'hmw-events'),
            'separate_items_with_commas' => __('Separate audiences with commas', 'hmw-events'),
            'add_or_remove_items'        => __('Add or remove audiences', 'hmw-events'),
            'choose_from_most_used'      => __('Choose from most used audiences', 'hmw-events'),
            'not_found'                  => __('No audiences found.', 'hmw-events'),
            'menu_name'                  => __('Audiences', 'hmw-events'),
        ];

        $args = [
            'labels'            => $labels,
            'description'       => __('Target audience categories for events', 'hmw-events'),
            'hierarchical'      => false,
            'public'            => true,
            'publicly_queryable' => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_nav_menus' => true,
            'show_in_rest'      => true,
            'show_tagcloud'     => false,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'event-audience', 'with_front' => false],
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
     * Insert default audience terms.
     */
    public function insert_default_terms(): void
    {
        if (get_option('hmwevents_audiences_inserted')) {
            return;
        }

        $defaults = [
            'parents'       => __('Parents', 'hmw-events'),
            'professionals' => __('Professionals', 'hmw-events'),
            'couples'       => __('Couples', 'hmw-events'),
            'individuals'   => __('Individuals', 'hmw-events'),
            'children'      => __('Children', 'hmw-events'),
        ];

        foreach ($defaults as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
            }
        }

        update_option('hmwevents_audiences_inserted', true);
    }
}
