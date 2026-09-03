<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\PostTypes\Event;
use HMWEvents\Services\CapacityService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class CapacityServiceTest extends TestCase
{
    private CapacityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_results')->andReturn([])->byDefault();
        $wpdb->shouldReceive('get_row')->andReturn(null)->byDefault();
        $wpdb->shouldReceive('get_var')->andReturn(0)->byDefault();

        $GLOBALS['wpdb'] = $wpdb;

        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        Functions\when('__')->returnArg();
        Functions\when('_n')->alias(function ($single, $plural, $count) {
            return $count === 1 ? $single : $plural;
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->alias(function ($value) {
            return strtolower((string) $value);
        });
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });

        $this->service = new CapacityService();
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stub_event_capacity($capacity): void
    {
        Functions\when('get_post')->justReturn(new \WP_Post((object) [
            'ID'        => 42,
            'post_type' => Event::POST_TYPE,
        ]));
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) use ($capacity) {
            return $key === '_event_capacity' ? $capacity : '';
        });
    }

    private function stubOptionRow(?object $row): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($row);
    }

    public function test_check_event_places_rejects_when_insufficient_places_remain(): void
    {
        $this->stub_event_capacity('5');
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(4);

        $result = $this->service->check_event_places(42, 2);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('event_full', $result->get_error_code());
    }

    public function test_check_event_places_allows_when_places_available(): void
    {
        $this->stub_event_capacity('5');
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(3);

        $this->assertTrue($this->service->check_event_places(42, 2));
    }

    public function test_check_event_places_unlimited_when_capacity_zero(): void
    {
        $this->stub_event_capacity('');

        $this->assertTrue($this->service->check_event_places(42, 10));
    }

    public function test_get_remaining_places_subtracts_booked_people(): void
    {
        $this->stub_event_capacity('5');
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(4);

        $this->assertSame(1, $this->service->get_remaining_places(42));
    }

    public function test_check_option_bookings_rejects_when_quota_exceeded(): void
    {
        $this->stubOptionRow((object) ['capacity' => 5, 'label' => 'Couple']);
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(5);

        $result = $this->service->check_option_bookings(2);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('attendance_option_full', $result->get_error_code());
    }

    public function test_check_option_bookings_allows_when_quota_available(): void
    {
        $this->stubOptionRow((object) ['capacity' => 5, 'label' => 'Couple']);
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(3);

        $this->assertTrue($this->service->check_option_bookings(2));
    }

    public function test_check_option_bookings_unlimited_when_capacity_null(): void
    {
        $this->stubOptionRow((object) ['capacity' => null, 'label' => 'Individual']);

        $this->assertTrue($this->service->check_option_bookings(1));
    }

    public function test_check_option_bookings_true_when_option_missing(): void
    {
        $this->stubOptionRow(null);

        $this->assertTrue($this->service->check_option_bookings(999));
    }

    public function test_get_option_remaining_returns_difference(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(8, 3);

        $this->assertSame(5, $this->service->get_option_remaining(2));
    }

    public function test_get_option_remaining_null_when_unlimited(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(null);

        $this->assertNull($this->service->get_option_remaining(2));
    }

    public function test_get_booked_people_for_events_returns_empty_without_query_when_no_ids(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_results')->never();

        $this->assertSame([], $this->service->get_booked_people_for_events([]));
        $this->assertSame([], $this->service->get_booked_people_for_events([0, '']));
    }

    public function test_get_booked_people_for_events_maps_rows_and_dedupes_ids(): void
    {
        $captured = null;
        $GLOBALS['wpdb']->shouldReceive('get_results')->once()->andReturnUsing(function ($sql) use (&$captured) {
            $captured = $sql;
            return [
                (object) ['event_post_id' => '10', 'booked_people' => '4'],
                (object) ['event_post_id' => '11', 'booked_people' => '0'],
            ];
        });

        $counts = $this->service->get_booked_people_for_events([10, 10, '11', 0]);

        $this->assertSame([10 => 4, 11 => 0], $counts);
        $this->assertIsString($captured);
        $this->assertSame(2, substr_count($captured, '%d'));
        $this->assertStringContainsString('GROUP BY b.event_post_id', $captured);
        $this->assertStringContainsString("b.status = 'confirmed'", $captured);
        $this->assertStringContainsString('b.deleted_at IS NULL', $captured);
    }

    public function test_get_booked_people_for_events_returns_empty_when_query_fails(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_results')->once()->andReturn(null);

        $this->assertSame([], $this->service->get_booked_people_for_events([10]));
    }
}
