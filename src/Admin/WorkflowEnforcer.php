<?php

namespace HMWEvents\Admin;

use HMWEvents\PostTypes\Event;
use HMWEvents\Registry\EventTypeRegistry;
use HMWEvents\Taxonomies\EventType;

defined('ABSPATH') || die('Don\'t run this file directly!');

class WorkflowEnforcer
{
    private static bool $reverting = false;

    public function register(): void
    {
        add_action('transition_post_status', [$this, 'enforce_workflow'], 10, 3);
    }

    public function enforce_workflow(string $new_status, string $old_status, \WP_Post $post): void
    {
        if (self::$reverting) {
            return;
        }

        if ($post->post_type !== Event::POST_TYPE) {
            return;
        }

        if ($new_status === 'trash') {
            return;
        }

        if ($new_status === $old_status) {
            return;
        }

        $type_slug = $this->get_event_type_slug($post->ID);

        if ($this->is_transition_allowed($type_slug, $old_status, $new_status)) {
            return;
        }

        error_log(sprintf(
            '[HMW Events] Blocked invalid status transition for event #%d: "%s" -> "%s" (type: %s)',
            $post->ID,
            $old_status,
            $new_status,
            $type_slug ?: 'unknown'
        ));

        self::$reverting = true;
        wp_update_post([
            'ID'          => $post->ID,
            'post_status' => $old_status,
        ]);
        self::$reverting = false;

        add_action('admin_notices', function () use ($old_status, $new_status, $type_slug) {
            $statuses = get_post_statuses();
            $from_label = $statuses[$old_status] ?? $old_status;
            $to_label   = $statuses[$new_status] ?? $new_status;

            printf(
                '<div class="notice notice-error is-dismissible"><p>%s</p></div>',
                sprintf(
                    /* translators: 1: original status label, 2: attempted status label */
                    esc_html__('Cannot change status from "%1$s" to "%2$s" for this event type.', 'hmw-events'),
                    esc_html($from_label),
                    esc_html($to_label)
                )
            );
        });
    }

    public function is_transition_allowed(string $type_slug, string $from_status, string $to_status): bool
    {
        if ($to_status === 'trash') {
            return true;
        }

        $workflow = EventTypeRegistry::get_workflow($type_slug);

        $allowed = $workflow[$from_status] ?? [];

        return in_array($to_status, $allowed, true);
    }

    private function get_event_type_slug(int $post_id): string
    {
        $terms = wp_get_post_terms($post_id, EventType::TAXONOMY);

        if (empty($terms) || is_wp_error($terms)) {
            return '';
        }

        return (string) $terms[0]->slug;
    }
}
