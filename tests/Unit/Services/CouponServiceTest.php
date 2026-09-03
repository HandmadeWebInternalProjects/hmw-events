<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\CouponService;
use HMWEvents\PostTypes\Coupon;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class CouponServiceTest extends TestCase
{
    private array $default_meta;
    private \Mockery\MockInterface $wpdb;

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
            '_coupon_max_per_user'   => 0,
            '_coupon_min_amount'     => 0,
            '_coupon_event_types'    => [],
            '_coupon_is_staff'       => '0',
            '_coupon_discount_type'  => 'fixed',
            '_coupon_discount_value' => 10.00,
            '_coupon_usage_count'    => 0,
        ];

        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-08-10 00:00:00');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('error_log')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);

        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });

        $this->wpdb = Mockery::mock();
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
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
                    continue;
                }
                $pos = strpos($result, '%d');
                if ($pos !== false) {
                    $result = substr_replace($result, (string) (int) $v, $pos, 2);
                    continue;
                }
                $pos = strpos($result, '%f');
                if ($pos !== false) {
                    $result = substr_replace($result, (string) (float) $v, $pos, 2);
                }
            }
            return $result;
        });
        $this->wpdb->shouldReceive('get_var')->andReturn(0)->byDefault();
        $this->wpdb->insert_id = 10;
        $this->wpdb->last_error = '';
        $GLOBALS['wpdb'] = $this->wpdb;
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

        $coupon_post = (object) ['ID' => 99, 'post_title' => 'SUMMER2026'];

        Functions\when('get_posts')->justReturn([$coupon_post]);

        return new CouponService();
    }

    // ============================================================
    // Basic validation: empty/invalid code
    // ============================================================

    public function test_validate_empty_code_returns_error(): void
    {
        $service = $this->createService();
        $result = $service->validate_coupon('', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('empty_code', $result->get_error_code());
    }

    public function test_validate_invalid_code_returns_error(): void
    {
        Functions\when('get_posts')->justReturn([]);

        $service = new CouponService();
        $result = $service->validate_coupon('NONEXISTENT', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_code', $result->get_error_code());
    }

    // ============================================================
    // Active/inactive
    // ============================================================

    public function test_validate_inactive_coupon_returns_error(): void
    {
        $service = $this->createService(['_coupon_is_active' => '0']);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('inactive', $result->get_error_code());
    }

    public function test_validate_active_coupon_succeeds(): void
    {
        $service = $this->createService(['_coupon_is_active' => '1']);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    // ============================================================
    // Date range: expired / not started
    // ============================================================

    public function test_validate_expired_coupon_returns_error(): void
    {
        $service = $this->createService([
            '_coupon_end_date' => '2026-08-09 00:00:00',
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('expired', $result->get_error_code());
    }

    public function test_validate_not_started_coupon_returns_error(): void
    {
        $service = $this->createService([
            '_coupon_start_date' => '2026-12-31 00:00:00',
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('not_started', $result->get_error_code());
    }

    public function test_validate_coupon_within_date_range_succeeds(): void
    {
        $service = $this->createService([
            '_coupon_start_date' => '2026-01-01 00:00:00',
            '_coupon_end_date'   => '2026-12-31 00:00:00',
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function test_validate_coupon_no_dates_succeeds(): void
    {
        $service = $this->createService([
            '_coupon_start_date' => '',
            '_coupon_end_date'   => '',
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function test_validate_coupon_end_boundary_inclusive(): void
    {
        $service = $this->createService([
            '_coupon_end_date' => '2026-08-10 00:00:00',
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result, 'Same-date end should pass when $now is before end');
        $this->assertTrue($result['valid']);
    }

    // ============================================================
    // Max total uses
    // ============================================================

    public function test_validate_max_uses_exceeded_returns_error(): void
    {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(10);

        $service = $this->createService([
            '_coupon_max_uses' => 10,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('usage_limit', $result->get_error_code());
    }

    public function test_validate_max_uses_not_exceeded_succeeds(): void
    {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(5);

        $service = $this->createService([
            '_coupon_max_uses' => 10,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function test_validate_max_uses_zero_means_unlimited(): void
    {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(999);

        $service = $this->createService([
            '_coupon_max_uses' => 0,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result, 'Zero max_uses should mean unlimited usage');
        $this->assertTrue($result['valid']);
    }

    // ============================================================
    // Max per user
    // ============================================================

    public function test_validate_per_user_limit_exceeded_returns_error(): void
    {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(1);

        $service = $this->createService([
            '_coupon_max_per_user' => 1,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1, 'user@example.com');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('user_limit', $result->get_error_code());
    }

    public function test_validate_per_user_limit_not_exceeded_succeeds(): void
    {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(0);

        $service = $this->createService([
            '_coupon_max_per_user' => 1,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1, 'user@example.com');

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function test_validate_per_user_limit_defaults_to_one(): void
    {
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(1);

        $service = $this->createService([
            '_coupon_max_per_user' => 0,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1, 'user@example.com');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('user_limit', $result->get_error_code());
    }

    public function test_validate_per_user_skips_when_no_email(): void
    {
        $service = $this->createService([
            '_coupon_max_per_user' => 0,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1, '');

        $this->assertIsArray($result, 'Per-user check should be skipped when no email provided');
        $this->assertTrue($result['valid']);
    }

    // ============================================================
    // Min amount
    // ============================================================

    public function test_validate_min_amount_not_met_returns_error(): void
    {
        $service = $this->createService([
            '_coupon_min_amount' => 50.00,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1, '', 'full', 25.00);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('min_amount', $result->get_error_code());
    }

    public function test_validate_min_amount_met_succeeds(): void
    {
        $service = $this->createService([
            '_coupon_min_amount' => 50.00,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1, '', 'full', 75.00);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function test_validate_min_amount_equality_succeeds(): void
    {
        $service = $this->createService([
            '_coupon_min_amount' => 50.00,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1, '', 'full', 50.00);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function test_validate_min_amount_zero_no_restriction(): void
    {
        $service = $this->createService([
            '_coupon_min_amount' => 0,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1, '', 'full', 0);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    // ============================================================
    // Event type restrictions
    // ============================================================

    public function test_validate_event_type_restriction_matches(): void
    {
        Functions\when('wp_get_object_terms')->justReturn(['workshop', 'webinar']);

        $service = $this->createService([
            '_coupon_event_types' => ['workshop'],
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
    }

    public function test_validate_event_type_restriction_no_match_returns_error(): void
    {
        Functions\when('wp_get_object_terms')->justReturn(['conference']);

        $service = $this->createService([
            '_coupon_event_types' => ['workshop'],
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('course_restricted', $result->get_error_code());
    }

    public function test_validate_event_type_restriction_empty_terms_returns_error(): void
    {
        Functions\when('wp_get_object_terms')->justReturn([]);

        $service = $this->createService([
            '_coupon_event_types' => ['workshop'],
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('course_restricted', $result->get_error_code());
    }

    public function test_validate_event_type_restriction_no_types_all_succeeds(): void
    {
        Functions\when('wp_get_object_terms')->justReturn(['workshop']);

        $service = $this->createService([
            '_coupon_event_types' => [],
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result, 'Empty event_types restriction should allow all');
        $this->assertTrue($result['valid']);
    }

    public function test_validate_event_type_wp_error_terms_returns_restricted(): void
    {
        Functions\when('wp_get_object_terms')->justReturn(new \WP_Error('error', 'Bad taxonomy'));

        $service = $this->createService([
            '_coupon_event_types' => ['workshop'],
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('course_restricted', $result->get_error_code());
    }

    // ============================================================
    // Case normalization
    // ============================================================

    public function test_validate_lowercase_code_normalized(): void
    {
        $service = $this->createService();

        $result = $service->validate_coupon('summer2026', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
        $this->assertSame('SUMMER2026', $result['code']);
    }

    public function test_validate_mixed_case_code_normalized(): void
    {
        $service = $this->createService();

        $result = $service->validate_coupon('SuMmEr2026', 1, 1);

        $this->assertIsArray($result);
        $this->assertSame('SUMMER2026', $result['code']);
    }

    // ============================================================
    // Successful validation result structure
    // ============================================================

    public function test_validate_successful_result_structure(): void
    {
        $service = $this->createService([
            '_coupon_discount_type'  => 'percent',
            '_coupon_discount_value' => 15.50,
        ]);

        $result = $service->validate_coupon('SUMMER2026', 1, 1);

        $this->assertIsArray($result);
        $this->assertTrue($result['valid']);
        $this->assertSame(99, $result['coupon_id']);
        $this->assertSame('SUMMER2026', $result['code']);
        $this->assertSame('percent', $result['discount_type']);
        $this->assertSame(15.50, $result['discount_value']);
        $this->assertSame('SUMMER2026', $result['description']);
        $this->assertFalse($result['is_staff']);
    }

    // ============================================================
    // calculate_discount: fixed
    // ============================================================

    public function test_calculate_fixed_discount(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(100.00, [
            'discount_type'  => 'fixed',
            'discount_value' => 20.00,
        ]);

        $this->assertSame(100.00, $result['original_amount']);
        $this->assertSame(20.00, $result['discount_amount']);
        $this->assertSame(80.00, $result['final_amount']);
        $this->assertSame(20.0, $result['discount_percentage']);
    }

    public function test_calculate_fixed_discount_exceeds_amount_caps(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(50.00, [
            'discount_type'  => 'fixed',
            'discount_value' => 100.00,
        ]);

        $this->assertSame(50.00, $result['discount_amount']);
        $this->assertEquals(0.0, $result['final_amount']);
    }

    // ============================================================
    // calculate_discount: percentage
    // ============================================================

    public function test_calculate_percentage_discount(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(100.00, [
            'discount_type'  => 'percent',
            'discount_value' => 15.00,
        ]);

        $this->assertSame(100.00, $result['original_amount']);
        $this->assertSame(15.00, $result['discount_amount']);
        $this->assertSame(85.00, $result['final_amount']);
        $this->assertSame(15.0, $result['discount_percentage']);
    }

    public function test_calculate_percentage_discount_capped_at_100(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(100.00, [
            'discount_type'  => 'percent',
            'discount_value' => 150.00,
        ]);

        $this->assertSame(100.00, $result['discount_amount']);
        $this->assertEquals(0.0, $result['final_amount']);
    }

    public function test_calculate_percentage_discount_zero(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(100.00, [
            'discount_type'  => 'percent',
            'discount_value' => 0.00,
        ]);

        $this->assertSame(0.0, $result['discount_amount']);
        $this->assertSame(100.00, $result['final_amount']);
        $this->assertSame(0.0, $result['discount_percentage']);
    }

    public function test_calculate_percentage_with_alternate_type_key(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(200.00, [
            'discount_type'  => 'percentage',
            'discount_value' => 10.00,
        ]);

        $this->assertSame(20.00, $result['discount_amount']);
        $this->assertSame(180.00, $result['final_amount']);
    }

    // ============================================================
    // calculate_discount: edge cases
    // ============================================================

    public function test_calculate_discount_does_not_go_below_zero(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(10.00, [
            'discount_type'  => 'fixed',
            'discount_value' => 20.00,
        ]);

        $this->assertEquals(0.0, $result['final_amount']);
        $this->assertSame(10.00, $result['discount_amount']);
    }

    public function test_calculate_discount_zero_original_amount(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(0.0, [
            'discount_type'  => 'fixed',
            'discount_value' => 10.00,
        ]);

        $this->assertSame(0.0, $result['discount_amount']);
        $this->assertEquals(0.0, $result['final_amount']);
        $this->assertEquals(0.0, $result['discount_percentage']);
    }

    public function test_calculate_100_percent_discount(): void
    {
        $service = new CouponService();

        $result = $service->calculate_discount(100.00, [
            'discount_type'  => 'percent',
            'discount_value' => 100.00,
        ]);

        $this->assertSame(100.00, $result['discount_amount']);
        $this->assertEquals(0.0, $result['final_amount']);
        $this->assertSame(100.0, $result['discount_percentage']);
    }

    // ============================================================
    // record_usage
    // ============================================================

    public function test_record_usage_inserts_with_coupon_post_id(): void
    {
        $test = $this;
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function ($table, $data, $format) use ($test) {
                $test->assertSame('wp_hmwevents_coupon_usage', $table);
                $test->assertSame(99, $data['coupon_post_id'], 'Should use coupon_post_id (the post ID), not coupon_id');
                $test->assertSame('SUMMER2026', $data['coupon_code']);
                $test->assertSame(123, $data['booking_id']);
                $test->assertSame('user@example.com', $data['registrant_email']);
                $test->assertSame(15.0, $data['discount_amount']);
                $test->assertSame(100.0, $data['original_amount']);
                return 1;
            });

        $service = $this->createService();

        $result = $service->record_usage('SUMMER2026', 123, 'user@example.com', 15.00, 100.00);

        $this->assertTrue($result);
    }

    public function test_record_usage_increments_usage_count_after_insert(): void
    {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);

        $service = $this->createService(['_coupon_usage_count' => 5]);

        $update_meta_calls = [];
        Functions\when('update_post_meta')->alias(function ($post_id, $key, $value) use (&$update_meta_calls) {
            $update_meta_calls[] = [$post_id, $key, $value];
            return true;
        });

        $result = $service->record_usage('SUMMER2026', 123, 'user@example.com', 15.00, 100.00);

        $this->assertTrue($result);

        $count_calls = array_filter($update_meta_calls, function ($call) {
            return $call[1] === '_coupon_usage_count';
        });
        $count_calls = array_values($count_calls);

        $this->assertCount(1, $count_calls, 'Should call update_post_meta to increment _coupon_usage_count');
        $this->assertSame(99, $count_calls[0][0], 'Should update the coupon post');
        $this->assertSame(6, $count_calls[0][2], 'Should increment from 5 to 6');
    }

    public function test_record_usage_coupon_not_found_returns_false(): void
    {
        Functions\when('get_posts')->justReturn([]);

        $service = new CouponService();

        $result = $service->record_usage('UNKNOWN', 123, 'user@example.com', 15.00, 100.00);

        $this->assertFalse($result);
    }

    public function test_record_usage_handles_insert_failure(): void
    {
        // Override the insert expectation to return false, simulating a DB failure
        $this->wpdb->shouldReceive('insert')
            ->andReturn(false);
        $this->wpdb->shouldReceive('get_var')
            ->andReturn(0)->byDefault();
        $this->wpdb->last_error = 'Duplicate entry';

        $service = $this->createService();

        $result = $service->record_usage('SUMMER2026', 123, 'user@example.com', 15.00, 100.00);

        $this->assertFalse($result);
    }

    public function test_record_usage_normalizes_code_case(): void
    {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function ($table, $data, $format) {
                \PHPUnit\Framework\Assert::assertSame('SUMMER2026', $data['coupon_code']);
                return 1;
            });

        $service = $this->createService();

        $result = $service->record_usage('summer2026', 123, 'user@example.com', 15.00, 100.00);

        $this->assertTrue($result);
    }

    public function test_record_usage_does_not_increment_on_insert_failure(): void
    {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(false);
        $this->wpdb->last_error = 'DB error';

        $update_meta_calls = [];
        Functions\when('update_post_meta')->alias(function ($post_id, $key, $value) use (&$update_meta_calls) {
            $update_meta_calls[] = compact('post_id', 'key', 'value');
            return true;
        });

        $service = $this->createService();

        $result = $service->record_usage('SUMMER2026', 123, 'user@example.com', 15.00, 100.00);

        $this->assertFalse($result);

        $usage_count_calls = array_filter($update_meta_calls, function ($c) {
            return $c['key'] === '_coupon_usage_count';
        });
        $this->assertEmpty($usage_count_calls, 'update_post_meta for _coupon_usage_count should NOT be called when insert fails');
    }
}
