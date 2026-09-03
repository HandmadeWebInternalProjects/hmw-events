<?php

/**
 * Tests for same-day multi-time session slots.
 *
 * Covers calculate_dates time cross-products, time normalization,
 * stored-time encoding, identity keys, and custom-date slot parsing.
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\SessionService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class SessionMultiTimeSlotsTest extends TestCase
{
    private SessionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(0);

        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post')->justReturn(null);
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });

        $this->service = new SessionService();
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function calculate_dates(array $config): array
    {
        $method = new \ReflectionMethod(SessionService::class, 'calculate_dates');
        $method->setAccessible(true);

        return $method->invoke($this->service, $config);
    }

    private function invoke(string $method, ...$args)
    {
        $reflection = new \ReflectionMethod(SessionService::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->service, ...$args);
    }

    private function base_config(array $overrides = []): array
    {
        return array_merge([
            'recurrence_type'     => 'weekly',
            'start_date'          => '2026-08-03 10:00:00',
            'end_date'            => null,
            'recurrence_interval' => 1,
            'recurrence_days'     => '1',
            'max_occurrences'     => null,
            'custom_dates'        => [],
            'times'               => [],
        ], $overrides);
    }

    // ---------------------------------------------------------------
    // Pattern types — times cross-product
    // ---------------------------------------------------------------

    public function test_weekly_with_times_expands_each_date_into_slots()
    {
        $dates = $this->calculate_dates($this->base_config([
            'max_occurrences' => 2,
            'times'           => ['10:00', '11:15', '12:45'],
        ]));

        $this->assertCount(5, $dates);

        $parent_day = array_values(array_filter($dates, fn($d) => $d['start'] === '2026-08-03'));
        $this->assertSame(['11:15:00', '12:45:00'], array_column($parent_day, 'time'));

        $first_generated = array_values(array_filter($dates, fn($d) => $d['start'] === '2026-08-10'));
        $this->assertCount(3, $first_generated);

        $times = array_column($first_generated, 'time');
        $this->assertSame(['10:00:00', '11:15:00', '12:45:00'], array_values($times));
    }

    public function test_weekly_without_times_uses_parent_start_time()
    {
        $dates = $this->calculate_dates($this->base_config([
            'max_occurrences' => 2,
        ]));

        $this->assertCount(2, $dates);
        foreach ($dates as $date) {
            $this->assertSame('10:00:00', $date['time']);
        }
        $this->assertSame('2026-08-10', $dates[0]['start']);
    }

    public function test_max_occurrences_counts_dates_not_sessions()
    {
        $dates = $this->calculate_dates($this->base_config([
            'max_occurrences' => 2,
            'times'           => ['10:00', '11:15', '12:45'],
        ]));

        $unique_dates = array_unique(array_column($dates, 'start'));
        $this->assertSame(['2026-08-03', '2026-08-10'], array_values($unique_dates));
        $this->assertCount(5, $dates);
    }

    public function test_daily_with_times_expands_and_sorts_chronologically()
    {
        $dates = $this->calculate_dates($this->base_config([
            'recurrence_type' => 'daily',
            'recurrence_days' => '',
            'max_occurrences' => 3,
            'times'           => ['12:45', '10:00'],
        ]));

        $this->assertCount(5, $dates);

        $expected = [
            ['2026-08-03', '12:45:00'],
            ['2026-08-04', '10:00:00'],
            ['2026-08-04', '12:45:00'],
            ['2026-08-05', '10:00:00'],
            ['2026-08-05', '12:45:00'],
        ];
        $actual = array_map(fn($d) => [$d['start'], $d['time']], $dates);
        $this->assertSame($expected, $actual);
    }

    public function test_fortnightly_includes_additional_slots_on_parent_start_date()
    {
        // Fortnightly Tuesdays from Tue 21 Jul 2026 — the parent's own date.
        $dates = $this->calculate_dates($this->base_config([
            'start_date'          => '2026-07-21 10:00:00',
            'recurrence_days'     => '2',
            'recurrence_interval' => 2,
            'times'               => ['10:00', '11:15', '12:45'],
        ]));

        $parent_day = array_values(array_filter($dates, fn($d) => $d['start'] === '2026-07-21'));
        $this->assertSame(['11:15:00', '12:45:00'], array_column($parent_day, 'time'));

        $next_fortnight = array_values(array_filter($dates, fn($d) => $d['start'] === '2026-08-04'));
        $this->assertSame(['10:00:00', '11:15:00', '12:45:00'], array_column($next_fortnight, 'time'));

        $this->assertNotContains('2026-07-28', array_column($dates, 'start'));
    }

    public function test_weekly_without_times_still_excludes_parent_start_date()
    {
        $dates = $this->calculate_dates($this->base_config([
            'start_date'          => '2026-07-21 10:00:00',
            'recurrence_days'     => '2',
            'recurrence_interval' => 2,
            'times'               => [],
        ]));

        $this->assertNotContains('2026-07-21', array_column($dates, 'start'));
        $this->assertSame('2026-08-04', $dates[0]['start']);
    }

    public function test_daily_with_times_includes_additional_slots_on_parent_date()
    {
        $dates = $this->calculate_dates($this->base_config([
            'recurrence_type'     => 'daily',
            'recurrence_days'     => '',
            'recurrence_interval' => 1,
            'max_occurrences'     => 2,
            'times'               => ['10:00', '18:30'],
            'start_date'          => '2026-07-21 10:00:00',
        ]));

        $expected = [
            ['2026-07-21', '18:30:00'],
            ['2026-07-22', '10:00:00'],
            ['2026-07-22', '18:30:00'],
        ];
        $this->assertSame($expected, array_map(fn($d) => [$d['start'], $d['time']], $dates));
    }

    public function test_monthly_with_times_includes_additional_slots_on_parent_date()
    {
        $dates = $this->calculate_dates($this->base_config([
            'recurrence_type'     => 'monthly',
            'recurrence_days'     => '',
            'recurrence_interval' => 1,
            'max_occurrences'     => 2,
            'times'               => ['10:00', '12:45'],
            'start_date'          => '2026-07-21 10:00:00',
        ]));

        $expected = [
            ['2026-07-21', '12:45:00'],
            ['2026-08-21', '10:00:00'],
            ['2026-08-21', '12:45:00'],
        ];
        $this->assertSame($expected, array_map(fn($d) => [$d['start'], $d['time']], $dates));
    }

    public function test_monthly_with_times_expands()
    {
        $dates = $this->calculate_dates($this->base_config([
            'recurrence_type' => 'monthly',
            'recurrence_days' => '',
            'max_occurrences' => 1,
            'times'           => ['09:00', '13:00'],
        ]));

        $this->assertCount(2, $dates);
        $this->assertSame('2026-08-03', $dates[0]['start']);
        $this->assertSame(['09:00:00', '13:00:00'], array_column($dates, 'time'));
    }

    // ---------------------------------------------------------------
    // Custom dates — per-row times
    // ---------------------------------------------------------------

    public function test_custom_dates_support_same_day_multiple_times()
    {
        $dates = $this->calculate_dates($this->base_config([
            'recurrence_type' => 'custom',
            'recurrence_days' => '',
            'custom_dates'    => [
                ['date' => '2026-08-10', 'time' => ''],
                ['date' => '2026-08-03', 'time' => '12:45'],
                ['date' => '2026-08-10', 'time' => '12:45'],
                ['date' => '2026-08-10', 'time' => '12:45'],
            ],
        ]));

        $this->assertCount(3, $dates);

        $this->assertSame(['start' => '2026-08-03', 'end' => '2026-08-03', 'time' => '12:45:00'], $dates[0]);
        $this->assertSame(['start' => '2026-08-10', 'end' => '2026-08-10', 'time' => null], $dates[1]);
        $this->assertSame(['start' => '2026-08-10', 'end' => '2026-08-10', 'time' => '12:45:00'], $dates[2]);
    }

    public function test_custom_dates_skip_parent_slot_but_keep_other_times_on_parent_date()
    {
        $dates = $this->calculate_dates($this->base_config([
            'recurrence_type' => 'custom',
            'recurrence_days' => '',
            'custom_dates'    => [
                ['date' => '2026-08-03', 'time' => '10:00'],
                ['date' => '2026-08-03'],
                ['date' => '2026-08-03', 'time' => '11:15'],
            ],
        ]));

        $this->assertCount(1, $dates);
        $this->assertSame('2026-08-03', $dates[0]['start']);
        $this->assertSame('11:15:00', $dates[0]['time']);
    }

    public function test_custom_dates_accept_datetime_strings_with_times()
    {
        $dates = $this->calculate_dates($this->base_config([
            'recurrence_type' => 'custom',
            'recurrence_days' => '',
            'custom_dates'    => [
                '2026-09-01 14:30:00',
                '2026-09-01',
            ],
        ]));

        $this->assertCount(2, $dates);
        $this->assertSame(['2026-09-01', '2026-09-01'], array_column($dates, 'start'));

        $times = array_column($dates, 'time');
        $this->assertContains('14:30:00', $times);
        $this->assertContains(null, $times);
    }

    // ---------------------------------------------------------------
    // Time normalization and storage encoding
    // ---------------------------------------------------------------

    public function test_normalize_times_handles_mixed_formats_and_sorts()
    {
        $times = $this->service->normalize_times(['12:45', '10:00', '11:15 am', '10:00', 'bogus', '']);

        $this->assertSame(['10:00:00', '11:15:00', '12:45:00'], $times);
    }

    public function test_normalize_times_rejects_non_arrays()
    {
        $this->assertSame([], $this->service->normalize_times('10:00'));
        $this->assertSame([], $this->service->normalize_times(null));
    }

    public function test_format_and_parse_stored_times_round_trip()
    {
        $stored = $this->invoke('format_stored_times', ['11:15', '10:00']);
        $this->assertSame('10:00:00,11:15:00', $stored);

        $this->assertSame(['10:00:00', '11:15:00'], $this->service->parse_stored_times($stored));
    }

    public function test_format_stored_times_returns_null_for_empty()
    {
        $this->assertNull($this->invoke('format_stored_times', []));
        $this->assertSame([], $this->service->parse_stored_times(null));
        $this->assertSame([], $this->service->parse_stored_times(''));
    }

    // ---------------------------------------------------------------
    // Identity keys
    // ---------------------------------------------------------------

    public function test_session_identity_key_includes_time()
    {
        $key = $this->invoke('session_identity_key', ['start' => '2026-08-10', 'end' => '2026-08-10', 'time' => '11:15:00']);
        $this->assertSame('2026-08-10|11:15:00', $key);

        $legacy = $this->invoke('session_identity_key', ['start' => '2026-08-10', 'end' => '2026-08-10', 'time' => null]);
        $this->assertSame('2026-08-10|', $legacy);
    }

    public function test_child_identity_key_derives_date_and_time_from_datetime()
    {
        $key = $this->invoke('child_identity_key', '2026-08-10 11:15:00');
        $this->assertSame('2026-08-10|11:15:00', $key);

        $date_only = $this->invoke('child_identity_key', '2026-08-10');
        $this->assertSame('2026-08-10|', $date_only);
    }

    public function test_child_identity_keys_match_session_identity_keys()
    {
        $date = ['start' => '2026-08-10', 'end' => '2026-08-10', 'time' => '11:15:00'];
        $session_key = $this->invoke('session_identity_key', $date);
        $child_key = $this->invoke('child_identity_key', '2026-08-10 11:15:00');

        $this->assertSame($session_key, $child_key);
    }

    // ---------------------------------------------------------------
    // Sorting
    // ---------------------------------------------------------------

    public function test_occurrences_are_sorted_by_date_then_time()
    {
        $dates = $this->calculate_dates($this->base_config([
            'recurrence_type' => 'custom',
            'recurrence_days' => '',
            'custom_dates'    => [
                ['date' => '2026-08-12', 'time' => '09:00'],
                ['date' => '2026-08-10', 'time' => '13:00'],
                ['date' => '2026-08-10', 'time' => '08:00'],
            ],
        ]));

        $actual = array_map(fn($d) => [$d['start'], $d['time']], $dates);
        $expected = [
            ['2026-08-10', '08:00:00'],
            ['2026-08-10', '13:00:00'],
            ['2026-08-12', '09:00:00'],
        ];
        $this->assertSame($expected, $actual);
    }
}
