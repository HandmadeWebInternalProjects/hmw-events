<?php

namespace HMWEvents\Services;

class RecurringJobs
{
    public function __construct()
    {
        // Set educator_course post types posts to expired if course date is in the past
        add_action('hmwevents_hourly_expire_courses', [$this, 'expire_past_courses']);

        // Sync course availability cache daily as a safety net
        add_action('hmwevents_daily_sync_course_availability', [$this, 'sync_course_availability']);
    }

    public function register()
    {
        // Ensure schedule exists after ActionScheduler is initialized
        if (function_exists('as_next_scheduled_action')) {
            add_action('action_scheduler_init', [$this, 'schedule']);
        } else {
            add_action('init', [$this, 'schedule']);
        }
    }

    /**
     * Schedule recurring action.
     *
     * @return void
     */
    public function schedule()
    {
        // Schedule hourly job using ActionScheduler if not already scheduled
        if (function_exists('as_next_scheduled_action') && !as_next_scheduled_action('hmwevents_hourly_expire_courses', [], 'hmw-events')) {
            as_schedule_recurring_action(
                time(),
                HOUR_IN_SECONDS,
                'hmwevents_hourly_expire_courses',
                [],
                'hmw-events'
            );
        }

        // Schedule daily availability sync using ActionScheduler if not already scheduled
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
        // Clean up scheduled actions on deactivation
        if (function_exists('as_unschedule_all_actions')) {
            as_unschedule_all_actions('hmwevents_hourly_expire_courses', [], 'hmw-events');
            as_unschedule_all_actions('hmwevents_daily_sync_course_availability', [], 'hmw-events');
        }
    }

    public function expire_past_courses()
    {
        $today = date('Ymd');
        $args = [
            'post_type'      => 'educator_course',
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => 'course_end_date',
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
            foreach ($query->posts as $course_id) {
                // Update post status to expired
                wp_update_post([
                    'ID'          => $course_id,
                    'post_status' => 'expired',
                ]);
            }
        }
    }

    /**
     * Sync course availability cache for all active courses.
     *
     * Runs daily via ActionScheduler as a safety net so incremental
     * bugs (like the sign-flip in StripePaymentGateway) self-correct
     * within 24 hours.
     *
     * @since 1.0.0
     * @return void
     */
    public function sync_course_availability()
    {
        global $wpdb;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = %s
               AND post_status NOT IN ('auto-draft', 'inherit', 'trash', 'expired')",
            'educator_course'
        ));

        foreach ($rows as $row) {
            \HMWEvents\Helpers\Course::ensure_course_availability_row((int) $row->ID);
        }
    }
}
