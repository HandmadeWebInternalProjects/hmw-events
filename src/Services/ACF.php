<?php

namespace HMWEvents\Services;

use HMWEvents\Registry\EventTypeRegistry;

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

    // Align event editor fields with template/type field config.
    add_filter('acf/prepare_field', [$this, 'apply_event_field_config']);

    // Hide recurrence fields on child sessions and cloned events.
    add_filter('acf/prepare_field', [$this, 'hide_recurrence_on_children'], 5);

    // Debug panel for field visibility.
    add_action('add_meta_boxes_hmw_event', [$this, 'add_debug_meta_box']);
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

  /**
   * Hide/require event fields based on resolved event field config.
   *
   * @param array $field
   * @return array|false
   */
  public function apply_event_field_config($field)
  {
    if (!is_array($field)) {
      return $field;
    }

    $name = $this->resolve_field_meta_name($field);
    if ($name === '') {
      return $field;
    }

    $post_id = $this->resolve_post_id();
    if ($post_id <= 0 || get_post_type($post_id) !== 'hmw_event') {
      return $field;
    }

    $event_field_key = $this->normalize_event_field_key($name);
    if (!str_starts_with($event_field_key, 'event_')) {
      return $field;
    }

    $config = $this->get_event_field_config($post_id);
    if (!$config) {
      static $logged_missing = [];
      if (empty($logged_missing[$post_id])) {
        $logged_missing[$post_id] = true;
        error_log(sprintf('HMWEvents ACF: No event_field_config for post %d. Type: %s', $post_id, get_post_type($post_id)));
      }
      return $field;
    }

    $hidden = $this->normalize_event_field_keys((array) ($config['hidden'] ?? []));
    $required = $this->normalize_event_field_keys((array) ($config['required'] ?? []));
    $optional = $this->normalize_event_field_keys((array) ($config['optional'] ?? []));

    if (in_array($event_field_key, $hidden, true)) {
      return false;
    }

    if (in_array($event_field_key, $required, true)) {
      $field['required'] = 1;
    } elseif (in_array($event_field_key, $optional, true)) {
      $field['required'] = 0;
    }

    return $field;
  }

  /**
   * Hide recurrence fields on child sessions and cloned events.
   *
   * Children (post_parent > 0 with _is_child_session)
   * and clones (_cloned_from meta) should not be configurable
   * as recurring events themselves.
   *
   * @param array $field
   * @return array|false
   */
  public function hide_recurrence_on_children($field)
  {
    $post_id = $this->resolve_post_id();
    if ($post_id <= 0 || get_post_type($post_id) !== 'hmw_event') {
      return $field;
    }

    $is_child = (bool) get_post_meta($post_id, '_is_child_session', true);
    $is_clone = !$is_child && (bool) get_post_meta($post_id, '_cloned_from', true);

    if (!$is_child && !$is_clone) {
      return $field;
    }

    $recurrence_fields = [
      'field_event_is_recurring',
      'field_event_recurrence_interval',
      'field_event_recurrence_unit',
      'field_event_recurrence_days',
      'field_event_recurrence_end_type',
      'field_event_recurrence_end_date',
      'field_event_recurrence_max_occurrences',
      'field_event_recurrence_custom_dates',
    ];

    $field_key = $field['key'] ?? '';
    if (in_array($field_key, $recurrence_fields, true)) {
      return false;
    }

    return $field;
  }

  private function resolve_field_meta_name(array $field): string
  {
    if (isset($field['_name']) && is_string($field['_name']) && $field['_name'] !== '') {
      return $field['_name'];
    }

    $name = $field['name'] ?? '';
    if (!is_string($name) || $name === '') {
      return '';
    }

    if (preg_match('/^acf\[([^\]]+)\]$/', $name, $m)) {
      return $this->acf_key_to_meta_name($m[1]);
    }

    return $name;
  }

  private function acf_key_to_meta_name(string $acf_key): string
  {
    if (function_exists('acf_get_field')) {
      $field_def = acf_get_field($acf_key);
      if (is_array($field_def) && isset($field_def['name']) && is_string($field_def['name'])) {
        return $field_def['name'];
      }
    }

    return $acf_key;
  }

  private function resolve_post_id(): int
  {
    if (function_exists('acf_get_form_data')) {
      $acf_post_id = acf_get_form_data('post_id');
      if ($acf_post_id && is_numeric($acf_post_id)) {
        return (int) $acf_post_id;
      }
    }

    global $post;
    if ($post instanceof \WP_Post) {
      return (int) $post->ID;
    }

    $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
    if ($post_id > 0) {
      return $post_id;
    }

    $post_id = isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0;
    if ($post_id > 0) {
      return $post_id;
    }

    $post_id = get_the_ID();
    if ($post_id > 0) {
      return (int) $post_id;
    }

    return 0;
  }

  /**
   * @return array<string,array>|null
   */
  private function get_event_field_config(int $post_id): ?array
  {
    $snapshot = get_post_meta($post_id, '_event_field_config', true);
    if (is_array($snapshot) && isset($snapshot['event_fields']) && is_array($snapshot['event_fields'])) {
      return $snapshot['event_fields'];
    }

    $terms = wp_get_object_terms($post_id, 'hmw_event_type', ['fields' => 'slugs']);
    if (is_wp_error($terms) || empty($terms)) {
      return null;
    }

    $type_slug = sanitize_key((string) $terms[0]);
    $type_config = EventTypeRegistry::get($type_slug);

    return [
      'required' => array_values(array_map('sanitize_key', (array) ($type_config['required_fields'] ?? []))),
      'optional' => [],
      'hidden'   => array_values(array_map('sanitize_key', (array) ($type_config['hidden_fields'] ?? []))),
    ];
  }

  private function normalize_event_field_key(string $field_name): string
  {
    $name = sanitize_key($field_name);

    if (str_starts_with($name, '_event_')) {
      return substr($name, 1);
    }

    return $name;
  }

  /**
   * @param array<int,string> $keys
   * @return string[]
   */
  private function normalize_event_field_keys(array $keys): array
  {
    $normalized = [];

    foreach ($keys as $key) {
      $key = sanitize_key((string) $key);
      if ($key === '') {
        continue;
      }

      if (str_starts_with($key, '_event_')) {
        $normalized[] = substr($key, 1);
      } else {
        $normalized[] = $key;
      }
    }

    return array_values(array_unique($normalized));
  }

  // ================================================================
  // DEBUG PANEL
  // ================================================================

  public function add_debug_meta_box(): void
  {
    if (!current_user_can('manage_options')) {
      return;
    }

    add_meta_box(
      'hmwevents-field-debug',
      'Template / Field Visibility Debug',
      [$this, 'render_debug_meta_box'],
      'hmw_event',
      'side',
      'low'
    );

    $this->capture_acf_filter_trace();
  }

  /**
   * Capture what the acf/prepare_field filter sees.
   * Saves to a persistent option read by the debug metabox.
   */
  private function capture_acf_filter_trace(): void
  {
    $trace = ['time' => current_time('mysql'), 'fields' => []];

    $capture = function ($field) use (&$trace) {
      if (!is_array($field)) {
        return $field;
      }
      $name = $this->resolve_field_meta_name($field);
      if ($name === '') {
        return $field;
      }

      $event_field_key = $this->normalize_event_field_key($name);

      $entry = [
        'acf_name'    => $name,
        'event_key'   => $event_field_key,
        'is_event'    => str_starts_with($event_field_key, 'event_'),
        'post_id'     => $this->resolve_post_id(),
        'post_type'   => get_post_type($this->resolve_post_id()),
        'action'      => 'passed_through',
      ];

      if ($entry['is_event'] && $entry['post_id'] > 0) {
        $config = $this->get_event_field_config($entry['post_id']);
        if ($config) {
          $hidden = $this->normalize_event_field_keys((array) ($config['hidden'] ?? []));
          if (in_array($event_field_key, $hidden, true)) {
            $entry['action'] = 'HIDDEN';
          } else {
            $required = $this->normalize_event_field_keys((array) ($config['required'] ?? []));
            if (in_array($event_field_key, $required, true)) {
              $entry['action'] = 'required';
            }
          }
        } else {
          $entry['action'] = 'no_config';
        }
      }

      $trace['fields'][] = $entry;
      return $field;
    };

    add_filter('acf/prepare_field', $capture, 9, 1);

    add_action('admin_footer', function () use (&$trace) {
      update_option('hmwevents_last_acf_trace', $trace, false);
    }, PHP_INT_MAX);
  }

  public function render_debug_meta_box(\WP_Post $post): void
  {
    $post_id = (int) $post->ID;

    // ── Source ──────────────────────────────────────────
    $has_snapshot = false;
    $type_slug = '';
    $type_source = 'none';

    $snapshot_config = get_post_meta($post_id, '_event_field_config', true);
    if (is_array($snapshot_config) && isset($snapshot_config['event_fields']) && is_array($snapshot_config['event_fields'])) {
      $has_snapshot = true;
      $type_source = 'snapshot';
    }

    $terms = wp_get_object_terms($post_id, 'hmw_event_type', ['fields' => 'all']);
    if (!is_wp_error($terms) && !empty($terms)) {
      $type_slug = sanitize_key((string) $terms[0]->slug);
      $type_source = $has_snapshot ? 'snapshot + taxonomy' : 'taxonomy';
    }

    $template_id = (int) get_post_meta($post_id, '_created_from_template_id', true);
    $template_version = (int) get_post_meta($post_id, '_created_from_template_version', true);

    echo '<div style="font-size:12px; line-height:1.6; max-height:500px; overflow-y:auto;">';

    echo '<p><strong>Event Type:</strong> <code>' . esc_html($type_slug ?: 'none') . '</code></p>';
    echo '<p><strong>Config source:</strong> ' . esc_html($type_source) . '</p>';
    if ($template_id > 0) {
      echo '<p><strong>Template:</strong> #' . (int) $template_id . ' (v' . (int) $template_version . ')</p>';
    }

    // ── Resolved config ─────────────────────────────────
    $config = $this->get_event_field_config($post_id);

    echo '<hr style="margin:8px 0;">';

    if (!$config) {
      echo '<p style="color:#b32d2e;"><strong>No event field config resolved.</strong></p>';
      echo '</div>';
      return;
    }

    $hidden   = $this->normalize_event_field_keys((array) ($config['hidden'] ?? []));
    $required = $this->normalize_event_field_keys((array) ($config['required'] ?? []));
    $optional = $this->normalize_event_field_keys((array) ($config['optional'] ?? []));

    $all_keys = array_values(array_unique(array_merge($hidden, $required, $optional)));
    sort($all_keys);

    echo '<table style="width:100%; border-collapse:collapse; font-size:11px;">';
    echo '<thead><tr>';
    echo '<th style="text-align:left; padding:2px 4px;">Field</th>';
    echo '<th style="text-align:center; padding:2px 4px;">State</th>';
    echo '<th style="text-align:left; padding:2px 4px;">ACF name</th>';
    echo '</tr></thead><tbody>';

    foreach ($all_keys as $key) {
      if (in_array($key, $hidden, true)) {
        $state = '<span style="color:#b32d2e;" title="hidden">HIDDEN</span>';
      } elseif (in_array($key, $required, true)) {
        $state = '<span style="color:#2271b1;" title="required">REQUIRED</span>';
      } elseif (in_array($key, $optional, true)) {
        $state = '<span style="color:#50575e;" title="optional">optional</span>';
      } else {
        $state = '<span style="color:#767676;" title="unlisted">—</span>';
      }

      $acf_name = '_' . $key;

      echo '<tr>';
      echo '<td style="padding:1px 4px;"><code>' . esc_html($key) . '</code></td>';
      echo '<td style="padding:1px 4px; text-align:center;">' . $state . '</td>';
      echo '<td style="padding:1px 4px; color:#767676;"><code>' . esc_html($acf_name) . '</code></td>';
      echo '</tr>';
    }

    echo '</tbody></table>';

    // ── Raw snapshot dump ────────────────────────────────
    if ($has_snapshot) {
      echo '<hr style="margin:8px 0;">';
      echo '<p><strong>Raw _event_field_config:</strong></p>';
      echo '<pre style="font-size:10px; line-height:1.3; background:#f6f7f7; padding:4px; overflow-x:auto;">';
      echo esc_html(wp_json_encode($snapshot_config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      echo '</pre>';
    }

    $type_config = EventTypeRegistry::get($type_slug ?: '');
    echo '<hr style="margin:8px 0;">';
    echo '<p><strong>EventTypeRegistry defaults for <code>' . esc_html($type_slug ?: 'none') . '</code>:</strong></p>';
    echo '<pre style="font-size:10px; line-height:1.3; background:#f6f7f7; padding:4px; overflow-x:auto;">';
    echo esc_html(wp_json_encode([
      'hidden'   => $type_config['hidden_fields'] ?? [],
      'required' => $type_config['required_fields'] ?? [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    echo '</pre>';

    // ── ACF filter trace ─────────────────────────────────
    $trace = get_option('hmwevents_last_acf_trace');
    if (is_array($trace) && !empty($trace['fields'])) {
      echo '<hr style="margin:8px 0;">';
      echo '<p><strong>acf/prepare_field trace (from last page load):</strong></p>';
      echo '<p style="font-size:10px;">Total fields captured: ' . count($trace['fields']) . ' | Captured at ' . esc_html($trace['time'] ?? '?') . '</p>';
      echo '<table style="width:100%; border-collapse:collapse; font-size:10px;">';
      echo '<thead><tr>';
      echo '<th style="text-align:left; padding:1px 2px;">ACF name</th>';
      echo '<th style="text-align:left; padding:1px 2px;">Event key</th>';
      echo '<th style="text-align:center; padding:1px 2px;">is_event</th>';
      echo '<th style="text-align:center; padding:1px 2px;">post_id</th>';
      echo '<th style="text-align:center; padding:1px 2px;">post_type</th>';
      echo '<th style="text-align:center; padding:1px 2px;">ACTION</th>';
      echo '</tr></thead><tbody>';
      foreach ($trace['fields'] as $entry) {
        $action_style = 'color:#767676;';
        if ($entry['action'] === 'HIDDEN') {
          $action_style = 'color:#b32d2e; font-weight:bold;';
        } elseif ($entry['action'] === 'required') {
          $action_style = 'color:#2271b1; font-weight:bold;';
        } elseif ($entry['action'] === 'no_config') {
          $action_style = 'color:#dba617;';
        }
        echo '<tr>';
        echo '<td style="padding:1px 2px;"><code>' . esc_html($entry['acf_name']) . '</code></td>';
        echo '<td style="padding:1px 2px;"><code>' . esc_html($entry['event_key']) . '</code></td>';
        echo '<td style="padding:1px 2px; text-align:center;">' . ($entry['is_event'] ? 'Y' : '-') . '</td>';
        echo '<td style="padding:1px 2px; text-align:center;">' . (int) $entry['post_id'] . '</td>';
        echo '<td style="padding:1px 2px; text-align:center;"><code>' . esc_html($entry['post_type'] ?: '-') . '</code></td>';
        echo '<td style="padding:1px 2px; text-align:center;"><span style="' . $action_style . '">' . esc_html($entry['action']) . '</span></td>';
        echo '</tr>';
      }
      echo '</tbody></table>';
      echo '<details style="margin-top:4px;"><summary style="font-size:10px; cursor:pointer;">Raw trace JSON</summary>';
      echo '<pre style="font-size:10px; line-height:1.3; background:#f6f7f7; padding:4px; overflow-x:auto; max-height:300px;">';
      echo esc_html(wp_json_encode($trace, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
      echo '</pre></details>';
      echo '<p style="font-size:10px; color:#767676; margin-top:4px;">Refresh to re-trace.</p>';
    } else {
      echo '<hr style="margin:8px 0;">';
      echo '<p style="font-size:10px; color:#767676;">No filter trace captured yet. Refresh this page to arm the trace, then refresh again to see results.</p>';
    }

    echo '</div>';
  }
}
