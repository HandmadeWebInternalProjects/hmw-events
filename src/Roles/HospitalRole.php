<?php

/**
 * Hospital User Role.
 *
 * Handles registration and management of the Hospital user role.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Roles;

use HMWEvents\Helpers\GoogleMapField;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Hospital Role.
 */
class HospitalRole
{
    /**
     * Role slug.
     *
     * @var string
     */
    public const ROLE = 'hospital';

    /**
     * Initialize the role.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        add_action('init', [$this, 'register_role']);
        add_action('init', [$this, 'add_capabilities']); // ADD THIS LINE
        add_action('profile_update', [$this, 'clear_state_cache']);
        add_action('user_register', [$this, 'clear_state_cache']);
        add_action('deleted_user', [$this, 'clear_state_cache']);
        add_action('set_user_role', [$this, 'clear_state_cache_on_role_change'], 10, 3);
        add_action('acf/save_post', [$this, 'clear_state_cache_on_acf_save']);
        add_action('acf/save_post', [$this, 'sync_map_to_fields'], 20, 3);
        add_filter('acf/load_field/name=hospital_state', [$this, 'make_acf_select_field_disabled']);

        // Remove Yoast dashboard widgets
        add_action('wp_dashboard_setup', [$this, 'remove_dashboard_widgets']);
  }

    /**
     * Register the Hospital role.
     *
     * @since 1.0.0
     */
    public function register_role()
    {
        // Check if role already exists
        if (get_role(self::ROLE)) {
            return;
        }

        add_role(
            self::ROLE,
            __('Hospital', 'hmw-events'),
            [
                'read'                   => true,
                'edit_posts'             => false,
                'edit_published_posts'   => false,
                'publish_posts'          => false,
                'upload_files'           => true,
                'delete_posts'           => false,
                'delete_published_posts' => false,
            ]
        );
    }

    /**
     * Get the role slug.
     *
     * @since 1.0.0
     * @return string
     */
    public static function get_role()
    {
        return self::ROLE;
    }

    /**
     * Add capabilities to the hospital role.
     *
     * @since 1.0.0
     */
  public function add_capabilities()
  {
    $role = get_role(self::ROLE);

    if (!$role) {
      return;
    }

    // Only add capabilities if they don't already exist (prevent duplicate additions)
    if ($role->has_cap('edit_edu_customer')) {
      return;
    }

    // Add capabilities for managing courses
    $role->add_cap('edit_educator_course');
    $role->add_cap('edit_educator_courses');
    $role->add_cap('edit_published_educator_courses');
    $role->add_cap('publish_educator_courses');
    $role->add_cap('read_educator_course');
    $role->add_cap('read_private_educator_courses');
    $role->add_cap('delete_educator_course');

    // Add capabilities for viewing/editing customers (custom capability type)
    $role->add_cap('read_edu_customer');
    $role->add_cap('read_private_edu_customers');
    $role->add_cap('edit_edu_customer');
    $role->add_cap('edit_edu_customers');
    $role->add_cap('edit_others_edu_customers');      // ✅ Scoped to customers only
    $role->add_cap('edit_published_edu_customers');   // ✅ Scoped to customers only
    $role->add_cap('delete_edu_customer');
    $role->add_cap('delete_edu_customers');
    $role->add_cap('delete_others_edu_customers');    // ✅ Scoped to customers only
    $role->add_cap('delete_published_edu_customers'); // ✅ Scoped to customers only
  }

    /**
     * Remove capabilities from the hospital role.
     *
     * @since 1.0.0
     */
    public function remove_capabilities()
    {
        $role = get_role(self::ROLE);

        if (!$role) {
            return;
        }
        
        // Remove capabilities for managing courses
        $role->remove_cap('edit_educator_course');
        $role->remove_cap('edit_educator_courses');
        $role->remove_cap('edit_published_educator_courses');
        $role->remove_cap('publish_educator_courses');
        $role->remove_cap('read_educator_course');
        $role->remove_cap('read_private_educator_courses');
        $role->remove_cap('delete_educator_course');
        // Remove capabilities for managing customers
        $role->remove_cap('read_edu_customer');
        $role->remove_cap('read_private_edu_customers');
        $role->remove_cap('edit_edu_customer');
        $role->remove_cap('edit_edu_customers');
        $role->remove_cap('edit_others_edu_customers');
        $role->remove_cap('edit_published_edu_customers');
        $role->remove_cap('delete_edu_customer');
        $role->remove_cap('delete_edu_customers');
        $role->remove_cap('delete_others_edu_customers');
        $role->remove_cap('delete_published_edu_customers');
    }

