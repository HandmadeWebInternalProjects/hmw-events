<?php

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * @var array $propertiesData
 * 
 * Educator Search Results Element
 * 
 * Supports multiple search types via single ?search= parameter:
 * - State: ?search=state:NSW
 * - Country: ?search=country:NZ
 * - Location: ?search=location:BERRY+2535
 * - Hospital: ?search=hospital
 * - Name: ?search=name:Kate
 * 
 * Also respects ?country=XX to narrow location searches to a specific country.
 */

// Include search functions
require_once __DIR__ . '/search-functions.php';

// Get and parse search parameter
$search_param = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

// Get country filter from URL (e.g. ?country=NZ)
$filter_country = '';
if (isset($_GET['country']) && $_GET['country']) {
  $filter_country = strtoupper(sanitize_text_field(wp_unslash($_GET['country'])));
}

// Parse search type and value
$state = '';
$country = '';
$location = '';
$hospital = false;
$name = '';

if ($search_param) {
  if (strpos($search_param, 'state:') === 0) {
    $state = substr($search_param, 6);
  } elseif (strpos($search_param, 'country:') === 0) {
    $country = substr($search_param, 8);
  } elseif (strpos($search_param, 'location:') === 0) {
    $location = substr($search_param, 9);
  } elseif ($search_param === 'hospital') {
    $hospital = true;
  } elseif (strpos($search_param, 'name:') === 0) {
    $name = substr($search_param, 5);
  } else {
    // Auto-detect search type based on input
    // If it's numeric and 4 digits, treat as postcode
    if (preg_match('/^\d{4}$/', $search_param)) {
      $location = $search_param;
    }
    // If it contains numbers or looks like "SUBURB POSTCODE", treat as location
    elseif (preg_match('/[0-9]/', $search_param) || preg_match('/^[A-Z\s]+\d{4}$/', $search_param)) {
      $location = $search_param;
    }
    // Otherwise treat as name search
    else {
      $name = $search_param;
    }
  }
}



// Build filters from URL parameters
$filters = [];
if (!empty($_GET['hmw_event_type'])) {
  $raw_course_types = explode(',', sanitize_text_field(wp_unslash($_GET['hmw_event_type'])));
  $filters['hmw_event_type'] = array_values(array_filter(array_map('sanitize_text_field', $raw_course_types)));
}

// Pagination - Read from URL query parameter
$paged = max(1, intval($_GET['pg'] ?? 1));
$per_page = 9;

$educators = [];
$show_distance = false;
$total = 0;
$max_pages = 0;

// Determine search type and fetch educators
if ($location) {
  $result = search_educators_by_location($location, $paged, $per_page, $filters, $filter_country);
  $educators = $result['educators'];
  $show_distance = $result['show_distance'];
  $total = $result['total'];
  $max_pages = $result['max_pages'];
} elseif ($state) {
  $result = search_events_by_state($state, $paged, $per_page, $filters);
  $educators = $result['educators'];
  $total = $result['total'];
  $max_pages = $result['max_pages'];
} elseif ($country) {
  $result = search_educators_by_country($country, $paged, $per_page, $filters);
  $educators = $result['educators'];
  $total = $result['total'];
  $max_pages = $result['max_pages'];
} elseif ($name) {
  $result = search_educators_by_name($name, $paged, $per_page, $filters);
  $educators = $result['educators'];
  $total = $result['total'];
  $max_pages = $result['max_pages'];
  
  // If name search returns no results, try as location search
  if (empty($educators)) {
    $result = search_educators_by_location($name, $paged, $per_page, $filters, $filter_country);
    $educators = $result['educators'];
    $show_distance = $result['show_distance'];
    $total = $result['total'];
    $max_pages = $result['max_pages'];
  }
} elseif ($hospital) {
  $result = search_educators_by_hospital($paged, $per_page, $filters);
  $educators = $result['educators'];
  $total = $result['total'];
  $max_pages = $result['max_pages'];
}

// var_dump($educators); exit;

// Apply hospital filter if combined with other searches
// if ($hospital && !empty($educators)) {
//   $educators = array_filter($educators, function ($educator) {
//     return get_field('educator_hospital', 'user_' . $educator->ID) === true;
//   });
// }


?>

<div class="event-search-results">

  <?php if (!empty($filters['hmw_event_type'])) : ?>
    <div class="active-filters">
      <span class="active-filters__label">Filtered by:</span>
      <?php foreach ($filters['hmw_event_type'] as $slug) :
        $term  = get_term_by('slug', $slug, 'hmw_event_type');
        $label = $term ? $term->name : $slug;
        $remaining = array_values(array_diff($filters['hmw_event_type'], [$slug]));
        $base_url  = remove_query_arg('pg');
        $remove_url = empty($remaining)
          ? remove_query_arg('hmw_event_type', $base_url)
          : add_query_arg('hmw_event_type', implode(',', $remaining), $base_url);
      ?>
        <a href="<?php echo esc_url($remove_url); ?>" class="active-filter-tag">
          <?php echo esc_html($label); ?>
          <span class="active-filter-tag__remove" aria-hidden="true">&times;</span>
          <span class="screen-reader-text">Remove filter</span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($educators)) : ?>
    <div class="no-results">
      <p>No organizers found matching your search criteria.</p>
    </div>
  <?php else : ?>
    <div class="results-count">
      <p><?php echo $total; ?> educator<?php echo $total !== 1 ? 's' : ''; ?> found</p>
    </div>

    <div class="organizers-grid">
      <?php foreach ($educators as $educator) :
        hmwevents_get_template_part(
          "cards/organizer-card",
          null,
          ['educator' => $educator, 'show_distance' => $show_distance]
        );
      endforeach; ?>
    </div>

    <?php if ($max_pages > 1) : ?>
      <div class="pagination">
        <?php
        $pagination = paginate_links([
          'base' => add_query_arg('pg', '%#%'),
          'format' => '?pg=%#%',
          'current' => $paged,
          'total' => $max_pages,
          'prev_text' => '←',
          'next_text' => '→',
          'type' => 'array',
          'mid_size' => 2,
          'end_size' => 1,
          'add_args' => ['search' => $search_param],
        ]);

        if ($pagination) :
          echo '<nav class="pagination-nav" aria-label="Pagination">';
          foreach ($pagination as $page) :
            echo $page;
          endforeach;
          echo '</nav>';
        endif;
        ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>