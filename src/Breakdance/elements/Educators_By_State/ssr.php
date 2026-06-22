<?php

/**
 * @var array $propertiesData
 */

// Cache key for transient
$cache_key = 'hmwevents_educators_by_state';
$cache_duration = 12 * HOUR_IN_SECONDS; // 12 hours

delete_transient($cache_key); // For testing purposes, remove in production
$states_data = get_transient($cache_key);

if (false === $states_data) {
  global $wpdb;
  $prefix = $wpdb->prefix;

  // Get count of educators per state from user meta
  $query = $wpdb->prepare(
    "SELECT  
          CASE  
              WHEN NULLIF(country.meta_value, '') = 'Australia' OR NULLIF(country_code.meta_value, '') = 'AU'  
              THEN COALESCE(NULLIF(um.meta_value, ''), 'Unknown') 
              ELSE COALESCE(NULLIF(country_code.meta_value, ''), NULLIF(country.meta_value, ''), 'Unknown') 
          END AS state_display, 
          CASE 
              WHEN NULLIF(country.meta_value, '') = 'Australia' OR NULLIF(country_code.meta_value, '') = 'AU' 
              THEN 'state' 
              ELSE 'country' 
          END AS search_type, 
          COUNT(*) AS educator_count 
      FROM {$wpdb->users} u 
      INNER JOIN {$prefix}usermeta um ON u.ID = um.user_id AND um.meta_key = %s 
      LEFT JOIN {$prefix}usermeta country ON u.ID = country.user_id AND country.meta_key = 'educator_country' 
      LEFT JOIN {$prefix}usermeta country_code ON u.ID = country_code.user_id AND country_code.meta_key = 'educator_country_code' 
      WHERE u.ID IN ( 
          SELECT user_id FROM {$prefix}usermeta  
          WHERE meta_key = %s AND meta_value LIKE %s 
      ) 
      AND um.meta_value IS NOT NULL 
      AND um.meta_value != '' 
      GROUP BY state_display 
      HAVING state_display != 'Unknown' 
      ORDER BY educator_count DESC",
    'educator_state',
    "{$prefix}capabilities",
    '%educator%'
  );

  $results = $wpdb->get_results($query);

  $states_data = array_map(function ($item) {
    $state_slug = strtolower($item->state_display);
    $search_prefix = $item->search_type === 'country' ? 'country:' : 'state:';
    return [
      'state' => $item->state_display,
      'count' => (int) $item->educator_count,
      'url' => get_the_permalink() . '?search=' . $search_prefix . urlencode($state_slug),
    ];
  }, $results);

  set_transient($cache_key, $states_data, $cache_duration);
}

$map_svg_path = HMWEvents_ABSPATH . '/resources/assets/map.svg';
if (file_exists($map_svg_path)) {
  $map_svg = file_get_contents($map_svg_path);
}
?>

<?php if (!empty($states_data)) : ?>
  <div class="state-grid">
    <?php foreach ($states_data as $state) : ?>
      <a data-state="<?php echo esc_attr($state['state']); ?>" href="<?php echo esc_url($state['url']); ?>" class="state-box">
        <div class="state-info">
          <div class="state-name"><?php echo esc_html($state['state']); ?></div>
          <div class="state-count"><?php echo esc_html($state['count']); ?> Educator<?php echo $state['count'] !== 1 ? 's' : ''; ?></div>
        </div>
        <div class="hmwevents-icon-arrow-wrapper">
          <?= bd_icon($propertiesData['content']['box_icon']['icon'], $propertiesData['design']['icon'], 'hmwevents-icon-arrow') ?>
        </div>
      </a>
    <?php endforeach; ?>
  </div>
<?php else : ?>
  <p>No educators found.</p>
<?php endif; ?>

<?= "<div class=\"educators-by-state-map\">{$map_svg}</div>"; ?>