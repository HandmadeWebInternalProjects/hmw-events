<?php

/**
 * Tests for Phase 2 multi-session booking edges:
 * - track extension when new sessions are added to a series
 * - cancellation of active rows when a session is deleted
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

if (!class_exists('FakeSyncWpdb')) {
    class FakeSyncWpdb
    {
        public $prefix = 'wp_';
        public $insert_id = 500;
        public $last_error = '';
        public array $inserts = [];
        public array $updates = [];
        public array $queries = [];
        public $results_handler;
        public $var_handler;
        public $row_handler;

        public function prepare($query, ...$args)
        {
            if (count($args) === 1 && is_array($args[0])) {
                $args = $args[0];
            }
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

        public function get_results($sql)
        {
            return ($this->results_handler)($sql);
        }

        public function get_var($sql)
        {
            return ($this->var_handler)($sql);
        }

        public function get_row($sql)
        {
            return ($this->row_handler)($sql);
        }

        public function insert($table, $data, $formats = [])
        {
            $this->inserts[] = ['table' => $table, 'data' => $data];
            $this->insert_id++;
            return 1;
        }

        public function update($table, $data, $where)
        {
            $this->updates[] = ['table' => $table, 'data' => $data, 'where' => $where];
            return 1;
        }

        public function query($sql)
        {
            $this->queries[] = $sql;
            return 1;
        }
    }
}

class SessionBookingSyncTest extends TestCase
{
    private SessionBookingService $service;
    private FakeSyncWpdb $fake_wpdb;
    public array $actions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $test = $this;

        $this->fake_wpdb = new FakeSyncWpdb();
        $this->fake_wpdb->results_handler = function ($sql) {
            if (str_contains($sql, 'event_post_id IN')) {
                return [
                    (object) [
                        'booking_group_id'     => 900,
                        'registrant_post_id'   => 77,
                        'ticket_quantity'      => 2,
                        'attendance_option_id' => null,
                        'ticket_type'          => 'full',
                        'status'               => 'confirmed',
                        'payment_status'       => 'paid',
                    ],
                    (object) [
                        'booking_group_id'     => 901,
                        'registrant_post_id'   => 78,
                        'ticket_quantity'      => 1,
                        'attendance_option_id' => null,
                        'ticket_type'          => 'full',
                        'status'               => 'confirmed',
                        'payment_status'       => 'paid',
                    ],
                ];
            }
            if (str_contains($sql, 'event_post_id =')) {
                return [
                    (object) ['id' => 61, 'ticket_quantity' => 1],
                    (object) ['id' => 62, 'ticket_quantity' => 3],
                ];
            }
            return [];
        };
        $this->fake_wpdb->row_handler = function ($sql) {
            if (str_contains($sql, 'booking_groups')) {
                if (str_contains($sql, 'id = 901')) {
                    return (object) [
                        'booking_type'    => 'package',
                        'payment_status'  => 'paid',
                        'metadata'        => json_encode(['mode' => 'individual', 'series_root' => 100]),
                    ];
                }
                return (object) [
                    'booking_type'    => $GLOBALS['__sync_group_type'] ?? 'recurring',
                    'payment_status'  => $GLOBALS['__sync_group_payment'] ?? 'paid',
                    'metadata'        => json_encode(['mode' => $GLOBALS['__sync_group_mode'] ?? 'track', 'slot' => $GLOBALS['__sync_group_slot'] ?? '11:15:00', 'series_root' => 100]),
                ];
            }
            return null;
        };
        $this->fake_wpdb->var_handler = function ($sql) {
            if (str_contains($sql, 'WHERE booking_group_id') && str_contains($sql, 'event_post_id')) {
                return $GLOBALS['__sync_existing_row'] ?? null;
            }
            return null;
        };

        $GLOBALS['wpdb'] = $this->fake_wpdb;

        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_option')->justReturn(false);
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('wp_generate_password')->justReturn('ZZ9P9Z');
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('do_action')->alias(function (...$args) use ($test) {
            $test->actions[] = $args;
        });

        Functions\when('get_post')->alias(function ($id) {
            $posts = [
                100 => ['post_type' => 'hmw_event', 'post_parent' => 0, 'post_title' => 'Series Parent'],
                101 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'Session A'],
                104 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'Session D'],
                105 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'New Session'],
                106 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'Lone Slot Session'],
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
                104 => '2026-08-10 11:15:00',
                105 => '2026-08-24 11:15:00',
                106 => '2026-08-31 13:00:00',
            ];
            if ($key === '_event_start_date') {
                return $starts[$post_id] ?? '';
            }
            if ($key === '_event_end_date') {
                return '';
            }
            if ($key === '_event_price') {
                return $post_id === 100 ? '150' : '';
            }
            return '';
        });

        Patchwork\replace('HMWEvents\Services\Hooks::get_all_sessions', function ($event) {
            return array_map(fn($id) => get_post($id), [101, 104]);
        });
        Patchwork\replace('HMWEvents\Services\CapacityService::get_capacity', function ($id) {
            return 20;
        });
        Patchwork\replace('HMWEvents\Services\CapacityService::get_remaining_places', function ($id) {
            return 20;
        });

        $this->service = new SessionBookingService();
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['__sync_group_mode'],
            $GLOBALS['__sync_group_type'],
            $GLOBALS['__sync_group_payment'],
            $GLOBALS['__sync_group_slot'],
            $GLOBALS['__sync_existing_row']
        );
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sync_creates_booking_row_for_matching_track_group()
    {
        $created = $this->service->sync_track_bookings_to_session(105);

        $this->assertSame(1, $created);

        $booking_inserts = array_filter($this->fake_wpdb->inserts, fn($i) => str_contains($i['table'], 'bookings') && !str_contains($i['table'], 'history') && !str_contains($i['table'], 'groups'));
        $booking_inserts = array_values($booking_inserts);
        $this->assertCount(1, $booking_inserts);
        $this->assertSame(105, $booking_inserts[0]['data']['event_post_id']);
        $this->assertSame(900, $booking_inserts[0]['data']['booking_group_id']);
        $this->assertSame(0.0, $booking_inserts[0]['data']['booking_amount']);
        $this->assertSame('track_extension', $booking_inserts[0]['data']['booking_source']);

        $availability_updates = array_filter($this->fake_wpdb->queries, fn($q) => str_contains($q, 'event_availability'));
        $this->assertCount(1, $availability_updates);
    }

    public function test_sync_skips_non_recurring_groups()
    {
        $GLOBALS['__sync_group_type'] = 'package';

        $this->assertSame(0, $this->service->sync_track_bookings_to_session(105));
        $this->assertSame([], $this->fake_wpdb->inserts);
    }

    public function test_sync_skips_groups_with_different_slot()
    {
        $GLOBALS['__sync_group_slot'] = '12:45:00';

        $this->assertSame(0, $this->service->sync_track_bookings_to_session(105));
        $this->assertSame([], $this->fake_wpdb->inserts);
    }

    public function test_sync_skips_refunded_groups()
    {
        $GLOBALS['__sync_group_payment'] = 'refunded';

        $this->assertSame(0, $this->service->sync_track_bookings_to_session(105));
        $this->assertSame([], $this->fake_wpdb->inserts);
    }

    public function test_sync_is_idempotent_when_row_already_exists()
    {
        $GLOBALS['__sync_existing_row'] = 777;

        $this->assertSame(0, $this->service->sync_track_bookings_to_session(105));
        $this->assertSame([], $this->fake_wpdb->inserts);
    }

    public function test_sync_does_nothing_when_no_siblings_share_the_slot()
    {
        $this->assertSame(0, $this->service->sync_track_bookings_to_session(106));
        $this->assertSame([], $this->fake_wpdb->inserts);
    }

    public function test_cancel_bookings_cancels_active_rows_and_fires_action()
    {
        $cancelled = $this->service->cancel_bookings_for_session(101, 'Session removed by organizer');

        $this->assertSame(2, $cancelled);

        $cancel_updates = array_filter($this->fake_wpdb->updates, fn($u) => ($u['data']['status'] ?? '') === 'cancelled');
        $this->assertCount(2, $cancel_updates);

        $cancel_actions = array_filter($this->actions, fn($a) => $a[0] === 'hmwevents_booking_cancelled');
        $this->assertCount(2, $cancel_actions);
    }

    public function test_cancel_bookings_does_nothing_without_active_rows()
    {
        $this->fake_wpdb->results_handler = function () {
            return [];
        };

        $this->assertSame(0, $this->service->cancel_bookings_for_session(101));
        $this->assertSame([], $this->actions);
    }
}
