<?php

namespace HMWEvents\PostTypes;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Coupon Custom Post Type.
 * 
 * Manages discount coupons for courses with flexible restrictions.
 */
class Coupon
{
    public const POST_TYPE = 'edu_coupon';

    public function register()
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta'], 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'set_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_action('admin_notices', [$this, 'show_admin_notices']);
        add_action('pre_get_posts', [$this, 'filter_educator_coupons']);
        add_action('admin_menu', [$this, 'register_educator_menu'], 20);
        add_filter('views_edit-' . self::POST_TYPE, [$this, 'filter_views']);
    }

    /**
     * Register the coupon admin pages under the correct parent menu per role.
     *
     * With show_in_menu => false, WordPress's _add_post_type_submenus() skips
     * this CPT entirely, so we register it manually here.
     *
     * The WordPress access check in user_can_access_admin_page() cannot match
     * 'edit.php?post_type=edu_coupon' against $pagenow ('edit.php'), so it falls
     * back to checking the PARENT menu's capability. Admins must land under
     * hmwevents-main (manage_options), educators under hmwevents-educator-payments (edit_posts).
     */
    public function register_educator_menu()
    {
        if (current_user_can('manage_options')) {
            add_submenu_page(
                'hmwevents-main',
                __('Coupons', 'hmw-events'),
                __('Coupons', 'hmw-events'),
                'edit_posts',
                'edit.php?post_type=' . self::POST_TYPE
            );
            return;
        }

        if (!current_user_can('edit_posts')) {
            return;
        }

        add_submenu_page(
            'hmwevents-educator-payments',
            __('Coupons', 'hmw-events'),
            __('Coupons', 'hmw-events'),
            'edit_posts',
            'edit.php?post_type=' . self::POST_TYPE
        );
    }

    /**
     * Replace the "All / Published / Draft" counts with author-scoped counts
     * for non-admins, so the numbers match the filtered list.
     */
    public function filter_views(array $views): array
    {
        if (current_user_can('manage_options')) {
            return $views;
        }

        $user_id = get_current_user_id();

        // Count posts for this author only, grouped by status.
        $counts = (array) wp_count_posts(self::POST_TYPE, 'readable');

        // wp_count_posts returns totals for all users; we need per-author counts.
        $stati = get_post_stati(['show_in_admin_all_list' => true]);
        $author_counts = [];
        $total = 0;

        foreach ($stati as $status) {
            $n = (int) (new \WP_Query([
                'post_type'      => self::POST_TYPE,
                'post_status'    => $status,
                'author'         => $user_id,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => false,
            ]))->found_posts;
            $author_counts[$status] = $n;
            $total += $n;
        }

        // Rebuild each view link with the corrected count.
        $rebuilt = [];
        foreach ($views as $status => $link) {
            if ($status === 'all') {
                $count = $total;
            } elseif (isset($author_counts[$status])) {
                $count = $author_counts[$status];
            } else {
                continue; // Drop views for statuses that have no posts.
            }

            if ($count === 0) {
                continue;
            }

            // Replace the existing count badge (e.g. <span class="count">(12)</span>) with the new one.
            $rebuilt[$status] = preg_replace(
                '/(<span class=["\']count["\']>)\([\d,]+\)(<\/span>)/',
                '${1}(' . number_format_i18n($count) . ')${2}',
                $link
            );
        }

        return $rebuilt;
    }

    /**
     * Limit non-admin users to only see their own coupons in the list view.
     */
    public function filter_educator_coupons(\WP_Query $query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        if ($query->get('post_type') !== self::POST_TYPE) {
            return;
        }

        if (current_user_can('manage_options')) {
            return;
        }

        $query->set('author', get_current_user_id());
    }

    public function register_post_type()
    {
        $labels = [
            'name' => __('Coupons', 'hmw-events'),
            'singular_name' => __('Coupon', 'hmw-events'),
            'add_new' => __('Add New', 'hmw-events'),
            'add_new_item' => __('Add New Coupon', 'hmw-events'),
            'edit_item' => __('Edit Coupon', 'hmw-events'),
            'new_item' => __('New Coupon', 'hmw-events'),
            'view_item' => __('View Coupon', 'hmw-events'),
            'search_items' => __('Search Coupons', 'hmw-events'),
            'not_found' => __('No coupons found', 'hmw-events'),
        ];

        $args = [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'capability_type' => 'post',
            'capabilities' => [
                'create_posts' => 'edit_posts',
            ],
            'map_meta_cap' => true,
            'supports' => ['title'],
            'has_archive' => false,
            'rewrite' => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    public function add_meta_boxes()
    {
        add_meta_box(
            'coupon_details',
            __('Coupon Details', 'hmw-events'),
            [$this, 'render_details_metabox'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'coupon_restrictions',
            __('Restrictions', 'hmw-events'),
            [$this, 'render_restrictions_metabox'],
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'coupon_usage',
            __('Usage Statistics', 'hmw-events'),
            [$this, 'render_usage_metabox'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function render_details_metabox($post)
    {
        wp_nonce_field('coupon_details', 'coupon_details_nonce');
        
        $code = get_post_meta($post->ID, '_coupon_code', true);
        $discount_type = get_post_meta($post->ID, '_discount_type', true) ?: 'percentage';
        $discount_value = get_post_meta($post->ID, '_discount_value', true);
        $description = get_post_meta($post->ID, '_description', true);
        $start_date = get_post_meta($post->ID, '_start_date', true);
        $end_date = get_post_meta($post->ID, '_end_date', true);
        $status = get_post_meta($post->ID, '_status', true) ?: 'active';
        ?>
        <table class="form-table">
            <tr>
                <th><label for="coupon_code">Coupon Code <span class="required">*</span></label></th>
                <td>
                    <input type="text" 
                           id="coupon_code" 
                           name="coupon_code" 
                           value="<?php echo esc_attr($code); ?>" 
                           class="regular-text" 
                           style="text-transform: uppercase;"
                           required>
                    <p class="description">Unique code customers will enter (auto-converted to uppercase)</p>
                </td>
            </tr>
            <tr>
                <th><label for="description">Description</label></th>
                <td>
                    <input type="text" 
                           id="description" 
                           name="description" 
                           value="<?php echo esc_attr($description); ?>" 
                           class="large-text">
                    <p class="description">Internal description (not shown to customers)</p>
                </td>
            </tr>
            <tr>
                <th><label for="discount_type">Discount Type</label></th>
                <td>
                    <select id="discount_type" name="discount_type">
                        <option value="percentage" <?php selected($discount_type, 'percentage'); ?>>Percentage</option>
                        <option value="fixed" <?php selected($discount_type, 'fixed'); ?>>Fixed Amount</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="discount_value">Discount Value <span class="required">*</span></label></th>
                <td>
                    <input type="number" 
                           id="discount_value" 
                           name="discount_value" 
                           value="<?php echo esc_attr($discount_value); ?>" 
                           step="0.01" 
                           min="0"
                           required>
                    <p class="description">For percentage: enter 0-100. For fixed: enter dollar amount.</p>
                </td>
            </tr>
            <tr>
                <th><label for="start_date">Start Date</label></th>
                <td>
                    <input type="datetime-local" 
                           id="start_date" 
                           name="start_date" 
                           value="<?php echo esc_attr($start_date); ?>">
                    <p class="description">Leave empty for immediate activation</p>
                </td>
            </tr>
            <tr>
                <th><label for="end_date">End Date</label></th>
                <td>
                    <input type="datetime-local" 
                           id="end_date" 
                           name="end_date" 
                           value="<?php echo esc_attr($end_date); ?>">
                    <p class="description">Leave empty for no expiration</p>
                </td>
            </tr>
            <tr>
                <th><label for="status">Status</label></th>
                <td>
                    <select id="status" name="status">
                        <option value="active" <?php selected($status, 'active'); ?>>Active</option>
                        <option value="inactive" <?php selected($status, 'inactive'); ?>>Inactive</option>
                        <option value="expired" <?php selected($status, 'expired'); ?>>Expired</option>
                    </select>
                </td>
            </tr>
        </table>
        <?php
    }

    public function render_restrictions_metabox($post)
    {
        $usage_limit = get_post_meta($post->ID, '_usage_limit', true);
        $usage_limit_per_user = get_post_meta($post->ID, '_usage_limit_per_user', true) ?: 1;
        $min_amount = get_post_meta($post->ID, '_min_amount', true);
        $applies_to = get_post_meta($post->ID, '_applies_to', true) ?: 'both';
        $educator_ids = get_post_meta($post->ID, '_educator_ids', true) ?: [];
        $course_ids = get_post_meta($post->ID, '_course_ids', true) ?: [];
        
        $current_user_id = get_current_user_id();
        $is_admin = current_user_can('manage_options');
        ?>
        <table class="form-table">
            <tr>
                <th><label for="usage_limit">Total Usage Limit</label></th>
                <td>
                    <input type="number" 
                           id="usage_limit" 
                           name="usage_limit" 
                           value="<?php echo esc_attr($usage_limit); ?>" 
                           min="0">
                    <p class="description">Total number of times this coupon can be used (leave empty for unlimited)</p>
                </td>
            </tr>
            <tr>
                <th><label for="usage_limit_per_user">Uses Per Customer</label></th>
                <td>
                    <input type="number" 
                           id="usage_limit_per_user" 
                           name="usage_limit_per_user" 
                           value="<?php echo esc_attr($usage_limit_per_user); ?>" 
                           min="1">
                    <p class="description">How many times each customer (email) can use this coupon</p>
                </td>
            </tr>
            <tr>
                <th><label for="min_amount">Minimum Amount</label></th>
                <td>
                    <input type="number" 
                           id="min_amount" 
                           name="min_amount" 
                           value="<?php echo esc_attr($min_amount); ?>" 
                           step="0.01" 
                           min="0">
                    <p class="description">Minimum booking amount required to use this coupon</p>
                </td>
            </tr>
            <tr>
                <th><label for="applies_to">Applies To</label></th>
                <td>
                    <select id="applies_to" name="applies_to">
                        <option value="both" <?php selected($applies_to, 'both'); ?>>Full & Deposit Payments</option>
                        <option value="full" <?php selected($applies_to, 'full'); ?>>Full Payment Only</option>
                        <option value="deposit" <?php selected($applies_to, 'deposit'); ?>>Deposit Only</option>
                    </select>
                </td>
            </tr>
            
            <?php if ($is_admin): ?>
            <tr>
                <th><label for="educator_ids">Allowed Educators</label></th>
                <td>
                    <?php
                    $educators = get_users(['role__in' => ['administrator', 'educator']]);
                    echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
                    foreach ($educators as $educator):
                        $checked = in_array($educator->ID, (array)$educator_ids);
                    ?>
                    <label style="display: block; margin: 5px 0;">
                        <input type="checkbox" 
                               name="educator_ids[]" 
                               value="<?php echo esc_attr($educator->ID); ?>"
                               <?php checked($checked); ?>>
                        <?php echo esc_html($educator->display_name); ?>
                    </label>
                    <?php endforeach;
                    echo '</div>';
                    ?>
                    <p class="description">Leave all unchecked to allow for all educators</p>
                </td>
            </tr>
            <?php endif; ?>
            
            <tr>
                <th><label for="course_ids">Allowed Courses</label></th>
                <td>
                    <?php
                    $course_args = ['post_type' => 'educator_course', 'posts_per_page' => -1, 'post_status' => 'publish'];
                    if (!$is_admin) {
                        $course_args['author'] = $current_user_id;
                    }
                    $courses = get_posts($course_args);
                    
                    if (empty($courses)) {
                        echo '<p>No courses available.</p>';
                    } else {
                        echo '<div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">';
                        foreach ($courses as $course):
                            $checked = in_array($course->ID, (array)$course_ids);
                        ?>
                        <label style="display: block; margin: 5px 0;">
                            <input type="checkbox" 
                                   name="course_ids[]" 
                                   value="<?php echo esc_attr($course->ID); ?>"
                                   <?php checked($checked); ?>>
                            <?php echo esc_html($course->post_title); ?>
                            <?php if ($is_admin): ?>
                                <small>(<?php echo get_the_author_meta('display_name', $course->post_author); ?>)</small>
                            <?php endif; ?>
                        </label>
                        <?php endforeach;
                        echo '</div>';
                    }
                    ?>
                    <p class="description">Leave all unchecked to allow for all courses</p>
                </td>
            </tr>
        </table>
        <?php
    }

    public function render_usage_metabox($post)
    {
        global $wpdb;
        
        $code = get_post_meta($post->ID, '_coupon_code', true);
        if (!$code) {
            echo '<p>Save coupon to see usage statistics.</p>';
            return;
        }
        
        $usage_count = $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(*) 
            FROM {$wpdb->prefix}educator_coupon_usage 
            WHERE coupon_code = %s
        ", $code));
        
        $total_discount = $wpdb->get_var($wpdb->prepare("
            SELECT SUM(discount_amount) 
            FROM {$wpdb->prefix}educator_coupon_usage 
            WHERE coupon_code = %s
        ", $code));
        
        $usage_limit = get_post_meta($post->ID, '_usage_limit', true);
        
        ?>
        <div class="coupon-stats">
            <p><strong>Times Used:</strong> <?php echo (int)$usage_count; ?>
            <?php if ($usage_limit): ?>
                / <?php echo (int)$usage_limit; ?>
            <?php endif; ?>
            </p>
            <p><strong>Total Discount Given:</strong> $<?php echo number_format((float)$total_discount, 2); ?></p>
        </div>
        <?php
    }

    public function save_meta($post_id, $post)
    {
        if (!isset($_POST['coupon_details_nonce']) || !wp_verify_nonce($_POST['coupon_details_nonce'], 'coupon_details')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Non-admins can only edit their own coupons
        if (!current_user_can('manage_options')) {
            $post_author = (int) get_post_field('post_author', $post_id);
            if ($post_author !== get_current_user_id()) {
                return;
            }
        }

        // Save coupon code (uppercase)
        if (isset($_POST['coupon_code'])) {
            $code = strtoupper(sanitize_text_field($_POST['coupon_code']));
            
            // Check for duplicate codes
            $existing = get_posts([
                'post_type' => self::POST_TYPE,
                'meta_key' => '_coupon_code',
                'meta_value' => $code,
                'post__not_in' => [$post_id],
                'posts_per_page' => 1,
            ]);
            
            if (!empty($existing)) {
                add_filter('redirect_post_location', function($location) {
                    return add_query_arg('coupon_error', 'duplicate_code', $location);
                });
                return;
            }
            
            update_post_meta($post_id, '_coupon_code', $code);
        }

        // Save other fields
        $fields = [
            'description' => 'sanitize_text_field',
            'discount_type' => 'sanitize_text_field',
            'discount_value' => 'floatval',
            'start_date' => 'sanitize_text_field',
            'end_date' => 'sanitize_text_field',
            'status' => 'sanitize_text_field',
            'usage_limit' => 'intval',
            'usage_limit_per_user' => 'intval',
            'min_amount' => 'floatval',
            'applies_to' => 'sanitize_text_field',
        ];

        foreach ($fields as $field => $sanitize) {
            if (isset($_POST[$field])) {
                $value = $_POST[$field];
                if ($value !== '') {
                    update_post_meta($post_id, '_' . $field, $sanitize($value));
                } else {
                    delete_post_meta($post_id, '_' . $field);
                }
            }
        }

        // Save arrays
        if (!current_user_can('manage_options')) {
            // Non-admins: always scope this coupon to themselves.
            update_post_meta($post_id, '_educator_ids', [get_current_user_id()]);
        } elseif (isset($_POST['educator_ids'])) {
            update_post_meta($post_id, '_educator_ids', array_map('intval', $_POST['educator_ids']));
        } else {
            delete_post_meta($post_id, '_educator_ids');
        }

        if (isset($_POST['course_ids'])) {
            $submitted_course_ids = array_map('intval', $_POST['course_ids']);

            if (!current_user_can('manage_options')) {
                // Non-admins: strip any course IDs that don't belong to them.
                $current_user_id = get_current_user_id();
                $submitted_course_ids = array_values(array_filter(
                    $submitted_course_ids,
                    function ($cid) use ($current_user_id) {
                        return (int) get_post_field('post_author', $cid) === $current_user_id;
                    }
                ));
            }

            update_post_meta($post_id, '_course_ids', $submitted_course_ids);
        } else {
            delete_post_meta($post_id, '_course_ids');
        }
    }

    public function set_columns($columns)
    {
        return [
            'cb' => $columns['cb'],
            'title' => __('Coupon Name', 'hmw-events'),
            'code' => __('Code', 'hmw-events'),
            'discount' => __('Discount', 'hmw-events'),
            'usage' => __('Usage', 'hmw-events'),
            'validity' => __('Validity', 'hmw-events'),
            'status' => __('Status', 'hmw-events'),
            'date' => __('Created', 'hmw-events'),
        ];
    }

    public function render_column($column, $post_id)
    {
        switch ($column) {
            case 'code':
                $code = get_post_meta($post_id, '_coupon_code', true);
                echo '<code>' . esc_html($code) . '</code>';
                break;
                
            case 'discount':
                $type = get_post_meta($post_id, '_discount_type', true);
                $value = get_post_meta($post_id, '_discount_value', true);
                if ($type === 'percentage') {
                    echo esc_html($value) . '%';
                } else {
                    echo '$' . number_format($value, 2);
                }
                break;
                
            case 'usage':
                global $wpdb;
                $code = get_post_meta($post_id, '_coupon_code', true);
                $used = $wpdb->get_var($wpdb->prepare("
                    SELECT COUNT(*) FROM {$wpdb->prefix}educator_coupon_usage WHERE coupon_code = %s
                ", $code));
                $limit = get_post_meta($post_id, '_usage_limit', true);
                
                echo (int)$used;
                if ($limit) {
                    echo ' / ' . (int)$limit;
                }
                break;
                
            case 'validity':
                $start = get_post_meta($post_id, '_start_date', true);
                $end = get_post_meta($post_id, '_end_date', true);
                
                if ($start) {
                    echo 'From ' . date('M j, Y', strtotime($start)) . '<br>';
                }
                if ($end) {
                    echo 'Until ' . date('M j, Y', strtotime($end));
                }
                if (!$start && !$end) {
                    echo 'Always valid';
                }
                break;
                
            case 'status':
                $status = get_post_meta($post_id, '_status', true) ?: 'active';
                $colors = [
                    'active' => 'green',
                    'inactive' => 'gray',
                    'expired' => 'red',
                ];
                echo '<span style="color: ' . $colors[$status] . ';">●</span> ' . ucfirst($status);
                break;
        }
    }

    public function show_admin_notices()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== self::POST_TYPE) {
            return;
        }

        if (isset($_GET['coupon_error']) && $_GET['coupon_error'] === 'duplicate_code') {
            ?>
            <div class="notice notice-error is-dismissible">
                <p><?php _e('Error: A coupon with this code already exists. Please use a different code.', 'hmw-events'); ?></p>
            </div>
            <?php
        }
    }
}
