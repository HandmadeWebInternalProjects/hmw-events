<?php

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Educator Search Functions
 * 
 * Shared search functions for SSR and API
 */

use HandmadeWeb\Locations\BusinessLogic\LocationManager;
use HandmadeWeb\Locations\Models\LocationLookup;

/**
 * Get educator user IDs who have courses matching the given filters.
 *
 * Returns null when no filter is active (meaning: do not restrict).
 * Returns an empty array when a filter is active but no courses match.
 *
 * @param array $filters Associative array of filter keys/values.
 * @return int[]|null
 */
function get_organizer_ids_for_filters(array $filters): ?array {
  if (empty($filters['hmw_event_type'])) {
    return null;
  }

  $posts = get_posts([
    'post_type'      => 'hmw_event',
    'posts_per_page' => -1,
    'post_status'    => 'publish',
    'tax_query'      => [[
      'taxonomy' => 'hmw_event_type',
      'field'    => 'slug',
      'terms'    => (array) $filters['hmw_event_type'],
    ]],
  ]);

  return array_values(array_unique(array_map(fn($p) => (int) $p->post_author, $posts)));
}

/**
 * Search organizers by location (postcode or suburb) — searches educator profiles directly.
 *
 * Uses educator_lat/educator_long user meta to find educators within $radius_km of the
 * searched location. Educators without a geocoded profile address are silently excluded.
 *
 * @param string $location Postcode (4 digits) or suburb/locality name
 * @param int $paged Current page number
 * @param int $per_page Results per page
 * @param array $filters Optional filters (e.g. ['hmw_event_type' => ['slug1', 'slug2']])
 * @param float $radius_km Search radius in kilometres (default 50)
 * @param string $country Country code to restrict lookup to (e.g. 'AU', 'NZ')
 * @return array ['educators' => array, 'show_distance' => bool, 'total' => int, 'max_pages' => int]
 */
