<?php

namespace HMWEvents\Admin;

use HMWEvents\PostTypes\Event;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventLifecycle
{
    public function register(): void
    {
        add_filter('post_row_actions', [$this, 'add_duplicate_action'], 10, 2);
        add_filter('page_row_actions', [$this, 'add_duplicate_action'], 10, 2);
        add_action('admin_action_hmwevents_duplicate_event', [$this, 'handle_duplicate']);
        add_filter('post_row_actions', [$this, 'add_restore_action'], 10, 2);
        add_filter('page_row_actions', [$this, 'add_restore_action'], 10, 2);
        add_action('admin_action_hmwevents_restore_event', [$this, 'handle_restore']);
        add_action('admin_notices', [$this, 'render_restore_notice']);
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

    public function add_restore_action(array $actions, \WP_Post $post): array
    {
        if ($post->post_type !== Event::POST_TYPE || $post->post_status !== 'archived') {
            return $actions;
        }

        $url = wp_nonce_url(
            admin_url('admin.php?action=hmwevents_restore_event&post=' . $post->ID),
            'hmwevents_restore_' . $post->ID
        );

        $actions['restore'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($url),
            __('Restore', 'hmw-events')
        );

        return $actions;
    }

    public function handle_restore(): void
    {
        $post_id = (int) ($_GET['post'] ?? 0);
        if (!$post_id || (!current_user_can('edit_post', $post_id) && !current_user_can('manage_options'))) {
            wp_die(__('Permission denied.', 'hmw-events'));
        }

        check_admin_referer('hmwevents_restore_' . $post_id);

        $post = get_post($post_id);
        if (!$post || $post->post_type !== Event::POST_TYPE || $post->post_status !== 'archived') {
            wp_die(__('Invalid archived event.', 'hmw-events'));
        }

        $result = wp_update_post([
            'ID'          => $post_id,
            'post_status' => 'publish',
        ], true);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }

        wp_safe_redirect(add_query_arg(
            ['post_type' => Event::POST_TYPE, 'hmwevents_restored' => 1],
            admin_url('edit.php')
        ));
        exit;
    }

    public function render_restore_notice(): void
    {
        if (empty($_GET['hmwevents_restored'])) {
            return;
        }

        echo '<div class="notice notice-success is-dismissible"><p>';
        esc_html_e('Event restored and published.', 'hmw-events');
        echo '</p></div>';
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
            '_event_is_recurring', '_event_venue',
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
