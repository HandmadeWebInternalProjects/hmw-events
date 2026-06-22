<?php

/**
 * Customer Custom Post Type.
 *
 * Handles registration and configuration of the Customer post type
 * for managing educator customers.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\PostTypes;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Customer Post Type.
 */
class Customer
{
  /**
   * Post type slug.
   *
   * @var string
   */
  public const POST_TYPE = 'edu_customer';

  /**
   * Initialize the post type.
   *
   * @since 1.0.0
   */
  public function register()
  {
    add_action('init', [$this, 'register_post_type']);
    add_action('init', [$this, 'grant_admin_capabilities'], 11);
    add_filter('pre_get_posts', [$this, 'filter_customers_by_educator']);
    add_filter('map_meta_cap', [$this, 'filter_customer_capabilities'], 10, 4);
    add_filter('views_edit-' . self::POST_TYPE, [$this, 'filter_customer_counts']);

    add_action('add_meta_boxes', [$this, 'add_customer_meta_boxes']);
    add_action('wp_ajax_hmwevents_get_booking_details', [$this, 'ajax_get_booking_details']);
  }

  /**
   * Register the Customer post type.
   *
   * @since 1.0.0
   */
  public function register_post_type()
  {
    $labels = [
      'name'                  => _x('Customers', 'Post type general name', 'hmw-events'),
      'singular_name'         => _x('Customer', 'Post type singular name', 'hmw-events'),
      'menu_name'             => _x('Customers', 'Admin Menu text', 'hmw-events'),
      'name_admin_bar'        => _x('Customer', 'Add New on Toolbar', 'hmw-events'),
      'add_new'               => __('Add New', 'hmw-events'),
      'add_new_item'          => __('Add New Customer', 'hmw-events'),
      'new_item'              => __('New Customer', 'hmw-events'),
      'edit_item'             => __('Edit Customer', 'hmw-events'),
      'view_item'             => __('View Customer', 'hmw-events'),
      'all_items'             => __('All Customers', 'hmw-events'),
      'search_items'          => __('Search Customers', 'hmw-events'),
      'parent_item_colon'     => __('Parent Customers:', 'hmw-events'),
      'not_found'             => __('No customers found.', 'hmw-events'),
      'not_found_in_trash'    => __('No customers found in Trash.', 'hmw-events'),
      'filter_items_list'     => _x('Filter customers list', 'Screen reader text for the filter links', 'hmw-events'),
      'items_list_navigation' => _x('Customers list navigation', 'Screen reader text for the pagination', 'hmw-events'),
      'items_list'            => _x('Customers list', 'Screen reader text for the items list', 'hmw-events'),
    ];

    $args = [
      'labels'             => $labels,
      'public'             => false,
      'publicly_queryable' => false,
      'show_ui'            => true,
      'show_in_menu'       => true,
      'query_var'          => false,
      'capability_type'    => ['edu_customer', 'edu_customers'],
      'map_meta_cap'       => true,
      'has_archive'        => false,
      'hierarchical'       => false,
      'menu_position'      => 21,
      'menu_icon'          => 'dashicons-groups',
      'supports'           => ['title', 'editor'],
      'show_in_rest'       => true,
    ];

    register_post_type(self::POST_TYPE, $args);
  }

  /**
   * Grant custom capabilities to the admin role.
   *
   * @since 1.0.0
   */
  public function grant_admin_capabilities()
  {
    $admin_role = get_role('administrator');
    
    if (!$admin_role) {
      return;
    }

    // Grant all customer-related capabilities to admin
    $capabilities = [
      'read_edu_customer',
      'read_private_edu_customers',
      'edit_edu_customer',
      'edit_edu_customers',
      'edit_others_edu_customers',
      'edit_published_edu_customers',
      'delete_edu_customer',
      'delete_edu_customers',
      'delete_others_edu_customers',
      'delete_published_edu_customers',
    ];

    foreach ($capabilities as $cap) {
      if (!$admin_role->has_cap($cap)) {
        $admin_role->add_cap($cap);
      }
    }
  }

