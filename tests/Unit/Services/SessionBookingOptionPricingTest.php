<?php

/**
 * Tests for SessionBookingService::expand_selection() option-driven pricing.
 *
 * When the selected attendance option carries a positive price it becomes
 * the product: option price × attendee count charged once on the primary
 * row. Free or unknown options fall back to per-session pricing.
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\SessionBookingService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class SessionBookingOptionPricingTest extends TestCase
{
    private SessionBookingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';

        Functions\when('__')->returnArg();
        Functions\when('get_option')->justReturn(false);
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });

        Functions\when('get_post')->alias(function ($id) {
            $posts = [
                5792 => ['post_type' => 'hmw_event', 'post_parent' => 0, 'post_title' => 'Series Parent'],
                6042 => ['post_type' => 'hmw_event', 'post_parent' => 5792, 'post_title' => 'Session One'],
                6043 => ['post_type' => 'hmw_event', 'post_parent' => 5792, 'post_title' => 'Session Two'],
            ];
            if (!isset($posts[$id])) {
                return null;
            }
            return new \WP_Post((object) array_merge($posts[$id], ['ID' => $id]));
        });

        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            $starts = [
                5792 => '2026-08-28 09:00:00',
                6042 => '2026-08-28 10:00:00',
                6043 => '2026-08-28 10:00:00',
            ];
            if ($key === '_event_start_date') {
                return $starts[$post_id] ?? '';
            }
            if ($key === '_event_end_date') {
                return '';
            }
            if ($key === '_event_price') {
                return [6042 => '500', 6043 => '500'][$post_id] ?? '';
            }
            if ($key === '_event_capacity') {
                return 50;
            }
            if ($key === '_event_session_booking_mode') {
                return $GLOBALS['__option_test_mode'] ?? 'individual';
            }
            return '';
        });

        Patchwork\replace('HMWEvents\Services\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        Patchwork\replace('HMWEvents\Services\Hooks::get_all_sessions', function ($event) {
            return [get_post(6042), get_post(6043)];
        });

        Patchwork\replace('HMWEvents\Services\CapacityService::get_booked_people', function ($id) {
            return 0;
        });

        Patchwork\replace('HMWEvents\Helpers\EventHelper::resolve_attendance_option', function ($event_id, $attendance_type) {
            $options = [
                'two-day'  => (object) ['price' => 100.0],
                'free-opt' => (object) ['price' => 0.0],
                'missing'  => null,
            ];
            return $options[$attendance_type] ?? null;
        });

        $this->service = new SessionBookingService();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['__option_test_mode']);
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_paid_option_charges_option_price_times_attendees_on_first_row()
    {
        $result = $this->service->expand_selection(5792, [6042], null, 2, 'two-day');

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertSame('package', $result['booking_type']);
        $this->assertSame(200.0, $result['total']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(6042, $result['rows'][0]['event_post_id']);
        $this->assertSame(200.0, $result['rows'][0]['booking_amount']);
        $this->assertNull($result['slot']);
    }

    public function test_paid_option_charged_once_across_multiple_picked_sessions()
    {
        $result = $this->service->expand_selection(5792, [6042, 6043], null, 1, 'two-day');

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertSame('package', $result['booking_type']);
        $this->assertSame(100.0, $result['total']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame(6042, $result['rows'][0]['event_post_id']);
        $this->assertSame(100.0, $result['rows'][0]['booking_amount']);
        $this->assertSame(6043, $result['rows'][1]['event_post_id']);
        $this->assertSame(0.0, $result['rows'][1]['booking_amount']);
    }

    public function test_free_option_falls_back_to_per_session_pricing()
    {
        $result = $this->service->expand_selection(5792, [6042], null, 2, 'free-opt');

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertSame('package', $result['booking_type']);
        $this->assertSame(1000.0, $result['total']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame(6042, $result['rows'][0]['event_post_id']);
        $this->assertSame(1000.0, $result['rows'][0]['booking_amount']);
    }

    public function test_unknown_option_falls_back_to_per_session_pricing()
    {
        $result = $this->service->expand_selection(5792, [6042, 6043], null, 1, 'missing');

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertSame('package', $result['booking_type']);
        $this->assertSame(1000.0, $result['total']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame(6042, $result['rows'][0]['event_post_id']);
        $this->assertSame(500.0, $result['rows'][0]['booking_amount']);
        $this->assertSame(6043, $result['rows'][1]['event_post_id']);
        $this->assertSame(500.0, $result['rows'][1]['booking_amount']);
    }

    public function test_track_mode_uses_paid_option_price_for_recurring_total()
    {
        $GLOBALS['__option_test_mode'] = 'track';

        $result = $this->service->expand_selection(5792, [6042], null, 1, 'two-day');

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertSame('recurring', $result['booking_type']);
        $this->assertSame('10:00:00', $result['slot']);
        $this->assertSame([6042, 6043], $result['session_ids']);
        $this->assertSame(100.0, $result['total']);
        $this->assertCount(2, $result['rows']);
        $this->assertSame(6042, $result['rows'][0]['event_post_id']);
        $this->assertSame(100.0, $result['rows'][0]['booking_amount']);
        $this->assertSame(6043, $result['rows'][1]['event_post_id']);
        $this->assertSame(0.0, $result['rows'][1]['booking_amount']);
    }
}
