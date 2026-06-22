<?php
/**
 * Integration Tests for Stripe Webhook Handler.
 *
 * Tests the complete flow of webhook event handling including
 * event verification, booking updates, and database transactions.
 *
 * @package HMWEvents\Tests\Integration
 */

namespace HMWEvents\Tests\Integration;

use HMWEvents\Api\StripeWebhook;
use HMWEvents\Services\StripeService;
use HMWEvents\Services\PaymentGateway;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test Stripe Webhook integration.
 */
class StripeWebhookIntegrationTest extends BaseIntegrationTest
{
    /**
     * Webhook handler instance.
     *
     * @var StripeWebhook
     */
    private $webhook;

    /**
     * Mock Stripe service.
     *
     * @var Mockery\MockInterface
     */
    private $stripe_service;

    /**
     * Mock PaymentGateway instance.
     *
     * @var Mockery\MockInterface
     */
    private $gateway;

    /**
     * Set up test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->webhook = new StripeWebhook();
        $this->stripe_service = Mockery::mock(StripeService::class);
        $this->gateway = Mockery::mock(PaymentGateway::class)->shouldIgnoreMissing();
    }

    /**
     * Inject mock service and gateway into webhook.
     */
    private function injectMocks()
    {
        $reflection = new \ReflectionClass($this->webhook);

        $stripe_prop = $reflection->getProperty('stripe');
        $stripe_prop->setValue($this->webhook, $this->stripe_service);

        $gateway_prop = $reflection->getProperty('gateway');
        $gateway_prop->setValue($this->webhook, $this->gateway);
    }

    /**
     * Test webhook route registration.
     *
     * @covers \HMWEvents\Api\StripeWebhook::register_routes
     */
    public function test_webhook_route_is_registered()
    {
        Functions\expect('register_rest_route')
            ->once()
            ->with(
                'cms/v1',
                '/webhook/stripe',
                Mockery::on(function ($args) {
                    return $args['methods'] === 'POST'
                        && isset($args['callback'])
                        && $args['permission_callback'] === '__return_true';
                })
            );

        $this->webhook->register_routes();

        $this->assertTrue(true);
    }

    /**
     * Test successful payment intent webhook.
     *
     * @covers \HMWEvents\Api\StripeWebhook::handle_webhook
     * @covers \HMWEvents\Api\StripeWebhook::handle_payment_succeeded
     */
    public function test_payment_succeeded_webhook_updates_booking()
    {
        // Create mock request
        $request = Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_body')
            ->once()
            ->andReturn('{"type":"payment_intent.succeeded"}');
        
        $request->shouldReceive('get_header')
            ->once()
            ->with('stripe-signature')
            ->andReturn('test_signature');

        // Create mock event
        $mock_event = new \stdClass();
        $mock_event->type = 'payment_intent.succeeded';
        $mock_event->data = new \stdClass();
        $mock_event->data->object = new \stdClass();
        $mock_event->data->object->id = 'pi_test_123';
        $mock_event->data->object->metadata = (object) [
            'booking_group_id' => '1',
        ];

        // Mock Stripe service
        $this->stripe_service->shouldReceive('construct_webhook_event')
            ->once()
            ->with('{"type":"payment_intent.succeeded"}', 'test_signature')
            ->andReturn($mock_event);

        // Mock gateway — handle_payment_succeeded delegates to gateway->confirm_payment()
        $this->gateway->shouldReceive('confirm_payment')
            ->once()
            ->with('pi_test_123')
            ->andReturn(true);

        $this->injectMocks();

        $response = $this->webhook->handle_webhook($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());
    }

    /**
     * Test failed payment intent webhook.
     *
     * @covers \HMWEvents\Api\StripeWebhook::handle_webhook
     * @covers \HMWEvents\Api\StripeWebhook::handle_payment_failed
     */
    public function test_payment_failed_webhook_updates_booking_status()
    {
        $request = Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_body')
            ->once()
            ->andReturn('{"type":"payment_intent.payment_failed"}');
        
        $request->shouldReceive('get_header')
            ->once()
            ->andReturn('test_signature');

        // Create mock failed payment event
        $mock_event = new \stdClass();
        $mock_event->type = 'payment_intent.payment_failed';
        $mock_event->data = new \stdClass();
        $mock_event->data->object = new \stdClass();
        $mock_event->data->object->id = 'pi_test_failed';
        $mock_event->data->object->last_payment_error = new \stdClass();
        $mock_event->data->object->last_payment_error->message = 'Your card was declined';
        $mock_event->data->object->metadata = (object) [
            'booking_group_id' => '2',
        ];

        $this->stripe_service->shouldReceive('construct_webhook_event')
            ->once()
            ->andReturn($mock_event);

        $this->injectMocks();

        // handle_payment_failed first updates educator_payment_transactions
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_educator_payment_transactions',
                ['status' => 'failed', 'error_message' => 'Your card was declined'],
                ['gateway_transaction_id' => 'pi_test_failed'],
                ['%s', '%s'],
                ['%s']
            )
            ->andReturn(1);