function search_educators_by_location_direct($location, $paged = 1, $per_page = 10, $filters = [], $radius_km = 2000, $country = '') {
  global $wpdb;

  // Resolve location string to lat/lng via the postcode lookup table.
  // For pure 4-digit postcodes use averageCenter (centroid of all rows for that postcode).
  // For suburb/locality names use findByQuery (exact then partial match).
  $coords = null;
  if (preg_match('/^\d{4}$/', trim($location))) {
    $coords = LocationLookup::averageCenter(trim($location));
  }

  if (!$coords && $country) {
    $coords = LocationLookup::findByQuery($location, $country);
  } elseif (!$coords) {
    // Fallback: try supported countries in order
    $coords = LocationLookup::findByQuery($location, 'AU')
           ?? LocationLookup::findByQuery($location, 'NZ')
           ?? LocationLookup::findByQuery($location, 'PH')
           ?? LocationLookup::findByQuery($location, 'CA');
  }

  error_log("Searching educators by location: resolved '$location' to coords: " . print_r($coords, true));

  if (!$coords || !isset($coords['lat'], $coords['long'])) {
    return ['educators' => [], 'show_distance' => true, 'total' => 0, 'max_pages' => 0];
  }

  $search_lng = (float) $coords['long'];
  $search_lat = (float) $coords['lat'];

  // Apply hmw_event_type filter.
  $filtered_educator_ids = get_organizer_ids_for_filters($filters);
  if ($filtered_educator_ids !== null && empty($filtered_educator_ids)) {
    return ['educators' => [], 'show_distance' => true, 'total' => 0, 'max_pages' => 0];
  }

  // Fetch educator user IDs via the WP API (handles role checking correctly).
  $educator_query_args = ['role' => 'educator', 'fields' => 'ID'];
  if ($filtered_educator_ids !== null) {
    $educator_query_args['include'] = $filtered_educator_ids;
  }
  $all_educator_ids = get_users($educator_query_args);

  if (empty($all_educator_ids)) {
    return ['educators' => [], 'show_distance' => true, 'total' => 0, 'max_pages' => 0];
  }

  // Radius search: JOIN on educator_lat and educator_long user meta, compute distance
  // with ST_Distance_Sphere (returns metres; divide by 1000 for km).
  // POINT(x, y) = POINT(longitude, latitude) — the convention for SRID 0 with ST_Distance_Sphere.
  $id_placeholders = implode(',', array_fill(0, count($all_educator_ids), '%d'));

  $sql = "
    SELECT u.ID,
      ROUND(ST_Distance_Sphere(
        POINT(CAST(lng_meta.meta_value AS DECIMAL(10,6)), CAST(lat_meta.meta_value AS DECIMAL(10,6))),
        POINT(%f, %f)
      ) / 1000, 2) AS distance
    FROM {$wpdb->users} u
    INNER JOIN {$wpdb->usermeta} lat_meta
      ON u.ID = lat_meta.user_id
      AND lat_meta.meta_key = 'organizer_lat'
      AND lat_meta.meta_value != ''
    INNER JOIN {$wpdb->usermeta} lng_meta
      ON u.ID = lng_meta.user_id
      AND lng_meta.meta_key = 'organizer_long'
      AND lng_meta.meta_value != ''
    WHERE u.ID IN ($id_placeholders)
    HAVING distance IS NOT NULL AND distance <= %f
    ORDER BY distance ASC
  ";

  $params = array_merge(
    [$search_lng, $search_lat],
    array_map('intval', $all_educator_ids),
    [(float) $radius_km]
  );

  $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params), ARRAY_A);

  if (empty($rows)) {
    return ['educators' => [], 'show_distance' => true, 'total' => 0, 'max_pages' => 0];
  }

  // Build ID => distance map. SQL returns rows already sorted ASC by distance.
  $educator_distances = [];
  foreach ($rows as $row) {
    $educator_distances[(int) $row['ID']] = (float) $row['distance'];
  }

  $sorted_ids  = array_keys($educator_distances);
  $total       = count($sorted_ids);
  $max_pages   = (int) ceil($total / $per_page);
  $paged_ids   = array_slice($sorted_ids, ($paged - 1) * $per_page, $per_page);

  if (empty($paged_ids)) {
    return ['educators' => [], 'show_distance' => true, 'total' => $total, 'max_pages' => $max_pages];
  }

  $educators = get_users(['include' => $paged_ids]);

  foreach ($educators as $educator) {
    $educator->distance = $educator_distances[$educator->ID] ?? 0;
  }

  // Re-sort by distance since get_users does not respect include order.
  usort($educators, fn($a, $b) => $a->distance <=> $b->distance);

  return [
    'educators'     => $educators,
    'show_distance' => true,
    'total'         => $total,
    'max_pages'     => $max_pages,
  ];
}

/**
 * Search organizers by location (postcode or suburb) — searches via educator courses.
 *
 * @param string $location Location search term
 * @param int $paged Current page number
 * @param int $per_page Results per page
 * @param array $filters Optional filters (e.g. ['hmw_event_type' => ['slug1', 'slug2']])
 * @param string $country Country code to restrict educator meta query to
 * @return array ['educators' => array, 'show_distance' => bool, 'total' => int, 'max_pages' => int]
 */