  /**
   * Filter customer list to show only educator's customers.
   *
   * @since 1.0.0
   * @param \WP_Query $query The WordPress query object.
   */
  public function filter_customers_by_educator($query)
  {
    global $pagenow, $wpdb;

    // Only filter in admin area, on edit.php page, for our post type
    if (!is_admin() || $pagenow !== 'edit.php' || !isset($_GET['post_type']) || $_GET['post_type'] !== self::POST_TYPE) {
      return;
    }

    // Admins see all customers
    if (current_user_can('manage_options')) {
      return;
    }

    // For educators, only show customers who have bookings for their courses
    $current_user_id = get_current_user_id();

    // Get customer IDs who have booked this educator's courses
    $customer_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT b.customer_post_id
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            WHERE c.post_author = %d
            AND b.deleted_at IS NULL
        ", $current_user_id));

    if (empty($customer_ids)) {
      // No customers found, show nothing
      $query->set('post__in', [0]);
    } else {
      // Show only these customers
      $query->set('post__in', $customer_ids);
    }
  }

  /**
   * Filter capabilities to prevent educators viewing other educators' customers.
   *
   * @since 1.0.0
   * @param array  $caps    Required capabilities.
   * @param string $cap     Capability being checked.
   * @param int    $user_id User ID.
   * @param array  $args    Additional arguments.
   * @return array Modified capabilities.
   */
  public function filter_customer_capabilities($caps, $cap, $user_id, $args)
  {
    global $wpdb;

    // Only filter read/edit/delete capabilities for our post type
    if (!in_array($cap, ['read_post', 'edit_post', 'delete_post'])) {
      return $caps;
    }

    // Get the post ID from args
    if (empty($args[0])) {
      return $caps;
    }

    $post_id = $args[0];
    $post = get_post($post_id);

    // Only apply to our post type
    if (!$post || $post->post_type !== self::POST_TYPE) {
      return $caps;
    }

    // Admins can do anything - check via role to avoid recursion
    $user = get_userdata($user_id);
    if ($user && in_array('administrator', (array) $user->roles)) {
      // Admin has the capabilities, return empty = grant access
      return [];
    }

    // Check if this customer has bookings with the current educator
    $has_booking = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*)
        FROM {$wpdb->prefix}educator_bookings b
        INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
        WHERE b.customer_post_id = %d
        AND c.post_author = %d
        AND b.deleted_at IS NULL
    ", $post_id, $user_id));

    if (!$has_booking) {
      // Educator doesn't have this customer, deny access
      $caps[] = 'do_not_allow';
    }

