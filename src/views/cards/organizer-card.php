<?php

/**
 * Educator Card Template
 * 
 * @var WP_User $educator The educator user object
 * @var bool $show_distance Whether to show distance
 */

use function Breakdance\Util\WP\get_author_permalink;

$educator = $args['educator'];
$show_distance = $args['show_distance'] ?? false;

$user_id = 'user_' . $educator->ID;
$avatar = wp_get_attachment_image(get_field('organizer_profile_image', $user_id)); // Debug line to check avatar value
$suburb = get_field('organizer_suburb', $user_id);
$state_field = get_field('organizer_state', $user_id);
$upcoming_courses = get_educator_upcoming_courses($educator->ID, 5);
$total_courses = get_educator_total_upcoming_courses($educator->ID);
$remaining_courses = max(0, $total_courses - count($upcoming_courses));
?>

<div class="organizer-card" data-educator-id="<?php echo esc_attr($educator->ID); ?>">
  <!-- Educator Header -->
  <div class="educator-header">
    <?php if ($avatar) : ?>
      <div class="educator-avatar">
        <?php echo $avatar; ?>
      </div>
    <?php else : ?>
      <div class="educator-avatar-placeholder">
        <?php echo esc_html(strtoupper(substr($educator->display_name, 0, 1))); ?>
      </div>
    <?php endif; ?>
    <h4 class="educator-name"><a href="<?php echo esc_url(get_author_permalink($educator->ID)); ?>"><?php echo esc_html($educator->display_name); ?></a></h4>
    <?php if ($show_distance && isset($educator->distance)) : ?>
      <span class="educator-distance"><?php echo esc_html(number_format($educator->distance, 1)); ?>km away</span>
    <?php endif; ?>
  </div>

  <hr class="educator-divider">

  <?php
  $organizer_accreditation = get_field('organizer_accreditation', $user_id);
  if ($organizer_accreditation) : ?>
    <div class="organizer-card-icon-list educator-credentials">
      <!-- graduation hat icon -->
      <svg class="organizer-card-icon" xmlns=" http://www.w3.org/2000/svg" viewBox="0 0 32 32">
        <path d="M 16 4.875 L 15.53125 5.125 L 2.03125 12.125 L 0.3125 13 L 2 13.84375 L 2 22.28125 C 1.402344 22.628906 1 23.261719 1 24 C 1 25.105469 1.894531 26 3 26 C 4.105469 26 5 25.105469 5 24 C 5 23.261719 4.597656 22.628906 4 22.28125 L 4 14.875 L 6 15.90625 L 6 21 C 6 21.441406 6.203125 21.839844 6.4375 22.09375 C 6.671875 22.347656 6.957031 22.5 7.25 22.65625 C 7.839844 22.964844 8.539063 23.183594 9.40625 23.375 C 11.140625 23.761719 13.453125 24 16 24 C 18.546875 24 20.859375 23.761719 22.59375 23.375 C 23.460938 23.183594 24.160156 22.964844 24.75 22.65625 C 25.042969 22.5 25.328125 22.347656 25.5625 22.09375 C 25.796875 21.839844 26 21.441406 26 21 L 26 15.90625 L 29.96875 13.875 L 31.6875 13 L 29.96875 12.125 L 16.46875 5.125 Z M 16 7.125 L 27.3125 13 L 25.53125 13.90625 C 25.304688 13.667969 25.03125 13.492188 24.75 13.34375 C 24.164063 13.035156 23.460938 12.816406 22.59375 12.625 C 20.863281 12.238281 18.558594 12 16 12 C 13.441406 12 11.136719 12.238281 9.40625 12.625 C 8.539063 12.816406 7.835938 13.035156 7.25 13.34375 C 6.96875 13.492188 6.695313 13.667969 6.46875 13.90625 L 4.6875 13 Z M 16 14 C 18.441406 14 20.636719 14.222656 22.15625 14.5625 C 22.914063 14.730469 23.523438 14.925781 23.84375 15.09375 C 23.945313 15.148438 23.960938 15.1875 24 15.21875 L 24 19.03125 C 23.582031 18.878906 23.125 18.742188 22.59375 18.625 C 20.859375 18.238281 18.546875 18 16 18 C 13.453125 18 11.140625 18.238281 9.40625 18.625 C 8.875 18.742188 8.417969 18.878906 8 19.03125 L 8 15.21875 C 8.039063 15.1875 8.054688 15.148438 8.15625 15.09375 C 8.476563 14.925781 9.085938 14.730469 9.84375 14.5625 C 11.363281 14.222656 13.558594 14 16 14 Z M 16 20 C 18.425781 20 20.632813 20.222656 22.15625 20.5625 C 22.789063 20.703125 23.1875 20.851563 23.53125 21 C 23.1875 21.148438 22.789063 21.296875 22.15625 21.4375 C 20.632813 21.777344 18.425781 22 16 22 C 13.574219 22 11.367188 21.777344 9.84375 21.4375 C 9.210938 21.296875 8.8125 21.148438 8.46875 21 C 8.8125 20.851563 9.210938 20.703125 9.84375 20.5625 C 11.367188 20.222656 13.574219 20 16 20 Z" />
      </svg>


      <span class="credential-badge"><?php echo esc_html($organizer_accreditation); ?></span>

    </div>
  <?php endif; ?>

  <!-- Location -->
  <div class="organizer-card-icon-list educator-location">
    <svg class="organizer-card-icon icon-map-pin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
      <circle cx="12" cy="10" r="3"></circle>
    </svg>
    <span><?php echo esc_html($suburb); ?>, <?php echo esc_html($state_field); ?></span>
  </div>

  <!-- Upcoming Courses -->
  <div class="organizer-card-icon-list educator-courses">
    <svg class="organizer-card-icon icon-calendar" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
      <line x1="16" y1="2" x2="16" y2="6"></line>
      <line x1="8" y1="2" x2="8" y2="6"></line>
      <line x1="3" y1="10" x2="21" y2="10"></line>
    </svg>

    <div class="courses-list-wrapper">
      <?php if (empty($upcoming_courses)) : ?>
        <p class="no-courses">No upcoming courses scheduled</p>
      <?php else : ?>
        <ul class="courses-list" data-educator-id="<?php echo esc_attr($educator->ID); ?>">
          <?php foreach ($upcoming_courses as $index => $course) :
            $start_date = get_post_meta($course->ID, '_event_start_date', true);
            // $course_suburb = get_field('course_location_suburb', $course->ID);
            $display_class = $index < 5 ? '' : ' course-hidden';
          ?>
            <li class="course-item<?php echo $display_class; ?>">
              <!-- <a href="<?php echo esc_url(get_permalink($course->ID)); ?>"></a> -->
               <?php 
                $course_type = get_course_type($course->ID);
               ?>
              <span>
                <?php echo esc_html(date_i18n('M j, Y', strtotime($start_date))) . ' - ' . $course_type; ?>
                <!-- <?php if ($course_suburb) : ?>
                  - <?php echo esc_html($course_suburb); ?>
                <?php endif; ?> -->
              </span>
            </li>
          <?php endforeach; ?>
        </ul>


        <?php if ($remaining_courses > 0) : ?>
          <button class="load-more-courses" data-educator-id="<?php echo esc_attr($educator->ID); ?>" data-loaded="5" data-total="<?php echo esc_attr($total_courses); ?>">
            View <?php echo esc_html($remaining_courses); ?> more date<?php echo $remaining_courses !== 1 ? 's' : ''; ?>
          </button>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Book Now Button -->
  <?php if (!empty($upcoming_courses)) : ?>
    <a href="<?php echo esc_url(get_author_permalink($educator->ID)); ?>" class="button-atom button-atom--secondary bde-button__button">
      Book Now
    </a>
  <?php endif; ?>
</div>