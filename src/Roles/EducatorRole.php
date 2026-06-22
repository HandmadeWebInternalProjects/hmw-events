<?php

/**
 * Educator User Role.
 *
 * Handles registration and management of the Educator user role.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Roles;

use HMWEvents\Helpers\GoogleMapField;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Educator Role.
 */
class EducatorRole
{
    /**
     * Role slug.
     *
     * @var string
     */
    public const ROLE = 'educator';

    /**
     * Initialize the role.
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        add_action('init', [$this, 'register_role']);
        add_action('init', [$this, 'add_capabilities']);
        add_filter('login_redirect', [$this, 'redirect_to_dashboard'], 10, 3);
        add_action('user_register', [$this, 'clear_state_cache_on_user_change']);
        add_action('deleted_user', [$this, 'clear_state_cache_on_user_change']);
        add_action('set_user_role', [$this, 'clear_state_cache_on_role_change'], 10, 3);
        add_filter('acf/update_value/name=educator_state', [$this, 'clear_state_cache'], 10, 3);
        add_action('acf/save_post', [$this, 'sync_map_to_fields'], 20, 3);
        // add_filter('acf/load_field/name=educator_state', [$this, 'make_acf_select_field_disabled']);
        add_filter('acf/load_field/name=educator_featured', [$this, 'admin_only_field']);
        add_filter('acf/load_field/name=educator_main_profile', [$this, 'admin_only_field']);
        
        // Hide unwanted profile fields
        add_action('admin_init', [$this, 'hide_profile_fields']);
        add_action('admin_head-profile.php', [$this, 'hide_profile_fields_css']);
        add_action('admin_head-user-edit.php', [$this, 'hide_profile_fields_css']);
        add_filter('user_contactmethods', [$this, 'remove_contact_methods'], 10, 2);
        add_filter('additional_capabilities_display', [$this, 'hide_additional_capabilities'], 10, 2);

        remove_action('show_user_profile', '\Breakdance\Permissions\customField');
        remove_action('edit_user_profile', '\Breakdance\Permissions\customField');
        remove_action('user_new_form', '\Breakdance\Permissions\customField');

        // Hide unwanted admin sidebar menu items
        add_action('admin_menu', [$this, 'hide_sidebar_menu_items'], 999);

        // Remove Yoast dashboard widgets
        add_action('wp_dashboard_setup', [$this, 'remove_dashboard_widgets']);

        // Blog posts by educators require admin approval before publishing
        add_filter('wp_insert_post_data', [$this, 'require_blog_post_approval'], 10, 2);

        // Rename "Publish" button to "Submit for Review" for educators on blog posts
        add_filter('gettext', [$this, 'rename_publish_button'], 10, 3);

        // Notify admins when an educator submits a blog post for review
        add_action('transition_post_status', [$this, 'notify_admin_of_pending_post'], 10, 3);

        // Add custom "Special Offer Text" style to TinyMCE styles dropdown
        add_action('admin_init', [$this, 'add_tinymce_editor_style']);
        add_filter('tiny_mce_before_init', [$this, 'add_tinymce_style_formats']);
        add_filter('teeny_mce_before_init', [$this, 'add_tinymce_style_formats']);
        add_filter('mce_buttons_2', [$this, 'add_styleselect_button']);
        add_filter('teeny_mce_buttons', [$this, 'add_styleselect_button']);

    }

    /**
     * Restricts ACF field access to administrators only.
     *
     * Disables and hides an Advanced Custom Fields (ACF) field for non-administrator users.
     * The field becomes disabled and a CSS class is added to hide it from the UI.
     *
     * @param array $field The ACF field array containing field configuration.
     *                     Expected to have 'disabled' and 'wrapper' keys.
     *
     * @return array Modified field array with disabled state and hidden CSS class
     *               if current user is not an administrator, otherwise unchanged field array.
     */
    public function admin_only_field($field)
    {
      if (!current_user_can('administrator')) {
        $field['disabled'] = true;
        $field['wrapper']['class'] .= ' acf-hidden';
      }

      return $field;
    }

