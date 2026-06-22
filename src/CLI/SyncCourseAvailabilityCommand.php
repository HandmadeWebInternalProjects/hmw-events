<?php
/**
 * WP-CLI Command to sync course availability cache rows.
 *
 * Usage:
 *   wp cms sync-course-availability                - dry run
 *   wp cms sync-course-availability --apply        - write cache rows
 *   wp cms sync-course-availability --course_id=12 - target one course
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\CLI;

use HMWEvents\Helpers\Course;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Sync Course Availability Command.
 */
class SyncCourseAvailabilityCommand
{
    /**
     * Backfill and sync educator course availability rows.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Actually write rows to educator_course_availability. Without this flag
     *   the command runs as a dry run and reports what would change.
     *
     * [--course_id=<id>]
     * : Limit sync to a single educator_course post ID.
     *
     * ## EXAMPLES
     *
     *     wp cms sync-course-availability
     *     wp cms sync-course-availability --apply
     *     wp cms sync-course-availability --apply --course_id=123
     *
     * @when after_wp_load
     *
     * @param array $args Positional arguments (unused).
     * @param array $assoc_args Associative arguments (--apply, --course_id).
     */
    public function __invoke($args, $assoc_args)
    {
        global $wpdb;

        $dry_run = empty($assoc_args['apply']);
        $single_course_id = isset($assoc_args['course_id']) ? (int) $assoc_args['course_id'] : 0;

        if ($dry_run) {
            \WP_CLI::line('DRY RUN - no changes will be saved. Pass --apply to commit changes.');
            \WP_CLI::line('');
        }

        if ($single_course_id > 0) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE ID = %d
                   AND post_type = %s
                   AND post_status NOT IN ('auto-draft', 'inherit', 'trash', 'expired')",
                $single_course_id,
                'educator_course'
            ));
        } else {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status NOT IN ('auto-draft', 'inherit', 'trash' , 'expired')",
                'educator_course'
            ));
        }

        if (empty($rows)) {
            \WP_CLI::success('No educator courses found to sync.');
            return;
        }

        \WP_CLI::line(sprintf('Found %d course(s) to inspect.', count($rows)));
        \WP_CLI::line('');

        $would_create = 0;
        $would_update = 0;
        $unchanged = 0;
        $written = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $course_id = (int) $row->ID;

            $calculated = Course::calculate_course_availability($course_id);
            if (!$calculated) {
                $errors++;
                \WP_CLI::warning(sprintf('  [%d] Could not calculate availability.', $course_id));
                continue;
            }

            $existing = $wpdb->get_row($wpdb->prepare(
                "SELECT capacity, booked_count, available_count
                 FROM {$wpdb->prefix}educator_course_availability
                 WHERE course_post_id = %d",
                $course_id
            ), ARRAY_A);

            $is_missing = !$existing;
            $needs_update = $is_missing
                || (int) $existing['capacity'] !== (int) $calculated['capacity']
                || (int) $existing['booked_count'] !== (int) $calculated['booked_count']
                || (int) $existing['available_count'] !== (int) $calculated['available_count'];

            if (!$needs_update) {
                $unchanged++;
                continue;
            }

            if ($is_missing) {
                $would_create++;
            } else {
                $would_update++;
            }

            \WP_CLI::line(sprintf(
                '  [%d] capacity=%d booked=%d available=%d (%s)',
                $course_id,
                (int) $calculated['capacity'],
                (int) $calculated['booked_count'],
                (int) $calculated['available_count'],
                $is_missing ? 'create' : 'update'
            ));

            if ($dry_run) {
                continue;
            }

            if (!Course::ensure_course_availability_row($course_id)) {
                $errors++;
                \WP_CLI::warning(sprintf('  [%d] Failed to write availability row.', $course_id));
                continue;
            }

            $written++;
        }

        \WP_CLI::line('');
        \WP_CLI::success(sprintf(
            '%s complete. %s: %d | Would create: %d | Would update: %d | Unchanged: %d | Errors: %d',
            $dry_run ? 'Dry run' : 'Sync',
            $dry_run ? 'Would write' : 'Written',
            $dry_run ? ($would_create + $would_update) : $written,
            $would_create,
            $would_update,
            $unchanged,
            $errors
        ));
    }
}

// Register WP-CLI command
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('hmwevents sync-course-availability', 'HMWEvents\CLI\SyncCourseAvailabilityCommand');
    \WP_CLI::add_command('sync-course-availability', 'HMWEvents\CLI\SyncCourseAvailabilityCommand');
}
