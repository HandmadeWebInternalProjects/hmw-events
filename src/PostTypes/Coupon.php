<?php

/**
 * Coupon Custom Post Type (Rebuild).
 *
 * Manages discount coupons for events. Replaces the legacy edu_coupon CPT.
 *
 * @package HMWEvents\PostTypes
 * @since 2.0.0
 */

namespace HMWEvents\PostTypes;

defined('ABSPATH') || die('Don\'t run this file directly!');

class Coupon
{
    public const POST_TYPE = 'hmw_coupon';

    public function register(): void
    {
        add_action('init', [$this, 'register_post_type']);
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'save_meta'], 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', [$this, 'set_columns']);
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_action('admin_menu', [$this, 'register_menu'], 20);
    }

    public function register_post_type(): void
    {
        $labels = [
            'name'               => _x('Coupons', 'Post type general name', 'hmw-events'),
            'singular_name'      => _x('Coupon', 'Post type singular name', 'hmw-events'),
            'menu_name'          => _x('Coupons', 'Admin Menu text', 'hmw-events'),
            'add_new'            => __('Add New', 'hmw-events'),
            'add_new_item'       => __('Add New Coupon', 'hmw-events'),
            'edit_item'          => __('Edit Coupon', 'hmw-events'),
            'view_item'          => __('View Coupon', 'hmw-events'),
            'all_items'          => __('All Coupons', 'hmw-events'),
            'search_items'       => __('Search Coupons', 'hmw-events'),
            'not_found'          => __('No coupons found.', 'hmw-events'),
            'not_found_in_trash' => __('No coupons found in Trash.', 'hmw-events'),
        ];

        $args = [
            'labels'              => $labels,
            'public'              => false,
            'publicly_queryable'  => false,
            'show_ui'             => true,
            'show_in_menu'        => false,
            'show_in_rest'        => true,
            'query_var'           => false,
            'capability_type'     => ['hmw_coupon', 'hmw_coupons'],
            'map_meta_cap'        => true,
            'has_archive'         => false,
            'hierarchical'        => false,
            'supports'            => ['title'],
            'delete_with_user'    => false,
        ];

        register_post_type(self::POST_TYPE, $args);
    }

    /**
     * Register the coupon admin page under the correct parent menu for each role.
     */
    public function register_menu(): void
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
            'hmwevents-organizer-payments',
            __('Coupons', 'hmw-events'),
            __('Coupons', 'hmw-events'),
            'edit_posts',
            'edit.php?post_type=' . self::POST_TYPE
        );
    }

    /**
     * Register meta boxes for coupon details.
     */
    public function add_meta_boxes(): void
    {
        add_meta_box(
            'hmw_coupon_details',
            __('Coupon Details', 'hmw-events'),
            [$this, 'render_details_meta_box'],
            self::POST_TYPE,
            'normal',
            'high'
        );

        add_meta_box(
            'hmw_coupon_restrictions',
            __('Usage Restrictions', 'hmw-events'),
            [$this, 'render_restrictions_meta_box'],
            self::POST_TYPE,
            'normal',
            'default'
        );

        add_meta_box(
            'hmw_coupon_usage',
            __('Usage', 'hmw-events'),
            [$this, 'render_usage_meta_box'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Render the coupon details meta box.
     */
    public function render_details_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('hmw_coupon_save', 'hmw_coupon_nonce');

        $code           = get_post_meta($post->ID, '_coupon_code', true) ?? '';
        $discount_type  = get_post_meta($post->ID, '_coupon_discount_type', true) ?: 'fixed';
        $discount_value = get_post_meta($post->ID, '_coupon_discount_value', true) ?: '';
        $start_date     = get_post_meta($post->ID, '_coupon_start_date', true) ?: '';
        $end_date       = get_post_meta($post->ID, '_coupon_end_date', true) ?: '';
        $is_active      = get_post_meta($post->ID, '_coupon_is_active', true);
        $is_active      = ($is_active === '' || $is_active === '1') ? '1' : '0';

        ?>
        <table class="form-table">
            <tr>
                <th><label for="hmw_coupon_code"><?php esc_html_e('Coupon Code', 'hmw-events'); ?></label></th>
                <td><input type="text" id="hmw_coupon_code" name="hmw_coupon_code" value="<?php echo esc_attr($code); ?>" class="regular-text" required /></td>
            </tr>
            <tr>
                <th><label for="hmw_coupon_discount_type"><?php esc_html_e('Discount Type', 'hmw-events'); ?></label></th>
                <td>
                    <select id="hmw_coupon_discount_type" name="hmw_coupon_discount_type">
                        <option value="fixed" <?php selected($discount_type, 'fixed'); ?>><?php esc_html_e('Fixed Amount ($)', 'hmw-events'); ?></option>
                        <option value="percent" <?php selected($discount_type, 'percent'); ?>><?php esc_html_e('Percentage (%)', 'hmw-events'); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="hmw_coupon_discount_value"><?php esc_html_e('Discount Value', 'hmw-events'); ?></label></th>
                <td><input type="number" id="hmw_coupon_discount_value" name="hmw_coupon_discount_value" value="<?php echo esc_attr($discount_value); ?>" step="0.01" min="0" class="small-text" /></td>
            </tr>
            <tr>
                <th><label for="hmw_coupon_start_date"><?php esc_html_e('Start Date', 'hmw-events'); ?></label></th>
                <td><input type="date" id="hmw_coupon_start_date" name="hmw_coupon_start_date" value="<?php echo esc_attr($start_date); ?>" /></td>
            </tr>
            <tr>
                <th><label for="hmw_coupon_end_date"><?php esc_html_e('End Date', 'hmw-events'); ?></label></th>
                <td><input type="date" id="hmw_coupon_end_date" name="hmw_coupon_end_date" value="<?php echo esc_attr($end_date); ?>" /></td>
            </tr>
            <tr>
                <th><label for="hmw_coupon_is_active"><?php esc_html_e('Active', 'hmw-events'); ?></label></th>
                <td><input type="checkbox" id="hmw_coupon_is_active" name="hmw_coupon_is_active" value="1" <?php checked($is_active, '1'); ?> /></td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render usage restrictions meta box.
     */
    public function render_restrictions_meta_box(\WP_Post $post): void
    {
        $min_amount    = get_post_meta($post->ID, '_coupon_min_amount', true) ?: '';
        $max_uses      = get_post_meta($post->ID, '_coupon_max_uses', true) ?: '';
        $max_per_user  = get_post_meta($post->ID, '_coupon_max_per_user', true) ?: '';
        $event_types   = get_post_meta($post->ID, '_coupon_event_types', true) ?: [];
        $specific_events = get_post_meta($post->ID, '_coupon_specific_events', true) ?: [];

        ?>
        <table class="form-table">
            <tr>
                <th><label for="hmw_coupon_min_amount"><?php esc_html_e('Minimum Order Amount ($)', 'hmw-events'); ?></label></th>
                <td><input type="number" id="hmw_coupon_min_amount" name="hmw_coupon_min_amount" value="<?php echo esc_attr($min_amount); ?>" step="0.01" min="0" class="small-text" /></td>
            </tr>
            <tr>
                <th><label for="hmw_coupon_max_uses"><?php esc_html_e('Maximum Total Uses', 'hmw-events'); ?></label></th>
                <td><input type="number" id="hmw_coupon_max_uses" name="hmw_coupon_max_uses" value="<?php echo esc_attr($max_uses); ?>" min="0" class="small-text" /> <span class="description"><?php esc_html_e('0 = unlimited', 'hmw-events'); ?></span></td>
            </tr>
            <tr>
                <th><label for="hmw_coupon_max_per_user"><?php esc_html_e('Maximum Per Registrant', 'hmw-events'); ?></label></th>
                <td><input type="number" id="hmw_coupon_max_per_user" name="hmw_coupon_max_per_user" value="<?php echo esc_attr($max_per_user); ?>" min="0" class="small-text" /> <span class="description"><?php esc_html_e('0 = unlimited', 'hmw-events'); ?></span></td>
            </tr>
            <tr>
                <th><label for="hmw_coupon_event_types"><?php esc_html_e('Restrict to Event Types', 'hmw-events'); ?></label></th>
                <td>
                    <?php
                    $types = get_terms(['taxonomy' => 'hmw_event_type', 'hide_empty' => false]);
                    if (!empty($types) && !is_wp_error($types)):
                        foreach ($types as $type): ?>
                            <label style="display:block; margin-bottom:4px;">
                                <input type="checkbox" name="hmw_coupon_event_types[]" value="<?php echo esc_attr($type->slug); ?>" <?php checked(in_array($type->slug, (array) $event_types)); ?> />
                                <?php echo esc_html($type->name); ?>
                            </label>
                        <?php endforeach;
                    else:
                        esc_html_e('No event types found.', 'hmw-events');
                    endif;
                    ?>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render usage stats meta box.
     */
    public function render_usage_meta_box(\WP_Post $post): void
    {
        global $wpdb;
        $usage = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}hmwevents_coupon_usage WHERE coupon_code = %s",
            get_post_meta($post->ID, '_coupon_code', true)
        ));
        ?>
        <p>
            <strong><?php esc_html_e('Times Used:', 'hmw-events'); ?></strong>
            <?php echo (int) $usage; ?>
        </p>
        <?php
    }

    /**
     * Save coupon meta.
     */
    public function save_meta(int $post_id, \WP_Post $post): void
    {
        if (!isset($_POST['hmw_coupon_nonce']) || !wp_verify_nonce($_POST['hmw_coupon_nonce'], 'hmw_coupon_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $fields = [
            '_coupon_code'          => sanitize_text_field($_POST['hmw_coupon_code'] ?? ''),
            '_coupon_discount_type' => sanitize_text_field($_POST['hmw_coupon_discount_type'] ?? 'fixed'),
            '_coupon_discount_value' => (float) ($_POST['hmw_coupon_discount_value'] ?? 0),
            '_coupon_start_date'    => sanitize_text_field($_POST['hmw_coupon_start_date'] ?? ''),
            '_coupon_end_date'      => sanitize_text_field($_POST['hmw_coupon_end_date'] ?? ''),
            '_coupon_is_active'     => isset($_POST['hmw_coupon_is_active']) ? '1' : '0',
            '_coupon_min_amount'    => (float) ($_POST['hmw_coupon_min_amount'] ?? 0),
            '_coupon_max_uses'      => (int) ($_POST['hmw_coupon_max_uses'] ?? 0),
            '_coupon_max_per_user'  => (int) ($_POST['hmw_coupon_max_per_user'] ?? 0),
            '_coupon_event_types'   => array_map('sanitize_text_field', (array) ($_POST['hmw_coupon_event_types'] ?? [])),
        ];

        foreach ($fields as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
    }

    /**
     * Set custom admin columns.
     */
    public function set_columns(array $columns): array
    {
        $new = [];
        foreach ($columns as $k => $v) {
            $new[$k] = $v;
            if ($k === 'title') {
                $new['coupon_code'] = __('Code', 'hmw-events');
                $new['discount']    = __('Discount', 'hmw-events');
            }
        }
        $new['usage']   = __('Used', 'hmw-events');
        $new['active']  = __('Active', 'hmw-events');
        unset($new['date']);
        return $new;
    }

    /**
     * Render custom column content.
     */
    public function render_column(string $column, int $post_id): void
    {
        switch ($column) {
            case 'coupon_code':
                echo esc_html(get_post_meta($post_id, '_coupon_code', true));
                break;
            case 'discount':
                $type  = get_post_meta($post_id, '_coupon_discount_type', true);
                $value = get_post_meta($post_id, '_coupon_discount_value', true);
                if ($type === 'percent') {
                    echo esc_html($value . '%');
                } else {
                    echo '$' . esc_html(number_format((float) $value, 2));
                }
                break;
            case 'usage':
                echo (int) get_post_meta($post_id, '_coupon_usage_count', true);
                break;
            case 'active':
                echo get_post_meta($post_id, '_coupon_is_active', true) === '1'
                    ? '<span style="color:green;">' . esc_html__('Yes', 'hmw-events') . '</span>'
                    : '<span style="color:red;">' . esc_html__('No', 'hmw-events') . '</span>';
                break;
        }
    }
}
