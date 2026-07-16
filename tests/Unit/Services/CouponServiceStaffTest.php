<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\CouponService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class CouponServiceStaffTest extends TestCase
{
    private array $default_meta;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $this->default_meta = [
            '_coupon_is_active'      => '1',
            '_coupon_start_date'     => '',
            '_coupon_end_date'       => '',
            '_coupon_max_uses'       => 0,
            '_coupon_min_amount'     => 0,
            '_coupon_event_types'    => [],
            '_coupon_is_staff'       => '0',
            '_coupon_discount_type'  => 'fixed',
            '_coupon_discount_value' => 10.00,
        ];

        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-07-16 00:00:00');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('error_log')->justReturn(true);

        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
            $flat = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    foreach ($arg as $v) {
                        $flat[] = $v;
                    }
                } else {
                    $flat[] = $arg;
                }
            }
            $result = $query;
            foreach ($flat as $v) {
                $pos = strpos($result, '%s');
                if ($pos !== false) {
                    $result = substr_replace($result, "'" . $v . "'", $pos, 2);
                } else {
                    $pos = strpos($result, '%d');
                    if ($pos !== false) {
                        $result = substr_replace($result, (string) (int) $v, $pos, 2);
                    }
                }
            }
            return $result;
        });
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(0);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function createService(array $meta_overrides = []): CouponService
    {
        $meta = array_merge($this->default_meta, $meta_overrides);

        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) use ($meta) {
            return $meta[$key] ?? '';
        });

        $coupon_post = (object) ['ID' => 10, 'post_title' => 'Test Coupon'];

        Functions\when('get_posts')->justReturn([$coupon_post]);

        return new CouponService();
    }

    public function test_staff_coupon_uses_coupon_discount_values(): void
    {
        $service = $this->createService(['_coupon_is_staff' => '1']);

        $result = $service->validate_coupon('STAFF', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('fixed', $result['discount_type']);
        $this->assertSame(10.0, $result['discount_value']);
        $this->assertTrue($result['is_staff']);
        $this->assertSame('STAFF', $result['code']);
    }

    public function test_staff_coupon_bypasses_date_validation(): void
    {
        $service = $this->createService([
            '_coupon_is_staff'   => '1',
            '_coupon_start_date' => '2099-12-31 00:00:00',
            '_coupon_end_date'   => '2020-01-01 00:00:00',
        ]);

        $result = $service->validate_coupon('STAFF', 1, 1);

        $this->assertIsArray($result, 'Staff coupon should bypass date validation and return success');
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['is_staff']);
    }

    public function test_staff_coupon_bypasses_usage_limit(): void
    {
        global $wpdb;

        $wpdb->shouldReceive('get_var')->andReturn(99);

        $service = $this->createService([
            '_coupon_is_staff' => '1',
            '_coupon_max_uses' => 5,
        ]);

        $result = $service->validate_coupon('STAFF', 1, 1);

        $this->assertIsArray($result, 'Staff coupon should bypass usage limit and return success');
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['is_staff']);
    }

    public function test_non_staff_coupon_does_not_get_staff_flag(): void
    {
        $service = $this->createService([
            '_coupon_is_staff'       => '0',
            '_coupon_discount_type'  => 'fixed',
            '_coupon_discount_value' => 20.00,
        ]);

        $result = $service->validate_coupon('REGULAR', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
        $this->assertFalse($result['is_staff']);
        $this->assertSame('fixed', $result['discount_type']);
        $this->assertSame(20.00, $result['discount_value']);
    }

    public function test_inactive_staff_coupon_returns_error(): void
    {
        $service = $this->createService([
            '_coupon_is_staff'   => '1',
            '_coupon_is_active'  => '0',
        ]);

        $result = $service->validate_coupon('STAFF', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('inactive', $result->get_error_code());
    }

    public function test_staff_coupon_within_valid_date_range_succeeds(): void
    {
        $service = $this->createService([
            '_coupon_is_staff'   => '1',
            '_coupon_start_date' => '2026-01-01 00:00:00',
            '_coupon_end_date'   => '2026-12-31 00:00:00',
        ]);

        $result = $service->validate_coupon('STAFF', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
        $this->assertTrue($result['is_staff']);
        $this->assertSame('fixed', $result['discount_type']);
        $this->assertSame(10.0, $result['discount_value']);
    }
}
