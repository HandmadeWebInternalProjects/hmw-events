<?php

use \HMWEvents\Helpers\Course;

$course = $args['course'] ?? null;
$propertiesData = $args['propertiesData'] ?? [];

$course_title = get_the_title($course->ID);
$course_start_date = get_field('course_start_date', $course->ID);
$course_end_date = get_field('course_end_date', $course->ID);
$course_link = get_permalink($course->ID);
$course_location = get_field('course_location_address', $course->ID);
$booking_notes = get_field('booking_notes', $course->ID);

$course_type = get_the_terms($course->ID, 'course_type');
$course_type_slug = $course_type ? $course_type[0]->slug : 'general';
$course_type_color = '#3b82f6'; // Default color
$course_type_icon = wp_get_attachment_image(get_field('icon', 'course_type_' . $course_type[0]->term_id), 'full');

$course_availability_count = Course::calculate_course_availability($course->ID);

$course_availability_text = match (true) {
  ($course_availability_count['available_count'] === 0) => 'Fully booked',
  ($course_availability_count['available_count'] <= 3) => sprintf('Filling up fast! Only %d %s left', $course_availability_count['available_count'], $course_availability_count['available_count'] === 1 ? 'spot' : 'spots'),
  default => null,
};

// Get the color from ACF if course type exists
if ($course_type && !empty($course_type[0])) {
  $term_color = get_field('course_type_color', 'course_type_' . $course_type[0]->term_id);
  if ($term_color) {
    $course_type_color = $term_color;
  }
}

// Choose between book now always visible, or revealed on accordion click along with some content
$enableAccordion = false;

// var_dump($propertiesData['design']['accordion_icon']); exit;

?>

<div class="upcoming-course-card <?= esc_attr($course_type_slug) ?>" style="background-color: <?= esc_attr($course_type_color) ?>;">
  <div class="course-head">
    <div class="course-type"><?= $course_type_icon ?><?= esc_html($course_type[0]->name) ?></div>
    <?php if ($enableAccordion): ?>
      <?= bd_icon($propertiesData['content']['icons']['accordion'], $propertiesData['design']['accordion_icon'], 'course-accordion-icon') ?>
    <?php endif; ?>
  </div>
  <h5><a href="<?php echo esc_url($course_link); ?>"><?php echo esc_html($course_title); ?></a></h5>

  <div class="course-meta">
    <?php if ($course_start_date || $course_end_date): ?>
      <div class="course-meta-icon course-dates">
        <?= bd_icon($propertiesData['content']['icons']['dates'], $propertiesData['design']['date_icon'], 'course-date-icon') ?>
        <span>
          <?php
          if ($course_start_date) {
            echo esc_html(date('d/m/Y', strtotime($course_start_date)));
          }
          if ($course_end_date) {
            echo ' - ' . esc_html(date('d/m/Y', strtotime($course_end_date)));
          }
          ?>
        </span>
      </div>
    <?php endif; ?>
    <?php if ($course_location): ?>
      <div class="course-meta-icon course-location">
        <?= bd_icon($propertiesData['content']['icons']['location'], $propertiesData['design']['location_icon'], 'course-location-icon') ?>
        <span><?php echo esc_html($course_location['address'] ?? ''); ?></span>
      </div>
    <?php endif; ?>

    <?php if ($course_availability_text): ?>
      <div class="course-meta-icon course-availability">
        <?= bd_icon($propertiesData['content']['icons']['course_availability'], $propertiesData['design']['course_availability_icon'], 'course-availability-icon') ?>
        <span><?php echo esc_html($course_availability_text); ?></span>
      </div>
    <?php endif; ?>
  </div>


  <? if ($enableAccordion): ?>
    <div class="course-reveal-content">
      <?php if ($booking_notes): ?>
        <div class="course-meta-icon course-notes">
          <?= bd_icon($propertiesData['content']['icons']['notes'], $propertiesData['design']['notes_icon'], 'course-notes-icon') ?>
          <?php echo __($booking_notes); ?>
        </div>
      <?php endif; ?>
      <a href="<?php echo esc_url($course_link); ?>" class="book-now-button breakdance-link button-atom button-atom--custom bde-button__button">Book Now</a>
    </div>
  <?php else: ?>
    <a href="<?php echo esc_url($course_link); ?>" class="book-now-button breakdance-link button-atom button-atom--custom bde-button__button">Book Now</a>
  <?php endif; ?>
</div>