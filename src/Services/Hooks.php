<?php 

namespace HMWEvents\Services;

use HMWEvents\PostTypes\Event;
use HMWEvents\Taxonomies\EventAudience;
use HMWEvents\Taxonomies\EventDeliveryMode;
use HMWEvents\Taxonomies\EventType;

defined('ABSPATH') || die("Don't run this file directly!");

class Hooks
{
  public static function register()
  {
    // Register hooks and filters here
    add_filter('posts_where', [static::class, 'exclude_archived_events_from_search'], 10, 2);
    // Fix Content Control + Search & Filter Pro pagination on the shop page
    add_action('pre_get_posts', [static::class, 'exclude_restricted_products_pre_query'], 10);

    add_filter('template_include', [static::class, 'breakdance_pdf_voucher_fix'], 999999);

    // Provide default single event template (theme-overridable via hmw-events/ in theme)
    add_filter('single_template', [static::class, 'single_event_template']);

    // Provide default event archive template (theme-overridable via hmw-events/ in theme)
    add_filter('taxonomy_template', [static::class, 'event_archive_template']);
    add_filter('archive_template', [static::class, 'event_archive_template']);

    add_action('hmwevents_event_meta_bottom', [static::class, 'render_session_schedule']);

    add_action('hmwevents_after_event_content', [static::class, 'render_same_day_slots'], 10);

    add_action('transition_post_status', [static::class, 'on_event_cancelled'], 10, 3);

    add_action('wp_head', [static::class, 'noindex_archived_events']);
  }


  public static function breakdance_pdf_voucher_fix($template)
  {
    $queried = get_queried_object();
    $voucherPostTypes = ['wc_voucher_template', 'wc_voucher'];
    $isVoucherPost = $queried instanceof \WP_Post && in_array($queried->post_type, $voucherPostTypes, true);
    $isCustomizerPreview = is_customize_preview();

    if ($isVoucherPost || ($isCustomizerPreview && isset($_GET['url']) && strpos($_GET['url'], 'wc_voucher_template') !== false)) {
      remove_filter('template_include', 'Breakdance\ActionsFilters\template_include', 1000000);
      remove_filter('the_content', '\Breakdance\ActionsFilters\replace_the_content_with_breakdance_content', -2147483648);
    }
    return $template;
  }

  /**
   * Exclude Content Control-restricted products from WP_Query before SQL runs.
   * This ensures Search & Filter Pro pagination counts are accurate.
   *
   * @param \WP_Query $query
   * @return void
   */
  public static function exclude_restricted_products_pre_query(\WP_Query $query): void
  {
    if (is_admin() || $query->get('ignore_restrictions')) {
      return;
    }

    $post_types = (array) $query->get('post_type');
    if (!in_array('product', $post_types, true)) {
      return;
    }

    if (!function_exists('ContentControl\content_is_restricted')) {
      return;
    }

    $restricted_ids = static::get_content_control_restricted_product_ids();

    if (empty($restricted_ids)) {
      return;
    }

    $existing = (array) $query->get('post__not_in');
    $query->set('post__not_in', array_unique(array_merge($existing, $restricted_ids)));
  }

  /**
   * Get all published product IDs that are restricted for the current user.
   * Uses a static cache so the sub-query only runs once per request.
   *
   * @return int[]
   */
  private static function get_content_control_restricted_product_ids(): array
  {
    static $cached = null;

    if ($cached !== null) {
      return $cached;
    }

    // Fetch all published product IDs, bypassing Content Control on this sub-query.
    $all_ids = get_posts([
      'post_type'           => 'product',
      'posts_per_page'      => -1,
      'fields'              => 'ids',
      'post_status'         => 'publish',
      'no_found_rows'       => true,
      'ignore_restrictions' => true, // Content Control respects this flag
    ]);

    $restricted = [];
    foreach ($all_ids as $id) {
      if (\ContentControl\content_is_restricted((int) $id)) {
        $restricted[] = (int) $id;
      }
    }

    $cached = $restricted;
    return $cached;
  }

  /**
   * Provide a default single event template, overridable by theme.
   *
   * Theme override priority:
   *   1. {theme}/single-hmw_event.php (WordPress native)
   *   2. {theme}/hmw-events/single-event.php (namespaced)
   *   3. Plugin default: src/views/single-event.php
   */
  public static function single_event_template(string $template): string
  {
    if (get_post_type() !== Event::POST_TYPE) {
      return $template;
    }

    $theme_template = locate_template(['single-hmw_event.php']);
    if ($theme_template) {
      return $theme_template;
    }

    $plugin_template = \hmwevents_locate_template('single-event.php');
    if ($plugin_template && file_exists($plugin_template)) {
      return $plugin_template;
    }

    return $template;
  }

