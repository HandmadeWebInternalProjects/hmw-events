<?php

namespace HMWEvents\Admin;

use HMWEvents\PostTypes\Event;

defined('ABSPATH') || die('Don\'t run this file directly!');

class SessionCalendar
{
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_page']);
    }

    public function add_page(): void
    {
        add_submenu_page(
            'edit.php?post_type=' . Event::POST_TYPE,
            __('Session Calendar', 'hmw-events'),
            __('Calendar', 'hmw-events'),
            'edit_hmw_events',
            'hmwevents-session-calendar',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        $month = (int) ($_GET['hmw_month'] ?? date('n'));
        $year  = (int) ($_GET['hmw_year'] ?? date('Y'));

        if ($month < 1) {
            $month = 12;
            $year--;
        }
        if ($month > 12) {
            $month = 1;
            $year++;
        }

        $first_day = mktime(0, 0, 0, $month, 1, $year);
        $days_in_month = (int) date('t', $first_day);
        $start_dow = (int) date('N', $first_day);
        $today = date('Y-m-d');

        $sessions = $this->get_sessions_for_month($month, $year);
        $by_date = [];
        foreach ($sessions as $s) {
            $d = date('Y-m-d', strtotime($s->start_date));
            $by_date[$d][] = $s;
        }

        $prev_month = $month - 1;
        $prev_year = $year;
        if ($prev_month < 1) {
            $prev_month = 12;
            $prev_year--;
        }
        $next_month = $month + 1;
        $next_year = $year;
        if ($next_month > 12) {
            $next_month = 1;
            $next_year++;
        }

        $month_name = date_i18n('F Y', $first_day);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Session Calendar', 'hmw-events'); ?></h1>

            <div class="hmwevents-calendar-nav" style="display:flex; align-items:center; gap:12px; margin:12px 0;">
                <a href="?post_type=hmw_event&page=hmwevents-session-calendar&hmw_month=<?php echo $prev_month; ?>&hmw_year=<?php echo $prev_year; ?>" class="button">&laquo; <?php esc_html_e('Previous', 'hmw-events'); ?></a>
                <strong style="font-size:16px;"><?php echo esc_html($month_name); ?></strong>
                <a href="?post_type=hmw_event&page=hmwevents-session-calendar&hmw_month=<?php echo $next_month; ?>&hmw_year=<?php echo $next_year; ?>" class="button"><?php esc_html_e('Next', 'hmw-events'); ?> &raquo;</a>
            </div>

            <table class="wp-list-table widefat fixed hmwevents-calendar">
                <thead>
                    <tr>
                        <?php
                        $day_names = [
                            __('Mon', 'hmw-events'), __('Tue', 'hmw-events'), __('Wed', 'hmw-events'),
                            __('Thu', 'hmw-events'), __('Fri', 'hmw-events'), __('Sat', 'hmw-events'), __('Sun', 'hmw-events'),
                        ];
                        foreach ($day_names as $d) : ?>
                            <th style="text-align:center; width:14.28%;"><?php echo esc_html($d); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $cell = 1;
                    for ($row = 0; $row < 6; $row++) :
                        if ($cell > $days_in_month && ($cell - $start_dow + 1) > $days_in_month) {
                            break;
                        }
                        ?>
                        <tr style="height:100px;">
                        <?php for ($col = 0; $col < 7; $col++) :
                            $day_num = $cell - $start_dow + 1;
                            $is_today = false;
                            $date_str = '';
                            $events = [];

                            if ($cell >= $start_dow && $day_num <= $days_in_month) {
                                $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day_num);
                                $is_today = ($date_str === $today);
                                $events = $by_date[$date_str] ?? [];
                            }
                            ?>
                            <td style="vertical-align:top; padding:4px; <?php echo $is_today ? 'background:#f0f6fc;' : ''; ?>">
                                <?php if ($date_str) : ?>
                                    <strong style="font-size:12px; <?php echo $is_today ? 'color:#2271b1;' : ''; ?>"><?php echo (int) $day_num; ?></strong>
                                    <?php foreach ($events as $ev) : ?>
                                        <div style="font-size:11px; margin-top:2px; padding:2px 4px; background:#e7f5e9; border-left:3px solid #46b450; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                            <a href="<?php echo esc_url(get_edit_post_link($ev->parent_id)); ?>" style="text-decoration:none;" title="<?php echo esc_attr($ev->parent_title); ?>">
                                                <?php echo esc_html($ev->session_title ?: $ev->parent_title); ?>
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <?php $cell++; endfor; ?>
                        </tr>
                    <?php endfor; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function get_sessions_for_month(int $month, int $year): array
    {
        global $wpdb;

        $start = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $end   = date('Y-m-t 23:59:59', strtotime($start));

        $results = $wpdb->get_results($wpdb->prepare(
            "
            SELECT p.ID, p.post_title AS session_title, p.post_parent AS parent_id,
                   p2.post_title AS parent_title,
                   pm.meta_value AS start_date
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_event_start_date'
            INNER JOIN {$wpdb->posts} p2 ON p2.ID = p.post_parent
            WHERE p.post_type = 'hmw_event'
              AND p.post_parent > 0
              AND p.post_status IN ('publish', 'fully_booked', 'by_invitation')
              AND pm.meta_value >= %s
              AND pm.meta_value <= %s
            ORDER BY pm.meta_value ASC
        ",
            $start,
            $end
        ));

        return $results ?: [];
    }
}
