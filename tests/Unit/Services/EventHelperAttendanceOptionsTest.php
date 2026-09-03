<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Helpers\EventHelper;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class EventHelperAttendanceOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $wpdb = Mockery::mock();
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
            return $value;
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_active_attendance_options_returns_rows(): void
    {
        $rows = [
            (object) ['id' => 1, 'option_type' => 'individual', 'label' => 'Individual', 'price' => '0.00', 'price_mode' => 'flat', 'pricing_rules' => null, 'capacity' => null, 'sort_order' => 0],
            (object) ['id' => 2, 'option_type' => 'couple', 'label' => 'Couple', 'price' => '650.00', 'price_mode' => 'flat', 'pricing_rules' => null, 'capacity' => 8, 'sort_order' => 1],
        ];

        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn($rows);

        $result = EventHelper::get_active_attendance_options(42);

        $this->assertCount(2, $result);
        $this->assertSame('couple', $result[1]->option_type);
        $this->assertSame(8, $result[1]->capacity);
    }

    public function test_get_active_attendance_options_returns_empty_array_when_no_rows(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn(null);

        $result = EventHelper::get_active_attendance_options(42);

        $this->assertSame([], $result);
    }

    public function test_resolve_attendance_option_returns_row(): void
    {
        $row = (object) ['id' => 2, 'option_type' => 'couple', 'label' => 'Couple', 'price' => '650.00', 'capacity' => 8];

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($row);

        $result = EventHelper::resolve_attendance_option(42, 'couple');

        $this->assertNotNull($result);
        $this->assertSame('couple', $result->option_type);
        $this->assertSame(8, $result->capacity);
    }

    public function test_resolve_attendance_option_returns_null_when_missing(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(null);

        $this->assertNull(EventHelper::resolve_attendance_option(42, 'professional'));
    }

    public function test_resolve_attendance_option_price_returns_float(): void
    {
        $row = (object) ['id' => 2, 'option_type' => 'couple', 'label' => 'Couple', 'price' => '650.00', 'capacity' => null];

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($row);

        $this->assertSame(650.0, EventHelper::resolve_attendance_option_price(42, 'couple'));
    }

    public function test_resolve_attendance_option_price_returns_null_when_missing(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(null);

        $this->assertNull(EventHelper::resolve_attendance_option_price(42, 'couple'));
    }

    public function test_check_attendance_option_capacity_unlimited_when_capacity_null(): void
    {
        $option = (object) ['capacity' => null, 'label' => 'Individual'];

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($option);

        $this->assertTrue(EventHelper::check_attendance_option_capacity(1));
    }

    public function test_check_attendance_option_capacity_returns_error_when_full(): void
    {
        $option = (object) ['capacity' => 5, 'label' => 'Couple'];

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($option);
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(5);

        $result = EventHelper::check_attendance_option_capacity(2);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('attendance_option_full', $result->get_error_code());
    }

    public function test_check_attendance_option_capacity_returns_true_when_space_available(): void
    {
        $option = (object) ['capacity' => 5, 'label' => 'Couple'];

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($option);
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(3);

        $this->assertTrue(EventHelper::check_attendance_option_capacity(2));
    }

    public function test_check_attendance_option_capacity_returns_true_when_option_missing(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(null);

        $this->assertTrue(EventHelper::check_attendance_option_capacity(999));
    }
}