  /**
   * Provide a default event archive template for event taxonomies and the
   * event post type archive, rendering the standard listings grid.
   *
   * Theme override priority:
   *   1. {theme}/taxonomy-{taxonomy}.php or {theme}/archive-hmw_event.php (WordPress native, event-specific)
   *   2. {theme}/hmw-events/archive-event.php (namespaced)
   *   3. Plugin default: src/views/archive-event.php
   *
   * Generic templates (archive.php, taxonomy.php) never take priority over
   * the plugin default. Disable entirely with the
   * hmwevents_event_archive_enabled filter.
   */
  public static function event_archive_template(string $template): string
  {
    if (!apply_filters('hmwevents_event_archive_enabled', true)) {
      return $template;
    }

    $taxonomies = apply_filters('hmwevents_event_archive_taxonomies', [
      EventType::TAXONOMY,
      EventAudience::TAXONOMY,
      EventDeliveryMode::TAXONOMY,
    ]);

    $native_candidates = [];
    if (is_tax($taxonomies)) {
      $queried = get_queried_object();
      if ($queried instanceof \WP_Term) {
        $native_candidates[] = 'taxonomy-' . $queried->taxonomy . '.php';
      }
    } elseif (is_post_type_archive(Event::POST_TYPE)) {
      $native_candidates[] = 'archive-hmw_event.php';
    } else {
      return $template;
    }

    $theme_template = locate_template($native_candidates);
    if ($theme_template) {
      return $theme_template;
    }

    $plugin_template = \hmwevents_locate_template('archive-event.php');
    if ($plugin_template && file_exists($plugin_template)) {
      return $plugin_template;
    }

    return $template;
  }

  /**
   * Exclude archived and cancelled hmw_event posts from frontend search results.
   *
   * @param string   $where The WHERE clause of the query.
   * @param \WP_Query $query The current WP_Query instance.
   * @return string Modified WHERE clause.
   */
  public static function exclude_archived_events_from_search(string $where, \WP_Query $query): string
  {
    if (is_admin()) {
      return $where;
    }

    $post_types = (array) $query->get('post_type');

    $involves_events = in_array(Event::POST_TYPE, $post_types, true)
      || in_array('any', $post_types, true)
      || empty(array_filter($post_types));

    if (!$involves_events) {
      return $where;
    }

    if ($query->is_singular(Event::POST_TYPE)) {
      return $where;
    }

    if ($query->is_main_query() && ($query->get('name') || $query->get('pagename'))) {
      $pt = $query->get('post_type');
      if ($pt === Event::POST_TYPE || $pt === [Event::POST_TYPE]) {
        return $where;
      }
    }

    global $wpdb;
    $where .= $wpdb->prepare(
      " AND NOT ({$wpdb->posts}.post_type = %s AND {$wpdb->posts}.post_status IN (%s, %s))",
      Event::POST_TYPE,
      'archived',
      'cancelled'
    );

    return $where;
  }

  public static function noindex_archived_events(): void
  {
    if (!is_singular(Event::POST_TYPE)) {
      return;
    }

    $post = get_post();
    if (!$post || !in_array($post->post_status, ['archived', 'cancelled'], true)) {
      return;
    }

    echo '<meta name="robots" content="noindex, nofollow">' . "\n";
  }

  public static function render_session_schedule(\WP_Post $event): void
  {
    $sessions = self::get_all_sessions($event);

    if (empty($sessions)) {
      return;
    }

    self::enqueue_session_schedule_styles();
    self::enqueue_session_schedule_scripts();

    hmwevents_get_template_part('session-schedule', null, ['event' => $event, 'sessions' => $sessions]);
  }

  private static function enqueue_session_schedule_scripts(): void
  {
    $js_path = \HMWEvents\HMWEvents::plugin_path() . '/assets/js/session-schedule.js';

    if (!file_exists($js_path)) {
      return;
    }

    wp_enqueue_script(
      'hmwevents-session-schedule',
      \HMWEvents\HMWEvents::plugin_url() . '/assets/js/session-schedule.js',
      [],
      filemtime($js_path),
      true
    );
  }

