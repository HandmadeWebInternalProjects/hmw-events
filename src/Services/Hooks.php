<?php 

namespace HMWEvents\Services;

use HMWEvents\PostTypes\Event;

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

    add_action('hmwevents_after_event_content', [static::class, 'render_session_schedule']);

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
    $session_service = new SessionService();
    $sessions = $session_service->get_sessions($event->ID, 'publish');

    if (empty($sessions)) {
      return;
    }

    hmwevents_get_template_part('session-schedule', null, ['event' => $event, 'sessions' => $sessions]);
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
