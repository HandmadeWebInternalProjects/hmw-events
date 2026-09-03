<?php

namespace HMWEvents\Tests\Unit\Services\Gateways;

use HMWEvents\Services\Gateways\AbstractPaymentGateway;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class BookingDataValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }

        Functions\when('__')->returnArg();
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Minimal concrete gateway exposing validate_booking_data for testing.
     */
    private function createGateway(): ValidationTestGateway
    {
        return new ValidationTestGateway();
    }

    public function test_valid_booking_data_passes_validation(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'customer_name'    => 'Test Customer',
            'registrant_email' => 'test@example.com',
            'event_id'         => 123,
            'organizer_id'     => 1,
        ];

        $result = $gateway->validateBookingData($data);

        $this->assertTrue($result);
    }

    public function test_missing_customer_name_returns_error(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'registrant_email' => 'test@example.com',
            'event_id'         => 123,
            'organizer_id'     => 1,
        ];

        $result = $gateway->validateBookingData($data);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_field', $result->get_error_code());
    }

    public function test_missing_email_returns_error(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'customer_name' => 'Test Customer',
            'event_id'      => 123,
            'organizer_id'  => 1,
        ];

        $result = $gateway->validateBookingData($data);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_field', $result->get_error_code());
    }

    public function test_missing_event_id_returns_error(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'customer_name'    => 'Test Customer',
            'registrant_email' => 'test@example.com',
            'organizer_id'     => 1,
        ];

        $result = $gateway->validateBookingData($data);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_field', $result->get_error_code());
    }

    public function test_missing_organizer_id_returns_error(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'customer_name'    => 'Test Customer',
            'registrant_email' => 'test@example.com',
            'event_id'         => 123,
        ];

        $result = $gateway->validateBookingData($data);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_field', $result->get_error_code());
    }

    public function test_additional_required_fields_are_validated(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'customer_name'    => 'Test Customer',
            'registrant_email' => 'test@example.com',
            'event_id'         => 123,
            'organizer_id'     => 1,
        ];

        $result = $gateway->validateBookingData($data, ['payment_method_id']);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_field', $result->get_error_code());
    }

    public function test_additional_required_fields_present_passes(): void
    {
        $gateway = $this->createGateway();

        $data = [
            'customer_name'      => 'Test Customer',
            'registrant_email'   => 'test@example.com',
            'event_id'           => 123,
            'organizer_id'       => 1,
            'payment_method_id'  => 'pm_card_visa',
        ];

        $result = $gateway->validateBookingData($data, ['payment_method_id']);

        $this->assertTrue($result);
    }
}

/**
 * Concrete gateway exposing the protected validate_booking_data for testing.
 */
class ValidationTestGateway extends AbstractPaymentGateway
{
    public function get_gateway_id(): string
    {
        return 'test';
    }

    public function get_gateway_name(): string
    {
        return 'Test Gateway';
    }

    public function get_gateway_client(): mixed
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

    public function process_booking($booking_data): array|\WP_Error
    {
        return [];
    }

    public function process_payment_with_confirmation($booking_data): array|\WP_Error
    {
        return [];
    }

    public function confirm_payment($transaction_id): bool|\WP_Error
    {
        return true;
    }

    public function get_payment_status($transaction_id): array|\WP_Error
    {
        return [];
    }

    public function create_or_get_customer($email, $data = []): string|\WP_Error
    {
        return 'cus_test';
    }

    public function refund_payment($transaction_id, $amount = null, $reason = ''): array|\WP_Error
    {
        return [];
    }

    public function verify_webhook_signature($payload, $signature): bool|\WP_Error
    {
        return true;
    }

    public function validateBookingData($booking_data, $required_fields = []): true|\WP_Error
    {
        return $this->validate_booking_data($booking_data, $required_fields);
    }
}
