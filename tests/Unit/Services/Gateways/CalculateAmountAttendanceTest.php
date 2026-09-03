<?php

namespace HMWEvents\Tests\Unit\Services\Gateways;

use HMWEvents\Services\Gateways\AbstractPaymentGateway;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class CalculateAmountAttendanceTest extends TestCase
{
    private AttendanceGateway $gateway;

    private array $fieldValues;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }

        $this->fieldValues = [
            '_event_price'     => null,
            '_event_deposit'   => null,
            '_event_surcharge' => null,
        ];

        $test = $this;
        Functions\when('get_post_meta')->alias(function ($event_id, $meta_key, $single = true) use ($test) {
            return $test->fieldValues[$meta_key] ?? null;
        });
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_type' => 'hmw_event'];
        });
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        $wpdb = Mockery::mock();
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_row')->andReturn(null)->byDefault();
        $GLOBALS['wpdb'] = $wpdb;

        $this->gateway = new AttendanceGateway();
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_option_price_replaces_base_price(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_surcharge'] = 5;

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(
            (object) ['id' => 2, 'option_type' => 'couple', 'label' => 'Couple', 'price' => '650.00', 'capacity' => null]
        );

        $result = $this->gateway->calculateAmount3(123, false, 'couple');

        $this->assertIsArray($result);
        $this->assertSame(655.0, $result['amount']);
        $this->assertSame(650.0, $result['base_amount']);
        $this->assertSame(5.0, $result['surcharge']);
        $this->assertSame('full', $result['payment_type']);
    }

    public function test_falls_back_to_base_price_when_no_option_resolves(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_surcharge'] = 5;

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(null);

        $result = $this->gateway->calculateAmount3(123, false, 'individual');

        $this->assertIsArray($result);
        $this->assertSame(105.0, $result['amount']);
        $this->assertSame(100.0, $result['base_amount']);
    }

    public function test_free_option_with_zero_price_is_not_rejected(): void
    {
        $this->fieldValues['_event_price']     = '';
        $this->fieldValues['_event_surcharge'] = 0;

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(
            (object) ['id' => 1, 'option_type' => 'individual', 'label' => 'Individual', 'price' => '0.00', 'capacity' => null]
        );

        $result = $this->gateway->calculateAmount3(123, false, 'individual');

        $this->assertIsArray($result);
        $this->assertSame(0.0, $result['amount']);
        $this->assertSame(0.0, $result['base_amount']);
    }

    public function test_missing_price_still_errors_without_option(): void
    {
        $this->fieldValues['_event_price']     = '';
        $this->fieldValues['_event_surcharge'] = 5;

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(null);

        $result = $this->gateway->calculateAmount3(123, false, 'individual');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_course', $result->get_error_code());
    }
}

class AttendanceGateway extends AbstractPaymentGateway
{
    public function get_gateway_id(): string
    {
        return 'test';
    }

    public function get_gateway_name(): string
    {
        return 'Test Gateway';
    }

    public function get_gateway_client()
    {
        return null;
    }

    public function is_available(): bool
    {
        return true;
    }

    public function register(): void
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
        return true;
    }

    public function get_payment_status($transaction_id)
    {
        return [];
    }

    public function create_or_get_customer($email, $data = [])
    {
        return 'cus_test';
    }

    public function refund_payment($transaction_id, $amount = null, $reason = '')
    {
        return [];
    }

    public function verify_webhook_signature($payload, $signature)
    {
        return true;
    }

    public function calculateAmount3($event_id, $is_deposit = false, $attendance_type = '')
    {
        return $this->calculate_amount($event_id, $is_deposit, $attendance_type);
    }
}
