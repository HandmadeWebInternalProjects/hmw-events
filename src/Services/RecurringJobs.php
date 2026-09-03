<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

use HMWEvents\Helpers\EventHelper;
use HMWEvents\PostTypes\Event;

class RecurringJobs
{
    public function __construct()
    {
        add_action('hmwevents_hourly_expire_courses', [$this, 'expire_past_events']);
        add_action('hmwevents_daily_sync_course_availability', [$this, 'sync_event_availability']);
    }

    public function register()
    {
        if (function_exists('as_next_scheduled_action')) {
            add_action('action_scheduler_init', [$this, 'schedule']);
        } else {
            add_action('init', [$this, 'schedule']);
        }
    }

    public function schedule()
    {
        if (function_exists('as_next_scheduled_action') && !as_next_scheduled_action('hmwevents_hourly_expire_courses', [], 'hmw-events')) {
            as_schedule_recurring_action(
                time(),
                HOUR_IN_SECONDS,
                'hmwevents_hourly_expire_courses',
                [],
                'hmw-events'
            );
        }

        if (function_exists('as_next_scheduled_action') && !as_next_scheduled_action('hmwevents_daily_sync_course_availability', [], 'hmw-events')) {
            as_schedule_recurring_action(
                time(),
                DAY_IN_SECONDS,
                'hmwevents_daily_sync_course_availability',
                [],
                'hmw-events'
            );
        }
    }

    public function unregister()
    {
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('hmwevents_hourly_expire_courses', [], 'hmw-events');
            as_unschedule_all_actions('hmwevents_daily_sync_course_availability', [], 'hmw-events');
        }
    }

    public function expire_past_events()
    {
        $today = date('Ymd');
        $args = [
            'post_type'      => Event::POST_TYPE,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => '_event_end_date',
                    'value'   => $today,
                    'compare' => '<',
                    'type'    => 'DATE',
                ],
            ],
            'fields'         => 'ids',
            'posts_per_page' => -1,
        ];

        $query = new \WP_Query($args);
        if ($query->have_posts()) {
            foreach ($query->posts as $event_id) {
                wp_update_post([
                    'ID'          => $event_id,
                    'post_status' => 'archived',
                ]);
            }
        }
    }

    public function sync_event_availability()
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_status NOT IN ('auto-draft', 'inherit', 'trash')",
            Event::POST_TYPE
        ));

        foreach ($rows as $row) {
            EventHelper::ensure_course_availability_row((int) $row->ID);
        }
    }
}
