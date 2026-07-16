<?php

namespace HMWEvents\Admin;

use HMWEvents\PostTypes\Event;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventLifecycle
{
    public function register(): void
    {
        add_filter('post_row_actions', [$this, 'add_duplicate_action'], 10, 2);
        add_action('admin_action_hmwevents_duplicate_event', [$this, 'handle_duplicate']);
    }

    public function add_duplicate_action(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== Event::POST_TYPE) {
            return $actions;
        }
        if (!current_user_can('edit_hmw_events')) {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=hmwevents_duplicate_event&post=' . $post->ID),
            'hmwevents_duplicate_' . $post->ID
        );

        $actions['duplicate'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            __('Duplicate', 'hmw-events')
        );

        return $actions;
    }

    public function handle_duplicate(): void
    {
        $post_id = (int) ($_GET['post'] ?? 0);
        if (!$post_id || !current_user_can('edit_hmw_events')) {
            wp_die(__('Permission denied.', 'hmw-events'));
        }

        check_admin_referer('hmwevents_duplicate_' . $post_id);

        $post = get_post($post_id);
        if (!$post || $post->post_type !== Event::POST_TYPE) {
            wp_die(__('Invalid event.', 'hmw-events'));
        }

        $new_id = wp_insert_post([
            'post_type'    => Event::POST_TYPE,
            'post_status'  => 'draft',
            'post_title'   => $post->post_title . ' (' . __('Copy', 'hmw-events') . ')',
            'post_content' => $post->post_content,
        ]);

        if (is_wp_error($new_id)) {
            wp_die($new_id->get_error_message());
        }

        $taxonomies = get_object_taxonomies(Event::POST_TYPE);
        foreach ($taxonomies as $tax) {
            $terms = wp_get_object_terms($post_id, $tax, ['fields' => 'ids']);
            if (!is_wp_error($terms) && !empty($terms)) {
                wp_set_object_terms($new_id, $terms, $tax);
            }
        }

        $meta_keys = [
            '_event_start_date', '_event_end_date', '_event_price', '_event_deposit',
            '_event_capacity', '_event_is_free', '_event_surcharge',
            '_event_max_per_registrant', '_event_allow_net_terms',
            '_event_is_recurring', '_event_venue_name', '_event_venue_address',
            '_event_webinar_url', '_event_booking_notes', '_organizer_id',
            '_event_delivery_mode', '_event_field_config',
        ];
        foreach ($meta_keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if ($value !== '') {
                update_post_meta($new_id, $key, $value);
            }
        }

        wp_redirect(admin_url('post.php?action=edit&post=' . $new_id));
        exit;
    }
}
