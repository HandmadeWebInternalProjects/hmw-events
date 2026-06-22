<?php

/**
 * Educator Course Custom Post Type.
 *
 * Handles registration and configuration of the Educator Course post type
 * for managing events.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\PostTypes;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Educator Course Post Type.
 */
class EducatorCourse
{
    /**
     * Post type slug.
     *
     * @var string
     */
    public const POST_TYPE = 'educator_course';

    /**
     * Initialize the post type.
     *
     * @since 1.0.0
     */
    public function register()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_post_status']);
        add_action('init', [$this, 'add_admin_capabilities']);

        // Add meta boxes
        add_action('add_meta_boxes', [$this, 'add_course_meta_boxes']);

        // On ACF Save, populate the suburn, state, and postcode from address
        add_action('acf/save_post', [$this, 'populate_location_fields']);
        // add_action('acf/save_post', [$this, 'append_date_to_title_slug'], 20);

      // Capture and trigger course changes for email notifications
      // add_action('acf/save_post', [$this, 'capture_course_changes'], 1);
      // add_action('acf/save_post', [$this, 'trigger_course_changed'], 30);
      add_action('acf/save_post', [$this, 'update_educator_id'], 30);
      add_action('acf/save_post', [$this, 'sync_course_availability_cache'], 40);
      add_action('save_post_' . self::POST_TYPE, [$this, 'sync_course_availability_cache_from_save_post'], 20, 3);

      // Validate course_type is selected before redirecting from post.php
      add_action('admin_action_editpost', [$this, 'validate_course_type_on_submit'], 1);
      add_filter('redirect_post_location', [$this, 'validate_course_type_on_redirect'], 99, 2);
      add_action('admin_notices', [$this, 'display_course_type_error_notice']);

      // Attendee list CSV export
      add_action('admin_post_hmwevents_export_attendees', [$this, 'export_attendees']);

      // Filter courses list to only show educator's own courses
      add_action('pre_get_posts', [$this, 'filter_courses_by_author']);
      add_filter('views_edit-' . self::POST_TYPE, [$this, 'remove_mine_filter']);
      
