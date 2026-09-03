<?php

namespace HMWEvents\Services;

use HMWEvents\PostTypes\Coupon;

defined('ABSPATH') || die('Don\'t run this file directly!');

class CouponService
{
    public function validate_coupon($coupon_code, $course_id, $educator_id, $registrant_email = '', $payment_type = 'full', $amount = 0)
    {
        global $wpdb;

        if (empty($coupon_code)) {
            return new \WP_Error('empty_code', 'Coupon code is required');
        }

        $coupon_posts = get_posts([
            'post_type'   => Coupon::POST_TYPE,
            'meta_key'    => '_coupon_code',
            'meta_value'  => strtoupper($coupon_code),
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ]);

        if (empty($coupon_posts)) {
            return new \WP_Error('invalid_code', 'Invalid coupon code');
        }

        $coupon = $coupon_posts[0];
        $coupon_id = $coupon->ID;

        $is_active = get_post_meta($coupon_id, '_coupon_is_active', true);
        if ($is_active !== '1') {
            return new \WP_Error('inactive', 'This coupon is not active');
        }

        $is_staff = get_post_meta($coupon_id, '_coupon_is_staff', true) === '1';

        if (!$is_staff) {
            $start_date = get_post_meta($coupon_id, '_coupon_start_date', true);
            $end_date   = get_post_meta($coupon_id, '_coupon_end_date', true);
            $now = current_time('mysql');

            if ($start_date && $now < $start_date) {
                return new \WP_Error('not_started', 'This coupon is not yet valid');
            }

            if ($end_date && $now > $end_date) {
                return new \WP_Error('expired', 'This coupon has expired');
            }
        }

        if (!$is_staff) {
            $max_uses = (int) get_post_meta($coupon_id, '_coupon_max_uses', true);
            if ($max_uses > 0) {
                $usage_count = (int) $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM " . \HMWEvents\Services\DatabaseService::get_table_name('coupon_usage') . " WHERE coupon_code = %s",
                    strtoupper($coupon_code)
                ));

                if ($usage_count >= $max_uses) {
                    return new \WP_Error('usage_limit', 'This coupon has reached its usage limit');
                }
            }
        }

        if (!$is_staff && $registrant_email) {
            $max_per_user = (int) get_post_meta($coupon_id, '_coupon_max_per_user', true) ?: 1;
            $user_usage = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM " . \HMWEvents\Services\DatabaseService::get_table_name('coupon_usage') . " WHERE coupon_code = %s AND registrant_email = %s",
                strtoupper($coupon_code),
                $registrant_email
            ));

            if ($user_usage >= $max_per_user) {
                return new \WP_Error('user_limit', 'You have already used this coupon the maximum number of times');
            }
        }

        if (!$is_staff) {
            $min_amount = (float) get_post_meta($coupon_id, '_coupon_min_amount', true);
            if ($min_amount > 0 && $amount < $min_amount) {
                return new \WP_Error('min_amount', sprintf('Minimum booking amount of $%.2f required', $min_amount));
            }
        }

        if (!$is_staff) {
            $event_types = (array) get_post_meta($coupon_id, '_coupon_event_types', true);
            $event_types = array_filter(array_map('sanitize_key', $event_types));
            if (!empty($event_types)) {
                $course_types = wp_get_object_terms($course_id, 'hmw_event_type', ['fields' => 'slugs']);
                if (is_wp_error($course_types) || empty($course_types)) {
                    return new \WP_Error('course_restricted', 'This coupon is not valid for this event');
                }

                $match = !empty(array_intersect($course_types, $event_types));
                if (!$match) {
                    return new \WP_Error('course_restricted', 'This coupon is not valid for this event');
                }
            }
        }

        $discount_type  = get_post_meta($coupon_id, '_coupon_discount_type', true) ?: 'fixed';
        $discount_value = (float) get_post_meta($coupon_id, '_coupon_discount_value', true);

        return [
            'valid'          => true,
            'coupon_id'      => $coupon_id,
            'code'           => strtoupper($coupon_code),
            'discount_type'  => $discount_type,
            'discount_value' => $discount_value,
            'description'    => $coupon->post_title,
            'is_staff'       => $is_staff,
        ];
    }

    public function calculate_discount($original_amount, $coupon_data)
    {
        $discount_amount = 0;

        if ($coupon_data['discount_type'] === 'percent' || $coupon_data['discount_type'] === 'percentage') {
            $percentage = min(100, max(0, $coupon_data['discount_value']));
            $discount_amount = ($original_amount * $percentage) / 100;
        } else {
            $discount_amount = min($coupon_data['discount_value'], $original_amount);
        }

        $final_amount = max(0, $original_amount - $discount_amount);

        return [
            'original_amount'     => $original_amount,
            'discount_amount'     => $discount_amount,
            'final_amount'        => $final_amount,
            'discount_percentage' => $original_amount > 0 ? ($discount_amount / $original_amount) * 100 : 0,
        ];
    }

    public function record_usage($coupon_code, $booking_id, $registrant_email, $discount_amount, $original_amount)
    {
        global $wpdb;

        $coupon_posts = get_posts([
            'post_type'   => Coupon::POST_TYPE,
            'meta_key'    => '_coupon_code',
            'meta_value'  => strtoupper($coupon_code),
            'posts_per_page' => 1,
        ]);

        if (empty($coupon_posts)) {
            return false;
        }

        $result = $wpdb->insert(
            \HMWEvents\Services\DatabaseService::get_table_name('coupon_usage'),
            [
                'coupon_post_id'  => $coupon_posts[0]->ID,
                'coupon_code'     => strtoupper($coupon_code),
                'booking_id'      => $booking_id,
                'registrant_email' => $registrant_email,
                'discount_amount'  => $discount_amount,
                'original_amount'  => $original_amount,
            ],
            ['%d', '%s', '%d', '%s', '%f', '%f']
        );

        if ($result === false) {
            error_log('Failed to record coupon usage: ' . $wpdb->last_error);
            return false;
        }

        $usage_count = (int) get_post_meta($coupon_posts[0]->ID, '_coupon_usage_count', true);
        update_post_meta($coupon_posts[0]->ID, '_coupon_usage_count', $usage_count + 1);

        return true;
    }
}
