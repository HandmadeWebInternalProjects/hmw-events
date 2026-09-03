<?php

/**
 * Tests for Hooks::get_all_sessions() — merged schedule listing of
 * child sessions and standalone custom-date clones.
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

class HooksSessionScheduleTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function event_post(int $id): \WP_Post
    {
        return new \WP_Post((object) [
            'ID'          => $id,
            'post_type'   => Event::POST_TYPE,
            'post_parent' => 0,
            'post_status' => 'publish',
        ]);
    }

    public function test_returns_children_unchanged_when_no_clones_exist()
    {
        $event = $this->event_post(5);

        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_start_date') {
                return $post_id === 11 ? '2026-08-10 09:00:00' : '2026-08-17 09:00:00';
            }
            return '';
        });

        Functions\when('get_posts')->alias(function ($args = []) {
            if (isset($args['post_parent'])) {
                return [$this->event_post(11), $this->event_post(12)];
            }
            return [];
        });

        $all = Hooks::get_all_sessions($event);

        $this->assertSame([11, 12], array_map(fn($p) => $p->ID, $all));
    }

    public function test_merges_children_and_clones_sorted_by_start_datetime()
    {
        $event = $this->event_post(5);

        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key !== '_event_start_date') {
                return '';
            }
            return [
                11 => '2026-08-17 09:00:00',
                12 => '2026-08-24 09:00:00',
                60 => '2026-08-10 10:45:00',
                61 => '2026-08-10 09:00:00',
            ][$post_id] ?? '';
        });

        Functions\when('get_posts')->alias(function ($args = []) {
            if (isset($args['post_parent'])) {
                return [$this->event_post(11), $this->event_post(12)];
            }
            return [$this->event_post(60), $this->event_post(61)];
        });

        $all = Hooks::get_all_sessions($event);

        $this->assertSame([61, 60, 11, 12], array_map(fn($p) => $p->ID, $all));
    }

    public function test_clone_query_requests_published_posts_only()
    {
        $event = $this->event_post(5);
        $captured = [];

        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_posts')->alias(function ($args = []) use (&$captured) {
            $captured[] = $args;
            return [];
        });

        Hooks::get_all_sessions($event);

        $clone_query = array_filter($captured, fn($args) => ($args['meta_key'] ?? '') === '_cloned_from');
        $this->assertCount(1, $clone_query);
        $clone_query = reset($clone_query);
        $this->assertSame('publish', $clone_query['post_status']);
        $this->assertSame(Event::POST_TYPE, $clone_query['post_type']);
        $this->assertSame(5, (int) $clone_query['meta_value']);
    }
}
