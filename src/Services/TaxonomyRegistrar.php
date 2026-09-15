<?php

namespace HMWEvents\Services;

use HMWEvents\Registry\TaxonomyRegistry;

defined('ABSPATH') || die('Don\'t run this file directly!');

class TaxonomyRegistrar
{
    public function register(): void
    {
        add_action('init', [$this, 'register_taxonomies']);
        add_action('init', [$this, 'insert_default_terms'], 20);
        add_filter('hmwevents_event_archive_taxonomies', [$this, 'append_archive_taxonomies']);

        if (function_exists('acf_add_local_field') && did_action('acf/init')) {
            $this->inject_acf_fields();
        } else {
            add_action('acf/init', [$this, 'inject_acf_fields']);
        }
    }

    public function register_taxonomies(): void
    {
        foreach (TaxonomyRegistry::all() as $slug => $def) {
            register_taxonomy($slug, ['hmw_event'], $this->build_args($def, $slug));
        }
    }

    public function insert_default_terms(): void
    {
        foreach (TaxonomyRegistry::all() as $slug => $raw_def) {
            $def = TaxonomyRegistry::normalize($raw_def, $slug);
            $terms = (array) $def['default_terms'];
            if (!$terms) {
                continue;
            }

            $flag = $def['option_flag'] ?: 'hmwevents_' . str_replace('-', '_', str_replace('hmw_event_', '', $slug)) . '_inserted';
            if (get_option($flag)) {
                continue;
            }

            foreach ($terms as $term_slug => $name) {
                if (!term_exists($term_slug, $slug)) {
                    wp_insert_term($name, $slug, ['slug' => $term_slug]);
                }
            }

            update_option($flag, true);
        }
    }

    public function inject_acf_fields(): void
    {
        if (!function_exists('acf_add_local_field')) {
            return;
        }

        $order = 10;
        foreach (TaxonomyRegistry::all() as $slug => $raw_def) {
            $def = TaxonomyRegistry::normalize($raw_def, $slug);
            $field_key = $def['event_field_key'];
            if (!$field_key) {
                continue;
            }

            acf_add_local_field([
                'key'           => 'field_' . $field_key,
                'label'         => $def['field_label'] ?: $def['singular'],
                'name'          => '_' . $field_key,
                'type'          => 'taxonomy',
                'parent'        => 'group_hmw_event_details',
                'taxonomy'      => $slug,
                'field_type'    => 'checkbox',
                'allow_null'    => 0,
                'add_term'      => 0,
                'create_terms'  => 0,
                'save_terms'    => 1,
                'load_terms'    => 1,
                'return_format' => 'id',
                'required'      => 0,
                'instructions'  => $def['description'],
                'menu_order'    => $order++,
            ]);
        }
    }

    public function append_archive_taxonomies(array $taxonomies): array
    {
        return array_values(array_unique(array_merge($taxonomies, TaxonomyRegistry::archive_taxonomies())));
    }

    private function build_args(array $raw_def, string $slug): array
    {
        $def = TaxonomyRegistry::normalize($raw_def, $slug);

        $args = [
            'labels'             => $this->build_labels($def),
            'description'        => $def['description'],
            'hierarchical'       => (bool) $def['hierarchical'],
            'public'             => (bool) $def['public'],
            'publicly_queryable' => (bool) $def['publicly_queryable'],
            'show_ui'            => true,
            'show_in_menu'       => true,
            'show_in_nav_menus'  => (bool) $def['publicly_queryable'],
            'show_in_rest'       => true,
            'show_tagcloud'      => false,
            'show_in_quick_edit' => false,
            'show_admin_column'  => (bool) $def['show_admin_column'],
            'meta_box_cb'        => false,
            'rewrite'            => $this->build_rewrite($def['rewrite']),
            'query_var'          => $def['query_var'] ?? (bool) $def['publicly_queryable'],
            'capabilities'       => [
                'manage_terms' => 'manage_categories',
                'edit_terms'   => 'manage_categories',
                'delete_terms' => 'manage_categories',
                'assign_terms' => 'edit_posts',
            ],
        ];

        return $args;
    }

    private function build_rewrite($rewrite)
    {
        if (is_array($rewrite)) {
            return array_replace_recursive(['with_front' => false], $rewrite);
        }

        if (is_string($rewrite) && $rewrite !== '') {
            return ['slug' => $rewrite, 'with_front' => false, 'hierarchical' => true];
        }

        return false;
    }

    private function build_labels(array $def): array
    {
        $name     = _x($def['name'], 'taxonomy general name', 'hmw-events');
        $singular = _x($def['singular'], 'taxonomy singular name', 'hmw-events');
        $lc_name  = strtolower($name);

        $labels = [
            'name'                       => $name,
            'singular_name'              => $singular,
            'search_items'               => sprintf(__('Search %s', 'hmw-events'), $name),
            'all_items'                  => sprintf(__('All %s', 'hmw-events'), $name),
            'edit_item'                  => sprintf(__('Edit %s', 'hmw-events'), $singular),
            'update_item'                => sprintf(__('Update %s', 'hmw-events'), $singular),
            'add_new_item'               => sprintf(__('Add New %s', 'hmw-events'), $singular),
            'new_item_name'              => sprintf(__('New %s Name', 'hmw-events'), $singular),
            'not_found'                  => sprintf(__('No %s found.', 'hmw-events'), $lc_name),
            'menu_name'                  => $name,
            'back_to_items'              => sprintf(__('← Back to %s', 'hmw-events'), $name),
        ];

        if ($def['hierarchical']) {
            $labels['parent_item']       = sprintf(__('Parent %s', 'hmw-events'), $singular);
            $labels['parent_item_colon'] = sprintf(__('Parent %s:', 'hmw-events'), $singular);
        } else {
            $labels['separate_items_with_commas'] = sprintf(__('Separate %s with commas', 'hmw-events'), $lc_name);
            $labels['add_or_remove_items']        = sprintf(__('Add or remove %s', 'hmw-events'), $lc_name);
            $labels['choose_from_most_used']      = sprintf(__('Choose from the most used %s', 'hmw-events'), $lc_name);
        }

        return $labels;
    }
}
