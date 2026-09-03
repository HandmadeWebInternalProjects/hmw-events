<?php
/**
 * Tests for Stripe Service.
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\StripeService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test Stripe Service functionality.
 */
class StripeServiceTest extends TestCase
{
    /**
     * Service instance.
     *
     * @var StripeService
     */
    private $service;

    /**
     * Set up test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->service = new StripeService();

        // Define HMWEvents_ABSPATH if not defined
        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }
    }

    /**
     * Tear down test environment.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Test convert to cents.
     *
     * @covers \HMWEvents\Services\StripeService::convert_to_cents
     * @dataProvider amountProvider
     */
    public function test_convert_to_cents($dollars, $expected_cents)
    {
        // Use reflection to access private method
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('convert_to_cents');

        $result = $method->invoke($this->service, $dollars);

        $this->assertEquals($expected_cents, $result);
        $this->assertIsInt($result);
    }

    /**
     * Data provider for amount conversion.
     *
     * @return array
     */
    public function amountProvider()
    {
        return [
            'whole_dollar' => [100.00, 10000],
            'decimal' => [99.99, 9999],
            'deposit' => [150.50, 15050],
            'large_amount' => [1234.56, 123456],
            'zero' => [0.00, 0],
            'single_cent' => [0.01, 1],
            'rounding_up' => [10.555, 1056], // Should round to nearest cent
            'rounding_down' => [10.554, 1055],
        ];
    }

    /**
     * Test convert to dollars.
     *
     * @covers \HMWEvents\Services\StripeService::convert_to_dollars
     * @dataProvider centsProvider
     */
    public function test_convert_to_dollars($cents, $expected_dollars)
    {
        $result = $this->service->convert_to_dollars($cents);

        $this->assertEquals($expected_dollars, $result);
        $this->assertIsFloat($result);
    }

    /**
     * Data provider for cents conversion.
     *
     * @return array
     */
    public function centsProvider()
    {
        return [
            'whole_dollar' => [10000, 100.00],
            'decimal' => [9999, 99.99],
            'deposit' => [15050, 150.50],
            'large_amount' => [123456, 1234.56],
            'zero' => [0, 0.00],
            'single_cent' => [1, 0.01],
        ];
    }

    /**
     * Test initialization with key.
     *
     * @covers \HMWEvents\Services\StripeService::init_stripe_with_key
     */
    public function test_init_stripe_with_key()
    {
        $secret_key = 'sk_test_123456789';

        // Since we can't easily mock Stripe SDK classes,
        // we just test that the method doesn't throw errors
        // In a real integration test, we'd use a test API key
        
        $this->service->init_stripe_with_key($secret_key);

        // Get client should now return a client instance
        $client = $this->service->get_client();
        $this->assertInstanceOf(\Stripe\StripeClient::class, $client);
    }

    /**
     * Test initialization with empty key logs error.
     *
     * @covers \HMWEvents\Services\StripeService::init_stripe_with_key
     */
    public function test_init_stripe_with_empty_key_logs_error()
    {
        Functions\expect('error_log')
            ->once()
            ->with(Mockery::pattern('/Cannot initialize Stripe/'));

        $this->service->init_stripe_with_key('');

        $client = $this->service->get_client();
        $this->assertNull($client);
    }

    /**
     * Test get client returns null before initialization.
     *
     * @covers \HMWEvents\Services\StripeService::get_client
     */
    public function test_get_client_returns_null_before_init()
    {
        $service = new StripeService();
        $this->assertNull($service->get_client());
    }

