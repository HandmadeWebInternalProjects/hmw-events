<?php

namespace HMWEvents\Tests\Unit\Api\Routes;

use HMWEvents\Api\Routes\BookingActions;
use Mockery;
use Patchwork;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class MarkAsPaidTest extends TestCase
{
    /** @var object */
    private $mockWpdb;

    /** @var object */
    private $mockBooking;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }

        $this->mockWpdb = Mockery::mock();
        $this->mockWpdb->prefix = 'wp_';
        $this->mockWpdb->posts = 'wp_posts';
        $this->mockWpdb->rows_affected = 1;
        $this->mockWpdb->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
            $flat = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    foreach ($arg as $v) {
                        $flat[] = $v;
                    }
                } else {
                    $flat[] = $arg;
                }
            }
            $result = $query;
            foreach ($flat as $v) {
                $pos = strpos($result, '%s');
                if ($pos !== false) {
                    $result = substr_replace($result, "'{$v}'", $pos, 2);
                    continue;
                }
                $pos = strpos($result, '%d');
                if ($pos !== false) {
                    $result = substr_replace($result, (int) $v, $pos, 2);
                }
            }

            return $result;
        });

        $GLOBALS['wpdb'] = $this->mockWpdb;

        $this->mockBooking = (object) [
            'id'                      => 42,
            'booking_number'          => 'CB-20260710-XXXX',
            'payment_status'          => 'pending',
            'status'                  => 'confirmed',
            'event_post_id'           => 100,
            'deleted_at'              => null,
            'booking_group_id'        => 10,
            'transaction_id'          => 5,
            'gateway_transaction_id'  => 'manual_CB-20260710-XXXX',
            'post_author'             => 1,
        ];

        $table_names = [
            'bookings'           => 'wp_hmwevents_bookings',
            'payment_transactions' => 'wp_hmwevents_payment_transactions',
            'booking_groups'     => 'wp_hmwevents_booking_groups',
            'booking_history'    => 'wp_hmwevents_booking_history',
        ];
        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) use ($table_names) {
            return $table_names[$name] ?? '';
        });
        Patchwork\replace('HMWEvents\\Registry\\EventTypeRegistry::is_registration_disabled', function () { return false; });
        Patchwork\replace('HMWEvents\\Registry\\EventTypeRegistry::is_external_registration', function () { return false; });

        Functions\when('register_rest_route')->justReturn(true);
        Functions\when('rest_url')->justReturn('https://example.com/wp-json/');
        Functions\when('sanitize_key')->alias(function ($key) {
            return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $key));
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-07-10 12:00:00');
        Functions\when('is_user_logged_in')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('do_action')->justReturn(true);
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');

        $user = (object) ['display_name' => 'Admin User'];
        Functions\when('wp_get_current_user')->justReturn($user);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeRequest(int $bookingId): \WP_REST_Request
    {
        $request = new \WP_REST_Request('POST', '/hmwevents/v1/booking/mark-as-paid');
        $request->set_param('booking_id', $bookingId);

        return $request;
    }

    private function createActions(): BookingActions
    {
        return new BookingActions();
    }

    public function test_mark_as_paid_succeeds_for_pending_manual_booking(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);

        $this->mockWpdb->shouldReceive('get_row')->andReturn($this->mockBooking);
        $this->mockWpdb->shouldReceive('query')->andReturn(true);
        $this->mockWpdb->shouldReceive('update')->andReturn(1);
        $this->mockWpdb->shouldReceive('insert')->andReturn(1);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['success']);
        $this->assertStringContainsString('successfully', $data['message']);
    }

    public function test_mark_as_paid_accepts_failed_status(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);

        $booking = clone $this->mockBooking;
        $booking->payment_status = 'failed';

        $this->mockWpdb->shouldReceive('get_row')->andReturn($booking);
        $this->mockWpdb->shouldReceive('query')->andReturn(true);
        $this->mockWpdb->shouldReceive('update')->andReturn(1);
        $this->mockWpdb->shouldReceive('insert')->andReturn(1);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['success']);
    }

    public function test_booking_not_found_returns_404(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);

        $this->mockWpdb->shouldReceive('get_row')->andReturn(null);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('booking_not_found', $response->get_error_code());
    }

    public function test_non_manual_booking_is_rejected(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);

        $booking = clone $this->mockBooking;
        $booking->gateway_transaction_id = 'pi_3abc123xyz';

        $this->mockWpdb->shouldReceive('get_row')->andReturn($booking);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('invalid_booking', $response->get_error_code());
    }

    public function test_empty_gateway_id_is_rejected(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);

        $booking = clone $this->mockBooking;
        $booking->gateway_transaction_id = '';

        $this->mockWpdb->shouldReceive('get_row')->andReturn($booking);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('invalid_booking', $response->get_error_code());
    }

    public function test_already_paid_booking_is_rejected(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);

        $booking = clone $this->mockBooking;
        $booking->payment_status = 'paid';

        $this->mockWpdb->shouldReceive('get_row')->andReturn($booking);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('invalid_status', $response->get_error_code());
    }

    public function test_refunded_booking_is_rejected(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);

        $booking = clone $this->mockBooking;
        $booking->payment_status = 'refunded';

        $this->mockWpdb->shouldReceive('get_row')->andReturn($booking);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('invalid_status', $response->get_error_code());
    }

    public function test_non_owner_without_cap_is_rejected(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(99);

        $this->mockWpdb->shouldReceive('get_row')->andReturn($this->mockBooking);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('rest_forbidden', $response->get_error_code());
    }

    public function test_admin_can_mark_any_booking(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('get_current_user_id')->justReturn(99);

        $this->mockWpdb->shouldReceive('get_row')->andReturn($this->mockBooking);
        $this->mockWpdb->shouldReceive('query')->andReturn(true);
        $this->mockWpdb->shouldReceive('update')->andReturn(1);
        $this->mockWpdb->shouldReceive('insert')->andReturn(1);

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $data = $response->get_data();
        $this->assertTrue($data['success']);
    }

    public function test_transaction_rollback_on_exception(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('get_current_user_id')->justReturn(1);

        $this->mockWpdb->shouldReceive('get_row')->andReturn($this->mockBooking);
        $this->mockWpdb->shouldReceive('query')->andReturn(true);
        $this->mockWpdb->shouldReceive('update')->andThrow(new \Exception('DB failure'));

        $actions = $this->createActions();
        $response = $actions->mark_as_paid($this->makeRequest(42));

        $this->assertInstanceOf(\WP_Error::class, $response);
        $this->assertSame('mark_as_paid_failed', $response->get_error_code());
    }
}
