<?php

namespace HMWEvents\Services;

class ACF
{
  public function register()
  {
    if (!class_exists('ACF_Pro')) {
      add_action('admin_notices', function () {
        echo '<div class="error"><p>ACF is not installed. Please install it to use this plugin.</p></div>';
      });
      return;
    }

    // Always allow saving and loading
    add_filter("acf/settings/save_json", [$this, 'custom_acf_json_save_point']);
    add_filter('acf/settings/load_json', [$this, 'custom_acf_json_load_point']);
  }


  public function custom_acf_json_save_point($path)
  {
    return HMWEvents_ABSPATH . 'acf-json';
  }

  public function custom_acf_json_load_point($paths)
  {
    // Remove the original path (optional).
    unset($paths[0]);

    // Append the new path and return it.
    $paths[] = HMWEvents_ABSPATH . 'acf-json';

    return $paths;
  }
}