    /**
     * Register the Educator role.
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
            __('Educator', 'hmw-events'),
            [
                'read'                   => true,
                'edit_posts'             => true,
                'edit_published_posts'   => true,
                'publish_posts'          => true,
                'upload_files'           => true,
                'delete_posts'           => true,
                'delete_published_posts' => true,
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
     * Add capabilities to the educator role.
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
    $role->add_cap('delete_educator_courses');
    $role->add_cap('delete_published_educator_courses');
    $role->add_cap('delete_others_published_educator_courses');
    $role->add_cap('delete_others_educator_course');

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
     * Remove capabilities from the educator role.
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
        $role->remove_cap('delete_educator_courses');
        $role->remove_cap('delete_published_educator_courses');
        $role->remove_cap('delete_others_published_educator_courses');
        $role->remove_cap('delete_others_educator_course');
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
   * Clear state cache when ACF educator field changes.
   *
   * @since 1.0.0
   * @param mixed $value The field value.
   * @param int|string $post_id The post ID.
   * @param array $field The field array.
   * @return mixed
   */
  public function clear_state_cache($value, $post_id, $field)
  {
    if (strpos($post_id, 'user_') === 0) {
      delete_transient('hmwevents_educators_by_state');
    }
    return $value;
  }

  /**
   * Clear state cache when user is registered or deleted.
   *
   * @since 1.0.0
   * @param int $user_id The user ID.
   */
  public function clear_state_cache_on_user_change($user_id)
  {
    delete_transient('hmwevents_educators_by_state');
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
    // Clear if changing to or from educator role
    if ($role === 'educator' || in_array('educator', $old_roles, true)) {
      delete_transient('hmwevents_educators_by_state');
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
    $map_value = get_field('educator_address_1', $post_id);

    if (empty($map_value) || !is_array($map_value)) {
      return;
    }
    
    // Parse address from Google Maps
    $parsed = GoogleMapField::parse_google_map_address($map_value);

    // Update structured fields
    if (!empty($parsed['suburb'])) {
      update_field('educator_suburb', $parsed['suburb'], $post_id);
    }

    if (!empty($parsed['state'])) {
      update_field('educator_state', $parsed['state'], $post_id);
    }

    if (!empty($parsed['postcode'])) {
      update_field('educator_postcode', $parsed['postcode'], $post_id);
    }
    if (!empty($parsed['country'])) {
      update_field('educator_country', $parsed['country'], $post_id);
    }
    if (!empty($parsed['country_code'])) {
      update_field('educator_country_code', $parsed['country_code'], $post_id);
    }

    // Update lat/lng
    if (!empty($parsed['lat'])) {
      update_field('educator_lat', $parsed['lat'], $post_id);
    }
    if (!empty($parsed['lng'])) {
      update_field('educator_long', $parsed['lng'], $post_id);
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

    if ($user && in_array('educator', (array) $user->roles, true)) {
      delete_transient('hmwevents_educators_by_state');
    }
  }

  public function make_acf_select_field_disabled($field)
  {
    // Replace 'your_select_field_name' with the actual name of your ACF Select field
    if ($field['name'] == 'educator_state') {
      $field['disabled'] = true;
    }
    return $field;
  }

  /**
   * Hide profile fields for educators.
   *
   * @since 1.0.0
   */
  public function hide_profile_fields()
  {
    // Remove personal options (color scheme, keyboard shortcuts, toolbar)
    add_filter('personal_options', [$this, 'remove_personal_options']);
  }

  /**
   * Remove personal options section.
   *
   * @param \WP_User $user User object
   */
  public function remove_personal_options($user)
  {
    if (!$this->should_hide_fields($user)) {
      return;
    }
    
    // Buffer and remove the personal options section
    ob_start(function($buffer) {
      // Remove visual editor, color scheme, keyboard shortcuts sections
      $buffer = preg_replace('/<tr class="user-admin-color-wrap">.*?<\/tr>/s', '', $buffer);
      $buffer = preg_replace('/<tr class="user-comment-shortcuts-wrap">.*?<\/tr>/s', '', $buffer);
      $buffer = preg_replace('/<tr class="show-admin-bar">.*?<\/tr>/s', '', $buffer);
      $buffer = preg_replace('/<tr class="user-language-wrap">.*?<\/tr>/s', '', $buffer);
      return $buffer;
    });
  }

  /**
   * Add CSS to hide specific profile fields.
   *
   * @since 1.0.0
   */
  public function hide_profile_fields_css()
  {
    global $pagenow;
    $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : get_current_user_id();
    $user = get_userdata($user_id);
    
    if (!$this->should_hide_fields($user)) {
      return;
    }
    
    ?>
    <style type="text/css">
      /* Hide admin color scheme */
      .user-admin-color-wrap,
      /* Hide keyboard shortcuts */
      .user-comment-shortcuts-wrap,
      /* Hide toolbar option */
      .show-admin-bar,
      /* Hide language */
      .user-language-wrap,
      /* Hide website field */
      .user-url-wrap,
      /* Hide biographical info */
      .user-description-wrap,
      /* Hide profile picture */
      .user-profile-picture {
        display: none !important;
      }
    </style>
    <?php
  }

  /**
   * Hide Additional Capabilities section for educators.
   *
   * @param bool     $enable       Whether to display the section.
   * @param \WP_User $profile_user The user being edited.
   * @return bool
   */
  public function hide_additional_capabilities($enable, $profile_user)
  {
    return $this->should_hide_fields($profile_user) ? false : $enable;
  }

  /**
   * Remove contact methods (Website field).
   *
   * @param array $methods Contact methods
   * @param \WP_User $user User object
   * @return array
   */
  public function remove_contact_methods($methods, $user = null)
  {
    // If no user provided, check current screen
    if (!$user) {
      $screen = get_current_screen();
      if ($screen && in_array($screen->id, ['profile', 'user-edit'])) {
        $user_id = isset($_GET['user_id']) ? (int) $_GET['user_id'] : get_current_user_id();
        $user = get_userdata($user_id);
      }
    }
    
    if ($user && $this->should_hide_fields($user)) {
      // Remove website field
      unset($methods['url']);
    }
    
    return $methods;
  }

  /**
   * Hide admin sidebar menu items for educators.
   *
   * Uses a high priority (999) so it runs after all plugins have registered
   * their menus. Add menu slugs to the $menus array to hide additional items.
   *
   * @since 1.0.0
   */
  public function hide_sidebar_menu_items()
  {
    if (!$this->is_educator_only()) {
      return;
    }

    $menus = [
      'breakdance', 
      'global-options',// Breakdance page builder
      'tools.php',   // Tools
    ];

    foreach ($menus as $slug) {
      remove_menu_page($slug);
    }
  }

  /**
   * Remove Yoast SEO dashboard widgets for educators.
   */
  public function remove_dashboard_widgets()
  {
    if (!$this->is_educator_only()) {
      return;
    }

    remove_meta_box('wpseo-dashboard-overview', 'dashboard', 'normal');
    remove_meta_box('wpseo-wincher-dashboard-overview', 'dashboard', 'normal');
  }

  /**
   * Rename the "Publish" button to "Submit for Review" for educators editing blog posts.
   *
   * @param string $translation Translated text.
   * @param string $text        Original text.
   * @param string $domain      Text domain.
   * @return string
   */
  public function rename_publish_button($translation, $text, $domain)
  {
      if (!$this->is_educator_only()) {
          return $translation;
      }

      global $post;
      if (!$post || $post->post_type !== 'post') {
          return $translation;
      }

      if ($text === 'Publish') {
          return __('Submit for Review', 'hmw-events');
      }

      return $translation;
  }

  /**
   * Force blog posts by educators into "pending" status so they require admin approval.
   *
   * @param array $data    Sanitised post data about to be inserted/updated.
   * @param array $postarr Raw post data array.
   * @return array
   */
  public function require_blog_post_approval($data, $postarr)
  {
      if ($data['post_type'] !== 'post') {
          return $data;
      }

      if (!$this->is_educator_only()) {
          return $data;
      }

      if ($data['post_status'] === 'publish') {
          $data['post_status'] = 'pending';
      }

      return $data;
  }

  /**
   * Email all administrators when an educator submits a blog post for review.
   *
   * @param string   $new_status New post status.
   * @param string   $old_status Previous post status.
   * @param \WP_Post $post       Post object.
   */
  public function notify_admin_of_pending_post($new_status, $old_status, $post)
  {
      if ($new_status !== 'pending' || $old_status === 'pending') {
          return;
      }

      if ($post->post_type !== 'post') {
          return;
      }

      $author = get_userdata($post->post_author);
      if (!$author || !in_array('educator', (array) $author->roles, true)) {
          return;
      }

      $to = get_option('admin_email');

      if (empty($to)) {
          return;
      }

      $edit_url   = admin_url('post.php?post=' . $post->ID . '&action=edit');
      $post_title = $post->post_title ?: __('(no title)', 'hmw-events');

      $subject = sprintf(
          __('[%s] Blog post pending review: %s', 'hmw-events'),
          get_bloginfo('name'),
          $post_title
      );

      $message = sprintf(
          __("%s (%s) has submitted a blog post for review.\n\nTitle: %s\n\nReview and publish it here:\n%s", 'hmw-events'),
          $author->display_name,
          $author->user_email,
          $post_title,
          $edit_url
      );

      wp_mail($to, $subject, $message);
  }

  /**
   * Redirect educators to the dashboard after login.
   *
   * @param string                $redirect_to           The redirect destination URL.
   * @param string                $requested_redirect_to The originally requested redirect URL.
   * @param \WP_User|\WP_Error   $user                  The logged-in user object.
   * @return string
   */
  public function redirect_to_dashboard($redirect_to, $requested_redirect_to, $user)
  {
      if ($user instanceof \WP_User && in_array('educator', (array) $user->roles, true)) {
          return admin_url();
      }
      return $redirect_to;
  }

  /**
   * Check if the current user is an educator (and not an administrator).
   *
   * @return bool
   */
  private function is_educator_only()
  {
    $user = wp_get_current_user();
    return in_array('educator', (array) $user->roles, true)
        && !in_array('administrator', (array) $user->roles, true);
  }

  /**
   * Ensure the Styleselect button is present in the TinyMCE toolbar so the
   * Formats dropdown (which contains "Special Offer Text") is accessible.
   *
   * @param array $buttons Second-row toolbar buttons.
   * @return array
   */
  public function add_styleselect_button($buttons)
  {
    if (! in_array('styleselect', $buttons, true)) {
      array_unshift($buttons, 'styleselect');
    }
    return $buttons;
  }

  /**
   * Register the editor stylesheet on admin_init so TinyMCE can pick it up.
   */
  public function add_tinymce_editor_style()
  {
    add_editor_style($this->get_tinymce_editor_styles_url());
  }

  /**
   * Add custom style formats to the TinyMCE "Formats" dropdown.
   *
   * Styles appear under the "Paragraph" group in the Formats dropdown
   * and can be applied to any block-level element.
   *
   * @param array $init TinyMCE init settings.
   * @return array
   */
  public function add_tinymce_style_formats($init)
  {
    // Build the custom style_formats array
    $custom_formats = [
      [
        'title'   => __('Special Offer Text', 'hmw-events'),
        'block'   => 'p',
        'classes' => 'special-offer-text',
        'wrapper' => false,
      ],
    ];

    // Ensure any pre-existing formats are preserved via merge
    $init['style_formats_merge'] = true;

    if (empty($init['style_formats'])) {
      $init['style_formats'] = wp_json_encode($custom_formats);
    } else {
      $existing = json_decode($init['style_formats'], true);
      if (is_array($existing)) {
        $init['style_formats'] = wp_json_encode(array_merge($existing, $custom_formats));
      }
    }

    return $init;
  }

  /**
   * Generate and return the URL for a dynamic editor stylesheet.
   *
   * Writes a CSS file to the uploads directory so `add_editor_style()` can
   * load it inside the TinyMCE iframe.
   *
   * @return string URL to the editor stylesheet.
   */
  private function get_tinymce_editor_styles_url(): string
  {
    $upload_dir = wp_upload_dir();
    $css_path   = $upload_dir['basedir'] . '/hmwevents-editor-styles.css';
    $css_url    = $upload_dir['baseurl'] . '/hmwevents-editor-styles.css';

    $css = 'p.special-offer-text { color: #e94d8e; }';

    if (! file_exists($css_path) || md5_file($css_path) !== md5($css)) {
        @file_put_contents($css_path, $css);
    }

    return $css_url;
  }

  /**
   * Check if fields should be hidden for this user.
   *
   * @param \WP_User $user User object
   * @return bool
   */
  private function should_hide_fields($user)
  {
    if (!$user) {
      return false;
    }
    
    // Hide for educators, but not for administrators
    return in_array('educator', (array) $user->roles, true) && !in_array('administrator', (array) $user->roles, true);
  }
}
