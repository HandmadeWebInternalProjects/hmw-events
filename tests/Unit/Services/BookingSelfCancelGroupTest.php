<?php

/**
 * Tests for BookingSelfCancelService group options — multi-session bookings
 * offer "cancel this session" vs "cancel all sessions".
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\BookingSelfCancelService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

if (!class_exists('FakeCancelWpdb')) {
    class FakeCancelWpdb
    {
        public $prefix = 'wp_';
        public $row_handler;
        public $results_handler;

        public function prepare($query, ...$args)
        {
            foreach ($args as $arg) {
                $pos = strpos($query, '%d');
                if ($pos === false) {
                    $pos = strpos($query, '%s');
                }
                if ($pos === false) {
                    break;
                }
                $query = substr_replace($query, is_numeric($arg) ? (string) (int) $arg : "'" . $arg . "'", $pos, 2);
            }
            return $query;
        }

        public function get_row($sql)
        {
            return ($this->row_handler)($sql);
        }

        public function get_results($sql)
        {
            return ($this->results_handler)($sql);
        }

        public function get_var($sql)
        {
            return null;
        }

        public function update($table, $data, $where)
        {
            return 1;
        }
    }
}

class BookingSelfCancelGroupTest extends TestCase
{
    private BookingSelfCancelService $service;
    private FakeCancelWpdb $fake;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $this->fake = new FakeCancelWpdb();
        $GLOBALS['wpdb'] = $this->fake;

        Functions\when('__')->returnArg();
        Functions\when('get_the_title')->alias(function ($id) {
            return [
                201 => 'Session A — 10:00 am',
                202 => 'Session B — 11:15 am',
                203 => 'Session C — 12:45 pm',
            ][$id] ?? 'Event ' . $id;
        });
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_start_date') {
                return [
                    201 => '2026-08-03 10:00:00',
                    202 => '2026-08-03 11:15:00',
                    203 => '2026-08-03 12:45:00',
                ][$post_id] ?? '';
            }
            return '';
        });
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('wp_die')->alias(function ($message = '') {
            throw new \Exception('wp_die: ' . substr((string) $message, 0, 60));
        });

        $this->service = new BookingSelfCancelService();
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_null_for_single_type_group()
    {
        $this->fake->row_handler = function ($sql) {
            if (str_contains($sql, 'SELECT booking_group_id FROM')) {
                return (object) ['booking_group_id' => 10];
            }
            if (str_contains($sql, 'booking_groups')) {
                return (object) ['id' => 10, 'booking_type' => 'single', 'booking_reference' => 'BKG-1'];
            }
            return null;
        };

        $this->assertNull($this->service->get_group_cancel_options(61));
    }

    public function test_returns_null_when_group_has_fewer_than_two_active_sessions()
    {
        $this->fake->row_handler = function ($sql) {
            if (str_contains($sql, 'SELECT booking_group_id FROM')) {
                return (object) ['booking_group_id' => 10];
            }
            if (str_contains($sql, 'booking_groups')) {
                return (object) ['id' => 10, 'booking_type' => 'recurring', 'booking_reference' => 'BKG-2'];
            }
            return null;
        };
        $this->fake->results_handler = function ($sql) {
            return [(object) ['id' => 61, 'event_post_id' => 201]];
        };

        $this->assertNull($this->service->get_group_cancel_options(61));
    }

    public function test_describes_multi_session_group_with_current_flag()
    {
        $this->fake->row_handler = function ($sql) {
            if (str_contains($sql, 'SELECT booking_group_id FROM')) {
                return (object) ['booking_group_id' => 10];
            }
            if (str_contains($sql, 'booking_groups')) {
                return (object) ['id' => 10, 'booking_type' => 'recurring', 'booking_reference' => 'BKG-3'];
            }
            return null;
        };
        $this->fake->results_handler = function ($sql) {
            return [
                (object) ['id' => 62, 'event_post_id' => 202],
                (object) ['id' => 61, 'event_post_id' => 201],
                (object) ['id' => 63, 'event_post_id' => 203],
            ];
        };

        $options = $this->service->get_group_cancel_options(61);

        $this->assertNotNull($options);
        $this->assertSame(3, $options['count']);
        $this->assertSame('BKG-3', $options['reference']);
        $this->assertEqualsCanonicalizing([61, 62, 63], $options['booking_ids']);
        $this->assertSame('2026-08-03 10:00:00', $options['sessions'][0]['start']);
        $this->assertTrue($options['sessions'][0]['is_current']);
        $this->assertFalse($options['sessions'][1]['is_current']);
    }
}
