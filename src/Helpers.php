<?php

/**
 * Core Functions available globally on frontend and admin both.
 *
 * @package HMWEvents
 */

use HMWEvents\Services\DatabaseService;

defined('ABSPATH') || die('Don\'t run this file directly!');


if (!function_exists('is_administrator')) {
    function is_administrator()
    {
        return current_user_can('administrator');
    }
}

if (!function_exists('acf_active')) {
    function acf_active()
    {
        return function_exists('get_field');
    }
}

function getAllAdmins()
{
    return get_users(['role' => 'administrator']);
}

if (!function_exists('flash')) {
    /**
     * Get the flash service instance
     *
     * @param string|null $key
     * @param mixed $message
     * @return \HMWEvents\Services\SessionFlash|mixed
     */
    function flash($key = null, $message = null)
    {
        $flash = \HMWEvents\HMWEvents::instance()->flash;

        if ($key === null) {
            return $flash;
        }

        if ($message === null) {
            return \HMWEvents\Services\SessionFlash::get($key);
        }

        return $flash->message($key, $message);
    }
}


/**
     * Convert RGB color to RGBA with specified opacity.
     *
     * @param string $rgb_color RGB color string like 'rgb(255, 0, 0)'
     * @param float $opacity Opacity value between 0 and 1
     * @return string RGBA color string
     */
function rgbToRgba($rgb_color, $opacity = 1.0)
{
    // Extract RGB values from the color string
    if (preg_match('/rgb\((\d+),\s*(\d+),\s*(\d+)\)/', $rgb_color, $matches)) {
        $r = $matches[1];
        $g = $matches[2];
        $b = $matches[3];
        return "rgba($r, $g, $b, $opacity)";
    }

    // Fallback if pattern doesn't match
    return str_replace('rgb', 'rgba', str_replace(')', ", $opacity)", $rgb_color));
}

/**
     * Convert hex color to RGB format.
     *
     * @param string $hex_color Hex color like '#8B5CF6' or '8B5CF6'
     * @return string RGB color string like 'rgb(139, 92, 246)'
     */
function hexToRgb($hex_color)
{
    // Remove # if present
    $hex_color = ltrim($hex_color, '#');

    // Convert to RGB
    if (strlen($hex_color) === 6) {
        $r = hexdec(substr($hex_color, 0, 2));
        $g = hexdec(substr($hex_color, 2, 2));
        $b = hexdec(substr($hex_color, 4, 2));
        return "rgb($r, $g, $b)";
    }

    // Fallback to default purple if invalid hex
    return 'rgb(148, 0, 211)';
}


if (!function_exists('bs_get_field')) {
    function bs_get_field(string $field, int | string $id, string $default = null): mixed
    {
        return match (true) {
            acf_active() && get_field($field, $id) && strpos($field, '.') === false => get_field($field, $id),
            strpos($field, '.') !== false => (function () use ($field, $id) {
                $subfields = explode('.', $field);
                $parent_field = array_shift($subfields);
                $repeater = get_field($parent_field, $id);

                if (empty($subfields) || !is_array($repeater)) {
                    return $repeater;
                }

                $subfield_path = implode('.', $subfields);
                return implode(', ', array_map(function ($row) use ($subfield_path) {
                    return bs_get_field($subfield_path, $row);
                }, $repeater));
            })(),
            default => $default,
        };
    }
}

if (!function_exists('bs_update_field')) {
    function bs_update_field(string $field, mixed $value, int | string $id): void
    {
        if (!acf_active()) {
            return;
        }
        update_field($field, $value, $id);
    }
}

if (!function_exists('disable_admin_bar')) {
    add_action('init', 'disable_admin_bar');
    function disable_admin_bar()
    {
        if (!is_administrator() && !is_admin()) {
            show_admin_bar(false);
        }
    }
}

