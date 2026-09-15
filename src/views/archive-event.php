<?php

/**
 * Default event archive template.
 *
 * Used for event taxonomy archives and the event post type archive.
 * Renders the standard event listings grid (same cards as the
 * [hmw_event_listings] shortcode) without the filter bar.
 *
 * Override by placing hmw-events/archive-event.php in your theme, or use
 * the native WordPress names taxonomy-hmw_event_type.php or
 * archive-hmw_event.php.
 */

defined('ABSPATH') || exit;

get_header();

$term = get_queried_object();

$listings_service = new \HMWEvents\Services\EventListingService();

$listings_atts = apply_filters('hmwevents_event_archive_listings_atts', [
  'limit' => 12,
  'sort' => 'date',
  'sort_order' => 'ASC',
  'show_filters' => 'no',
], $term);

do_action('hmwevents_before_event_archive', $term);
?>

<div class="container">
  <div class="hmwevents-event-archive">

    <?php do_action('hmwevents_before_event_archive_header', $term); ?>

    <header class="hmwevents-archive-header">
      <?php
      $archive_title = is_post_type_archive() ? post_type_archive_title('', false) : single_term_title('', false);
      $title_html = '<h1 class="hmwevents-archive-title">' . esc_html((string) $archive_title) . '</h1>';
      echo apply_filters('hmwevents_event_archive_title_html', $title_html, $term);

      $archive_description = get_the_archive_description();
      if ($archive_description) {
        echo '<div class="hmwevents-archive-description">' . wp_kses_post($archive_description) . '</div>';
      }
      ?>
    </header>

    <?php do_action('hmwevents_after_event_archive_header', $term); ?>

    <div class="hmwevents-event-archive-listings">
      <?php
      if ($term instanceof \WP_Term) {
        echo $listings_service->render_term_listings($term, $listings_atts);
      } else {
        echo $listings_service->render_listings($listings_atts);
      }
      ?>
    </div>

    <?php do_action('hmwevents_after_event_archive', $term); ?>

  </div>
</div>

<?php
get_footer();
