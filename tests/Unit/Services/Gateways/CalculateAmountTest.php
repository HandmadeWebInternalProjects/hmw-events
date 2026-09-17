<?php

namespace HMWEvents\Tests\Unit\Services\Gateways;

use HMWEvents\Services\Gateways\AbstractPaymentGateway;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class CalculateAmountTest extends TestCase
{
    private ConcreteGateway $gateway;

    private array $fieldValues;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }

        $this->fieldValues = [
            '_event_price'         => null,
            '_event_deposit'       => null,
            '_event_surcharge'     => null,
            '_event_surcharge_type' => null,
        ];

        $test = $this;
        Functions\when('get_post_meta')->alias(function ($event_id, $meta_key, $single = true) use ($test) {
            $value = $test->fieldValues[$meta_key] ?? null;
            // CourseMeta::get_course_currency mock
            if ($meta_key === 'currency') {
                return 'AUD';
            }
            return $value;
        });
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_type' => 'hmw_event'];
        });
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });

        $this->gateway = new ConcreteGateway();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_full_payment_includes_surcharge(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123);

        $this->assertIsArray($result);
        $this->assertSame(105.0, $result['amount']);
        $this->assertSame(100.0, $result['base_amount']);
        $this->assertSame(5.0, $result['surcharge']);
        $this->assertSame('full', $result['payment_type']);
    }

    public function test_deposit_payment_includes_surcharge(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_deposit']   = 50;
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123, true);

        $this->assertIsArray($result);
        $this->assertSame(55.0, $result['amount']);
        $this->assertSame(50.0, $result['base_amount']);
        $this->assertSame(5.0, $result['surcharge']);
        $this->assertSame('deposit', $result['payment_type']);
    }

    public function test_zero_surcharge_returns_only_base_price(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_surcharge'] = 0;

        $result = $this->gateway->calculateAmount(123);

        $this->assertIsArray($result);
        $this->assertSame(100.0, $result['amount']);
        $this->assertSame(100.0, $result['base_amount']);
        $this->assertSame(0.0, $result['surcharge']);
    }

    public function test_percent_surcharge_applied_to_full_payment(): void
    {
        $this->fieldValues['_event_price']         = 100;
        $this->fieldValues['_event_surcharge']     = 2;
        $this->fieldValues['_event_surcharge_type'] = 'percent';

        $result = $this->gateway->calculateAmount(123);

        $this->assertIsArray($result);
        $this->assertSame(102.0, $result['amount']);
        $this->assertSame(100.0, $result['base_amount']);
        $this->assertSame(2.0, $result['surcharge']);
        $this->assertSame('full', $result['payment_type']);
    }

    public function test_percent_surcharge_calculated_on_deposit_amount(): void
    {
        $this->fieldValues['_event_price']         = 100;
        $this->fieldValues['_event_deposit']       = 50;
        $this->fieldValues['_event_surcharge']     = 2;
        $this->fieldValues['_event_surcharge_type'] = 'percent';

        $result = $this->gateway->calculateAmount(123, true);

        $this->assertIsArray($result);
        $this->assertSame(51.0, $result['amount']);
        $this->assertSame(50.0, $result['base_amount']);
        $this->assertSame(1.0, $result['surcharge']);
        $this->assertSame('deposit', $result['payment_type']);
    }

    public function test_percent_surcharge_rounds_to_two_decimals(): void
    {
        $this->fieldValues['_event_price']         = 33.33;
        $this->fieldValues['_event_surcharge']     = 3;
        $this->fieldValues['_event_surcharge_type'] = 'percent';

        $result = $this->gateway->calculateAmount(123);

        $this->assertIsArray($result);
        $this->assertSame(1.0, $result['surcharge']);
        $this->assertSame(34.33, $result['amount']);
        $this->assertSame(33.33, $result['base_amount']);
    }

    public function test_surcharge_type_null_behaves_as_flat(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123);

        $this->assertIsArray($result);
        $this->assertSame(105.0, $result['amount']);
        $this->assertSame(100.0, $result['base_amount']);
        $this->assertSame(5.0, $result['surcharge']);
    }

    public function test_surcharge_with_no_deposit_available(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_deposit']   = 0;
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123, true);

        $this->assertIsArray($result);
        $this->assertSame(105.0, $result['amount']);
        $this->assertSame(100.0, $result['base_amount']);
        $this->assertSame(5.0, $result['surcharge']);
        $this->assertSame('full', $result['payment_type']);
    }

    public function test_missing_price_returns_error(): void
    {
        $this->fieldValues['_event_price']     = '';
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_course', $result->get_error_code());
    }

    public function test_surcharge_field_not_set_returns_zero_surcharge(): void
    {
        $this->fieldValues['_event_price'] = 100;

        $result = $this->gateway->calculateAmount(123);

        $this->assertIsArray($result);
        $this->assertSame(100.0, $result['amount']);
        $this->assertSame(100.0, $result['base_amount']);
        $this->assertSame(0.0, $result['surcharge']);
    }

    public function test_currency_is_included_in_result(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123);

        $this->assertArrayHasKey('currency', $result);
        $this->assertSame('AUD', $result['currency']);
    }

    public function test_payment_type_is_full_when_not_deposit(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_deposit']   = 50;
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123, false);

        $this->assertIsArray($result);
        $this->assertSame('full', $result['payment_type']);
    }

    public function test_payment_type_is_deposit_when_deposit(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_deposit']   = 50;
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123, true);

        $this->assertIsArray($result);
        $this->assertSame('deposit', $result['payment_type']);
    }

    public function test_null_price_returns_error(): void
    {
        $this->fieldValues['_event_price'] = null;

        $result = $this->gateway->calculateAmount(123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_course', $result->get_error_code());
    }

    public function test_result_contains_all_required_keys(): void
    {
        $this->fieldValues['_event_price']     = 100;
        $this->fieldValues['_event_surcharge'] = 5;

        $result = $this->gateway->calculateAmount(123);

        $this->assertArrayHasKey('amount', $result);
        $this->assertArrayHasKey('base_amount', $result);
        $this->assertArrayHasKey('surcharge', $result);
        $this->assertArrayHasKey('payment_type', $result);
        $this->assertArrayHasKey('currency', $result);
    }
}

/**
 * Minimal concrete gateway for testing the protected calculate_amount() method.
 */
class ConcreteGateway extends AbstractPaymentGateway
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

    public function calculateAmount($event_id, $is_deposit = false)
    {
        return $this->calculate_amount($event_id, $is_deposit);
    }
}