      // Display custom post status in admin
      add_filter('display_post_states', [$this, 'display_custom_post_states'], 10, 2);
      add_action('admin_footer-edit.php', [$this, 'add_expired_status_to_quick_edit']);
      add_action('post_submitbox_misc_actions', [$this, 'add_expired_status_to_publish_metabox']);
    }

    /**
     * Capture course fields before ACF saves, for change detection.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function capture_course_changes($post_id)
    {
      if (get_post_type($post_id) !== self::POST_TYPE) {
        return;
      }

      if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
      }

      $post = get_post($post_id);
      if (!$post) {
        return;
      }

      $snapshot = [
        'course_title' => $post->post_title,
        'course_start_date' => get_post_meta($post_id, 'course_start_date', true),
        'course_end_date' => get_post_meta($post_id, 'course_end_date', true),
        'course_location_address' => get_post_meta($post_id, 'course_location_address', true),
      ];

      set_transient('hmwevents_course_change_' . $post_id, $snapshot, 120 * MINUTE_IN_SECONDS);
    }

    /**
     * Trigger course changed event if tracked fields changed.
     *
     * @param int $post_id Post ID.
     * @return void
     */
    public function trigger_course_changed($post_id)
    {
      if (get_post_type($post_id) !== self::POST_TYPE) {
        return;
      }

      if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
        return;
      }

      $old = get_transient('hmwevents_course_change_' . $post_id);
      if (!$old || !is_array($old)) {
        return;
      }

      $post = get_post($post_id);
      if (!$post) {
        return;
      }

      $new = [
        'course_title' => $post->post_title,
        'course_start_date' => get_post_meta($post_id, 'course_start_date', true),
        'course_end_date' => get_post_meta($post_id, 'course_end_date', true),
        'course_location_address' => get_post_meta($post_id, 'course_location_address', true),
      ];

      $changed = [];
      foreach ($new as $key => $value) {
        $old_value = $old[$key] ?? null;
        if ($old_value !== $value) {
          $changed[$key] = $value;
        }
      }

      if (!empty($changed)) {
        do_action('hmwevents_course_changed', $post_id, $changed, $old);
      }

      delete_transient('hmwevents_course_change_' . $post_id);
    }

    /**
     * Grant admin capabilities for managing courses.
     */
    public function add_admin_capabilities()
    {
      $admin_role = get_role('administrator');
      if ($admin_role) {
        $admin_role->add_cap('edit_educator_courses');
        $admin_role->add_cap('edit_others_educator_courses');
        $admin_role->add_cap('publish_educator_courses');
        $admin_role->add_cap('read_private_educator_courses');
        $admin_role->add_cap('delete_educator_courses');
        $admin_role->add_cap('delete_private_educator_courses');
        $admin_role->add_cap('delete_published_educator_courses');
        $admin_role->add_cap('delete_others_educator_courses');
        $admin_role->add_cap('edit_private_educator_courses');
        $admin_role->add_cap('edit_published_educator_courses');
      }
    }

    public function update_educator_id($post_id)
    {
      
        if (get_post_type($post_id) !== self::POST_TYPE) {
            return;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }

        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Update educator_id meta to match post_author
        update_field('course_educator_id', $post->post_author, $post_id);
    }

      /**
       * Sync course availability cache after ACF saves course data.
       *
       * @since 1.0.0
       * @param int|string $post_id Post ID from ACF hook.
       * @return void
       */
      public function sync_course_availability_cache($post_id)
      {
        if (!is_numeric($post_id)) {
          return;
        }

        $post_id = (int) $post_id;

        if (get_post_type($post_id) !== self::POST_TYPE) {
          return;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
          return;
        }

        \HMWEvents\Helpers\Course::ensure_course_availability_row($post_id);
      }

      /**
       * Sync course availability cache when course posts are saved outside ACF.
       *
       * @since 1.0.0
       * @param int      $post_id Post ID.
       * @param \WP_Post $post Post object.
       * @param bool     $update Whether this is an update.
       * @return void
       */
      public function sync_course_availability_cache_from_save_post($post_id, $post, $update)
      {
        if (!$post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
          return;
        }

        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
          return;
        }

        \HMWEvents\Helpers\Course::ensure_course_availability_row((int) $post_id);
      }

      /**
       * Capture submitted course_type terms BEFORE WordPress processes the save.
       *
       * Runs at start of admin_action_editpost, before wp_after_insert_post
       * or any save_post hooks fire. Stores the submitted terms so we can
       * check them later in the redirect filter, after everything is persisted.
       *
       * @since 1.0.0
       * @return void
       */
      public function validate_course_type_on_submit()
      {
        if (empty($_POST['post_type']) || $_POST['post_type'] !== self::POST_TYPE) {
          return;
        }

        $post_id = isset($_POST['post_ID']) ? (int) $_POST['post_ID'] : 0;
        if (!$post_id) {
          return;
        }

        // Only validate when publishing to a non-draft status.
        $new_status = $_POST['post_status'] ?? 'draft';
        if ($new_status === 'draft' || $new_status === 'auto-draft') {
          return;
        }

        $submitted = [];
        if (!empty($_POST['tax_input'][\HMWEvents\Taxonomies\CourseType::TAXONOMY])) {
          $raw = $_POST['tax_input'][\HMWEvents\Taxonomies\CourseType::TAXONOMY];
          if (is_array($raw)) {
            $submitted = array_filter(array_map('intval', $raw));
          } elseif (is_string($raw) && $raw !== '') {
            $submitted = array_filter(array_map('intval', explode(',', $raw)));
          }
        }

        // Store what was submitted so the redirect filter can use it.
        set_transient('hmwevents_course_type_submitted_' . $post_id, $submitted, 180);
      }

      /**
       * Validate course_type selection at redirect time (after full save).
       *
       * At this point WordPress has fully saved the post and taxonomy terms,
       * so we can reliably check DB state. We also check against submitted
       * values captured at the start of the request.
       *
       * @since 1.0.0
       * @param string $location Redirect URL.
       * @param int    $post_id  Post ID.
       * @return string Modified redirect URL if validation fails.
       */
      public function validate_course_type_on_redirect($location, $post_id)
      {
        if (empty($post_id) || get_post_type($post_id) !== self::POST_TYPE) {
          return $location;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_status === 'draft' || $post->post_status === 'auto-draft') {
          delete_transient('hmwevents_course_type_submitted_' . $post_id);
          return $location;
        }

        // Check: did the user submit any course_type terms?
        $submitted = get_transient('hmwevents_course_type_submitted_' . $post_id);
        $submitted = is_array($submitted) ? $submitted : [];

        // Check: what terms are actually on the post now?
        $db_terms = wp_get_object_terms($post_id, \HMWEvents\Taxonomies\CourseType::TAXONOMY, ['fields' => 'ids']);
        $db_terms = is_array($db_terms) ? array_filter(array_map('intval', $db_terms)) : [];

        // Valid if user submitted terms OR terms exist in the DB.
        $has_terms = !empty($submitted) || !empty($db_terms);

        delete_transient('hmwevents_course_type_submitted_' . $post_id);

        if ($has_terms) {
          return $location;
        }

        // Validation failed — demote to draft and show error.
        wp_update_post([
          'ID'          => $post_id,
          'post_status' => 'draft',
        ]);

        set_transient('hmwevents_course_type_error_' . $post_id, __('Please select at least one Course Type.', 'hmw-events'), 60);

        return add_query_arg('hmwevents_course_type_error', '1', $location);
      }

      /**
       * Display a dashboard notice if course_type validation failed.
       *
       * @since 1.0.0
       * @return void
       */
      public function display_course_type_error_notice()
      {
        if (empty($_GET['hmwevents_course_type_error'])) {
          return;
        }

        $post_id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$post_id) {
          return;
        }

        $error = get_transient('hmwevents_course_type_error_' . $post_id);
        if ($error) {
          delete_transient('hmwevents_course_type_error_' . $post_id);
          ?>
          <div class="notice notice-error">
            <p><strong><?php esc_html_e('Course Type required:', 'hmw-events'); ?></strong> <?php echo esc_html($error); ?></p>
          </div>
          <?php
        }
      }

     /**
     * Schedule post-course feedback email.
     *
     * @param int $booking_id Booking ID.
     * @param int $days_after Days after course to send email.
     * @return bool True if queued successfully, false otherwise.
     */

    public function populate_location_fields($post_id)
    {
        // Avoid infinite loop
        remove_action('acf/save_post', [$this, 'populate_location_fields']);

        $address = get_field('course_location_address', $post_id);

        // Use the helper to parse the address
        $parsed = \HMWEvents\Helpers\GoogleMapField::parse_google_map_address($address);

        // var_dump($parsed); exit;

        // Update fields only if they're not already set (don't overwrite manual entries)
        if (empty(get_field('course_location_suburb', $post_id)) && !empty($parsed['suburb'])) {
            update_field('course_location_suburb', $parsed['suburb'], $post_id);
        }

        if (empty(get_field('course_location_state', $post_id)) && !empty($parsed['state'])) {
          // This is a taxonomy relation field in acf
          $state_slug = strtolower($parsed['state']);
          $state_term = get_term_by('slug', $state_slug, \HMWEvents\Taxonomies\CourseState::TAXONOMY);

          // If term doesn't exist, create it
          if (!$state_term) {
            $new_term = wp_insert_term(
              $parsed['state'], // Term name
              \HMWEvents\Taxonomies\CourseState::TAXONOMY,
              ['slug' => $state_slug]
            );

            if (!is_wp_error($new_term)) {
              $state_term = get_term($new_term['term_id'], \HMWEvents\Taxonomies\CourseState::TAXONOMY);
            }
          }

          if ($state_term && !is_wp_error($state_term)) {
            update_field('course_location_state', [$state_term->term_id], $post_id);
          }
        }

        if (empty(get_field('course_location_postcode', $post_id)) && !empty($parsed['postcode'])) {
            update_field('course_location_postcode', $parsed['postcode'], $post_id);
        }

        // Re-add action
        add_action('acf/save_post', [$this, 'populate_location_fields']);
    }

    public function append_date_to_title_slug($post_id)
    {
        // Avoid infinite loop
        remove_action('acf/save_post', [$this, 'append_date_to_title_slug']);

        $date = get_field('course_start_date', $post_id);

        if (empty($date)) {
            // Re-add action and exit if no date
            add_action('acf/save_post', [$this, 'append_date_to_title_slug']);
            return;
        }

        // Normalise to timestamp if stored as Y-m-d or similar
        $ts = strtotime($date);
        if (!$ts) {
            // Re-add action and exit if invalid date
            add_action('acf/save_post', [$this, 'append_date_to_title_slug']);
            return;
        }

        // Get the current title — educators manage this themselves.
        $title = get_post_field('post_title', $post_id);

        if (empty($title)) {
            add_action('acf/save_post', [$this, 'append_date_to_title_slug']);
            return;
        }

        // Generate a unique slug incorporating the date (keeps permalinks unique).
        $date_suffix = date('d-m-Y', $ts);
        $new_slug = sanitize_title($title . ' ' . $date_suffix);

        // Only update the slug/permalink — leave the title alone so educators
        // can manage their own course titles.
        wp_update_post([
            'ID'        => $post_id,
            'post_name' => $new_slug,
        ]);

        // Re-add action
        add_action('acf/save_post', [$this, 'append_date_to_title_slug']);
    }

    /**
     * Register the Educator Course post type.
     *
     * @since 1.0.0
     */
    public function register_post_type()
    {
        $labels = [
          'name'                  => _x('Courses', 'Post type general name', 'hmw-events'),
          'singular_name'         => _x('Course', 'Post type singular name', 'hmw-events'),
          'menu_name'             => _x('Courses', 'Admin Menu text', 'hmw-events'),
          'name_admin_bar'        => _x('Course', 'Add New on Toolbar', 'hmw-events'),
          'add_new'               => __('Add New', 'hmw-events'),
          'add_new_item'          => __('Add New Course', 'hmw-events'),
          'new_item'              => __('New Course', 'hmw-events'),
          'edit_item'             => __('Edit Course', 'hmw-events'),
          'view_item'             => __('View Course', 'hmw-events'),
          'all_items'             => __('All Courses', 'hmw-events'),
          'search_items'          => __('Search Courses', 'hmw-events'),
          'parent_item_colon'     => __('Parent Courses:', 'hmw-events'),
          'not_found'             => __('No courses found.', 'hmw-events'),
          'not_found_in_trash'    => __('No courses found in Trash.', 'hmw-events'),
          'featured_image'        => _x('Course Image', 'Overrides the "Featured Image" phrase', 'hmw-events'),
          'set_featured_image'    => _x('Set course image', 'Overrides the "Set featured image" phrase', 'hmw-events'),
          'remove_featured_image' => _x('Remove course image', 'Overrides the "Remove featured image" phrase', 'hmw-events'),
          'use_featured_image'    => _x('Use as course image', 'Overrides the "Use as featured image" phrase', 'hmw-events'),
          'archives'              => _x('Course archives', 'The post type archive label', 'hmw-events'),
          'insert_into_item'      => _x('Insert into course', 'Overrides the "Insert into post" phrase', 'hmw-events'),
          'uploaded_to_this_item' => _x('Uploaded to this course', 'Overrides the "Uploaded to this post" phrase', 'hmw-events'),
          'filter_items_list'     => _x('Filter courses list', 'Screen reader text for the filter links', 'hmw-events'),
          'items_list_navigation' => _x('Courses list navigation', 'Screen reader text for the pagination', 'hmw-events'),
          'items_list'            => _x('Courses list', 'Screen reader text for the items list', 'hmw-events'),
        ];

        $args = [
          'labels'             => $labels,
          'public'             => true,
          'publicly_queryable' => true,
          'show_ui'            => true,
          'show_in_menu'       => true,
          'query_var'          => true,
          'rewrite'            => ['slug' => 'courses'],
          'capability_type'    => ['educator_course', 'educator_courses'],
          'map_meta_cap'       => true,
          'has_archive'        => false,
          'hierarchical'       => false,
          'menu_position'      => 20,
          'menu_icon'          => 'dashicons-calendar-alt',
          'supports'           => ['title', 'editor', 'author', 'thumbnail'],
          'show_in_rest'       => true,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Register custom post status for expired courses.
     *
     * @since 1.0.0
     */
    public function register_post_status()
    {
        register_post_status('expired', [
            'label'                     => _x('Expired', 'post status', 'hmw-events'),
            'public'                    => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop('Expired <span class="count">(%s)</span>', 'Expired <span class="count">(%s)</span>', 'hmw-events'),
            'post_type'                 => [self::POST_TYPE],
        ]);
    }

    /**
     * Display custom post state label.
     *
     * @since 1.0.0
     * @param array   $post_states An array of post display states.
     * @param WP_Post $post The current post object.
     * @return array Modified post states.
     */
    public function display_custom_post_states($post_states, $post)
    {
        if ($post->post_type === self::POST_TYPE && $post->post_status === 'expired') {
            return [__('Expired', 'hmw-events')];
        }
        return $post_states;
    }

    /**
     * Add Expired status to quick edit dropdown.
     *
     * @since 1.0.0
     */
    public function add_expired_status_to_quick_edit()
    {
        global $typenow;
        
        if ($typenow !== self::POST_TYPE) {
            return;
        }
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Add Expired option to quick edit status dropdown
            $('.inline-edit-row select[name="_status"]').append('<option value="expired"><?php echo esc_js(__('Expired', 'hmw-events')); ?></option>');
        });
        </script>
        <?php
    }

    /**
     * Add Expired status to publish metabox dropdown.
     *
     * @since 1.0.0
     */
    public function add_expired_status_to_publish_metabox()
    {
        global $post;
        
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return;
        }
        
        $status = $post->post_status;
        ?>
        <script>
        jQuery(document).ready(function($) {
            var $statusSelect = $('#post_status');
            
            // Add Expired option if not exists
            if ($statusSelect.find('option[value="expired"]').length === 0) {
                $statusSelect.append('<option value="expired"><?php echo esc_js(__('Expired', 'hmw-events')); ?></option>');
            }
            
            <?php if ($status === 'expired'): ?>
                // Set the selected status
                $statusSelect.val('expired');
                $('#post-status-display').text('<?php echo esc_js(__('Expired', 'hmw-events')); ?>');
            <?php endif; ?>
        });
        </script>
        <?php
    }

    /**
     * Get the post type slug.
     *
     * @since 1.0.0
     * @return string
     */
    public static function get_post_type()
    {
        return self::POST_TYPE;
    }

    /**
     * Add meta boxes to course edit screen.
     *
     * @since 1.0.0
     */
    public function add_course_meta_boxes()
    {
        // Only add for existing courses (not new ones)
        if (get_the_ID()) {
            add_meta_box(
                'course_bookings',
                __('Course Bookings', 'hmw-events'),
                [$this, 'render_course_bookings_meta_box'],
                self::POST_TYPE,
                'normal',
                'high'
            );
        }
    }

    /**
     * Render course bookings meta box.
     *
     * @since 1.0.0
     * @param WP_Post $post Current post object.
     */
    public function render_course_bookings_meta_box($post)
    {
        global $wpdb;

        $current_user_id = get_current_user_id();

        // Security check: Only show bookings for own courses (unless admin)
        if (!current_user_can('manage_options') && $post->post_author != $current_user_id) {
            echo '<p>' . __('You do not have permission to view bookings for this course.', 'hmw-events') . '</p>';
            return;
        }

        // Pagination setup
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;

        // Get total count of bookings for this course
        $total_bookings = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->prefix}educator_bookings b
        WHERE b.course_post_id = %d
        AND b.deleted_at IS NULL
    ", $post->ID));

        // Create pagination instance
        $pagination = new \HMWEvents\Utils\Pagination(
            $total_bookings,
            $per_page,
            $current_page,
            add_query_arg(['post' => $post->ID], admin_url('post.php'))
        );

        // Get educator's courses for transfer dropdown
        $educator_courses = get_posts([
          'post_type' => self::POST_TYPE,
          'author' => current_user_can('manage_options') ? null : $current_user_id,
          'posts_per_page' => -1,
          'post_status' => 'publish',
          'orderby' => 'title',
          'order' => 'ASC',
        ]);

        // Get all bookings for this course WITH course pricing data
        $bookings = $wpdb->get_results($wpdb->prepare("
        SELECT 
            b.id,
            b.booking_number,
            b.booking_amount,
            b.currency,
            b.status,
            b.payment_status,
            b.created_at,
            cust.ID as customer_id,
            cust.post_title as customer_name,
            bg.booking_reference,
            bg.id as booking_group_id,
            bg.payment_type,
            bg.total_amount,
            vu.voucher_code,
            vu.redeemed_value as voucher_amount,
            cuu.coupon_code,
            cuu.discount_amount as coupon_amount,
            -- Get course pricing from post meta
            COALESCE(price_meta.meta_value, 0) as course_full_price,
            COALESCE(deposit_meta.meta_value, 0) as course_deposit_amount,
            COALESCE((
                SELECT SUM(pt_total.amount)
                FROM {$wpdb->prefix}educator_payment_transactions pt_total
                WHERE pt_total.booking_group_id = bg.id
                AND pt_total.status = 'succeeded'
            ), 0) as total_paid
        FROM {$wpdb->prefix}educator_bookings b
        INNER JOIN {$wpdb->posts} cust ON b.customer_post_id = cust.ID
        INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
          LEFT JOIN {$wpdb->prefix}educator_voucher_usage vu ON b.id = vu.booking_id
          LEFT JOIN {$wpdb->prefix}educator_coupon_usage cuu ON b.id = cuu.booking_id
        LEFT JOIN {$wpdb->postmeta} price_meta ON (
            price_meta.post_id = b.course_post_id 
            AND price_meta.meta_key = 'course_full_cost'
        )
        LEFT JOIN {$wpdb->postmeta} deposit_meta ON (
            deposit_meta.post_id = b.course_post_id 
            AND deposit_meta.meta_key = 'course_deposit_cost'
        )
        WHERE b.course_post_id = %d
        AND b.deleted_at IS NULL
        ORDER BY b.created_at DESC
        {$pagination->get_limit()}
    ", $post->ID));

        $booking_details_nonce = wp_create_nonce('hmwevents_booking_details_nonce');
        $course_full_cost = get_field('course_full_cost', $post->ID);
        $course_currency  = get_field('course_currency', $post->ID) ?: 'AUD';
        $course_currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($course_currency);
        ?>

    <!-- Add Booking Button -->
    <div style="margin:0 0 12px;">
      <button type="button" id="hmwevents-add-booking-btn"
              class="button button-primary"
              data-course-id="<?php echo esc_attr($post->ID); ?>"
              data-course-name="<?php echo esc_attr(get_the_title($post->ID)); ?>"
              data-course-cost="<?php echo esc_attr(floatval($course_full_cost)); ?>"
              data-currency-symbol="<?php echo esc_attr($course_currency_symbol); ?>">
        + <?php _e('Add Manual Booking', 'hmw-events'); ?>
      </button>
    </div>

    <?php if (empty($bookings)): ?>
      <p><?php _e('No bookings for this course yet.', 'hmw-events'); ?></p>
    <?php else: ?>
    <?php

        // Calculate statistics from ALL bookings (not just current page)
        $confirmed_bookings = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}educator_bookings
            WHERE course_post_id = %d AND deleted_at IS NULL AND status = 'confirmed'
        ", $post->ID));

        $pending_bookings = (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) FROM {$wpdb->prefix}educator_bookings
            WHERE course_post_id = %d AND deleted_at IS NULL AND status = 'pending'
        ", $post->ID));

        // Paid Revenue: sum of all actual succeeded payments (deposits + remaining).
        $paid_revenue = (float) $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(pt_total.amount), 0)
            FROM {$wpdb->prefix}educator_payment_transactions pt_total
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON pt_total.booking_group_id = bg.id
            INNER JOIN {$wpdb->prefix}educator_bookings b ON bg.id = b.booking_group_id
            WHERE b.course_post_id = %d
              AND b.deleted_at IS NULL
              AND pt_total.status = 'succeeded'
        ", $post->ID));

        // Potential revenue: confirmed bookings.
        // For deposit bookings where remaining hasn't been paid, use full course price.
        // For everything else (full payments, fully-paid deposits), use actual total_paid.
        $potential_revenue = (float) $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN bg.payment_type = 'deposit'
                         AND COALESCE(price_meta.meta_value + 0, 0) > 0
                         AND COALESCE(total_paid_sub.total_paid, 0) <= b.booking_amount
                        THEN price_meta.meta_value + 0
                    ELSE COALESCE(total_paid_sub.total_paid, b.booking_amount)
                END
            ), 0)
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            LEFT JOIN {$wpdb->postmeta} price_meta ON (
                price_meta.post_id = b.course_post_id AND price_meta.meta_key = 'course_full_cost'
            )
            LEFT JOIN (
                SELECT pt.booking_group_id, COALESCE(SUM(pt.amount), 0) as total_paid
                FROM {$wpdb->prefix}educator_payment_transactions pt
                WHERE pt.status = 'succeeded'
                GROUP BY pt.booking_group_id
            ) total_paid_sub ON total_paid_sub.booking_group_id = bg.id
            WHERE b.course_post_id = %d AND b.deleted_at IS NULL AND b.status = 'confirmed'
        ", $post->ID));

        // Outstanding balance: remaining amounts unpaid on confirmed deposit bookings.
        $outstanding_balance = (float) $wpdb->get_var($wpdb->prepare("
            SELECT COALESCE(SUM(
                CASE
                    WHEN bg.payment_type = 'deposit'
                         AND COALESCE(price_meta.meta_value + 0, 0) > 0
                         AND COALESCE(total_paid_sub2.total_paid, 0) <= b.booking_amount
                        THEN price_meta.meta_value + 0 - COALESCE(total_paid_sub2.total_paid, 0)
                    ELSE 0
                END
            ), 0)
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            LEFT JOIN {$wpdb->postmeta} price_meta ON (
                price_meta.post_id = b.course_post_id AND price_meta.meta_key = 'course_full_cost'
            )
            LEFT JOIN (
                SELECT pt.booking_group_id, COALESCE(SUM(pt.amount), 0) as total_paid
                FROM {$wpdb->prefix}educator_payment_transactions pt
                WHERE pt.status = 'succeeded'
                GROUP BY pt.booking_group_id
            ) total_paid_sub2 ON total_paid_sub2.booking_group_id = bg.id
            WHERE b.course_post_id = %d AND b.deleted_at IS NULL AND b.status = 'confirmed'
        ", $post->ID));
        
        // Get course currency for stats display (from first booking or course meta)
        $course_currency = !empty($bookings) && !empty($bookings[0]->currency) 
            ? $bookings[0]->currency 
            : (get_field('course_currency', $post->ID) ?: 'AUD');
        $stats_currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($course_currency);

        ?>

    <!-- Statistics Summary -->
    <div class="booking-stats-summary">
      <div>
        <div class="stat-value color-blue"><?php echo $total_bookings; ?></div>
        <div class="stat-label"><?php _e('Total Bookings', 'hmw-events'); ?></div>
      </div>
      <div>
        <div class="stat-value color-green"><?php echo $confirmed_bookings; ?></div>
        <div class="stat-label"><?php _e('Confirmed', 'hmw-events'); ?></div>
      </div>
      <div>
        <div class="stat-value color-orange"><?php echo $pending_bookings; ?></div>
        <div class="stat-label"><?php _e('Pending', 'hmw-events'); ?></div>
      </div>
      <div>
        <div class="stat-value color-green"><?php echo esc_html($stats_currency_symbol . number_format($paid_revenue, 2)); ?></div>
        <div class="stat-label"><?php _e('Paid Revenue', 'hmw-events'); ?></div>
      </div>
      <div>
        <div class="stat-value color-orange"><?php echo esc_html($stats_currency_symbol . number_format($outstanding_balance, 2)); ?></div>
        <div class="stat-label"><?php _e('Outstanding Balance', 'hmw-events'); ?></div>
      </div>
      <div>
        <div class="stat-value color-purple"><?php echo esc_html($stats_currency_symbol . number_format($potential_revenue, 2)); ?></div>
        <div class="stat-label"><?php _e('Potential Revenue', 'hmw-events'); ?></div>
      </div>
    </div>

    <!-- Export Button -->
    <div style="margin:12px 0;">
      <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=hmwevents_export_attendees&course_id=' . $post->ID), 'hmwevents_export_attendees_' . $post->ID, 'hmwevents_export_nonce')); ?>" class="button button-secondary">
        <?php _e('Export Attendee List (CSV)', 'hmw-events'); ?>
      </a>
    </div>
    <?php endif; // end empty($bookings) check for stats/export ?>

    <!-- Bookings Table -->
    <?php if (!empty($bookings)): ?>
    <div class="table-wrapper">
      <table class="widefat striped hmwevents-bookings-table">
        <thead>
          <tr>
            <th><?php _e('Customer', 'hmw-events'); ?></th>
            <th><?php _e('Booking #', 'hmw-events'); ?></th>
            <th><?php _e('Type', 'hmw-events'); ?></th>
            <th><?php _e('Amount', 'hmw-events'); ?></th>
            <th><?php _e('Status', 'hmw-events'); ?></th>
            <th><?php _e('Payment', 'hmw-events'); ?></th>
            <th><?php _e('Date', 'hmw-events'); ?></th>
            <th><?php _e('Actions', 'hmw-events'); ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($bookings as $booking): ?>
            <tr data-booking-id="<?php echo $booking->id; ?>">
              <td data-label="<?php _e('Customer', 'hmw-events'); ?>">
                <a href="<?php echo get_edit_post_link($booking->customer_id); ?>" target="_blank">
                  <strong><?php echo esc_html($booking->customer_name); ?></strong>
                </a>
              </td>
              <td data-label="<?php _e('Booking #', 'hmw-events'); ?>">
                <button type="button" class="button-link hmwevents-view-booking" data-booking-id="<?php echo esc_attr($booking->id); ?>" data-nonce="<?php echo esc_attr($booking_details_nonce); ?>" style="color: #2271b1; text-decoration: underline; cursor: pointer; background: none; border: none; padding: 0; font-weight: 600;">
                  <?php echo esc_html($booking->booking_number); ?>
                </button>
                <br>
                <small style="color: #666;"><?php echo esc_html($booking->booking_reference); ?></small>
              </td>
              <td data-label="<?php _e('Type', 'hmw-events'); ?>">
                <span class="badge" style="background: #e3f2fd; color: #1976d2; padding: 3px 8px; border-radius: 3px; font-size: 11px;">
                  <?php echo esc_html(ucfirst($booking->payment_type)); ?>
                </span>
              </td>
              <td data-label="<?php _e('Amount', 'hmw-events'); ?>">
                <?php
                $booking_currency = $booking->currency ?: 'AUD';
                $booking_currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($booking_currency);
                ?>
                <strong><?php echo esc_html($booking_currency_symbol . number_format($booking->total_paid, 2)); ?></strong>
                <?php if (!empty($booking->voucher_code)): ?>
                  <br>
                  <small style="color: #10b981; font-weight: 600;" title="<?php echo esc_attr(sprintf(__('Voucher: %s (-%s%s)', 'hmw-events'), $booking->voucher_code, $booking_currency_symbol, number_format($booking->voucher_amount, 2))); ?>">
                    <span class="dashicons dashicons-tickets-alt" style="font-size: 14px; vertical-align: middle;"></span>
                    <?php echo esc_html($booking->voucher_code); ?> (-<?php echo esc_html($booking_currency_symbol . number_format($booking->voucher_amount, 2)); ?>)
                  </small>
                <?php endif; ?>
                <?php if (!empty($booking->coupon_code)): ?>
                  <br>
                  <small style="color: #2271b1; font-weight: 600;" title="<?php echo esc_attr(sprintf(__('Coupon: %s (-%s%s)', 'hmw-events'), $booking->coupon_code, $booking_currency_symbol, number_format($booking->coupon_amount, 2))); ?>">
                    <span class="dashicons dashicons-tag" style="font-size: 14px; vertical-align: middle;"></span>
                    <?php echo esc_html($booking->coupon_code); ?> (-<?php echo esc_html($booking_currency_symbol . number_format($booking->coupon_amount, 2)); ?>)
                  </small>
                <?php endif; ?>
                <?php if ($booking->payment_type === 'deposit'): ?>
                  <?php
                    $full_course_price = $booking->course_full_price ?: $booking->total_amount;
                    $total_paid = floatval($booking->total_paid ?? 0);
                    ?>
                  <?php if ($total_paid > $booking->booking_amount): ?>
                    <?php $remaining_paid = $total_paid - $booking->booking_amount; ?>
                    <br>
                    <small style="color: #666;">
                      <?php echo esc_html($booking_currency_symbol . number_format($booking->booking_amount, 2)); ?> + <?php echo esc_html($booking_currency_symbol . number_format($remaining_paid, 2)); ?>
                    </small>
                    <br>
                    <small style="color: #00a32a; font-weight: 500;">
                      <?php _e('(Full payment received)', 'hmw-events'); ?>
                    </small>
                  <?php else: ?>
                    <br>
                    <small style="color: #666;">
                      of <?php echo esc_html($booking_currency_symbol . number_format($full_course_price, 2)); ?>
                    </small>
                    <?php $remaining = $full_course_price - $booking->booking_amount; ?>
                    <?php if ($remaining > 0): ?>
                      <br>
                      <small style="color: #ff6b35; font-weight: 500;">
                        <?php echo esc_html($booking_currency_symbol . number_format($remaining, 2)); ?> remaining
                      </small>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
              <td data-label="<?php _e('Status', 'hmw-events'); ?>">
                <?php
                $status_colors = [
                    'pending' => '#ff9800',
                    'confirmed' => '#4caf50',
                    'cancelled' => '#f44336',
                    'completed' => '#2196f3',
                ];
              $color = $status_colors[$booking->status] ?? '#757575';
              ?>
                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo $color; ?>; margin-right: 5px;"></span>
                <?php echo esc_html(ucfirst($booking->status)); ?>
              </td>
              <td data-label="<?php _e('Payment', 'hmw-events'); ?>">
                <?php
              $payment_colors = [
                'pending' => '#ff9800',
                'paid' => '#4caf50',
                'failed' => '#f44336',
                'refunded' => '#9c27b0',
              ];
              $payment_color = $payment_colors[$booking->payment_status] ?? '#757575';
              ?>
                <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: <?php echo $payment_color; ?>; margin-right: 5px;"></span>
                <?php echo esc_html(ucfirst($booking->payment_status)); ?>
              </td>
              <td data-label="<?php _e('Date', 'hmw-events'); ?>">
                <?php echo date('M j, Y', strtotime($booking->created_at)); ?>
                <br>
                <small style="color: #666;"><?php echo date('g:i a', strtotime($booking->created_at)); ?></small>
              </td>
              <td data-label="<?php _e('Actions', 'hmw-events'); ?>">
                <div class="row-actions">
                  <span class="view-payment">
                    <a href="<?php echo esc_url(admin_url('admin.php?page=hmwevents-educator-payments&s=' . urlencode($booking->booking_number))); ?>"
                      title="<?php _e('View Payment Info', 'hmw-events'); ?>">
                      <?php _e('Payment Info', 'hmw-events'); ?>
                    </a>
                  </span>
                  | <span class="edit-booking">
                    <a href="#"
                      class="hmwevents-edit-booking"
                      data-booking-id="<?php echo esc_attr($booking->id); ?>"
                      data-nonce="<?php echo esc_attr($booking_details_nonce); ?>">
                      <?php _e('Edit', 'hmw-events'); ?>
                    </a>
                  </span>
                  | <span class="transfer">
                    <a href="#TB_inline?width=400&height=250&inlineId=transfer-booking-modal-<?php echo $booking->id; ?>"
                      class="thickbox"
                      title="<?php _e('Transfer Booking', 'hmw-events'); ?>">
                      <?php _e('Transfer', 'hmw-events'); ?>
                    </a>
                  </span>
                  <?php if ($booking->status === 'confirmed'): ?>
                    | <span class="resend-confirmation">
                      <a href="#"
                        class="hmwevents-resend-confirmation"
                        data-booking-id="<?php echo $booking->id; ?>"
                        data-nonce="<?php echo wp_create_nonce('resend_confirmation_' . $booking->id); ?>">
                        <?php _e('Resend Confirmation', 'hmw-events'); ?>
                      </a>
                    </span>
                  <?php endif; ?>
                  <?php if (in_array($booking->payment_status, ['pending', 'failed'], true)): ?>
                    | <span class="send-payment-link">
                      <a href="#"
                        class="hmwevents-send-payment-link"
                        data-booking-id="<?php echo esc_attr($booking->id); ?>">
                        <?php _e('Send Payment Link', 'hmw-events'); ?>
                      </a>
                    </span>
                  <?php elseif (\HMWEvents\Helpers\Booking::needs_remaining_payment_link($booking)): ?>
                    | <span class="send-payment-link">
                      <a href="#"
                        class="hmwevents-send-payment-link"
                        data-booking-id="<?php echo esc_attr($booking->id); ?>">
                        <?php _e('Send Remaining Link', 'hmw-events'); ?>
                      </a>
                    </span>
                  <?php endif; ?>
                  <!-- <?php if ($booking->payment_status === 'paid'): ?>
                    | <span class="resend-receipt">
                      <a href="#"
                        class="hmwevents-resend-receipt"
                        data-booking-id="<?php echo $booking->id; ?>"
                        data-nonce="<?php echo wp_create_nonce('resend_receipt_' . $booking->id); ?>">
                        <?php _e('Resend Receipt', 'hmw-events'); ?>
                      </a>
                    </span>
                  <?php endif; ?> -->
                </div>

                <!-- Hidden transfer modal content -->
                <div id="transfer-booking-modal-<?php echo $booking->id; ?>" style="display:none;">
                  <div style="padding: 20px;">
                    <h3><?php _e('Transfer Booking to Another Course', 'hmw-events'); ?></h3>
                    <p>
                      <?php printf(
                          __('Transfer booking %s from <strong>%s</strong> to:', 'hmw-events'),
                          $booking->booking_number,
                          get_the_title($post->ID)
                      ); ?>
                    </p>
                    <select id="transfer-course-<?php echo $booking->id; ?>" style="width: 100%; padding: 8px; margin: 15px 0;">
                      <option value=""><?php _e('-- Select Course --', 'hmw-events'); ?></option>
                      <?php foreach ($educator_courses as $course): ?>
                        <?php if ($course->ID != $post->ID): ?>
                          <?php
                          $course_start_date = get_post_meta($course->ID, 'course_start_date', true);
                            $date_display = $course_start_date ? ' - ' . date('M j, Y', strtotime($course_start_date)) : '';
                            ?>
                          <option value="<?php echo $course->ID; ?>">
                            <?php echo esc_html($course->post_title . $date_display); ?>
                          </option>
                        <?php endif; ?>
                      <?php endforeach; ?>
                    </select>
                    <div style="margin-top: 20px; text-align: right;">
                      <button type="button" class="button" onclick="tb_remove();">
                        <?php _e('Cancel', 'hmw-events'); ?>
                      </button>
                      <button type="button"
                        class="button button-primary hmwevents-confirm-transfer"
                        data-booking-id="<?php echo $booking->id; ?>"
                        data-nonce="<?php echo wp_create_nonce('transfer_booking_' . $booking->id); ?>">
                        <?php _e('Transfer Booking', 'hmw-events'); ?>
                      </button>
                    </div>
                    <div class="hmwevents-transfer-message" style="margin-top: 15px; display: none;"></div>
                  </div>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div><!-- /.table-wrapper -->
    <?php endif; // end !empty($bookings) for table ?>

    <!-- Pagination -->
    <?php if (!empty($bookings) && $pagination->has_pages()): ?>
      <div style="margin-top: 20px;">
        <?php echo $pagination->render('wordpress'); ?>
      </div>
    <?php endif; ?>

    <!-- Booking Details Modal -->
    <div id="hmwevents-booking-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; overflow-y:auto;">
      <div style="background:#fff; margin:40px auto; max-width:700px; border-radius:4px; box-shadow:0 4px 20px rgba(0,0,0,0.3); overflow:hidden;">
        <div style="background:#2271b1; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
          <h3 id="hmwevents-booking-modal-title" style="margin:0; font-size:16px; color:#fff;"></h3>
          <button type="button" id="hmwevents-booking-modal-close" style="background:none; border:none; color:#fff; font-size:22px; cursor:pointer; line-height:1; padding:0;">&times;</button>
        </div>
        <div id="hmwevents-booking-modal-body" style="padding:20px;">
          <p style="text-align:center; color:#666;"><?php _e('Loading&hellip;', 'hmw-events'); ?></p>
        </div>
      </div>
    </div>

    <script>
    (function($) {
      var LABELS = <?php echo wp_json_encode(\HMWEvents\Config\BookingFields::labels()); ?>;

      function formatLabel(key) {
        if (LABELS[key]) return LABELS[key];
        return key.replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
      }

      function formatValue(val) {
        if (val === null || val === undefined || val === '') return '<em style="color:#999">—</em>';
        if (typeof val === 'boolean' || val === 'true' || val === 'false' || val === '1' || val === 1 || val === '0' || val === 0) {
          return (val === true || val === 'true' || val === '1' || val === 1) ? '&#10004; Yes' : '&#10008; No';
        }
        if (typeof val === 'object') return JSON.stringify(val, null, 2);
        return $('<div>').text(String(val)).html();
      }

      function buildModalContent(data) {
        var b = data.booking;
        var currency_symbol = b.currency === 'AUD' ? '$' : b.currency + ' ';
        var html = '';

        html += '<table class="widefat fixed" style="margin-bottom:16px;">';
        html += '<thead><tr><th colspan="2" style="background:#f0f6ff; color:#2271b1;"><?php echo esc_js(__('Booking Summary', 'hmw-events')); ?></th></tr></thead><tbody>';

        var summary = [
          ['Reference',       b.booking_reference],
          b.educator_name ? ['Educator', b.educator_name] : null,
          ['Payment Type',    b.payment_type ? b.payment_type.charAt(0).toUpperCase() + b.payment_type.slice(1) : '—'],
          ['Amount',          currency_symbol + parseFloat(b.booking_amount).toFixed(2)],
          ['Group Total',     currency_symbol + parseFloat(b.group_total).toFixed(2)],
          ['Status',          b.status ? b.status.charAt(0).toUpperCase() + b.status.slice(1) : '—'],
          ['Payment Status',  b.payment_status ? b.payment_status.charAt(0).toUpperCase() + b.payment_status.slice(1) : '—'],
          ['Group Payment',   b.group_payment_status ? b.group_payment_status.charAt(0).toUpperCase() + b.group_payment_status.slice(1) : '—'],
          ['Booked At',       b.created_at || '—'],
          b.cancelled_at ? ['Cancelled At', b.cancelled_at] : null
        ];

        $.each(summary, function(i, row) {
          if (!row) return;
          html += '<tr><td style="width:40%; font-weight:600; padding:8px 10px;">' + $('<div>').text(row[0]).html() + '</td>';
          html += '<td style="padding:8px 10px;">' + $('<div>').text(String(row[1] || '')).html() + '</td></tr>';
        });
        html += '</tbody></table>';

        // Merge customer_contact fields with booking details in canonical display order.
        // form_data (booking_details table) wins on any overlapping keys.
        var cc = data.customer_contact || {};
        var fd = data.form_data || {};
        var contact_mapped = {};
        if (cc.first_name)     contact_mapped.mothers_first_name   = cc.first_name;
        if (cc.last_name)      contact_mapped.mothers_last_name    = cc.last_name;
        if (cc.partner_name)   contact_mapped.partner_name         = cc.partner_name;
        if (cc.email)          contact_mapped.email                = cc.email;
        if (cc.phone)          contact_mapped.phone                = cc.phone;
        if (cc.street_address) contact_mapped.street_address       = cc.street_address;
        if (cc.city)           contact_mapped.city                 = cc.city;
        if (cc.postcode)       contact_mapped.postcode             = cc.postcode;
        var merged = Object.assign({}, contact_mapped, fd);
        var DISPLAY_ORDER = <?php echo wp_json_encode(\HMWEvents\Config\BookingFields::keys()); ?>;
        var form_data = {};
        DISPLAY_ORDER.forEach(function(k) {
          if (merged.hasOwnProperty(k) && merged[k] !== null && merged[k] !== undefined && merged[k] !== '') form_data[k] = merged[k];
        });
        $.each(merged, function(k) { if (!form_data.hasOwnProperty(k)) form_data[k] = merged[k]; });
        if (Object.keys(form_data).length > 0) {
          html += '<table class="widefat fixed">';
          html += '<thead><tr><th colspan="2" style="background:#f0f6ff; color:#2271b1;"><?php echo esc_js(__('Booking Details', 'hmw-events')); ?></th></tr></thead><tbody>';
          var odd = true;
          $.each(form_data, function(key, val) {
            var bg = odd ? '#fff' : '#f9f9f9';
            html += '<tr style="background:' + bg + ';">';
            html += '<td style="width:40%; font-weight:600; padding:8px 10px; vertical-align:top;">' + formatLabel(key) + '</td>';
            html += '<td style="padding:8px 10px; vertical-align:top;">' + formatValue(val) + '</td>';
            html += '</tr>';
            odd = !odd;
          });
          html += '</tbody></table>';
        } else {
          html += '<p style="color:#666; font-style:italic;"><?php echo esc_js(__('No additional booking details on file.', 'hmw-events')); ?></p>';
        }

        return html;
      }

      $(document).on('click', '.hmwevents-view-booking', function() {
        var $btn  = $(this);
        var id    = $btn.data('booking-id');
        var nonce = $btn.data('nonce');
        var $modal = $('#hmwevents-booking-modal');
        var $body  = $('#hmwevents-booking-modal-body');
        var $title = $('#hmwevents-booking-modal-title');

        $title.text('<?php echo esc_js(__('Booking Details', 'hmw-events')); ?>');
        $body.html('<p style="text-align:center; color:#666; padding:30px;"><?php echo esc_js(__('Loading…', 'hmw-events')); ?></p>');
        $modal.fadeIn(150);

        $.post(ajaxurl, {
          action:     'hmwevents_get_booking_details',
          booking_id: id,
          nonce:      nonce
        }, function(resp) {
          if (resp.success) {
            $title.text('<?php echo esc_js(__('Booking', 'hmw-events')); ?> ' + resp.data.booking.booking_number);
            $body.html(buildModalContent(resp.data));
          } else {
            $body.html('<p style="color:#d63638;">' + (resp.data && resp.data.message ? resp.data.message : '<?php echo esc_js(__('Unable to load booking details.', 'hmw-events')); ?>') + '</p>');
          }
        }).fail(function() {
          $body.html('<p style="color:#d63638;"><?php echo esc_js(__('Request failed. Please try again.', 'hmw-events')); ?></p>');
        });
      });

      $('#hmwevents-booking-modal-close').on('click', function() {
        $('#hmwevents-booking-modal').fadeOut(150);
      });

      $(document).on('click', '#hmwevents-booking-modal', function(e) {
        if ($(e.target).is('#hmwevents-booking-modal')) {
          $('#hmwevents-booking-modal').fadeOut(150);
        }
      });
    })(jQuery);
    </script>

    <!-- Add Booking Modal -->
    <div id="hmwevents-add-booking-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; overflow-y:auto;">
      <div style="background:#fff; margin:40px auto; max-width:680px; border-radius:4px; box-shadow:0 4px 20px rgba(0,0,0,0.3); overflow:hidden;">
        <div style="background:#2271b1; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
          <h3 style="margin:0; font-size:16px; color:#fff;"><?php _e('Add Manual Booking', 'hmw-events'); ?></h3>
          <button type="button" id="hmwevents-add-booking-modal-close" style="background:none; border:none; color:#fff; font-size:22px; cursor:pointer; line-height:1; padding:0;">&times;</button>
        </div>
        <div style="padding:20px;">
          <p style="color:#666; margin-top:0;">
            <?php printf(
              __('Booking will be confirmed immediately with payment status <strong>Pending</strong>. The customer will receive a confirmation email. Use the <em>Send Payment Link</em> action to email the customer a link to pay for the course in full, if you wish to. Full course price: <strong>%s%s</strong>.', 'hmw-events'),
              esc_html($course_currency_symbol),
              esc_html(number_format(floatval($course_full_cost), 2))
            ); ?>
          </p>
          <div id="hmwevents-add-booking-form">
            <input type="hidden" name="course_id" value="<?php echo esc_attr($post->ID); ?>" data-hmwevents-field>
            <table class="form-table" style="margin:0;">
              <?php foreach (\HMWEvents\Config\BookingFields::for_edit_modal() as $key => $field): ?>
              <tr>
                <th style="width:35%;">
                  <?php echo esc_html($field['label']); ?>
                  <?php if ($field['manual_required']): ?><span style="color:red;">*</span><?php endif; ?>
                </th>
                <td>
                  <?php if ($field['type'] === 'textarea'): ?>
                    <textarea name="<?php echo esc_attr($field['form_name']); ?>" class="regular-text" rows="3"></textarea>
                  <?php elseif ($field['type'] === 'select'): ?>
                    <select name="<?php echo esc_attr($field['form_name']); ?>" class="regular-text">
                      <?php foreach ($field['options'] as $opt_val => $opt_label): ?>
                        <option value="<?php echo esc_attr($opt_val); ?>"><?php echo esc_html($opt_label); ?></option>
                      <?php endforeach; ?>
                    </select>
                  <?php elseif ($field['type'] === 'checkbox'): ?>
                    <label>
                      <input type="checkbox" name="<?php echo esc_attr($field['form_name']); ?>" value="1">
                      <?php echo esc_html($field['form_description'] ?? $field['label']); ?>
                    </label>
                  <?php else: ?>
                    <input type="<?php echo esc_attr($field['type']); ?>" name="<?php echo esc_attr($field['form_name']); ?>" class="regular-text">
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </table>
            <div id="hmwevents-add-booking-message" style="margin:12px 0; display:none;"></div>
            <div style="margin-top:20px; display:flex; gap:10px; justify-content:flex-end;">
              <button type="button" class="button" id="hmwevents-add-booking-cancel"><?php _e('Cancel', 'hmw-events'); ?></button>
              <button type="button" class="button button-primary" id="hmwevents-add-booking-submit"><?php _e('Create Booking', 'hmw-events'); ?></button>
            </div>
          </div><!-- /#hmwevents-add-booking-form -->
        </div>
      </div>
    </div>

    <!-- Edit Booking Modal -->
    <div id="hmwevents-edit-booking-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:99999; overflow-y:auto;">
      <div style="background:#fff; margin:40px auto; max-width:680px; border-radius:4px; box-shadow:0 4px 20px rgba(0,0,0,0.3); overflow:hidden;">
        <div style="background:#2271b1; color:#fff; padding:16px 20px; display:flex; justify-content:space-between; align-items:center;">
          <h3 id="hmwevents-edit-booking-modal-title" style="margin:0; font-size:16px; color:#fff;"><?php _e('Edit Booking', 'hmw-events'); ?></h3>
          <button type="button" id="hmwevents-edit-booking-modal-close" style="background:none; border:none; color:#fff; font-size:22px; cursor:pointer; line-height:1; padding:0;">&times;</button>
        </div>
        <div id="hmwevents-edit-booking-modal-body" style="padding:20px;">
          <p style="text-align:center; color:#666;"><?php _e('Loading&hellip;', 'hmw-events'); ?></p>
        </div>
      </div>
    </div>
<?php
    }

    /**
     * Export attendee list as a CSV file for a given course.
     *
     * @since 1.0.0
     */
    public function export_attendees()
    {
        $course_id = intval($_GET['course_id'] ?? 0);

        if (!$course_id || !check_admin_referer('hmwevents_export_attendees_' . $course_id, 'hmwevents_export_nonce')) {
            wp_die(__('Invalid request.', 'hmw-events'));
        }

        $post = get_post($course_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_die(__('Course not found.', 'hmw-events'));
        }

        $current_user_id = get_current_user_id();
        if (!current_user_can('manage_options') && $post->post_author != $current_user_id) {
            wp_die(__('You do not have permission to export bookings for this course.', 'hmw-events'));
        }

        global $wpdb;

        // Fetch all bookings for this course (no pagination)
        $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT
                b.id,
                b.booking_number,
                b.booking_amount,
                b.currency,
                b.status,
                b.payment_status,
                b.created_at,
                b.cancelled_at,
                b.customer_post_id,
                bg.booking_reference,
                bg.payment_type,
                vu.voucher_code,
                cuu.coupon_code
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            LEFT JOIN {$wpdb->prefix}educator_voucher_usage vu ON b.id = vu.booking_id
            LEFT JOIN {$wpdb->prefix}educator_coupon_usage cuu ON b.id = cuu.booking_id
            WHERE b.course_post_id = %d
            AND b.deleted_at IS NULL
            ORDER BY b.created_at ASC
        ", $course_id));

        // Known customer/booking fields that always appear as ordered columns.
        // Any form_data keys not in this list are appended dynamically at the end.
        $known_form_fields = \HMWEvents\Config\BookingFields::keys_for_csv();
        $field_labels      = \HMWEvents\Config\BookingFields::labels_for_csv();

        // Collect form data for each booking, tracking any extra keys beyond the known set
        $rows       = [];
        $extra_keys = [];

        foreach ($bookings as $booking) {
            $form_data = [];

            $details_row = $wpdb->get_row($wpdb->prepare(
                "SELECT form_data FROM {$wpdb->prefix}educator_booking_details WHERE booking_id = %d",
                $booking->id
            ));
            if ($details_row) {
                $decoded = json_decode($details_row->form_data, true);
                if (is_array($decoded)) {
                    $form_data = $decoded;
                }
            }

            // For manual bookings (and any booking where contact fields are absent from
            // booking_details), pull them from customer post meta so the CSV is complete.
            if (!empty($booking->customer_post_id)) {
                foreach (\HMWEvents\Config\BookingFields::with_meta_fallback() as $key => $field) {
                    if (empty($form_data[$key])) {
                        $val = get_post_meta($booking->customer_post_id, $field['meta_key'], true);
                        if ($val !== '' && $val !== false) {
                            $form_data[$key] = $val;
                        }
                    }
                }
            }

            $meta_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->prefix}educator_booking_meta WHERE booking_id = %d ORDER BY meta_key ASC",
                $booking->id
            ));
            foreach ($meta_rows as $meta) {
                $val = json_decode($meta->meta_value, true);
                $val = ($val !== null) ? $val : $meta->meta_value;
                if (!array_key_exists($meta->meta_key, $form_data)) {
                    $form_data[$meta->meta_key] = $val;
                }
            }

            // Gather keys that fall outside the known set (preserving encounter order)
            foreach (array_keys($form_data) as $key) {
                if (!in_array($key, $known_form_fields, true) && !in_array($key, $extra_keys, true)) {
                    $extra_keys[] = $key;
                }
            }

            $rows[] = ['booking' => $booking, 'form_data' => $form_data];
        }

        // Helper to format a single cell value
        $format_cell = function ($val) {
            if ($val === null || $val === '') return '';
            if ($val === true  || $val === 1 || $val === '1') return 'Yes';
            if ($val === false || $val === 0 || $val === '0') return 'No';
            if (is_array($val) || is_object($val)) return json_encode($val);
            return (string) $val;
        };

        // Build headers
        $fixed_headers = [
            'Booking Number',
            'Booking Reference',
            'Status',
            'Payment Status',
            'Payment Type',
            'Amount',
            'Currency',
            'Voucher Code',
            'Coupon Code',
            'Booked At',
            'Cancelled At',
        ];

        $known_headers = array_map(function ($k) use ($field_labels) {
            return $field_labels[$k] ?? ucwords(str_replace('_', ' ', $k));
        }, $known_form_fields);

        $extra_headers = array_map(function ($k) {
            return ucwords(str_replace('_', ' ', $k));
        }, $extra_keys);

        $all_headers = array_merge($fixed_headers, $known_headers, $extra_headers);

        // Stream CSV
        $course_slug = sanitize_title($post->post_title);
        $filename    = 'attendees-' . $course_slug . '-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $output = fopen('php://output', 'w');
        fputcsv($output, $all_headers);

        foreach ($rows as $row) {
            $b  = $row['booking'];
            $fd = $row['form_data'];

            $fixed_values = [
                $b->booking_number,
                $b->booking_reference,
                $b->status,
                $b->payment_status,
                $b->payment_type,
                $b->booking_amount,
                $b->currency,
                $b->voucher_code ?? '',
                $b->coupon_code  ?? '',
                $b->created_at   ?? '',
                $b->cancelled_at ?? '',
            ];

            $known_values = array_map(function ($key) use ($fd, $format_cell) {
                return $format_cell($fd[$key] ?? '');
            }, $known_form_fields);

            $extra_values = array_map(function ($key) use ($fd, $format_cell) {
                return $format_cell($fd[$key] ?? '');
            }, $extra_keys);

            fputcsv($output, array_merge($fixed_values, $known_values, $extra_values));
        }

        fclose($output);
        exit;
    }

    /**
     * Filter courses list to only show educator's own courses (unless admin).
     *
     * @since 1.0.0
     * @param WP_Query $query The WordPress query object.
     */
    public function filter_courses_by_author($query)
    {
        global $pagenow;

        // Only apply in admin on the edit.php page for our post type
        if (!is_admin() || $pagenow !== 'edit.php') {
            return;
        }

        // Only for educator_course post type
        if (!isset($query->query['post_type']) || $query->query['post_type'] !== self::POST_TYPE) {
            return;
        }

        // Don't filter for administrators
        if (current_user_can('manage_options')) {
            return;
        }

        // Set query to only show current user's courses
        $query->set('author', get_current_user_id());
    }

    /**
     * Remove the "Mine" filter from the courses list views and update counts.
     *
     * @since 1.0.0
     * @param array $views Array of available list table views.
     * @return array Modified views array.
     */
    public function remove_mine_filter($views)
    {
        // Remove the "Mine" view for non-admins (since "All" now shows only their courses)
        if (!current_user_can('manage_options')) {
            unset($views['mine']);

            // Recalculate counts to only show educator's courses
            $user_id = get_current_user_id();
            
            // Count posts by status for this user
            $counts = [
                'all' => 0,
                'publish' => 0,
                'draft' => 0,
                'pending' => 0,
                'expired' => 0,
                'trash' => 0,
            ];

            foreach (array_keys($counts) as $status) {
                $args = [
                    'post_type' => self::POST_TYPE,
                    'author' => $user_id,
                    'posts_per_page' => -1,
                    'fields' => 'ids',
                ];

                if ($status !== 'all') {
                    $args['post_status'] = $status;
                } else {
                    $args['post_status'] = ['publish', 'draft', 'pending', 'future', 'private', 'expired'];
                }

                $query = new \WP_Query($args);
                $counts[$status] = $query->found_posts;
            }

            // Update the view links with correct counts
            global $wp_query;
            $current_status = isset($_GET['post_status']) ? $_GET['post_status'] : 'all';
            
            // All link
            if (isset($views['all'])) {
                $class = ($current_status === 'all') ? ' class="current"' : '';
                $views['all'] = sprintf(
                    '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                    admin_url('edit.php?post_type=' . self::POST_TYPE),
                    $class,
                    __('All', 'hmw-events'),
                    $counts['all']
                );
            }

            // Published link
            if (isset($views['publish'])) {
                $class = ($current_status === 'publish') ? ' class="current"' : '';
                $views['publish'] = sprintf(
                    '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                    admin_url('edit.php?post_type=' . self::POST_TYPE . '&post_status=publish'),
                    $class,
                    __('Published', 'hmw-events'),
                    $counts['publish']
                );
            }

            // Draft link
            if (isset($views['draft']) && $counts['draft'] > 0) {
                $class = ($current_status === 'draft') ? ' class="current"' : '';
                $views['draft'] = sprintf(
                    '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                    admin_url('edit.php?post_type=' . self::POST_TYPE . '&post_status=draft'),
                    $class,
                    __('Draft', 'hmw-events'),
                    $counts['draft']
                );
            } elseif (isset($views['draft'])) {
                unset($views['draft']);
            }

            // Trash link
            if (isset($views['trash'])) {
                $class = ($current_status === 'trash') ? ' class="current"' : '';
                $views['trash'] = sprintf(
                    '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                    admin_url('edit.php?post_type=' . self::POST_TYPE . '&post_status=trash'),
                    $class,
                    __('Trash', 'hmw-events'),
                    $counts['trash']
                );
            }

            // Expired link
            if ($counts['expired'] > 0) {
                $class = ($current_status === 'expired') ? ' class="current"' : '';
                $views['expired'] = sprintf(
                    '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                    admin_url('edit.php?post_type=' . self::POST_TYPE . '&post_status=expired'),
                    $class,
                    __('Expired', 'hmw-events'),
                    $counts['expired']
                );
            }
        }

        return $views;
    }
}