    /**
     * Test create payment intent metadata.
     *
     * This is a conceptual test showing how to test payment intent creation
     * In a real test, you'd use Stripe's test mode or mock the Stripe client
     *
     * @covers \HMWEvents\Services\StripeService::create_payment_intent
     */
    public function test_create_payment_intent_includes_metadata()
    {
        // Mock Stripe client
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_payment_intents = Mockery::mock();

        $mock_stripe_client->paymentIntents = $mock_payment_intents;

        $expected_metadata = [
            'booking_group_id' => 123,
            'customer_post_id' => 456,
        ];

        $mock_payment_intent = new \stdClass();
        $mock_payment_intent->id = 'pi_test_123';
        $mock_payment_intent->amount = 45000;
        $mock_payment_intent->currency = 'aud';
        $mock_payment_intent->metadata = $expected_metadata;

        $mock_payment_intents->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) use ($expected_metadata) {
                return $params['amount'] === 45000
                    && $params['currency'] === 'aud'
                    && $params['metadata'] === $expected_metadata
                    && $params['automatic_payment_methods']['enabled'] === true
                    && $params['automatic_payment_methods']['allow_redirects'] === 'never';
            }))
            ->andReturn($mock_payment_intent);

        // Inject mock client
        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_payment_intent(450.00, 'AUD', $expected_metadata);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals('pi_test_123', $result->id);
        $this->assertEquals($expected_metadata, $result->metadata);
    }

    /**
     * Test create payment intent handles API errors.
     *
     * @covers \HMWEvents\Services\StripeService::create_payment_intent
     */
    public function test_create_payment_intent_handles_api_error()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_payment_intents = Mockery::mock();
        $mock_stripe_client->paymentIntents = $mock_payment_intents;

        $mock_payment_intents->shouldReceive('create')
            ->once()
            ->andThrow(Mockery::mock(\Stripe\Exception\ApiErrorException::class));

        Functions\expect('error_log')
            ->once();

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_payment_intent(450.00);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    /**
     * Test create and confirm payment.
     *
     * @covers \HMWEvents\Services\StripeService::create_and_confirm_payment
     */
    public function test_create_and_confirm_payment()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_payment_intents = Mockery::mock();
        $mock_stripe_client->paymentIntents = $mock_payment_intents;

        $mock_payment_intent = new \stdClass();
        $mock_payment_intent->id = 'pi_test_confirmed_123';
        $mock_payment_intent->status = 'succeeded';
        $mock_payment_intent->amount = 15000;

        $mock_payment_intents->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['amount'] === 15000
                    && $params['payment_method'] === 'pm_test_card'
                    && $params['confirm'] === true
                    && $params['automatic_payment_methods']['allow_redirects'] === 'never';
            }))
            ->andReturn($mock_payment_intent);

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_and_confirm_payment(150.00, 'pm_test_card');

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals('succeeded', $result->status);
    }

    /**
     * Test create and confirm payment handles card errors.
     *
     * @covers \HMWEvents\Services\StripeService::create_and_confirm_payment
     */
    public function test_create_and_confirm_payment_handles_card_error()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_payment_intents = Mockery::mock();
        $mock_stripe_client->paymentIntents = $mock_payment_intents;

        $mock_error = Mockery::mock();
        $mock_error->message = 'Your card was declined';
        
        $card_exception = Mockery::mock(\Stripe\Exception\CardException::class);
        $card_exception->shouldReceive('getError')->andReturn($mock_error);
        $card_exception->shouldReceive('getMessage')->andReturn('Card declined');

        $mock_payment_intents->shouldReceive('create')
            ->once()
            ->andThrow($card_exception);

        Functions\expect('error_log')->once();

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_and_confirm_payment(150.00, 'pm_test_card');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('card_error', $result->get_error_code());
    }

    /**
     * Test create refund with full amount.
     *
     * @covers \HMWEvents\Services\StripeService::create_refund
     */
    public function test_create_refund_full_amount()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_refunds = Mockery::mock();
        $mock_stripe_client->refunds = $mock_refunds;

        $mock_refund = new \stdClass();
        $mock_refund->id = 're_test_123';
        $mock_refund->amount = 45000;
        $mock_refund->status = 'succeeded';

        $mock_refunds->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['payment_intent'] === 'pi_test_123'
                    && $params['reason'] === 'requested_by_customer'
                    && !isset($params['amount']); // Full refund
            }))
            ->andReturn($mock_refund);

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_refund('pi_test_123');

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals('re_test_123', $result->id);
    }

    /**
     * Test create refund with partial amount.
     *
     * @covers \HMWEvents\Services\StripeService::create_refund
     */
    public function test_create_refund_partial_amount()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_refunds = Mockery::mock();
        $mock_stripe_client->refunds = $mock_refunds;

        $mock_refund = new \stdClass();
        $mock_refund->id = 're_test_partial_123';
        $mock_refund->amount = 10000; // $100

        $mock_refunds->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['payment_intent'] === 'pi_test_123'
                    && $params['amount'] === 10000; // $100 in cents
            }))
            ->andReturn($mock_refund);

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_refund('pi_test_123', 100.00);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals(10000, $result->amount);
    }

    /**
     * Test currency conversion to lowercase.
     *
     * @covers \HMWEvents\Services\StripeService::create_payment_intent
     */
    public function test_create_payment_intent_converts_currency_to_lowercase()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_payment_intents = Mockery::mock();
        $mock_stripe_client->paymentIntents = $mock_payment_intents;

        $mock_payment_intent = new \stdClass();
        $mock_payment_intent->id = 'pi_test_123';
        $mock_payment_intent->currency = 'aud';

        $mock_payment_intents->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['currency'] === 'aud'; // Lowercase
            }))
            ->andReturn($mock_payment_intent);

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_payment_intent(450.00, 'AUD'); // Uppercase input

        $this->assertEquals('aud', $result->currency);
    }

    /**
     * Test payment intent disables redirect payment methods.
     *
     * @covers \HMWEvents\Services\StripeService::create_payment_intent
     */
    public function test_payment_intent_disables_redirects()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_payment_intents = Mockery::mock();
        $mock_stripe_client->paymentIntents = $mock_payment_intents;

        $mock_payment_intent = new \stdClass();
        $mock_payment_intent->id = 'pi_test_123';

        $mock_payment_intents->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['automatic_payment_methods']['enabled'] === true
                    && $params['automatic_payment_methods']['allow_redirects'] === 'never';
            }))
            ->andReturn($mock_payment_intent);

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $this->service->create_payment_intent(450.00);

        $this->assertTrue(true); // Assertion is in the mock expectation
    }

    /**
     * Test create customer delegates to Stripe client.
     *
     * @covers \HMWEvents\Services\StripeService::create_customer
     */
    public function test_create_customer_delegates_to_stripe_client()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_customers = Mockery::mock();
        $mock_stripe_client->customers = $mock_customers;

        $mock_customer = new \stdClass();
        $mock_customer->id = 'cus_test_123';
        $mock_customer->email = 'test@example.com';

        $mock_customers->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['email'] === 'test@example.com'
                    && $params['name'] === 'John Doe'
                    && $params['metadata']['booking_group_id'] === 123;
            }))
            ->andReturn($mock_customer);

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_customer('test@example.com', [
            'name' => 'John Doe',
            'metadata' => ['booking_group_id' => 123],
        ]);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals('cus_test_123', $result->id);
        $this->assertEquals('test@example.com', $result->email);
    }

    /**
     * Test create customer email overrides data-provided email.
     *
     * @covers \HMWEvents\Services\StripeService::create_customer
     */
    public function test_create_customer_email_overrides_data_email()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_customers = Mockery::mock();
        $mock_stripe_client->customers = $mock_customers;

        $mock_customer = new \stdClass();
        $mock_customer->id = 'cus_test_456';
        $mock_customer->email = 'primary@example.com';

        $mock_customers->shouldReceive('create')
            ->once()
            ->with(Mockery::on(function ($params) {
                return $params['email'] === 'primary@example.com'
                    && $params['name'] === 'Jane Doe';
            }))
            ->andReturn($mock_customer);

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_customer('primary@example.com', [
            'email' => 'stale@example.com',
            'name' => 'Jane Doe',
        ]);

        $this->assertInstanceOf(\stdClass::class, $result);
        $this->assertEquals('primary@example.com', $result->email);
    }

    /**
     * Test create customer handles API errors.
     *
     * @covers \HMWEvents\Services\StripeService::create_customer
     */
    public function test_create_customer_handles_api_error()
    {
        $mock_stripe_client = Mockery::mock(\Stripe\StripeClient::class);
        $mock_customers = Mockery::mock();
        $mock_stripe_client->customers = $mock_customers;

        // Exception::getMessage() is final in PHP, so Mockery cannot override it.
        // Pass the message via the constructor so the real getMessage() returns it.
        $api_exception = Mockery::mock(\Stripe\Exception\ApiErrorException::class, ['Invalid email address']);

        $mock_customers->shouldReceive('create')
            ->once()
            ->andThrow($api_exception);

        Functions\expect('error_log')
            ->once()
            ->with(Mockery::pattern('/HMWEvents Stripe Error: Invalid email address/'));

        $reflection = new \ReflectionClass($this->service);
        $property = $reflection->getProperty('stripe');
        $property->setValue($this->service, $mock_stripe_client);

        $result = $this->service->create_customer('invalid-email');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('stripe_error', $result->get_error_code());
        $this->assertEquals('Invalid email address', $result->get_error_message());
    }
}