  /**
   * All published sessions to display on the parent event's schedule:
   * child sessions (pattern recurrence) plus standalone custom-date
   * clones (_cloned_from), merged and sorted by start datetime.
   *
   * @return \WP_Post[]
   */
  public static function get_all_sessions(\WP_Post $event): array
  {
    $sessions = (new SessionService())->get_sessions($event->ID, 'publish');

    $clones = get_posts([
      'post_type'      => Event::POST_TYPE,
      'post_status'    => 'publish',
      'posts_per_page' => -1,
      'meta_key'       => '_cloned_from',
      'meta_value'     => $event->ID,
    ]) ?: [];

    if (empty($clones)) {
      return $sessions;
    }

    $all = array_merge($sessions, $clones);

    usort($all, static function ($a, $b) {
      return strcmp(
        (string) get_post_meta($a->ID, '_event_start_date', true),
        (string) get_post_meta($b->ID, '_event_start_date', true)
      );
    });

    return $all;
  }

  /**
   * Render an "Other sessions on {date}" strip above the booking form on
   * session pages that share their date with sibling sessions.
   *
   * Covers both child sessions (pattern recurrence) and standalone
   * custom-date clones (via _cloned_from).
   */
  public static function render_same_day_slots(\WP_Post $event): void
  {
    if (!$event instanceof \WP_Post || $event->post_type !== Event::POST_TYPE) {
      return;
    }

    $slots = self::get_same_day_slots($event);

    if (empty($slots)) {
      return;
    }

    self::enqueue_session_schedule_styles();

    hmwevents_get_template_part('same-day-slots', null, ['event' => $event, 'slots' => $slots]);
  }

  /**
   * Same-day slots markup as a string, for themes placing it in custom
   * positions (e.g. Blade components).
   */
  public static function get_same_day_slots_html(\WP_Post $event): string
  {
    ob_start();
    self::render_same_day_slots($event);
    return (string) ob_get_clean();
  }

  /**
   * Get published sibling sessions sharing the event's start date.
   *
   * @return array[] List of ['session' => WP_Post, 'start' => string, 'end' => string]
   *                 sorted by start datetime.
   */
  public static function get_same_day_slots(\WP_Post $event): array
  {
    $is_child_session = (bool) $event->post_parent;
    $parent_id = $is_child_session
      ? (int) $event->post_parent
      : (int) get_post_meta($event->ID, '_cloned_from', true);

    if (!$parent_id) {
      return [];
    }

    $event_data = new EventDataService();
    $own_start = $event_data->get_start_date($event->ID);
    if (!$own_start) {
      return [];
    }
    $own_date = substr($own_start, 0, 10);

    if ($is_child_session) {
      $candidates = (new SessionService())->get_sessions((int) $parent_id, 'publish');
    } else {
      $candidates = get_posts([
        'post_type'      => Event::POST_TYPE,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'meta_key'       => '_cloned_from',
        'meta_value'     => $parent_id,
        'exclude'        => [$event->ID],
      ]) ?: [];
    }

    $slots = [];
    foreach ($candidates as $sibling) {
      if ((int) $sibling->ID === (int) $event->ID) {
        continue;
      }
      $start = $event_data->get_start_date($sibling->ID);
      if (!$start || substr($start, 0, 10) !== $own_date) {
        continue;
      }
      $slots[] = [
        'session' => $sibling,
        'start'   => $start,
        'end'     => $event_data->get_end_date($sibling->ID),
      ];
    }

    usort($slots, static function ($a, $b) {
      return strcmp($a['start'], $b['start']);
    });

    return apply_filters('hmwevents_same_day_slots', $slots, $event);
  }

  private static function enqueue_session_schedule_styles(): void
  {
    $css_path = \HMWEvents\HMWEvents::plugin_path() . '/assets/css/session-schedule.css';
    wp_enqueue_style(
      'hmwevents-session-schedule',
      \HMWEvents\HMWEvents::plugin_url() . '/assets/css/session-schedule.css',
      [],
      file_exists($css_path) ? filemtime($css_path) : '1.0.0'
    );
  }

  public static function on_event_cancelled(string $new_status, string $old_status, \WP_Post $post): void
  {
    if ($post->post_type !== 'hmw_event') {
      return;
    }

    if ($new_status !== 'cancelled') {
      return;
    }

    if ($old_status === 'cancelled') {
      return;
    }

    global $wpdb;
    $bookings_table = DatabaseService::get_table_name('bookings');

    $bookings = $wpdb->get_results($wpdb->prepare(
      "SELECT id FROM {$bookings_table}
      WHERE event_post_id = %d
        AND status IN ('confirmed', 'pending')
        AND deleted_at IS NULL",
      $post->ID
    ));

    if (empty($bookings)) {
      return;
    }

    foreach ($bookings as $booking) {
      $wpdb->update(
        $bookings_table,
        ['status' => 'cancelled', 'cancelled_at' => current_time('mysql')],
        ['id' => $booking->id],
        ['%s', '%s'],
        ['%d']
      );

      do_action('hmwevents_booking_cancelled', (int) $booking->id, 'Event cancelled', []);
    }
  }

}