    return $caps;
  }

  /**
   * Filter the customer counts in the list table views.
   *
   * @since 1.0.0
   * @param array $views An array of available list table views.
   * @return array Modified views with correct counts.
   */
  public function filter_customer_counts($views)
  {
    global $wpdb;

    // Admins see all customers - no need to modify counts
    if (current_user_can('manage_options')) {
      return $views;
    }

    $current_user_id = get_current_user_id();

    // Get customer IDs who have booked this educator's courses
    $customer_ids = $wpdb->get_col($wpdb->prepare("
        SELECT DISTINCT b.customer_post_id
        FROM {$wpdb->prefix}educator_bookings b
        INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
        WHERE c.post_author = %d
        AND b.deleted_at IS NULL
    ", $current_user_id));

    $total_count = count($customer_ids);

    if ($total_count === 0) {
      // No customers, set all counts to 0
      foreach ($views as $key => $view) {
        $views[$key] = preg_replace('/\(.+?\)/', '(0)', $view);
      }
      return $views;
    }

    // Count by status
    $status_counts = $wpdb->get_results($wpdb->prepare("
        SELECT post_status, COUNT(*) as count
        FROM {$wpdb->posts}
        WHERE post_type = %s
        AND ID IN (" . implode(',', array_map('intval', $customer_ids)) . ")
        GROUP BY post_status
    ", self::POST_TYPE), OBJECT_K);

    // Update the 'All' view
    if (isset($views['all'])) {
      $views['all'] = preg_replace('/\(.+?\)/', '(' . $total_count . ')', $views['all']);
    }

    // Update status-specific views
    foreach ($views as $key => $view) {
      if ($key === 'all') {
        continue;
      }

      $status = $key;
      $count = isset($status_counts[$status]) ? $status_counts[$status]->count : 0;
      $views[$key] = preg_replace('/\(.+?\)/', '(' . $count . ')', $view);
    }

    return $views;
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
   * AJAX handler: fetch full booking details for the modal.
   *
   * @since 1.0.0
   */
  public function ajax_get_booking_details()
  {
    check_ajax_referer('hmwevents_booking_details_nonce', 'nonce');

    $booking_id = intval($_POST['booking_id'] ?? 0);
    if (!$booking_id) {
      wp_send_json_error(['message' => 'Invalid booking ID'], 400);
    }

    global $wpdb;

    // Load the booking with course info, verifying educator access if not admin
    if (current_user_can('manage_options')) {
      $booking = $wpdb->get_row($wpdb->prepare("
        SELECT b.*,
               c.post_title AS course_name,
               c.ID         AS course_id,
               bg.booking_reference,
               bg.payment_type  AS group_payment_type,
               bg.total_amount  AS group_total_amount,
               bg.payment_status AS group_payment_status,
               u.display_name   AS educator_name
        FROM {$wpdb->prefix}educator_bookings b
        INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
        INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
        INNER JOIN {$wpdb->users} u ON c.post_author = u.ID
        WHERE b.id = %d
      ", $booking_id));
    } else {
      $current_user_id = get_current_user_id();
      $booking = $wpdb->get_row($wpdb->prepare("
        SELECT b.*,
               c.post_title AS course_name,
               c.ID         AS course_id,
               bg.booking_reference,
               bg.payment_type  AS group_payment_type,
               bg.total_amount  AS group_total_amount,
               bg.payment_status AS group_payment_status
        FROM {$wpdb->prefix}educator_bookings b
        INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
        INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
        WHERE b.id = %d
          AND c.post_author = %d
      ", $booking_id, $current_user_id));
    }

    if (!$booking) {
      wp_send_json_error(['message' => 'Booking not found or access denied'], 403);
    }

    // Form data (questionnaire responses stored as JSON)
    $form_data_row = $wpdb->get_row($wpdb->prepare("
      SELECT form_data, form_version FROM {$wpdb->prefix}educator_booking_details
      WHERE booking_id = %d
    ", $booking_id));

    $form_data = [];
    if ($form_data_row) {
      $decoded = json_decode($form_data_row->form_data, true);
      if (is_array($decoded)) {
        $form_data = $decoded;
      }
    }

    // All booking meta rows (searchable fields + any extra custom meta)
    $meta_rows = $wpdb->get_results($wpdb->prepare("
      SELECT meta_key, meta_value FROM {$wpdb->prefix}educator_booking_meta
      WHERE booking_id = %d
      ORDER BY meta_key ASC
    ", $booking_id));

    // Merge meta into form_data for keys not already present (avoids duplicates)
    $booking_meta = [];
    foreach ($meta_rows as $row) {
      $decoded = json_decode($row->meta_value, true);
      $booking_meta[$row->meta_key] = ($decoded !== null) ? $decoded : $row->meta_value;
      if (!array_key_exists($row->meta_key, $form_data)) {
        $form_data[$row->meta_key] = $booking_meta[$row->meta_key];
      }
    }

    // Customer contact fields for pre-populating the edit booking form
    $customer_post_id = intval($booking->customer_post_id);
    $customer_contact = [
      'first_name'     => get_post_meta($customer_post_id, 'customer_first_name', true),
      'last_name'      => get_post_meta($customer_post_id, 'customer_last_name', true),
      'email'          => get_post_meta($customer_post_id, 'customer_email', true),
      'phone'          => get_post_meta($customer_post_id, 'customer_phone', true),
      'partner_name'   => get_post_meta($customer_post_id, 'partner_name', true),
      'street_address' => get_post_meta($customer_post_id, 'customer_address', true),
      'city'           => get_post_meta($customer_post_id, 'customer_suburb', true),
      'postcode'       => get_post_meta($customer_post_id, 'customer_postcode', true),
    ];

    // Build canonical current_values for the registry-driven edit modal
    $current_values = [];
    foreach (\HMWEvents\Config\BookingFields::for_edit_modal() as $key => $field) {
      if ($field['source'] === 'customer_meta') {
        $current_values[$key] = get_post_meta($customer_post_id, $field['meta_key'], true) ?: '';
      } else {
        // Primary: form_data; fallback to CPT meta for fields that also carry a meta_key
        $value = $form_data[$key] ?? '';
        if ($value === '' && !empty($field['meta_key'])) {
          $value = get_post_meta($customer_post_id, $field['meta_key'], true) ?: '';
        }
        $current_values[$key] = $value;
      }
    }

    wp_send_json_success([
      'booking'          => [
        'id'             => $booking->id,
        'booking_number' => $booking->booking_number,
        'booking_reference' => $booking->booking_reference,
        'course_name'    => $booking->course_name,
        'educator_name'  => $booking->educator_name ?? null,
        'status'         => $booking->status,
        'payment_status' => $booking->payment_status,
        'payment_type'   => $booking->group_payment_type,
        'booking_amount' => $booking->booking_amount,
        'currency'       => $booking->currency,
        'group_total'    => $booking->group_total_amount,
        'group_payment_status' => $booking->group_payment_status,
        'created_at'     => $booking->created_at,
        'cancelled_at'   => $booking->cancelled_at,
        'customer_post_id' => $customer_post_id,
      ],
      'form_data'        => $form_data,
      'booking_meta'     => $booking_meta,
      'customer_contact' => $customer_contact,
      'current_values'   => $current_values,
    ]);
  }

  /**
   * Add meta boxes to customer edit screen.
   *
   * @since 1.0.0
   */
  public function add_customer_meta_boxes()
  {
    add_meta_box(
      'customer_bookings',
      __('Bookings', 'hmw-events'),
      [$this, 'render_bookings_meta_box'],
      self::POST_TYPE,
      'normal',
      'high'
    );
  }

  /**
   * Render bookings meta box.
   *
   * @since 1.0.0
   * @param WP_Post $post Current post object.
   */
  public function render_bookings_meta_box($post)
  {
    global $wpdb;

    $current_user_id = get_current_user_id();

    // For admins, show all bookings; for educators, show only their bookings
    if (current_user_can('manage_options')) {
      $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.id,
                b.booking_number,
                b.booking_amount,
                b.currency,
                b.status,
                b.payment_status,
                b.created_at,
                c.ID as course_id,
                c.post_title as course_name,
                u.display_name as educator_name,
                bg.booking_reference,
                bg.payment_type,
                vu.voucher_code,
                vu.redeemed_value as voucher_amount,
                vu.voucher_type
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->users} u ON c.post_author = u.ID
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            LEFT JOIN {$wpdb->prefix}educator_voucher_usage vu ON b.id = vu.booking_id
            WHERE b.customer_post_id = %d
            AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC
        ", $post->ID));
    } else {
      $bookings = $wpdb->get_results($wpdb->prepare("
            SELECT 
                b.id,
                b.booking_number,
                b.booking_amount,
                b.currency,
                b.status,
                b.payment_status,
                b.created_at,
                c.ID as course_id,
                c.post_title as course_name,
                bg.booking_reference,
                bg.payment_type,
                vu.voucher_code,
                vu.redeemed_value as voucher_amount,
                vu.voucher_type
            FROM {$wpdb->prefix}educator_bookings b
            INNER JOIN {$wpdb->posts} c ON b.course_post_id = c.ID
            INNER JOIN {$wpdb->prefix}educator_booking_groups bg ON b.booking_group_id = bg.id
            LEFT JOIN {$wpdb->prefix}educator_voucher_usage vu ON b.id = vu.booking_id
            WHERE b.customer_post_id = %d
            AND c.post_author = %d
            AND b.deleted_at IS NULL
            ORDER BY b.created_at DESC
        ", $post->ID, $current_user_id));
    }

    // Debug: Check for database errors
    if ($wpdb->last_error) {
      echo '<div class="notice notice-error"><p><strong>Database Error:</strong> ' . esc_html($wpdb->last_error) . '</p></div>';
    }

    if (empty($bookings)) {
      echo '<p>' . __('No bookings found for this customer.', 'hmw-events') . '</p>';
      return;
    }

    $nonce = wp_create_nonce('hmwevents_booking_details_nonce');
?>
    <table class="widefat striped">
      <thead>
        <tr>
          <th><?php _e('Booking #', 'hmw-events'); ?></th>
          <th><?php _e('Course', 'hmw-events'); ?></th>
          <?php if (current_user_can('manage_options')): ?>
            <th><?php _e('Educator', 'hmw-events'); ?></th>
          <?php endif; ?>
          <th><?php _e('Type', 'hmw-events'); ?></th>
          <th><?php _e('Amount', 'hmw-events'); ?></th>
          <th><?php _e('Status', 'hmw-events'); ?></th>
          <th><?php _e('Payment', 'hmw-events'); ?></th>
          <th><?php _e('Date', 'hmw-events'); ?></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($bookings as $booking): ?>
          <tr>
            <td>
              <button type="button" class="button-link hmwevents-view-booking" data-booking-id="<?php echo esc_attr($booking->id); ?>" data-nonce="<?php echo esc_attr($nonce); ?>" style="color: #2271b1; text-decoration: underline; cursor: pointer; background: none; border: none; padding: 0; font-weight: 600;">
                <?php echo esc_html($booking->booking_number); ?>
              </button>
              <br>
              <small style="color: #666;"><?php echo esc_html($booking->booking_reference); ?></small>
            </td>
            <td>
              <a href="<?php echo get_edit_post_link($booking->course_id); ?>" target="_blank">
                <?php echo esc_html($booking->course_name); ?>
              </a>
            </td>
            <?php if (current_user_can('manage_options')): ?>
              <td><?php echo esc_html($booking->educator_name); ?></td>
            <?php endif; ?>
            <td>
              <span class="badge" style="background: #e3f2fd; color: #1976d2; padding: 3px 8px; border-radius: 3px; font-size: 11px;">
                <?php echo esc_html(ucfirst($booking->payment_type)); ?>
              </span>
            </td>
            <td>
              <?php 
              $currency = $booking->currency ?: 'AUD';
              $currency_symbol = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
              ?>
              <strong><?php echo esc_html($currency_symbol . number_format($booking->booking_amount, 2)); ?></strong>
              <?php if (!empty($booking->voucher_code)): ?>
                <br>
                <small style="color: #10b981; font-weight: 600;" title="<?php echo esc_attr(sprintf(__('Voucher: %s (-%s%s)', 'hmw-events'), $booking->voucher_code, $currency_symbol, number_format($booking->voucher_amount, 2))); ?>">
                  <span class="dashicons dashicons-tickets-alt" style="font-size: 14px; vertical-align: middle;"></span>
                  <?php echo esc_html($booking->voucher_code); ?> (-<?php echo esc_html($currency_symbol . number_format($booking->voucher_amount, 2)); ?>)
                </small>
              <?php endif; ?>
            </td>
            <td>
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
            <td>
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
            <td>
              <?php echo date('M j, Y', strtotime($booking->created_at)); ?>
              <br>
              <small style="color: #666;"><?php echo date('g:i a', strtotime($booking->created_at)); ?></small>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <style>
      .badge {
        display: inline-block;
        text-transform: uppercase;
        font-weight: 600;
        letter-spacing: 0.5px;
      }
    </style>

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
      var LABELS = {
        mothers_first_name:    "Mother's First Name",
        mothers_last_name:     "Mother's Last Name",
        partner_name:          "Partner Name",
        email:                 "Email",
        phone:                 "Phone",
        street_address:        "Street Address",
        city:                  "Suburb / City",
        postcode:              "Postcode",
        health_fund:           "Health Fund",
        due_date:              "Due Date",
        dietary_requirements:  "Dietary Requirements",
        mailing_agreement:     "Mailing Agreement",
        terms_accepted:        "Terms Accepted"
      };

      function formatLabel(key) {
        if (LABELS[key]) return LABELS[key];
        return key.replace(/_/g, ' ').replace(/\b\w/g, function(c){ return c.toUpperCase(); });
      }

      function formatValue(val) {
        if (val === null || val === undefined || val === '') return '<em style="color:#999">—</em>';
        if (typeof val === 'boolean' || val === 'true' || val === 'false') {
          return val === true || val === 'true' || val === '1' || val === 1 ? '✔ Yes' : '✘ No';
        }
        if (typeof val === 'object') return JSON.stringify(val, null, 2);
        return $('<div>').text(String(val)).html();
      }

      function buildModalContent(data) {
        var b = data.booking;
        var currency_symbol = b.currency === 'AUD' ? '$' : b.currency + ' ';
        var html = '';

        // Summary section
        html += '<table class="widefat fixed" style="margin-bottom:16px;">';
        html += '<thead><tr><th colspan="2" style="background:#f0f6ff; color:#2271b1;"><?php echo esc_js(__('Booking Summary', 'hmw-events')); ?></th></tr></thead><tbody>';

        var summary = [
          ['Course',          b.course_name],
          b.educator_name ? ['Educator', b.educator_name] : null,
          ['Reference',       b.booking_reference],
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

        // Form / booking details section
        var form_data = data.form_data;
        if (form_data && Object.keys(form_data).length > 0) {
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
  <?php
  }
}
