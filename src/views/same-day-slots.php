<?php

defined('ABSPATH') || die("Don't run this file directly!");

/**
 * "Other sessions on {date}" strip shown above the booking form on
 * session pages that share their date with sibling sessions.
 *
 * @var WP_Post $event The session being viewed.
 * @var array   $slots Sibling slots: ['session' => WP_Post, 'start' => string, 'end' => string].
 */

$event = $args['event'] ?? null;
$slots = $args['slots'] ?? [];
if (!$event || empty($slots)) {
    return;
}

$event_data        = new \HMWEvents\Services\EventDataService();
$capacity_service  = new \HMWEvents\Services\CapacityService();

$date_format = get_option('date_format');
$time_format = get_option('time_format');

$own_start = $event_data->get_start_date($event->ID);
$own_end   = $event_data->get_end_date($event->ID);
$day_label = $own_start ? date_i18n($date_format, strtotime(substr($own_start, 0, 10))) : '';

$heading = sprintf(__('Other sessions on %s', 'hmw-events'), $day_label);
$heading = apply_filters('hmwevents_same_day_slots_heading', $heading, $event, $slots);
?>

<div class="<?php echo esc_attr(implode(' ', apply_filters('hmwevents_same_day_slots_classes', ['hmwevents-same-day-slots'], $event))); ?>">
    <h3 class="hmwevents-same-day-slots-title"><?php echo esc_html($heading); ?></h3>

    <div class="hmwevents-session-slots">
        <?php if ($own_start) : ?>
            <span class="hmwevents-session-slot hmwevents-session-slot--current">
                <?php if ($own_start && $own_end) : ?>
                    <?php echo esc_html(date_i18n($time_format, strtotime($own_start))); ?>
                    &ndash;
                    <?php echo esc_html(date_i18n($time_format, strtotime($own_end))); ?>
                <?php else : ?>
                    <?php echo esc_html(date_i18n($time_format, strtotime($own_start))); ?>
                <?php endif; ?>
            </span>
        <?php endif; ?>

        <?php foreach ($slots as $slot) : ?>
            <?php
            $session  = $slot['session'];
            $capacity = $capacity_service->get_capacity($session->ID);
            $is_full  = $capacity > 0 && $capacity_service->get_remaining_places($session->ID) <= 0;

            $classes = apply_filters(
                'hmwevents_session_slot_item_classes',
                ['hmwevents-session-slot'],
                $session,
                substr((string) $slot['start'], 0, 10)
            );
            if ($is_full) {
                $classes[] = 'hmwevents-session-slot--full';
            }

            $permalink = get_permalink($session->ID);
            ?>
            <<?php echo $permalink ? 'a' : 'span'; ?>
                class="<?php echo esc_attr(implode(' ', $classes)); ?>"
                <?php if ($permalink) : ?>
                    href="<?php echo esc_url($permalink); ?>"
                <?php endif; ?>
            >
                <?php if ($slot['start'] && $slot['end']) : ?>
                    <?php echo esc_html(date_i18n($time_format, strtotime($slot['start']))); ?>
                    &ndash;
                    <?php echo esc_html(date_i18n($time_format, strtotime($slot['end']))); ?>
                <?php elseif ($slot['start']) : ?>
                    <?php echo esc_html(date_i18n($time_format, strtotime($slot['start']))); ?>
                <?php else : ?>
                    <?php esc_html_e('Time TBA', 'hmw-events'); ?>
                <?php endif; ?>
                <?php if ($is_full) : ?>
                    <em>(<?php esc_html_e('Full', 'hmw-events'); ?>)</em>
                <?php endif; ?>
            </<?php echo $permalink ? 'a' : 'span'; ?>>
        <?php endforeach; ?>
    </div>
</div>