if (!function_exists('hmwevents_get_template_part')) {
  function hmwevents_get_template_part($slug, $name = null, $args = [])
  {
    $templates = [];
    if ($name) {
      $templates[] = "hmw-events/{$slug}-{$name}.php";
    }
    $templates[] = "hmw-events/{$slug}.php";

    // Check child theme override first (via hmw-events/ namespace)
    $located = locate_template($templates, false);

    if (!$located) {
      // Fallback to bare paths for backward compat
      $bare = [];
      if ($name) {
        $bare[] = "{$slug}-{$name}.php";
      }
      $bare[] = "{$slug}.php";
      $located = locate_template($bare, false);
    }

    if (!$located) {
      // Fallback to plugin templates
      foreach ($templates as $template) {
        $plugin_path = VIEWS_PATH . str_replace('hmw-events/', '', $template);
        if (file_exists($plugin_path)) {
          $located = $plugin_path;
          break;
        }
      }
    }

    if ($located && file_exists($located)) {
      load_template($located, false, $args);
    }
  }
}

if (!function_exists('hmwevents_get_template')) {
  function hmwevents_get_template($template_name, $args = [], $template_path = '', $default_path = '')
  {
    $located = hmwevents_locate_template($template_name, $template_path, $default_path);

    if ($located && file_exists($located)) {
      load_template($located, false, $args);
    }
  }
}

if (!function_exists('hmwevents_locate_template')) {
  function hmwevents_locate_template($template_name, $template_path = '', $default_path = '')
  {
    if (!$template_path) {
      $template_path = 'hmw-events';
    }

    if (!$default_path) {
      $default_path = VIEWS_PATH;
    }

    $template = locate_template([
      trailingslashit($template_path) . $template_name,
      $template_name, // backward compat: bare path in theme root
    ]);

    if (!$template) {
      $template = $default_path . $template_name;
    }

    return apply_filters('hmwevents_locate_template', $template, $template_name, $template_path);
  }
}

if (!function_exists('is_breakdance_editing')) {
  function is_breakdance_editing()
  {
    // Use Breakdance's built-in function if available
    if (function_exists('\Breakdance\isRequestFromBuilderIframe')) {
      return \Breakdance\isRequestFromBuilderIframe();
    }

    // Fallback to constant check
    return defined('BREAKDANCE_EDITING_MODE') && BREAKDANCE_EDITING_MODE === true;
  }
}

if (!function_exists('bd_icon')) {
  function bd_icon($iconPath, $designPath, $className = 'icon')
  {
    $twig = \Breakdance\Render\Twig::getInstance();
    $template = "{{ macros.atomV1IconHtml('" . $className . "', content.icon.icon, false, false, design.icon) }}";
    $result = $twig->runTwig($template, [
      'content' => [
        'icon' => [
          'icon' => $iconPath ?? [],
          'rotate' => '',
          'link' => ''
        ]
      ],
      'design' => [
        'icon' => $designPath ?? []
      ]
    ]);
    return $result;
  }
}

if (!function_exists('has_shortcode_in_breakdance_tree')) {
 /**
     * Recursively search Breakdance tree for shortcode.
     *
     * @since 1.0.0
     * @param array  $tree Breakdance tree structure.
     * @param string $shortcode Shortcode to search for.
     * @return bool True if shortcode found.
     */
    function has_shortcode_in_breakdance_tree($tree, $shortcode)
    {
        if (!is_array($tree)) {
            return false;
        }

        // Check if current node has the shortcode
        if (isset($tree['data']['properties']['content']['shortcode']['full_shortcode'])) {
            $full_shortcode = $tree['data']['properties']['content']['shortcode']['full_shortcode'];
            if (strpos($full_shortcode, '[' . $shortcode) !== false) {
                return true;
            }
        }

        // Recursively check children
        if (isset($tree['children']) && is_array($tree['children'])) {
            foreach ($tree['children'] as $child) {
                if (has_shortcode_in_breakdance_tree($child, $shortcode)) {
                    return true;
                }
            }
        }

        // Check root children if this is the top level
        if (isset($tree['root']['children']) && is_array($tree['root']['children'])) {
            foreach ($tree['root']['children'] as $child) {
                if (has_shortcode_in_breakdance_tree($child, $shortcode)) {
                    return true;
                }
            }
        }

        return false;
    }
} 