function search_educators_by_location($location, $paged = 1, $per_page = 10, $filters = [], $country = '') {
  $location_manager = new LocationManager();
  $args = [
    'post_type' => 'hmw_event',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'meta_query' => [
      [
        'key' => '_event_start_date',
        'value' => current_time('mysql'),
        'compare' => '>=',
        'type' => 'DATETIME',
      ],
    ],
  ];

  if (!empty($filters['hmw_event_type'])) {
    $args['tax_query'] = [[
      'taxonomy' => 'hmw_event_type',
      'field'    => 'slug',
      'terms'    => (array) $filters['hmw_event_type'],
    ]];
  }

  $results = $location_manager->find($location, 2500, $args); // 50km radius

  if (is_wp_error($results) || empty($results['posts'])) {
    return ['educators' => [], 'show_distance' => true, 'total' => 0, 'max_pages' => 0];
  }

  // Get unique educators from courses
  $educator_ids = [];
  $educator_distances = [];

  foreach ($results['posts'] as $course) {
    $educator_id = $course->post_author;
    if (!isset($educator_ids[$educator_id])) {
      $educator_ids[$educator_id] = true;
      $educator_distances[$educator_id] = $course->distance ?? 0;
    } else {
      if (isset($course->distance) && $course->distance < $educator_distances[$educator_id]) {
        $educator_distances[$educator_id] = $course->distance;
      }
    }
  }

  // var_dump($educator_ids, $educator_distances); // DEBUG

  // Sort all educator IDs by distance before paginating
  asort($educator_distances);
  $sorted_educator_ids = array_keys($educator_distances);
  $total_educators = count($sorted_educator_ids);
  $max_pages = ceil($total_educators / $per_page);

  // Paginate by distance order in PHP, then fetch only that page's IDs
  $paged_educator_ids = array_slice($sorted_educator_ids, ($paged - 1) * $per_page, $per_page);

  $educators = get_users([
    'role' => 'educator',
    'include' => $paged_educator_ids,
  ]);

  // Add distance to educator objects
  foreach ($educators as $educator) {
    $educator->distance = $educator_distances[$educator->ID] ?? 0;
  }

  // Re-sort by distance since get_users does not respect include order
  usort($educators, function ($a, $b) {
    return $a->distance <=> $b->distance;
  });

  // Filter by country if specified
  if ($country) {
    $educators = array_filter($educators, function ($educator) use ($country) {
      $edu_country = get_field('organizer_country_code', 'user_' . $educator->ID);
      return $edu_country && strtoupper($edu_country) === strtoupper($country);
    });
    $educators = array_values($educators);
    $total_educators = count($educators);
    $max_pages = ceil($total_educators / $per_page);
  }

  return [
    'educators' => $educators, 
    'show_distance' => true,
    'total' => $total_educators,
    'max_pages' => $max_pages
  ];
}

/**
 * Search organizers by state
 * 
 * @param string $state State code
 * @param int $paged Current page number
 * @param int $per_page Results per page
 * @param array $filters Optional filters (e.g. ['hmw_event_type' => ['slug1', 'slug2']])
 * @return array ['educators' => array, 'total' => int, 'max_pages' => int]
 */
function search_events_by_state($state, $paged = 1, $per_page = 10, $filters = []) {
  $filtered_educator_ids = get_organizer_ids_for_filters($filters);
  if ($filtered_educator_ids !== null && empty($filtered_educator_ids)) {
    return ['educators' => [], 'total' => 0, 'max_pages' => 0];
  }

  $user_args = [
    'role'       => 'educator',
    'meta_query' => [[
      'key'     => 'organizer_state',
      'value'   => $state,
      'compare' => '=',
    ]],
  ];

  if ($filtered_educator_ids !== null) {
    $user_args['include'] = $filtered_educator_ids;
  }

  $total_educators = count(get_users(array_merge($user_args, ['fields' => 'ID'])));

  $educators = get_users(array_merge($user_args, [
    'number' => $per_page,
    'offset' => ($paged - 1) * $per_page,
  ]));

  return [
    'educators' => $educators,
    'total'     => $total_educators,
    'max_pages' => ceil($total_educators / $per_page),
  ];
}

/**
 * Search organizers by country (for non-Australian educators)
 *
 * Matches against educator_country_code (e.g. "NZ") or educator_country
 * (e.g. "New Zealand"), whichever was used as the URL slug.
 *
 * @param string $country Country code or name (case-insensitive)
 * @param int $paged Current page number
 * @param int $per_page Results per page
 * @param array $filters Optional filters (e.g. ['hmw_event_type' => ['slug1', 'slug2']])
 * @return array ['educators' => array, 'total' => int, 'max_pages' => int]
 */
function search_educators_by_country($country, $paged = 1, $per_page = 10, $filters = []) {
  $filtered_educator_ids = get_organizer_ids_for_filters($filters);
  if ($filtered_educator_ids !== null && empty($filtered_educator_ids)) {
    return ['educators' => [], 'total' => 0, 'max_pages' => 0];
  }

  $user_args = [
    'role'       => 'educator',
    'meta_query' => [
      'relation' => 'OR',
      [
        'key'     => 'organizer_country_code',
        'value'   => $country,
        'compare' => '=',
      ],
      [
        'key'     => 'organizer_country',
        'value'   => $country,
        'compare' => '=',
      ],
    ],
  ];

  if ($filtered_educator_ids !== null) {
    $user_args['include'] = $filtered_educator_ids;
  }

  $total_educators = count(get_users(array_merge($user_args, ['fields' => 'ID'])));

  $educators = get_users(array_merge($user_args, [
    'number' => $per_page,
    'offset' => ($paged - 1) * $per_page,
  ]));

  return [
    'educators' => $educators,
    'total'     => $total_educators,
    'max_pages' => ceil($total_educators / $per_page),
  ];
}