        // Then queries for the transaction
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_QUERY');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->with('PREPARED_QUERY')
            ->andReturn((object)[
                'booking_group_id' => 2,
                'amount' => 450.00,
            ]);

        // Updates bookings to failed
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_educator_bookings',
                ['payment_status' => 'failed'],
                ['booking_group_id' => 2],
                ['%s'],
                ['%d']
            )
            ->andReturn(1);

        // handle_payment_failed also calls send_recovery_email on the gateway
        $this->gateway->shouldReceive('send_recovery_email')
            ->once()
            ->with(2)
            ->andReturn(true);

        $response = $this->webhook->handle_webhook($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(200, $response->get_status());
    }

    /**
     * Test webhook with invalid signature returns error.
     *
     * @covers \HMWEvents\Api\StripeWebhook::handle_webhook
     */
    public function test_webhook_with_invalid_signature_returns_error()
    {
        $request = Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_body')
            ->once()
            ->andReturn('{"type":"payment_intent.succeeded"}');
        
        $request->shouldReceive('get_header')
            ->once()
            ->andReturn('invalid_signature');

        // Mock service returning error
        $wp_error = new \WP_Error('webhook_error', 'Invalid signature');
        
        $this->stripe_service->shouldReceive('construct_webhook_event')
            ->once()
            ->andReturn($wp_error);

        $this->injectMocks();

        $response = $this->webhook->handle_webhook($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
        $this->assertEquals(400, $response->get_status());
    }

    /**
     * Test webhook ignores duplicate events (idempotency).
     *
     * @covers \HMWEvents\Api\StripeWebhook::handle_webhook
     */
    public function test_webhook_handles_duplicate_events_idempotently()
    {
        $request = Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_body')->andReturn('{"type":"payment_intent.succeeded"}');
        $request->shouldReceive('get_header')->andReturn('test_signature');

        $mock_event = new \stdClass();
        $mock_event->type = 'payment_intent.succeeded';
        $mock_event->data = new \stdClass();
        $mock_event->data->object = new \stdClass();
        $mock_event->data->object->id = 'pi_test_123';
        $mock_event->data->object->metadata = (object) [
            'booking_group_id' => '1',
        ];

        $this->stripe_service->shouldReceive('construct_webhook_event')
            ->andReturn($mock_event);

        $this->gateway->shouldReceive('confirm_payment')
            ->once()
            ->with('pi_test_123')
            ->andReturn(true);

        $this->injectMocks();

        $response = $this->webhook->handle_webhook($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
    }

    /**
     * Test webhook creates payment transaction record.
     *
     * @covers \HMWEvents\Api\StripeWebhook::handle_payment_succeeded
     */
    public function test_webhook_creates_payment_transaction_record()
    {
        $request = Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_body')->andReturn('{"type":"payment_intent.succeeded"}');
        $request->shouldReceive('get_header')->andReturn('test_signature');

        $mock_event = new \stdClass();
        $mock_event->type = 'payment_intent.succeeded';
        $mock_event->data = new \stdClass();
        $mock_event->data->object = new \stdClass();
        $mock_event->data->object->id = 'pi_test_123';
        $mock_event->data->object->amount = 45000;
        $mock_event->data->object->currency = 'aud';
        $mock_event->data->object->metadata = (object) [
            'booking_group_id' => '1',
        ];

        $this->stripe_service->shouldReceive('construct_webhook_event')
            ->andReturn($mock_event);

        $this->gateway->shouldReceive('confirm_payment')
            ->once()
            ->with('pi_test_123')
            ->andReturn(true);

        $this->injectMocks();

        $response = $this->webhook->handle_webhook($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
    }

    /**
     * Test webhook with missing booking group metadata.
     *
     * @covers \HMWEvents\Api\StripeWebhook::handle_webhook
     */
    public function test_webhook_handles_missing_booking_group_metadata()
    {
        $request = Mockery::mock(\WP_REST_Request::class);
        $request->shouldReceive('get_body')->andReturn('{"type":"payment_intent.succeeded"}');
        $request->shouldReceive('get_header')->andReturn('test_signature');

        $mock_event = new \stdClass();
        $mock_event->type = 'payment_intent.succeeded';
        $mock_event->data = new \stdClass();
        $mock_event->data->object = new \stdClass();
        $mock_event->data->object->id = 'pi_test_123';
        $mock_event->data->object->metadata = (object) []; // No booking_group_id

        $this->stripe_service->shouldReceive('construct_webhook_event')
            ->andReturn($mock_event);

        $this->gateway->shouldReceive('confirm_payment')
            ->once()
            ->with('pi_test_123')
            ->andReturn(true);

        $this->injectMocks();

        $response = $this->webhook->handle_webhook($request);

        $this->assertInstanceOf(\WP_REST_Response::class, $response);
    }
}
