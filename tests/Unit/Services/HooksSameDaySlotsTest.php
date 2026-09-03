<?php

/**
 * Tests for Hooks::get_same_day_slots() — sibling session discovery
 * for the "Other sessions on {date}" strip.
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\PostTypes\Event;
use HMWEvents\Services\Hooks;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class HooksSameDaySlotsTest extends TestCase
{
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
        Functions\when('get_option')->justReturn(false);
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('apply_filters')->alias(function (...$args) {
            return $args[1];
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function event_post(int $id, int $parent_id = 0): \WP_Post
    {
        return new \WP_Post((object) [
            'ID'          => $id,
            'post_type'   => Event::POST_TYPE,
            'post_parent' => $parent_id,
            'post_status' => 'publish',
        ]);
    }

    private function stub_posts(array $posts): void
    {
        Functions\when('get_post')->alias(function ($id) use ($posts) {
            return $posts[(int) $id] ?? null;
        });
    }

    private function stub_meta(array $dates, $cloned_from = ''): void
    {
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) use ($dates, $cloned_from) {
            if ($key === '_cloned_from') {
                return $cloned_from;
            }
            return $dates[(int) $post_id][$key] ?? '';
        });
    }

    public function test_returns_empty_for_standalone_event_without_clone_source()
    {
        $event = $this->event_post(10);
        $this->stub_posts([10 => $event]);
        $this->stub_meta([]);

        $this->assertSame([], Hooks::get_same_day_slots($event));
    }

    public function test_returns_empty_when_event_has_no_start_date()
    {
        $event = $this->event_post(10, 5);
        $parent = $this->event_post(5);
        $this->stub_posts([5 => $parent, 10 => $event]);
        $this->stub_meta([]);

        $this->assertSame([], Hooks::get_same_day_slots($event));
    }

    public function test_returns_same_date_siblings_sorted_by_time_excluding_self()
    {
        $event  = $this->event_post(10, 5);
        $parent = $this->event_post(5);
        $this->stub_posts([
            5  => $parent,
            10 => $event,
            11 => $this->event_post(11, 5),
            12 => $this->event_post(12, 5),
            13 => $this->event_post(13, 5),
            14 => $this->event_post(14, 5),
        ]);

        $this->stub_meta([
            10 => ['_event_start_date' => '2026-08-10 10:00:00', '_event_end_date' => '2026-08-10 11:30:00'],
            11 => ['_event_start_date' => '2026-08-10 11:15:00', '_event_end_date' => '2026-08-10 12:45:00'],
            12 => ['_event_start_date' => '2026-08-10 12:45:00', '_event_end_date' => '2026-08-10 14:15:00'],
            13 => ['_event_start_date' => '2026-08-10 09:00:00', '_event_end_date' => '2026-08-10 10:30:00'],
            14 => ['_event_start_date' => '2026-08-17 10:00:00', '_event_end_date' => '2026-08-17 11:30:00'],
        ]);

        Functions\when('get_posts')->justReturn([
            $this->event_post(11, 5),
            $this->event_post(12, 5),
            $this->event_post(13, 5),
            $this->event_post(14, 5),
            $this->event_post(10, 5),
        ]);

        $slots = Hooks::get_same_day_slots($event);

        $this->assertCount(3, $slots);
        $this->assertSame(
            [13, 11, 12],
            array_map(fn($slot) => $slot['session']->ID, $slots)
        );
        $this->assertSame('2026-08-10 09:00:00', $slots[0]['start']);
        $this->assertSame('2026-08-10 10:30:00', $slots[0]['end']);
    }

    public function test_returns_empty_when_no_siblings_share_the_date()
    {
        $event  = $this->event_post(10, 5);
        $parent = $this->event_post(5);
        $this->stub_posts([5 => $parent, 10 => $event, 14 => $this->event_post(14, 5)]);

        $this->stub_meta([
            10 => ['_event_start_date' => '2026-08-10 10:00:00', '_event_end_date' => '2026-08-10 11:30:00'],
            14 => ['_event_start_date' => '2026-08-17 10:00:00', '_event_end_date' => '2026-08-17 11:30:00'],
        ]);

        Functions\when('get_posts')->justReturn([$this->event_post(14, 5)]);

        $this->assertSame([], Hooks::get_same_day_slots($event));
    }

    public function test_resolves_siblings_for_standalone_clones_via_cloned_from()
    {
        $event = $this->event_post(20);
        $this->stub_posts([20 => $event, 21 => $this->event_post(21)]);

        $this->stub_meta([
            20 => ['_event_start_date' => '2026-08-10 12:45:00', '_event_end_date' => '2026-08-10 14:15:00'],
            21 => ['_event_start_date' => '2026-08-10 10:00:00', '_event_end_date' => '2026-08-10 11:30:00'],
        ], '55');

        Functions\when('get_posts')->alias(function ($args = []) {
            if (isset($args['post_parent'])) {
                return [];
            }
            return [$this->event_post(21)];
        });

        $slots = Hooks::get_same_day_slots($event);

        $this->assertCount(1, $slots);
        $this->assertSame(21, $slots[0]['session']->ID);
        $this->assertSame('2026-08-10 10:00:00', $slots[0]['start']);
    }
}
