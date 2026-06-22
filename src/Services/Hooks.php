<?php 

namespace HMWEvents\Services;

defined('ABSPATH') || die("Don't run this file directly!");

class Hooks
{
  public static function register()
  {
    // Register hooks and filters here
    add_filter('posts_where', [static::class, 'exclude_expired_courses_from_search'], 10, 2);
    // Fix Content Control + Search & Filter Pro pagination on the shop page
    add_action('pre_get_posts', [static::class, 'exclude_restricted_products_pre_query'], 10);
    
    add_filter('template_include', [static::class, 'breakdance_pdf_voucher_fix'], 999999);
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
   * Exclude educator_course posts with 'expired' status from frontend search results.
   *
   * @param string   $where The WHERE clause of the query.
   * @param \WP_Query $query The current WP_Query instance.
   * @return string Modified WHERE clause.
   */
  public static function exclude_expired_courses_from_search(string $where, \WP_Query $query): string
  {
    if (is_admin()) {
      return $where;
    }

    $post_types = (array) $query->get('post_type');

    $involves_courses = in_array(\HMWEvents\PostTypes\EducatorCourse::POST_TYPE, $post_types, true)
      || in_array('any', $post_types, true)
      || empty(array_filter($post_types));

    if (!$involves_courses) {
      return $where;
    }

    global $wpdb;
    $where .= $wpdb->prepare(
      " AND NOT ({$wpdb->posts}.post_type = %s AND {$wpdb->posts}.post_status = %s)",
      \HMWEvents\PostTypes\EducatorCourse::POST_TYPE,
      'expired'
      );

    return $where;
  }

}