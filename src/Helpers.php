<?php

/**
 * Core Functions available globally on frontend and admin both.
 *
 * @package HMWEvents
 */

defined('ABSPATH') || die('Don\'t run this file directly!');


if (!function_exists('bs_get_field')) {
    function bs_get_field(string $field, int | string $id, string $default = null): mixed
    {
        return match (true) {
            function_exists('get_field') && get_field($field, $id) && strpos($field, '.') === false => get_field($field, $id),
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

if (!function_exists('disable_admin_bar')) {
    add_action('init', 'disable_admin_bar');
    function disable_admin_bar()
    {
        if (!current_user_can('administrator') && !is_admin()) {
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