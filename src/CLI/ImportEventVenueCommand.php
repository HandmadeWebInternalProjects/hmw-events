<?php
/**
 * WP-CLI Command to import event_venue into the booking_notes ACF field.
 *
 * Each educator_course post stores the old class ID in the `old_course_id`
 * meta field. This command reads `event_venue` from the legacy
 * `wp_educator_classes` table and writes it to the `booking_notes` ACF field
 * on the corresponding post — but only when `booking_notes` is currently empty.
 *
 * Usage:
 *   wp cms import-event-venue          — dry run (preview changes only)
 *   wp cms import-event-venue --apply  — apply changes
 *   wp cms import-event-venue --apply --overwrite  — overwrite existing values too
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\CLI;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Import Event Venue Commands.
 */
class ImportEventVenueCommand
{
    /**
     * Import legacy event_venue values into the booking_notes ACF field.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Actually update the posts. Without this flag the command runs as a dry
     *   run and only reports what it *would* change.
     *
     * [--overwrite]
     * : Also update posts that already have a booking_notes value. By default
     *   only empty booking_notes fields are populated.
     *
     * ## EXAMPLES
     *
     *     wp cms import-event-venue
     *     wp cms import-event-venue --apply
     *     wp cms import-event-venue --apply --overwrite
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments (--apply, --overwrite).
     */
    public function __invoke($args, $assoc_args)
    {
        $dry_run   = empty($assoc_args['apply']);
        $overwrite = ! empty($assoc_args['overwrite']);
        global $wpdb;

        if ($dry_run) {
            \WP_CLI::line('DRY RUN — no changes will be saved. Pass --apply to commit changes.');
            \WP_CLI::line('');
        }

        // Fetch all educator_course posts that have an old_course_id.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, pm.meta_value AS old_course_id
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm
                     ON pm.post_id = p.ID AND pm.meta_key = %s
                 WHERE p.post_type = %s
                   AND p.post_status NOT IN ('auto-draft', 'inherit', 'trash')",
                'old_course_id',
                'educator_course'
            )
        );

        if (empty($rows)) {
            \WP_CLI::success('No educator courses with an old_course_id found.');
            return;
        }

        \WP_CLI::line(sprintf('Found %d course(s) with an old_course_id to inspect.', count($rows)));
        \WP_CLI::line('');

        $updated  = 0;
        $skipped  = 0;
        $no_venue = 0;
        $errors   = 0;

        foreach ($rows as $row) {
            $post_id       = (int) $row->ID;
            $old_course_id = (int) $row->old_course_id;

            // Look up event_venue in the legacy table.
            $event_venue = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT event_venue FROM " . \HMWEvents\Services\DatabaseService::get_table_name('classes') . " WHERE id = %d",
                    $old_course_id
                )
            );

            if ($event_venue === null || $event_venue === '') {
                \WP_CLI::line(sprintf('  [%d] old_id=%d — no event_venue, skipping.', $post_id, $old_course_id));
                $no_venue++;
                continue;
            }

            // Check existing booking_notes value.
            $existing = get_post_meta($post_id, 'booking_notes', true);

            if ($existing !== '' && ! $overwrite) {
                \WP_CLI::line(sprintf(
                    '  [%d] old_id=%d — booking_notes already set, skipping. (use --overwrite to replace)',
                    $post_id,
                    $old_course_id
                ));
                $skipped++;
                continue;
            }

            \WP_CLI::line(sprintf(
                '  [%d] old_id=%d — "%s"%s',
                $post_id,
                $old_course_id,
                $event_venue,
                $existing !== '' ? ' (overwriting existing)' : ''
            ));

            if (! $dry_run) {
                $result = update_post_meta($post_id, 'booking_notes', $event_venue);

                if ($result === false) {
                    \WP_CLI::warning(sprintf('  Failed to update post %d.', $post_id));
                    $errors++;
                    continue;
                }
            }

            $updated++;
        }

        \WP_CLI::line('');
        \WP_CLI::success(sprintf(
            '%s complete. %s: %d | No venue: %d | Skipped (already set): %d | Errors: %d',
            $dry_run ? 'Dry run' : 'Import',
            $dry_run ? 'Would update' : 'Updated',
            $updated,
            $no_venue,
            $skipped,
            $errors
        ));
    }
}

// Register WP-CLI command
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('hmwevents import-event-venue', 'HMWEvents\CLI\ImportEventVenueCommand');
}
