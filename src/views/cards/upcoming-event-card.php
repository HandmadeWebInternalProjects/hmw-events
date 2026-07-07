<?php

use HMWEvents\Helpers\EventHelper;

$event = $args['event'] ?? $args['course'] ?? null;
$propertiesData = $args['propertiesData'] ?? [];

if (!$event) {
    return;
}

$title       = get_the_title($event->ID);
$start       = get_post_meta($event->ID, '_event_start_date', true);
$end         = get_post_meta($event->ID, '_event_end_date', true);
$link        = get_permalink($event->ID);
$venue       = get_post_meta($event->ID, '_event_venue_name', true);
$note        = get_post_meta($event->ID, '_event_booking_notes', true);

$type_terms  = get_the_terms($event->ID, 'hmw_event_type');
$type_slug   = ($type_terms && !is_wp_error($type_terms)) ? $type_terms[0]->slug : 'general';
$type_name   = ($type_terms && !is_wp_error($type_terms)) ? $type_terms[0]->name : __('Event', 'hmw-events');
$type_icon   = '';
$type_color  = '#3b82f6';

if ($type_terms && !is_wp_error($type_terms) && !empty($type_terms[0])) {
    $icon_id = get_field('icon', 'hmw_event_type_' . $type_terms[0]->term_id);
    if ($icon_id) {
        $type_icon = wp_get_attachment_image($icon_id, 'full');
    }
    $term_color = get_field('event_type_color', 'hmw_event_type_' . $type_terms[0]->term_id);
    if ($term_color) {
        $type_color = $term_color;
    }
}

$capacity = (int) get_post_meta($event->ID, '_event_capacity', true);
$availability_text = '';
if ($capacity > 0) {
    $booked = \HMWEvents\Helpers\EventHelper::calculate_course_availability($event->ID)['booked_count'] ?? 0;
    $available = $capacity - $booked;
    if ($available <= 0) {
        $availability_text = __('Fully booked', 'hmw-events');
    } elseif ($available <= 3) {
        $availability_text = sprintf(__('Filling up fast! Only %d %s left', 'hmw-events'), $available, $available === 1 ? __('spot', 'hmw-events') : __('spots', 'hmw-events'));
    }
}

$enableAccordion = false;
?>

<div class="upcoming-event-card <?= esc_attr($type_slug) ?>" style="background-color: <?= esc_attr($type_color) ?>;">
  <div class="event-head">
    <div class="event-type"><?= $type_icon ?><?= esc_html($type_name) ?></div>
    <?php if ($enableAccordion): ?>
      <?= bd_icon($propertiesData['content']['icons']['accordion'], $propertiesData['design']['accordion_icon'], 'event-accordion-icon') ?>
    <?php endif; ?>
  </div>
  <h5><a href="<?php echo esc_url($link); ?>"><?php echo esc_html($title); ?></a></h5>

  <div class="event-meta">
    <?php if ($start || $end): ?>
      <div class="event-meta-icon event-dates">
        <?= bd_icon($propertiesData['content']['icons']['dates'], $propertiesData['design']['date_icon'], 'event-date-icon') ?>
        <span>
          <?php
          if ($start) echo esc_html(date_i18n('d/m/Y', strtotime($start)));
          if ($end) echo ' - ' . esc_html(date_i18n('d/m/Y', strtotime($end)));
          ?>
        </span>
      </div>
    <?php endif; ?>
    <?php if ($venue): ?>
      <div class="event-meta-icon event-location">
        <?= bd_icon($propertiesData['content']['icons']['location'], $propertiesData['design']['location_icon'], 'event-location-icon') ?>
        <span><?php echo esc_html($venue); ?></span>
      </div>
    <?php endif; ?>

    <?php if ($availability_text): ?>
      <div class="event-meta-icon event-availability">
        <?= bd_icon($propertiesData['content']['icons']['event_availability'], $propertiesData['design']['event_availability_icon'], 'event-availability-icon') ?>
        <span><?php echo esc_html($availability_text); ?></span>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($enableAccordion): ?>
    <div class="event-reveal-content">
      <?php if ($note): ?>
        <div class="event-meta-icon event-notes">
          <?= bd_icon($propertiesData['content']['icons']['notes'], $propertiesData['design']['notes_icon'], 'event-notes-icon') ?>
          <?php echo esc_html($note); ?>
        </div>
      <?php endif; ?>
      <a href="<?php echo esc_url($link); ?>" class="book-now-button breakdance-link button-atom button-atom--custom bde-button__button"><?php esc_html_e('Book Now', 'hmw-events'); ?></a>
    </div>
  <?php else: ?>
    <a href="<?php echo esc_url($link); ?>" class="book-now-button breakdance-link button-atom button-atom--custom bde-button__button"><?php esc_html_e('Book Now', 'hmw-events'); ?></a>
  <?php endif; ?>
</div>
