<?php

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Coupon validation and application service.
 */
class CouponService
{
    /**
     * Validate a coupon code.
     *
     * @param string $coupon_code Coupon code.
     * @param int $course_id Course ID.
     * @param int $educator_id Educator ID.
     * @param string $registrant_email Customer email.
     * @param string $payment_type 'full' or 'deposit'.
     * @param float $amount Booking amount before discount.
     * @return array|\WP_Error Coupon data or error.
     */
    public function validate_coupon($coupon_code, $course_id, $educator_id, $registrant_email = '', $payment_type = 'full', $amount = 0)
    {
        global $wpdb;
        
        if (empty($coupon_code)) {
            return new \WP_Error('empty_code', 'Coupon code is required');
        }

        // Find coupon by code
        $coupon_posts = get_posts([
            'post_type' => 'edu_coupon',
            'meta_key' => '_coupon_code',
            'meta_value' => strtoupper($coupon_code),
            'posts_per_page' => 1,
            'post_status' => 'publish',
        ]);

        if (empty($coupon_posts)) {
            return new \WP_Error('invalid_code', 'Invalid coupon code');
        }

        $coupon = $coupon_posts[0];
        $coupon_id = $coupon->ID;

        // Check status
        $status = get_post_meta($coupon_id, '_status', true) ?: 'active';
        if ($status !== 'active') {
            return new \WP_Error('inactive', 'This coupon is not active');
        }

        // Check dates
        $start_date = get_post_meta($coupon_id, '_start_date', true);
        $end_date = get_post_meta($coupon_id, '_end_date', true);
        $now = current_time('mysql');

        if ($start_date && $now < $start_date) {
            return new \WP_Error('not_started', 'This coupon is not yet valid');
        }

        if ($end_date && $now > $end_date) {
            return new \WP_Error('expired', 'This coupon has expired');
        }

        // Check total usage limit
        $usage_limit = get_post_meta($coupon_id, '_usage_limit', true);
        if ($usage_limit) {
            $usage_count = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}hmwevents_coupon_usage 
                WHERE coupon_code = %s
            ", strtoupper($coupon_code)));

            if ($usage_count >= $usage_limit) {
                return new \WP_Error('usage_limit', 'This coupon has reached its usage limit');
            }
        }

        // Check per-user usage limit
        if ($registrant_email) {
            $usage_limit_per_user = get_post_meta($coupon_id, '_usage_limit_per_user', true) ?: 1;
            $user_usage = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) 
                FROM {$wpdb->prefix}hmwevents_coupon_usage 
                WHERE coupon_code = %s AND registrant_email = %s
            ", strtoupper($coupon_code), $registrant_email));

            if ($user_usage >= $usage_limit_per_user) {
                return new \WP_Error('user_limit', 'You have already used this coupon the maximum number of times');
            }
        }

        // Check minimum amount
        $min_amount = get_post_meta($coupon_id, '_min_amount', true);
        if ($min_amount && $amount < $min_amount) {
            return new \WP_Error('min_amount', sprintf('Minimum booking amount of $%.2f required', $min_amount));
        }

        // Check payment type restriction
        $applies_to = get_post_meta($coupon_id, '_applies_to', true) ?: 'both';
        if ($applies_to !== 'both' && $applies_to !== $payment_type) {
            $type_label = $applies_to === 'full' ? 'full payments' : 'deposit payments';
            return new \WP_Error('payment_type', sprintf('This coupon only applies to %s', $type_label));
        }

        // Check educator restriction.
        // If no educators are explicitly set, fall back to the coupon's post author
        // when that author is not an administrator.
        $allowed_educators = get_post_meta($coupon_id, '_educator_ids', true);
        if (empty($allowed_educators)) {
            $coupon_author_id = (int) $coupon->post_author;
            if (!user_can($coupon_author_id, 'manage_options')) {
                $allowed_educators = [$coupon_author_id];
            }
        }

        if (!empty($allowed_educators) && !in_array($educator_id, (array)$allowed_educators)) {
            return new \WP_Error('educator_restricted', 'This coupon is not valid for this educator');
        }

        // Check course restriction.
        // If specific courses are selected, the course must be in that list.
        // If no courses are selected but the coupon is educator-scoped, the course
        // must belong to one of the allowed educators (i.e. the creator's courses only).
        $allowed_courses = get_post_meta($coupon_id, '_course_ids', true);
        if (!empty($allowed_courses)) {
            if (!in_array($course_id, (array)$allowed_courses)) {
                return new \WP_Error('course_restricted', 'This coupon is not valid for this course');
            }
        } elseif (!empty($allowed_educators)) {
            $course_author_id = (int) get_post_field('post_author', $course_id);
            if (!in_array($course_author_id, (array)$allowed_educators)) {
                return new \WP_Error('course_restricted', 'This coupon is not valid for this course');
            }
        }

        // Get discount details
        $discount_type = get_post_meta($coupon_id, '_discount_type', true) ?: 'percentage';
        $discount_value = get_post_meta($coupon_id, '_discount_value', true);

        return [
            'valid' => true,
            'coupon_id' => $coupon_id,
            'code' => strtoupper($coupon_code),
            'discount_type' => $discount_type,
            'discount_value' => floatval($discount_value),
            'description' => get_post_meta($coupon_id, '_description', true),
        ];
    }

    /**
     * Calculate discount amount.
     *
     * @param float $original_amount Original price.
     * @param array $coupon_data Coupon data from validate_coupon().
     * @return array Discount calculation.
     */
    public function calculate_discount($original_amount, $coupon_data)
    {
        $discount_amount = 0;

        if ($coupon_data['discount_type'] === 'percentage') {
            $percentage = min(100, max(0, $coupon_data['discount_value']));
            $discount_amount = ($original_amount * $percentage) / 100;
        } else {
            $discount_amount = min($coupon_data['discount_value'], $original_amount);
        }

        $final_amount = max(0, $original_amount - $discount_amount);

        return [
            'original_amount' => $original_amount,
            'discount_amount' => $discount_amount,
            'final_amount' => $final_amount,
            'discount_percentage' => $original_amount > 0 ? ($discount_amount / $original_amount) * 100 : 0,
        ];
    }

    /**
     * Record coupon usage.
     *
     * @param string $coupon_code Coupon code.
     * @param int $booking_id Booking ID.
     * @param string $registrant_email Customer email.
     * @param float $discount_amount Discount amount applied.
     * @param float $original_amount Original booking amount.
     * @return bool Success status.
     */
    public function record_usage($coupon_code, $booking_id, $registrant_email, $discount_amount, $original_amount)
    {
        global $wpdb;

        // Get coupon ID
        $coupon_posts = get_posts([
            'post_type' => 'edu_coupon',
            'meta_key' => '_coupon_code',
            'meta_value' => strtoupper($coupon_code),
            'posts_per_page' => 1,
        ]);

        if (empty($coupon_posts)) {
            return false;
        }

        $result = $wpdb->insert(
            $wpdb->prefix . 'educator_coupon_usage',
            [
                'coupon_id' => $coupon_posts[0]->ID,
                'coupon_code' => strtoupper($coupon_code),
                'booking_id' => $booking_id,
                'registrant_email' => $registrant_email,
                'discount_amount' => $discount_amount,
                'original_amount' => $original_amount,
            ],
            ['%d', '%s', '%d', '%s', '%f', '%f']
        );

        if ($result === false) {
            error_log('Failed to record coupon usage: ' . $wpdb->last_error);
            return false;
        }

        return true;
    }
}
