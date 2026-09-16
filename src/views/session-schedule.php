<?php

defined('ABSPATH') || die("Don't run this file directly!");

$event    = $args['event'] ?? null;
$sessions = $args['sessions'] ?? [];
if (!$event || empty($sessions)) {
    return;
}

$date_format = get_option('date_format');
$time_format = get_option('time_format');
$event_data  = new \HMWEvents\Services\EventDataService();

$grouped = [];
foreach ($sessions as $session) {
    $start = $event_data->get_start_date($session->ID);
    $end   = $event_data->get_end_date($session->ID);
    $key   = $start ? substr($start, 0, 10) : 'unknown';
    $grouped[$key][] = [
        'session' => $session,
        'start'   => $start,
        'end'     => $end,
    ];
}

$visible_days  = (int) apply_filters('hmwevents_session_schedule_visible_days', 4);
$total_days    = count($grouped);
$collapsible   = $visible_days >= 0 && $total_days > $visible_days;
$schedule_id   = 'hmwevents-session-schedule-' . (int) $event->ID;
$rendered_days = 0;

$classes = $collapsible
    ? ['hmwevents-session-schedule', 'hmwevents-session-schedule--collapsible']
    : ['hmwevents-session-schedule'];
$schedule_classes = implode(' ', apply_filters('hmwevents_session_schedule_classes', $classes, $event, $grouped));

$show_all_label = sprintf(
    /* translators: %s: total number of session dates. */
    _n('Show all %s date', 'Show all %s dates', $total_days, 'hmw-events'),
    number_format_i18n($total_days)
);
$show_fewer_label = __('Show fewer dates', 'hmw-events');
?>

<div class="<?php echo esc_attr($schedule_classes); ?>" id="<?php echo esc_attr($schedule_id); ?>">
    <h3><?php esc_html_e('Session Schedule', 'hmw-events'); ?></h3>
    <table class="hmwevents-session-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Date', 'hmw-events'); ?></th>
                <th><?php esc_html_e('Sessions', 'hmw-events'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($grouped as $day => $slots): ?>
                <?php
                $rendered_days++;
                $is_extra  = $collapsible && $rendered_days > $visible_days;
                $row_class = $is_extra ? ' class="hmwevents-session-schedule__extra"' : '';
                $day_label = $day !== 'unknown'
                    ? esc_html(date_i18n($date_format, strtotime($day)))
                    : '&mdash;';
                $multiple = count($slots) > 1;
                ?>
                <tr<?php echo $row_class; ?> <?php echo $is_extra ? 'hidden' : ''; ?>>
                    <td><?php echo $day_label; ?></td>
                    <td>
                        <div class="<?php echo esc_attr(implode(' ', apply_filters('hmwevents_session_slot_classes', $multiple ? ['hmwevents-session-slots'] : [], $day, $slots))); ?>">
                            <?php foreach ($slots as $slot): ?>
                                <?php
                                $start = $slot['start'];
                                $end   = $slot['end'];
                                $permalink = get_permalink($slot['session']->ID);

                                if ($start && $end) {
                                    $time_text = date_i18n($time_format, strtotime($start)) . ' &ndash; ' . date_i18n($time_format, strtotime($end));
                                } elseif ($start) {
                                    $time_text = date_i18n($time_format, strtotime($start));
                                } else {
                                    $time_text = __('Time TBA', 'hmw-events');
                                }
                                ?>
                                <span class="<?php echo esc_attr(implode(' ', apply_filters('hmwevents_session_slot_item_classes', ['hmwevents-session-slot'], $slot['session'], $day))); ?>">
                                    <?php if ($permalink) : ?>
                                        <a href="<?php echo esc_url($permalink); ?>"><?php echo wp_kses_post($time_text); ?></a>
                                    <?php else : ?>
                                        <?php echo wp_kses_post($time_text); ?>
                                    <?php endif; ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($collapsible) : ?>
        <button
            type="button"
            class="hmwevents-session-schedule__toggle"
            aria-expanded="false"
            aria-controls="<?php echo esc_attr($schedule_id); ?>"
            data-collapsed-label="<?php echo esc_attr($show_all_label); ?>"
            data-expanded-label="<?php echo esc_attr($show_fewer_label); ?>"
        ><?php echo esc_html($show_all_label); ?></button>
    <?php endif; ?>
</div>
