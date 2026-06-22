<?php
/**
 * WP-CLI Command to backfill scheduled emails for existing bookings.
 *
 * Queues missing pre-course reminders (7-day, 1-day) and the post-course
 * follow-up (1-day after) for confirmed/pending bookings. Safe to re-run —
 * checks for existing non-cancelled entries before adding any.
 *
 * Usage:
 *   wp cms backfill-reminders           — dry run (preview only)
 *   wp cms backfill-reminders --apply   — queue the missing emails
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\CLI;

use HMWEvents\Services\Emails\EmailService;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Backfill Reminders Command.
 */
class BackfillRemindersCommand
{
    /**
     * Queue missing pre-course reminders and post-course follow-ups for existing bookings.
     *
     * Iterates every confirmed/pending booking that has a course post attached
     * and queues any of the following that are missing:
     *
     *   - 7-day pre-course reminder
     *   - 1-day pre-course reminder
     *   - 1-day post-course follow-up
     *
     * Emails whose scheduled send time has already passed are skipped.
     * Safe to re-run — will never create duplicates.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Actually queue the emails. Without this flag the command runs as a dry
     *   run and only reports what it *would* queue.
     *
     * ## EXAMPLES
     *
     *     wp cms backfill-reminders
     *     wp cms backfill-reminders --apply
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments (--apply).
     */
    public function __invoke($args, $assoc_args)
    {
        global $wpdb;

        $dry_run = empty($assoc_args['apply']);

        if ($dry_run) {
            \WP_CLI::line('DRY RUN — pass --apply to queue the emails.');
            \WP_CLI::line('');
        }

        // ----------------------------------------------------------------
        // 1. Fetch all confirmed/pending bookings with a course post.
        // ----------------------------------------------------------------
        $bookings = $wpdb->get_results(
            "SELECT id, course_post_id
             FROM {$wpdb->prefix}educator_bookings
             WHERE status IN ('confirmed')
               AND course_post_id IS NOT NULL
               AND deleted_at IS NULL
               AND course_post_id > 0
             ORDER BY id ASC"
        );

        $total = count($bookings);
        \WP_CLI::line(sprintf('Found %d confirmed/pending bookings with a course_post_id.', $total));
        \WP_CLI::line('');

        if (!$total) {
            \WP_CLI::success('Nothing to do.');
            return;
        }

        // ----------------------------------------------------------------
        // 2. Iterate and queue missing emails.
        // ----------------------------------------------------------------
        $email_service = new EmailService();
        $now           = time();

        $counts = [
            'queued'         => 0,
            'failed'         => 0,
            'skipped_past'   => 0,
            'skipped_exists' => 0,
            'skipped_nodate' => 0,
        ];

        $progress = \WP_CLI\Utils\make_progress_bar('Processing bookings', $total);

        foreach ($bookings as $booking) {

            $course_id = $booking->course_post_id;

            // --- Pre-course reminders (based on course_start_date) -------
            $start_date = get_field('course_start_date', $course_id);

            if (!$start_date || ($start_ts = strtotime($start_date)) === false) {
                // Can't queue reminders without a start date; still try post-course below.
                $counts['skipped_nodate'] += 2;
            } else {
                foreach ([7, 1] as $days_before) {
                    // Matches ReminderHandler::calculate_scheduled_time().
                    $send_ts      = strtotime('09:00:00', $start_ts - ($days_before * DAY_IN_SECONDS));
                    $scheduled_at = gmdate('Y-m-d H:i:s', $send_ts);

                    if ($send_ts <= $now) {
                        $counts['skipped_past']++;
                        continue;
                    }

                    $existing = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM {$wpdb->prefix}email_queue
                         WHERE booking_id = %d
                           AND email_type = 'course_reminder'
                           AND status NOT IN ('cancelled')
                           AND ABS( TIMESTAMPDIFF( HOUR, scheduled_at, %s ) ) < 48",
                        $booking->id,
                        $scheduled_at
                    ));

                    if ($existing > 0) {
                        $counts['skipped_exists']++;
                        continue;
                    }

                    $this->queue_or_preview(
                        $dry_run,
                        $email_service,
                        'reminder',
                        $booking->id,
                        $days_before,
                        $course_id,
                        $scheduled_at,
                        $counts
                    );
                }
            }

