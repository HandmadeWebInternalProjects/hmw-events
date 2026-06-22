<?php 

namespace HMWEvents\Helpers;

defined('ABSPATH') || die("Don't run this file directly!");

class GoogleMapField
{
  /**
   * Register ACF filters to ensure Google Map field values are always arrays.
   *
   * Runs at priority 20 (after ACF's own formatting) so any string or invalid
   * value is normalised to false before third-party code (e.g. Breakdance) reads it.
   *
   * @return void
   */
  public static function register_hooks(): void
  {
    add_filter('acf/format_value/type=google_map', function ($value) {
      if (!is_array($value)) {
        return false;
      }
      return $value;
    }, 20, 1);
  }

  /**
   * Parse Google Map field value into components.
   * 
   * Attempts to extract street, suburb, state, and postcode from a Google Map address.
   * Falls back to simple parsing if components aren't available.
   *
   * @since 1.0.0
   * @param array|string|null $map_field The Google Map field value from ACF.
   * @return array Array with 'street', 'suburb', 'state', 'postcode', 'full_address', 'lat', 'lng'.
   */
  public static function parse_google_map_address($map_field)
  {
    $default = [
      'street' => '',
      'suburb' => '',
      'state' => '',
      'country' => '',
      'country_code' => '',
      'postcode' => '',
      'full_address' => '',
      'lat' => '',
      'lng' => '',
    ];

    if (empty($map_field)) {
      return $default;
    }

    // Handle Google Map array format
    if (is_array($map_field)) {
      $result = [
        'full_address' => $map_field['address'] ?? '',
        'lat' => $map_field['lat'] ?? '',
        'lng' => $map_field['lng'] ?? '',
      ];

      // Use Google's structured address components if available
      if (isset($map_field['street_number']) && isset($map_field['street_name'])) {
        $result['street'] = trim($map_field['street_number'] . ' ' . $map_field['street_name']);
      } else {
        // Fallback: parse from full address
        if (!empty($result['full_address'])) {
          $parts = array_map('trim', explode(',', $result['full_address']));
          $result['street'] = $parts[0] ?? '';
        }
      }

      // Use city/suburb field
      $result['suburb'] = $map_field['city'] ?? '';

      // Use state_short for consistent format (NSW, VIC, etc.)
      $result['state'] = $map_field['state_short'] ?? ($map_field['state'] ?? '');

      // Use country field
      $result['country'] = $map_field['country'] ?? '';

      // Use country_short field for consistent country code (e.g., US, AU)
      $result['country_code'] = $map_field['country_short'] ?? '';

      // Use post_code field
      $result['postcode'] = $map_field['post_code'] ?? '';

      return array_merge($default, $result);
    }

    // Handle legacy text format
    if (is_string($map_field)) {
      return array_merge($default, [
        'street' => $map_field,
        'full_address' => $map_field,
      ]);
    }

    return $default;
  }
}