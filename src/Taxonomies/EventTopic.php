<?php

namespace HMWEvents\Taxonomies;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventTopic
{
    public const TAXONOMY = 'hmw_event_topic';

    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomy']);
    }

    public function register_taxonomy(): void
    {
        $labels = [
            'name'              => __('Topics', 'hmw-events'),
            'singular_name'     => __('Topic', 'hmw-events'),
            'search_items'      => __('Search Topics', 'hmw-events'),
            'all_items'         => __('All Topics', 'hmw-events'),
            'edit_item'         => __('Edit Topic', 'hmw-events'),
            'update_item'       => __('Update Topic', 'hmw-events'),
            'add_new_item'      => __('Add New Topic', 'hmw-events'),
            'new_item_name'     => __('New Topic Name', 'hmw-events'),
            'menu_name'         => __('Topics', 'hmw-events'),
        ];

        register_taxonomy(self::TAXONOMY, ['hmw_event'], [
            'labels'             => $labels,
            'hierarchical'       => true,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_rest'       => true,
            'show_admin_column'  => true,
            'rewrite'            => ['slug' => 'event-topic', 'with_front' => false],
            'query_var'          => true,
            'capabilities'       => [
                'manage_terms' => 'manage_categories',
                'edit_terms'   => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ],
        ]);
    }
}
