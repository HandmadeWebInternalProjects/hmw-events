<?php

namespace HMWEvents\Tests\Unit\PostTypes;

use HMWEvents\PostTypes\Coupon;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class CouponStaffMetaTest extends TestCase
{
    private static array $update_post_meta_calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        self::$update_post_meta_calls = [];

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html_e')->justReturn('');
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('error_log')->justReturn(true);

        Functions\when('update_post_meta')->alias(function ($post_id, $key, $value) {
            self::$update_post_meta_calls[] = [$post_id, $key, $value];
            return true;
        });

        $_POST = [];
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_staff_checkbox_is_saved(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $_POST['hmw_coupon_nonce']         = 'test-nonce';
        $_POST['hmw_coupon_code']          = 'STAFF50';
        $_POST['hmw_coupon_discount_type']  = 'percent';
        $_POST['hmw_coupon_discount_value'] = '50';
        $_POST['hmw_coupon_start_date']     = '';
        $_POST['hmw_coupon_end_date']       = '';
        $_POST['hmw_coupon_is_active']      = '1';
        $_POST['hmw_coupon_is_staff']       = '1';
        $_POST['hmw_coupon_min_amount']     = '0';
        $_POST['hmw_coupon_max_uses']       = '0';
        $_POST['hmw_coupon_max_per_user']   = '0';
        $_POST['hmw_coupon_event_types']    = [];

        $post = new \WP_Post((object) ['ID' => 1, 'post_type' => Coupon::POST_TYPE]);

        $coupon = new Coupon();
        $coupon->save_meta(1, $post);

        $staff_calls = array_filter(self::$update_post_meta_calls, function ($call) {
            return $call[1] === '_coupon_is_staff';
        });
        $staff_calls = array_values($staff_calls);

        $this->assertCount(1, $staff_calls, '_coupon_is_staff should be saved exactly once');
        $this->assertSame(1, $staff_calls[0][0], 'post_id should be 1');
        $this->assertSame('_coupon_is_staff', $staff_calls[0][1]);
        $this->assertSame('1', $staff_calls[0][2]);
    }

    public function test_staff_checkbox_unchecked_saves_zero(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $_POST['hmw_coupon_nonce']         = 'test-nonce';
        $_POST['hmw_coupon_code']          = 'REGULAR';
        $_POST['hmw_coupon_discount_type']  = 'fixed';
        $_POST['hmw_coupon_discount_value'] = '10';
        $_POST['hmw_coupon_start_date']     = '';
        $_POST['hmw_coupon_end_date']       = '';
        $_POST['hmw_coupon_is_active']      = '1';
        // hmw_coupon_is_staff NOT set (unchecked checkbox)
        $_POST['hmw_coupon_min_amount']     = '0';
        $_POST['hmw_coupon_max_uses']       = '0';
        $_POST['hmw_coupon_max_per_user']   = '0';
        $_POST['hmw_coupon_event_types']    = [];

        $post = new \WP_Post((object) ['ID' => 2, 'post_type' => Coupon::POST_TYPE]);

        $coupon = new Coupon();
        $coupon->save_meta(2, $post);

        $staff_calls = array_filter(self::$update_post_meta_calls, function ($call) {
            return $call[1] === '_coupon_is_staff';
        });
        $staff_calls = array_values($staff_calls);

        $this->assertCount(1, $staff_calls);
        $this->assertSame('0', $staff_calls[0][2], 'Unchecked staff checkbox should save "0"');
    }

    public function test_save_meta_bails_without_nonce(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(false);

        $_POST = [];

        $post = new \WP_Post((object) ['ID' => 3, 'post_type' => Coupon::POST_TYPE]);

        $coupon = new Coupon();
        $coupon->save_meta(3, $post);

        $this->assertEmpty(self::$update_post_meta_calls, 'No meta should be saved when nonce fails');
    }

    public function test_all_expected_fields_are_saved(): void
    {
        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $_POST['hmw_coupon_nonce']         = 'test-nonce';
        $_POST['hmw_coupon_code']          = 'CODE123';
        $_POST['hmw_coupon_discount_type']  = 'fixed';
        $_POST['hmw_coupon_discount_value'] = '25';
        $_POST['hmw_coupon_start_date']     = '2026-01-01';
        $_POST['hmw_coupon_end_date']       = '2026-12-31';
        $_POST['hmw_coupon_is_active']      = '1';
        $_POST['hmw_coupon_is_staff']       = '1';
        $_POST['hmw_coupon_min_amount']     = '50';
        $_POST['hmw_coupon_max_uses']       = '100';
        $_POST['hmw_coupon_max_per_user']   = '1';
        $_POST['hmw_coupon_event_types']    = ['workshop'];

        $expected_keys = [
            '_coupon_code',
            '_coupon_discount_type',
            '_coupon_discount_value',
            '_coupon_start_date',
            '_coupon_end_date',
            '_coupon_is_active',
            '_coupon_is_staff',
            '_coupon_min_amount',
            '_coupon_max_uses',
            '_coupon_max_per_user',
            '_coupon_event_types',
        ];

        $post = new \WP_Post((object) ['ID' => 5, 'post_type' => Coupon::POST_TYPE]);

        $coupon = new Coupon();
        $coupon->save_meta(5, $post);

        $saved_keys = array_column(self::$update_post_meta_calls, 1);

        foreach ($expected_keys as $key) {
            $this->assertContains($key, $saved_keys, "Expected meta key '$key' to be saved");
        }
    }
}
