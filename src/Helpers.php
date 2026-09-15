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

if (!function_exists('hmwevents_meta_icon')) {
    function hmwevents_meta_icon(string $meta_key): string
    {
        $icons = apply_filters('hmwevents_meta_icons', [
            'location' => '<path d="M8 14s6-3.5 6-7A6 6 0 002 7c0 3.5 6 7 6 7z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8" cy="7" r="1.5" stroke="currentColor" stroke-width="1.5"/>',
            'venue' => '<path d="M8 14s6-3.5 6-7A6 6 0 002 7c0 3.5 6 7 6 7z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/><circle cx="8" cy="7" r="1.5" stroke="currentColor" stroke-width="1.5"/>',
            'topic' => '<path d="M2 2.8c0-.44.36-.8.8-.8h4.5c.21 0 .42.08.57.23l5.9 5.9a.8.8 0 010 1.14l-4.5 4.5a.8.8 0 01-1.14 0l-5.9-5.9A.8.8 0 012 7.3V2.8z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="5.4" cy="5.4" r="1" fill="currentColor"/>',
            'delivery' => '<rect x="2" y="2.5" width="12" height="8.5" rx="1" stroke="currentColor" stroke-width="1.5"/><path d="M6 14h4M8 11v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
            'webinar_url' => '<rect x="2" y="2.5" width="12" height="8.5" rx="1" stroke="currentColor" stroke-width="1.5"/><path d="M6 14h4M8 11v3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
            'price' => '<path d="M8 1.5v13M10.8 4C10.2 3.4 9.2 3 8 3 6.3 3 5 3.9 5 5.1c0 2.8 6 1.5 6 4.5 0 1.3-1.3 2.2-3 2.2-1.4 0-2.6-.5-3.1-1.3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
            'start_date' => '<path d="M5.5 1v2m5-2v2M2 6.5h12M3.5 3h9a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 012 13.5v-9A1.5 1.5 0 013.5 3z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
            'end_date' => '<path d="M5.5 1v2m5-2v2M2 6.5h12M3.5 3h9a1.5 1.5 0 011.5 1.5v9a1.5 1.5 0 01-1.5 1.5h-9A1.5 1.5 0 012 13.5v-9A1.5 1.5 0 013.5 3z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
            'audience' => '<circle cx="5.5" cy="5" r="2.2" stroke="currentColor" stroke-width="1.5"/><path d="M1.8 13.5c0-2.1 1.7-3.5 3.7-3.5s3.7 1.4 3.7 3.5M11 8.2c1.8 0 3.2 1.3 3.2 3.1" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="11" cy="5.5" r="1.7" stroke="currentColor" stroke-width="1.5"/>',
            'event_program' => '<path d="M8 1.8l6 3-6 3-6-3 6-3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M2 8.3l6 3 6-3M2 11.3l6 3 6-3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>',
            'places_remaining' => '<path d="M2 5.5c0-.55.45-1 1-1h10c.55 0 1 .45 1 1v1.2a1.8 1.8 0 000 3.6v1.2c0 .55-.45 1-1 1H3c-.55 0-1-.45-1-1v-1.2a1.8 1.8 0 000-3.6V5.5z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M10 4.5v7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-dasharray="1.6 1.6"/>',
            'organizer' => '<circle cx="8" cy="5.2" r="2.4" stroke="currentColor" stroke-width="1.5"/><path d="M3.2 13.5c0-2.4 2.1-3.9 4.8-3.9s4.8 1.5 4.8 3.9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>',
        ], $meta_key);

        $markup = $icons[$meta_key] ?? '';

        if ($markup === '') {
            return '';
        }

        return '<svg class="hmwevents-meta-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">' . $markup . '</svg>';
    }
}