<?php

namespace HMWEvents\Admin;

use HMWEvents\PostTypes\Event;
use HMWEvents\Services\CapacityService;

defined('ABSPATH') || die('Don\'t run this file directly!');

class EventListColumns
{
    public const COLUMN_KEY = 'hmwevents_bookings';

    private ?CapacityService $capacity_service = null;

    private ?array $booked_counts = null;

    public function register(): void
    {
        add_filter('manage_' . Event::POST_TYPE . '_posts_columns', [$this, 'add_bookings_column']);
        add_action('manage_' . Event::POST_TYPE . '_posts_custom_column', [$this, 'render_bookings_column'], 10, 2);
    }

    public function add_bookings_column(array $columns): array
    {
        $rebuilt = [];

        foreach ($columns as $key => $label) {
            $rebuilt[$key] = $label;

            if ($key === 'title') {
                $rebuilt[self::COLUMN_KEY] = __('Bookings', 'hmw-events');
            }
        }

        if (!isset($rebuilt[self::COLUMN_KEY])) {
            $rebuilt[self::COLUMN_KEY] = __('Bookings', 'hmw-events');
        }

        return $rebuilt;
    }

    public function render_bookings_column(string $column, int $post_id): void
    {
        if ($column !== self::COLUMN_KEY) {
            return;
        }

        if (!current_user_can('edit_hmw_events')) {
            echo '<span aria-hidden="true">—</span>';
            return;
        }

        $booked = $this->booked_counts()[$post_id] ?? 0;
        $capacity = $this->capacity_service()->get_capacity($post_id);

        if ($capacity > 0) {
            $classes = ['hmwevents-capacity-badge'];

            if ($booked >= $capacity) {
                $classes[] = 'hmwevents-capacity-badge--full';
            } elseif (($capacity - $booked) <= max(1, (int) round($capacity * 0.1))) {
                $classes[] = 'hmwevents-capacity-badge--nearly-full';
            }

            printf(
                '<span class="%s"><strong>%d</strong> / %d</span>',
                esc_attr(implode(' ', $classes)),
                (int) $booked,
                (int) $capacity
            );
            return;
        }

        printf(
            '<span class="hmwevents-capacity-badge">%d</span>',
            (int) $booked
        );
    }

    private function capacity_service(): CapacityService
    {
        if ($this->capacity_service === null) {
            $this->capacity_service = new CapacityService();
        }

        return $this->capacity_service;
    }

    private function booked_counts(): array
    {
        if ($this->booked_counts === null) {
            $post_ids = array_map(
                fn($post) => (int) $post->ID,
                $GLOBALS['wp_query']->posts ?? []
            );

            $this->booked_counts = $this->capacity_service()->get_booked_people_for_events($post_ids);
        }

        return $this->booked_counts;
    }
}