  /**
   * Clear state cache when hospital data changes.
   *
   * @since 1.0.0
   * @param int $user_id The user ID.
   */
  public function clear_state_cache($user_id)
  {
    $user = get_userdata($user_id);

    if ($user && in_array('hospital', (array) $user->roles, true)) {
      delete_transient('hmwevents_hospitals_by_state');
    }
  }

  /**
   * Clear state cache when user role changes.
   *
   * @since 1.0.0
   * @param int    $user_id The user ID.
   * @param string $role The new role.
   * @param array  $old_roles Previous roles.
   */
  public function clear_state_cache_on_role_change($user_id, $role, $old_roles)
  {
    // Clear if changing to or from hospital role
    if ($role === 'hospital' || in_array('hospital', $old_roles, true)) {
      delete_transient('hmwevents_hospitals_by_state');
    }
  }

  /**
   * Sync Google Map field to structured address fields.
   */
  public function sync_map_to_fields($post_id)
  {
    // Only process user updates
    if (strpos($post_id, 'user_') !== 0) {
      return;
    }

    // Get the map field value after ACF has processed it
    $map_value = get_field('hospital_address_1', $post_id);

    if (empty($map_value) || !is_array($map_value)) {
      return;
    }

    // Parse address from Google Maps
    $parsed = GoogleMapField::parse_google_map_address($map_value);


    // Update structured fields
    if (!empty($parsed['suburb'])) {
      update_field('hospital_suburb', $parsed['suburb'], $post_id);
    }
    if (!empty($parsed['state'])) {
      update_field('hospital_state', $parsed['state'], $post_id);
    }
    if (!empty($parsed['postcode'])) {
      update_field('hospital_postcode', $parsed['postcode'], $post_id);
    }
    if (!empty($parsed['country'])) {
      update_field('hospital_country', $parsed['country'], $post_id);
    }
    if (!empty($parsed['country_code'])) {
      update_field('hospital_country_code', $parsed['country_code'], $post_id);
    }

    // Update lat/lng
    if (!empty($parsed['lat'])) {
      update_field('hospital_lat', $parsed['lat'], $post_id);
    }
    if (!empty($parsed['lng'])) {
      update_field('hospital_long', $parsed['lng'], $post_id);
    }
  }

  /**
   * Clear state cache when ACF user fields are saved.
   *
   * @since 1.0.0
   * @param int $post_id The post ID (user_X for user meta).
   */
  public function clear_state_cache_on_acf_save($post_id)
  {
    // Check if this is a user update (ACF uses 'user_X' format)
    if (strpos($post_id, 'user_') !== 0) {
      return;
    }

    $user_id = (int) str_replace('user_', '', $post_id);
    $user = get_userdata($user_id);

    if ($user && in_array('hospital', (array) $user->roles, true)) {
      delete_transient('hmwevents_hospitals_by_state');
    }
  }

  public function make_acf_select_field_disabled($field)
  {
    // Replace 'your_select_field_name' with the actual name of your ACF Select field
    if ($field['name'] == 'hospital_state') {
      $field['disabled'] = true;
    }
    return $field;
  }

  /**
   * Remove Yoast SEO dashboard widgets for hospitals.
   */
  public function remove_dashboard_widgets()
  {
    if (!$this->is_hospital_only()) {
      return;
    }

    remove_meta_box('wpseo-dashboard-overview', 'dashboard', 'normal');
    remove_meta_box('wpseo-wincher-dashboard-overview', 'dashboard', 'normal');
  }

  /**
   * Check if the current user is a hospital (and not an administrator).
   *
   * @return bool
   */
  private function is_hospital_only()
  {
    $user = wp_get_current_user();
    return in_array('hospital', (array) $user->roles, true)
        && !in_array('administrator', (array) $user->roles, true);
  }
}
