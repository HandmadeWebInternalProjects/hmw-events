<?php

/**
 * Baseline tests for the booking group + per-row booking data model.
 *
 * Locks in the foundation the multi-session booking feature builds on:
 * one booking_groups row, N bookings rows with per-row booking_amount,
 * and the per-row booking_created action that currently triggers emails.
 *
 * @package HMWEvents\Tests\Unit\Services\Gateways
 */

namespace HMWEvents\Tests\Unit\Services\Gateways;

use HMWEvents\Services\Gateways\AbstractPaymentGateway;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class BookingGroupRowsTest extends TestCase
{
    private AbstractPaymentGateway $gateway;

    private array $inserts = [];
    private array $queries = [];
    private array $actions = [];
    private int $next_insert_id = 100;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $test = $this;

        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->last_error = '';
        $wpdb->insert_id = 0;
        $wpdb->shouldReceive('insert')->andReturnUsing(
            function ($table, $data, $formats) use ($test) {
                $test->record_insert($table, $data, $formats);
                $test->bump_insert_id();
                return 1;
            }
        );
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('query')->andReturnUsing(function ($sql) use ($test) {
            $test->record_query($sql);
            return 1;
        });
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('wp_generate_password')->justReturn('AB12CD34');
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('do_action')->alias(function (...$args) use ($test) {
            $test->record_action($args);
        });

        $this->gateway = new class extends AbstractPaymentGateway {
            public function get_gateway_id()
            {
                return 'test';
            }

            public function get_gateway_name()
            {
                return 'Test Gateway';
            }

            public function get_gateway_client()
            {
                return null;
            }

            public function is_available()
            {
                return true;
            }

            public function register()
            {
            }

            public function process_booking($booking_data)
            {
                return [];
            }

            public function process_payment_with_confirmation($booking_data)
            {
                return [];
            }

            public function confirm_payment($transaction_id)
            {
                return [];
            }

            public function get_payment_status($transaction_id)
            {
                return [];
            }

            public function create_or_get_customer($email, $data = [])
            {
                return null;
            }

            public function refund_payment($transaction_id, $amount = null, $reason = '')
            {
                return [];
            }

            public function verify_webhook_signature($payload, $signature)
            {
                return true;
            }

            public function call_create_booking_group($data)
            {
                return $this->create_booking_group($data);
            }

            public function call_create_booking($data)
            {
                return $this->create_booking($data);
            }

            public function call_update_course_availability($event_id, $change)
            {
                return $this->update_course_availability($event_id, $change);
            }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function record_insert($table, $data, $formats): void
    {
        $this->inserts[] = ['table' => $table, 'data' => $data, 'formats' => $formats];
    }

    public function record_query($sql): void
    {
        $this->queries[] = $sql;
    }

    public function record_action($args): void
    {
        $this->actions[] = $args;
    }

    public function bump_insert_id(): void
    {
        $GLOBALS['wpdb']->insert_id = $this->next_insert_id++;
    }

    // ---------------------------------------------------------------
    // create_booking_group
    // ---------------------------------------------------------------

    public function test_create_booking_group_defaults_to_single_type()
    {
        $group_id = $this->gateway->call_create_booking_group([
            'booking_reference' => 'BKG-TEST0001',
            'customer_post_id'  => 77,
            'payment_type'      => 'full',
            'total_amount'      => 150.0,
        ]);

        $this->assertSame(100, $group_id);

        $insert = $this->inserts[0];
        $this->assertStringContainsString('booking_groups', $insert['table']);
        $this->assertSame('single', $insert['data']['booking_type']);
        $this->assertSame(1, $insert['data']['total_bookings']);
        $this->assertNull($insert['data']['metadata']);
        $this->assertSame('pending', $insert['data']['payment_status']);
    }

    public function test_create_booking_group_accepts_recurring_type_and_total_bookings()
    {
        $this->gateway->call_create_booking_group([
            'booking_reference' => 'BKG-TEST0002',
            'customer_post_id'  => 77,
            'booking_type'      => 'recurring',
            'payment_type'      => 'full',
            'total_courses'     => 8,
            'total_amount'      => 450.0,
            'metadata'          => ['series_root' => 6660, 'slot' => '11:15:00'],
        ]);

        $insert = $this->inserts[0];
        $this->assertSame('recurring', $insert['data']['booking_type']);
        $this->assertSame(8, $insert['data']['total_bookings']);
        $this->assertSame('{"series_root":6660,"slot":"11:15:00"}', $insert['data']['metadata']);
    }

    // ---------------------------------------------------------------
    // create_booking — per-row amounts and the created action
    // ---------------------------------------------------------------

    public function test_create_booking_persists_per_row_amount()
    {
        $booking_id = $this->gateway->call_create_booking([
            'booking_group_id'   => 42,
            'booking_number'     => 'CB-20260101-AAA',
            'event_post_id'      => 6661,
            'customer_post_id'   => 77,
            'ticket_type'        => 'adult',
            'booking_amount'     => 45.0,
            'status'             => 'confirmed',
            'payment_status'     => 'paid',
        ]);

        $this->assertSame(100, $booking_id);

        $insert = $this->inserts[0];
        $this->assertStringContainsString('bookings', $insert['table']);
        $this->assertSame(45.0, $insert['data']['booking_amount']);
        $this->assertSame(6661, $insert['data']['event_post_id']);
        $this->assertSame('confirmed', $insert['data']['status']);
        $this->assertSame(1, $insert['data']['ticket_quantity']);
    }

    public function test_create_booking_fires_created_action_once_per_row()
    {
        $row_data = [
            'booking_group_id' => 42,
            'booking_number'   => 'CB-20260101-AAA',
            'event_post_id'    => 6661,
            'customer_post_id' => 77,
            'ticket_type'      => 'adult',
            'booking_amount'   => 0.0,
        ];

        $this->gateway->call_create_booking($row_data);
        $this->gateway->call_create_booking($row_data);

        $this->assertCount(2, $this->actions);
        $this->assertSame('hmwevents_booking_created', $this->actions[0][0]);
        $this->assertSame(100, $this->actions[0][1]);
        $this->assertSame('hmwevents_booking_created', $this->actions[1][0]);
        $this->assertSame(101, $this->actions[1][1]);
    }

    public function test_create_booking_can_suppress_created_action_for_extra_session_rows()
    {
        $row_data = [
            'booking_group_id'        => 42,
            'booking_number'          => 'CB-20260101-AAA',
            'event_post_id'           => 6662,
            'customer_post_id'        => 77,
            'ticket_type'             => 'adult',
            'booking_amount'          => 0.0,
            'suppress_created_action' => true,
        ];

        $this->gateway->call_create_booking($row_data);
        $this->gateway->call_create_booking($row_data);

        $this->assertSame([], $this->actions);
        $this->assertCount(2, $this->inserts);
    }

    // ---------------------------------------------------------------
    // update_course_availability
    // ---------------------------------------------------------------

    public function test_update_course_availability_clamps_decrement_at_zero()
    {
        $this->gateway->call_update_course_availability(6660, -1);

        $sql = $this->queries[0];
        $this->assertStringContainsString('GREATEST(0, booked_count + %d)', $sql);
        $this->assertStringContainsString('GREATEST(0, available_count - %d)', $sql);
        $this->assertStringContainsString('event_availability', $sql);
    }
}
