<?php
/**
 * WP-CLI Command to fix duplicate date suffixes in course titles.
 *
 * Some courses had the old date format (dd-mm-YYYY) appended before the new
 * format (dd/mm/YYYY) was introduced, resulting in titles like:
 *   "Calmbirth with Kat – 14-02-2026 – 14/02/2026"
 *
 * This command strips any old/duplicate date suffix and re-appends the correct
 * new format based on the course_start_date ACF field.
 *
 * Usage:
 *   wp cms fix-course-titles          — dry run (preview changes only)
 *   wp cms fix-course-titles --apply  — apply changes
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\CLI;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Fix Course Title Commands.
 */
class FixCourseTitlesCommand
{
    /**
     * Fix duplicate / old-format date suffixes in educator course titles.
     *
     * ## OPTIONS
     *
     * [--apply]
     * : Actually update the posts. Without this flag the command runs as a dry
     *   run and only reports what it *would* change.
     *
     * [--diagnose]
     * : Run a raw SQL search for any titles that contain two date patterns and
     *   dump their IDs, statuses, and raw hex bytes. Useful for debugging.
     *
     * ## EXAMPLES
     *
     *     wp cms fix-course-titles
     *     wp cms fix-course-titles --apply
     *
     * @when after_wp_load
     *
     * @param array $args       Positional arguments (unused).
     * @param array $assoc_args Associative arguments (--apply).
     */
    public function __invoke($args, $assoc_args)
    {
        $dry_run  = empty($assoc_args['apply']);
        $diagnose = ! empty($assoc_args['diagnose']);
        global $wpdb;

        // --diagnose: raw SQL search for any title that looks like it has two dates
        if ($diagnose) {
            \WP_CLI::line('DIAGNOSE — searching DB for titles with double date patterns...');
            \WP_CLI::line('');
            $rows = $wpdb->get_results(
                "SELECT ID, post_title, post_status FROM {$wpdb->posts}
                 WHERE post_type = 'educator_course'
                   AND post_status NOT IN ('auto-draft','inherit','trash')
                   AND (
                       post_title REGEXP '[0-9]{2}[-/][0-9]{2}[-/][0-9]{4}.{1,10}[0-9]{2}[-/][0-9]{2}[-/][0-9]{4}'
                       OR post_title LIKE '%&#8211;%'
                   )"
            );
            if (empty($rows)) {
                \WP_CLI::success('No posts with double dates found in DB.');
            } else {
                foreach ($rows as $r) {
                    \WP_CLI::line(sprintf('[%d] (%s) %s', $r->ID, $r->post_status, $r->post_title));
                    \WP_CLI::line('  hex: ' . bin2hex($r->post_title));
                    \WP_CLI::line('');
                }
                \WP_CLI::success(count($rows) . ' post(s) found.');
            }
            return;
        }

        if ($dry_run) {
            \WP_CLI::line('DRY RUN — no changes will be saved. Pass --apply to commit changes.');
            \WP_CLI::line('');
        }

        // Use wpdb directly to bypass WP_Query completely — custom statuses like
        // 'expired' are reliably returned this way regardless of WP version.
        // Fetch post_title directly (raw) to avoid any the_title filters.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT ID, post_title FROM {$wpdb->posts}
                 WHERE post_type = %s
                   AND post_status NOT IN ('auto-draft', 'inherit', 'trash')",
                'educator_course'
            )
        );

        if (empty($rows)) {
            \WP_CLI::success('No educator courses found.');
            return;
        }

        \WP_CLI::line(sprintf('Found %d courses to inspect.', count($rows)));
        \WP_CLI::line('');

        $updated  = 0;
        $skipped  = 0;
        $no_date  = 0;

        foreach ($rows as $row) {
            $post_id        = (int) $row->ID;
            $original_title = $row->post_title; // raw DB value — no filters applied

            // Get the ACF start date field; fall back to parsing from the title
            $date = get_post_meta($post_id, 'course_start_date', true);
            $ts   = $date ? strtotime($date) : 0;

            if (!$ts) {
                // Try to parse the last date suffix already in the title (old or new format)
                // Separators may be UTF-8 en-dash (–), HTML entity (&#8211;), or hyphen (-)
                if (preg_match('/( – | &#8211; | - )(\d{2})[-\/](\d{2})[-\/](\d{4})(?:$| – | &#8211; | - )/', $original_title, $m)) {
                    // $m[2]=day, $m[3]=month, $m[4]=year
                    $ts = mktime(0, 0, 0, (int)$m[3], (int)$m[2], (int)$m[4]);
                }
            }

            if (!$ts) {
                \WP_CLI::warning(sprintf('  [%d] No date found — skipping: %s', $post_id, $original_title));
                $no_date++;
                continue;
            }

            $correct_suffix = ' – ' . date('d/m/Y', $ts);

            // Strip ALL existing date suffixes:
            //   " – dd/mm/YYYY" or " – dd-mm-YYYY" (UTF-8 en-dash)
            //   " &#8211; dd-mm-YYYY"              (HTML entity en-dash, legacy)
            //   " - dd-mm-YYYY"                    (hyphen, RecurringCourse generator)
            $clean_title = preg_replace('/(( – | &#8211; | - )\d{2}[-\/]\d{2}[-\/]\d{4})+$/', '', $original_title);
            $clean_title = rtrim($clean_title);

            $expected_title = $clean_title . $correct_suffix;

            if ($original_title === $expected_title) {
                $skipped++;
                continue;
            }

            \WP_CLI::line(sprintf('  [%d] %s', $post_id, $original_title));
            \WP_CLI::line(sprintf('       → %s', $expected_title));

            if (!$dry_run) {
                $result = $wpdb->update(
                    $wpdb->posts,
                    [
                        'post_title' => $expected_title,
                        'post_name'  => sanitize_title($expected_title),
                    ],
                    ['ID' => $post_id],
                    ['%s', '%s'],
                    ['%d']
                );

                if ($result === false) {
                    \WP_CLI::warning(sprintf('  Failed to update post %d: %s', $post_id, $wpdb->last_error));
                } else {
                    $updated++;
                }
            } else {
                $updated++;
            }
        }

        \WP_CLI::line('');
        \WP_CLI::success(sprintf(
            '%s complete. Would update: %d | Already correct: %d | No date: %d',
            $dry_run ? 'Dry run' : 'Update',
            $updated,
            $skipped,
            $no_date
        ));
    }
}

// Register WP-CLI command
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('hmwevents fix-course-titles', 'HMWEvents\CLI\FixCourseTitlesCommand');
}
