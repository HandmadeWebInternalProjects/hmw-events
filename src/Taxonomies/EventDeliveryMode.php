<?php

/**
 * Event Delivery Mode Taxonomy.
 *
 * Non-hierarchical taxonomy for delivery modality
 * (e.g. in-person, online, hybrid).
 *
 * @package HMWEvents\Taxonomies
 * @since 2.0.0
 */

namespace HMWEvents\Taxonomies;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventDeliveryMode
{
    public const TAXONOMY = 'hmw_event_delivery_mode';

    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomy']);
        add_action('init', [$this, 'insert_default_terms'], 20);
    }

    public function register_taxonomy(): void
    {
        $labels = [
            'name'                       => _x('Delivery Modes', 'taxonomy general name', 'hmw-events'),
            'singular_name'              => _x('Delivery Mode', 'taxonomy singular name', 'hmw-events'),
            'search_items'               => __('Search Delivery Modes', 'hmw-events'),
            'all_items'                  => __('All Delivery Modes', 'hmw-events'),
            'edit_item'                  => __('Edit Delivery Mode', 'hmw-events'),
            'update_item'                => __('Update Delivery Mode', 'hmw-events'),
            'add_new_item'               => __('Add New Delivery Mode', 'hmw-events'),
            'new_item_name'              => __('New Delivery Mode Name', 'hmw-events'),
            'not_found'                  => __('No delivery modes found.', 'hmw-events'),
            'menu_name'                  => __('Delivery Modes', 'hmw-events'),
        ];

        $args = [
            'labels'            => $labels,
            'description'       => __('How the event is delivered', 'hmw-events'),
            'hierarchical'      => true,
            'public'            => true,
            'publicly_queryable' => true,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_in_rest'      => true,
            'show_tagcloud'     => false,
            'show_admin_column' => true,
            'rewrite'           => ['slug' => 'delivery-mode', 'with_front' => false],
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
     * Insert default delivery mode terms.
     */
    public function insert_default_terms(): void
    {
        if (get_option('hmwevents_delivery_modes_inserted')) {
            return;
        }

        $defaults = [
            'in-person' => __('In Person', 'hmw-events'),
            'online'    => __('Online', 'hmw-events'),
            'hybrid'    => __('Hybrid', 'hmw-events'),
        ];

        foreach ($defaults as $slug => $name) {
            if (!term_exists($slug, self::TAXONOMY)) {
                wp_insert_term($name, self::TAXONOMY, ['slug' => $slug]);
            }
        }

        update_option('hmwevents_delivery_modes_inserted', true);
    }
}