/**
 * Search organizers by name
 * 
 * @param string $name Search term
 * @param int $paged Current page number
 * @param int $per_page Results per page
 * @param array $filters Optional filters (e.g. ['hmw_event_type' => ['slug1', 'slug2']])
 * @return array ['educators' => array, 'total' => int, 'max_pages' => int]
 */
function search_educators_by_name($name, $paged = 1, $per_page = 10, $filters = []) {
  $filtered_educator_ids = get_organizer_ids_for_filters($filters);
  if ($filtered_educator_ids !== null && empty($filtered_educator_ids)) {
    return ['educators' => [], 'total' => 0, 'max_pages' => 0];
  }

  $user_args = [
    'role'           => 'educator',
    'search'         => '*' . $name . '*',
    'search_columns' => ['display_name', 'user_login', 'user_email'],
  ];

  if ($filtered_educator_ids !== null) {
    $user_args['include'] = $filtered_educator_ids;
  }

  $total_educators = count(get_users(array_merge($user_args, ['fields' => 'ID'])));

  $educators = get_users(array_merge($user_args, [
    'number' => $per_page,
    'offset' => ($paged - 1) * $per_page,
  ]));

  return [
    'educators' => $educators,
    'total'     => $total_educators,
    'max_pages' => ceil($total_educators / $per_page),
  ];
}

/**
 * Search hospital-certified educators
 * 
 * @param int $paged Current page number
 * @param int $per_page Results per page
 * @param array $filters Optional filters (e.g. ['hmw_event_type' => ['slug1', 'slug2']])
 * @return array ['educators' => array, 'total' => int, 'max_pages' => int]
 */
function search_educators_by_hospital($paged = 1, $per_page = 10, $filters = []) {
  $filtered_educator_ids = get_organizer_ids_for_filters($filters);
  if ($filtered_educator_ids !== null && empty($filtered_educator_ids)) {
    return ['educators' => [], 'total' => 0, 'max_pages' => 0];
  }

  $user_args = [
    'role'       => 'educator',
    'meta_query' => [[
      'key'     => 'organizer_hospital',
      'value'   => '1',
      'compare' => '=',
    ]],
  ];

  if ($filtered_educator_ids !== null) {
    $user_args['include'] = $filtered_educator_ids;
  }

  $total_educators = count(get_users(array_merge($user_args, ['fields' => 'ID'])));

  $educators = get_users(array_merge($user_args, [
    'number' => $per_page,
    'offset' => ($paged - 1) * $per_page,
  ]));

  return [
    'educators' => $educators,
    'total'     => $total_educators,
    'max_pages' => ceil($total_educators / $per_page),
  ];
}

/**
 * Get upcoming courses for an educator
 * 
 * @param int $educator_id WordPress user ID
 * @param int $limit Maximum number of courses to return
 * @return array Array of course posts
 */
function get_upcoming_events_v2($educator_id, $limit = 5)
{
  $args = [
    'post_type' => 'hmw_event',
    'author' => $educator_id,
    'posts_per_page' => $limit,
    'post_status' => 'publish',
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
    ],
  ];

  return get_posts($args);
}

/**
 * Get total count of upcoming courses for an educator
 * 
 * @param int $educator_id WordPress user ID
 * @return int Total count
 */
function get_organizer_total_upcoming_courses($educator_id)
{
  $args = [
    'post_type' => 'hmw_event',
    'author' => $educator_id,
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'fields' => 'ids',
    'meta_query' => [
      [
        'key' => '_event_start_date',
        'value' => current_time('mysql'),
        'compare' => '>=',
        'type' => 'DATETIME',
      ],
    ],
  ];

  $courses = get_posts($args);
  return count($courses);
}
