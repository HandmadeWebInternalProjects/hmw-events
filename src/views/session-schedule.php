<?php

defined('ABSPATH') || die("Don't run this file directly!");

$event    = $args['event'] ?? null;
$sessions = $args['sessions'] ?? [];
if (!$event || empty($sessions)) {
    return;
}

$date_format = get_option('date_format');
$time_format = get_option('time_format');
?>

<div class="hmwevents-session-schedule">
    <h3><?php esc_html_e('Session Schedule', 'hmw-events'); ?></h3>
    <table class="hmwevents-session-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Session', 'hmw-events'); ?></th>
                <th><?php esc_html_e('Date', 'hmw-events'); ?></th>
                <th><?php esc_html_e('Time', 'hmw-events'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php $index = 1; foreach ($sessions as $session): ?>
                <?php
                $start = get_post_meta($session->ID, '_event_start_date', true);
                $end   = get_post_meta($session->ID, '_event_end_date', true);
                ?>
                <tr>
                    <td><?php echo (int) $index; ?></td>
                    <td><?php echo $start ? esc_html(date_i18n($date_format, strtotime($start))) : '&mdash;'; ?></td>
                    <td>
                        <?php if ($start && $end): ?>
                            <?php echo esc_html(date_i18n($time_format, strtotime($start))); ?>
                            &ndash;
                            <?php echo esc_html(date_i18n($time_format, strtotime($end))); ?>
                        <?php elseif ($start): ?>
                            <?php echo esc_html(date_i18n($time_format, strtotime($start))); ?>
                        <?php else: ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                </tr>
            <?php $index++; endforeach; ?>
        </tbody>
    </table>
</div>