            // --- Post-course follow-up (based on course_end_date, fallback to start) ---
            $end_date    = get_field('course_end_date', $course_id) ?: $start_date;
            $days_after  = 1;
            $email_type  = 'post_course_feedback'; // matches PostCourseHandler for < 30 days

            if (!$end_date || ($end_ts = strtotime($end_date)) === false) {
                $counts['skipped_nodate']++;
            } else {
                // Matches PostCourseHandler::calculate_scheduled_time().
                $send_ts      = strtotime('09:00:00', $end_ts + ($days_after * DAY_IN_SECONDS));
                $scheduled_at = gmdate('Y-m-d H:i:s', $send_ts);

                if ($send_ts <= $now) {
                    $counts['skipped_past']++;
                } else {
                    $existing = (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*)
                         FROM {$wpdb->prefix}email_queue
                         WHERE booking_id = %d
                           AND email_type = %s
                           AND status NOT IN ('cancelled')
                           AND ABS( TIMESTAMPDIFF( HOUR, scheduled_at, %s ) ) < 48",
                        $booking->id,
                        $email_type,
                        $scheduled_at
                    ));

                    if ($existing > 0) {
                        $counts['skipped_exists']++;
                    } else {
                        $this->queue_or_preview(
                            $dry_run,
                            $email_service,
                            'post_course',
                            $booking->id,
                            $days_after,
                            $course_id,
                            $scheduled_at,
                            $counts
                        );
                    }
                }
            }

            $progress->tick();
        }

        $progress->finish();
        \WP_CLI::line('');

        // ----------------------------------------------------------------
        // 3. Summary.
        // ----------------------------------------------------------------
        $label = $dry_run ? 'Dry run' : 'Backfill';

        \WP_CLI::success(sprintf(
            '%s complete — queued: %d | failed: %d | skipped (already exists): %d | skipped (past): %d | skipped (no date): %d',
            $label,
            $counts['queued'],
            $counts['failed'],
            $counts['skipped_exists'],
            $counts['skipped_past'],
            $counts['skipped_nodate']
        ));
    }

    /**
     * Queue or preview a single email, updating counts in place.
     *
     * @param bool         $dry_run      Whether this is a preview-only run.
     * @param EmailService $service      Email service instance.
     * @param string       $type         'reminder' or 'post_course'.
     * @param int          $booking_id   Booking ID.
     * @param int          $days         Days before (reminder) or after (post-course).
     * @param int          $course_id    Course post ID.
     * @param string       $scheduled_at Formatted scheduled datetime.
     * @param array        &$counts      Running totals array (passed by reference).
     */
    private function queue_or_preview($dry_run, $service, $type, $booking_id, $days, $course_id, $scheduled_at, &$counts)
    {
        $label = $type === 'reminder'
            ? sprintf('%d-day reminder', $days)
            : sprintf('%d-day post-course follow-up', $days);

        if (!$dry_run) {
            $result = $type === 'reminder'
                ? $service->queue_course_reminder($booking_id, $days)
                : $service->queue_post_course_email($booking_id, $days);

            if ($result) {
                $counts['queued']++;
                \WP_CLI::debug(sprintf(
                    'Queued %s — booking:%d course:%d send:%s',
                    $label, $booking_id, $course_id, $scheduled_at
                ));
            } else {
                $counts['failed']++;
                \WP_CLI::warning(sprintf(
                    'Failed to queue %s for booking %d',
                    $label, $booking_id
                ));
            }
        } else {
            $counts['queued']++;
            \WP_CLI::debug(sprintf(
                '[DRY RUN] Would queue %s — booking:%d course:%d send:%s',
                $label, $booking_id, $course_id, $scheduled_at
            ));
        }
    }
}

// Register WP-CLI command.
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('hmwevents backfill-reminders', 'HMWEvents\CLI\BackfillRemindersCommand');
}
