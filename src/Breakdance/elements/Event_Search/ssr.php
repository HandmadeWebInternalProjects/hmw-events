<?php
$search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';

// Fetch supported countries from the locations plugin table
global $wpdb;
$table = $wpdb->prefix . 'hmw_location_country_lookup';
$countries = $wpdb->get_results("SELECT shortcode, name FROM {$table} ORDER BY id ASC", ARRAY_A);

// Determine current country (from URL or default to first)
$current_country = '';
if (isset($_GET['country']) && $_GET['country']) {
  $current_country = strtoupper(sanitize_text_field(wp_unslash($_GET['country'])));
}
// Validate against known countries
$valid_codes = array_column($countries, 'shortcode');
if (!in_array($current_country, $valid_codes, true)) {
  $current_country = $countries[0]['shortcode'] ?? 'AU';
}

$flags_url = wp_upload_dir()['baseurl'] . '/country_flag_icons/';

echo htmlspecialchars(json_encode([
  'search'         => $search,
  'countries'      => $countries ?: [],
  'currentCountry' => $current_country,
  'flagsUrl'       => $flags_url,
]), ENT_QUOTES, 'UTF-8');

