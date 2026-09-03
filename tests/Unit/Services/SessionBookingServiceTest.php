<?php

/**
 * Tests for SessionBookingService — bookable occurrence listing and
 * multi-session selection expansion (track + individual modes).
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\PostTypes\Event;
use HMWEvents\Services\SessionBookingService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class SessionBookingServiceTest extends TestCase
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
                100 => ['post_type' => 'hmw_event', 'post_parent' => 0, 'post_title' => 'Series Parent'],
                101 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'Session A'],
                102 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'Session B (full)'],
                103 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'Session C'],
                104 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'Session D'],
            ];
            if (!isset($posts[$id])) {
                return null;
            }
            return new \WP_Post((object) array_merge($posts[$id], ['ID' => $id]));
        });

        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            $starts = [
                100 => '2026-08-03 10:00:00',
                101 => '2026-08-03 11:15:00',
                102 => '2026-08-03 12:45:00',
                103 => '2026-08-10 10:00:00',
                104 => '2026-08-10 11:15:00',
            ];
            if ($key === '_event_start_date') {
                return $starts[$post_id] ?? '';
            }
            if ($key === '_event_end_date') {
                return '';
            }
            if ($key === '_event_price') {
                if ($GLOBALS['__session_prices_all_free'] ?? false) {
                    return $post_id === 102 ? '50' : '';
                }
                return [
                    100 => '150',
                    101 => '',
                    102 => '50',
                    103 => '',
                    104 => '',
                ][$post_id] ?? '';
            }
            if ($key === '_event_session_booking_mode') {
                return $GLOBALS['__session_booking_mode'] ?? '';
            }
            return '';
        });

        Patchwork\replace('HMWEvents\Services\Hooks::get_all_sessions', function ($event) {
            return array_map(fn($id) => get_post($id), [101, 102, 103, 104]);
        });

        Patchwork\replace('HMWEvents\Services\CapacityService::get_capacity', function ($id) {
            return $id === 102 ? 2 : 20;
        });
        Patchwork\replace('HMWEvents\Services\CapacityService::get_remaining_places', function ($id) {
            return $id === 102 ? 0 : 20;
        });

        $this->service = new SessionBookingService();
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__session_booking_mode'],
            $GLOBALS['__session_prices_all_free']
        );
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_bookable_sessions_include_parent_and_all_sessions_sorted()
    {
        $occurrences = $this->service->get_bookable_sessions(100);

        $this->assertCount(5, $occurrences);
        $this->assertSame(100, $occurrences[0]['id']);
        $this->assertTrue($occurrences[0]['is_parent']);
        $this->assertSame('2026-08-03 10:00:00', $occurrences[0]['start']);
        $this->assertSame('2026-08-03 11:15:00', $occurrences[1]['start']);
    }

    public function test_price_falls_back_to_parent_price_when_session_price_empty()
    {
        $occurrences = $this->service->get_bookable_sessions(100);

        $by_id = array_column($occurrences, null, 'id');
        $this->assertSame(150.0, $by_id[101]['price']);
        $this->assertSame(50.0, $by_id[102]['price']);
    }

    public function test_marks_full_sessions()
    {
        $occurrences = $this->service->get_bookable_sessions(100);
        $by_id = array_column($occurrences, null, 'id');

        $this->assertTrue($by_id[102]['is_full']);
        $this->assertFalse($by_id[101]['is_full']);
    }

    public function test_detects_priced_bookable_sessions()
    {
        $this->assertTrue($this->service->has_priced_bookable_sessions(100));
    }

    public function test_priced_sessions_ignore_full_occurrences()
    {
        // Only the full session (102) carries its own price here; with the
        // parent and other sessions free, nothing BOOKABLE is priced.
        $GLOBALS['__session_prices_all_free'] = true;
        Patchwork\replace('HMWEvents\Services\CapacityService::get_remaining_places', function ($id) {
            return $id === 102 ? 0 : 20;
        });
        Patchwork\replace('HMWEvents\Services\EventDataService::get_price', function ($id) {
            return 0.0;
        });

        $this->assertFalse($this->service->has_priced_bookable_sessions(100));
    }

    public function test_empty_selection_is_rejected()
    {
        $result = $this->service->expand_selection(100, []);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('no_sessions_selected', $result->get_error_code());
    }

    public function test_invalid_session_id_is_rejected()
    {
        $result = $this->service->expand_selection(100, [999]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_session', $result->get_error_code());
    }

    public function test_full_session_is_rejected_by_name()
    {
        $result = $this->service->expand_selection(100, [102]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('session_full', $result->get_error_code());
        $this->assertStringContainsString('Session B (full)', $result->get_error_message());
    }

    public function test_track_mode_expands_to_all_sessions_with_same_time()
    {
        $result = $this->service->expand_selection(100, [101]);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertSame('recurring', $result['booking_type']);
        $this->assertSame([101, 104], $result['session_ids']);
        $this->assertSame('11:15:00', $result['slot']);

        $this->assertCount(2, $result['rows']);
        $this->assertSame(101, $result['rows'][0]['event_post_id']);
        $this->assertSame(150.0, $result['rows'][0]['booking_amount']);
        $this->assertSame(104, $result['rows'][1]['event_post_id']);
        $this->assertSame(0.0, $result['rows'][1]['booking_amount']);
        $this->assertSame(150.0, $result['total']);
    }

    public function test_individual_mode_creates_per_session_rows_with_attendee_multiplication()
    {
        $result = $this->service->expand_selection(100, [101, 103], 'individual', 2);

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertSame('package', $result['booking_type']);
        $this->assertCount(2, $result['rows']);

        $this->assertSame(101, $result['rows'][0]['event_post_id']);
        $this->assertSame(300.0, $result['rows'][0]['booking_amount']);
        $this->assertSame(103, $result['rows'][1]['event_post_id']);
        $this->assertSame(300.0, $result['rows'][1]['booking_amount']);
        $this->assertSame(600.0, $result['total']);
    }

    public function test_individual_mode_rejects_when_attendees_exceed_remaining_places()
    {
        $result = $this->service->expand_selection(100, [103], 'individual', 25);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('session_full', $result->get_error_code());
    }
}
