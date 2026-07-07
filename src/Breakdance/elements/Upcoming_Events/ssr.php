
<?php
/**
 * @var array $propertiesData
 */

// This element will render on the author archive page.
$author = get_queried_object_id();

if ($author) {

  $upcoming_events = get_posts([
    'post_type' => 'hmw_event',
    'posts_per_page' => -1,
    'author' => $author,
    'meta_key' => '_event_start_date',
    'orderby' => 'meta_value',
    'order' => 'ASC',
    'meta_query' => [
      [
        'key' => '_event_start_date',
        'value' => current_time('mysql'),
        'compare' => '>=',
        'type' => 'DATETIME',
      ],
      [
        'key' => 'course_cutoff_date',
        'value' => current_time('mysql'),
        'compare' => '>=',
        'type' => 'DATETIME',
      ]
    ],
  ]);

  if ($upcoming_events) {
    $initial_limit = 5;
    $total = count($upcoming_events);
    $has_more = $total > $initial_limit;

    foreach ($upcoming_events as $index => $course) {
      if ($index === $initial_limit) {
        echo '<div class="upcoming-events-hidden" style="display:none;">';
      }
      hmwevents_get_template_part('cards/upcoming-event-card', null, ['course' => $course, 'propertiesData' => $propertiesData]);
    }

    if ($has_more) {
      echo '</div>';
      echo '<div class="upcoming-events-load-more-wrap">';
      echo '<button type="button" class="upcoming-events-load-more breakdance-link button-atom button-atom--secondary bde-button__button" data-per-page="' . $initial_limit . '">';
      echo esc_html__('View more events', 'hmw-events');
      echo '</button>';
      echo '</div>';
    }
  } else {
    echo '<p>No upcoming events found.</p>';
  }

}