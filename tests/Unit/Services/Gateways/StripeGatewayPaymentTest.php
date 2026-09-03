<?php

namespace HMWEvents\Tests\Unit\Services\Gateways;

use HMWEvents\Services\Gateways\StripePaymentGateway;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class StripeGatewayPaymentTest extends TestCase
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
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_post')->alias(function ($id) {
            $post = new \WP_Post((object) ['ID' => (int) $id, 'post_type' => 'hmw_event', 'post_author' => 1, 'post_title' => 'Test Event']);
            $post->post_type = 'hmw_event';
            $post->post_author = 1;
            $post->post_title = 'Test Event';
            return $post;
        });
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('wp_verify_nonce')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_is_available_returns_false_with_no_keys(): void
    {
        $gateway = new StripePaymentGateway();

        $this->assertFalse($gateway->is_available());
    }

    public function test_get_gateway_id_returns_stripe(): void
    {
        $gateway = new StripePaymentGateway();

        $this->assertSame('stripe', $gateway->get_gateway_id());
    }

    public function test_get_gateway_name_returns_stripe(): void
    {
        $gateway = new StripePaymentGateway();

        $this->assertSame('Stripe', $gateway->get_gateway_name());
    }

    public function test_get_supported_currencies_includes_aud(): void
    {
        $gateway = new StripePaymentGateway();

        $currencies = $gateway->get_supported_currencies();

        $this->assertContains('AUD', $currencies);
    }

    public function test_key_info_returns_expected_structure(): void
    {
        $gateway = new StripePaymentGateway();

        $info = $gateway->get_key_info();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('source', $info);
        $this->assertArrayHasKey('has_secret_key', $info);
        $this->assertArrayHasKey('has_publishable_key', $info);
        $this->assertArrayHasKey('is_test_mode', $info);
        $this->assertArrayHasKey('organizer_id', $info);
    }

    public function test_default_organizer_id_is_null(): void
    {
        $gateway = new StripePaymentGateway();

        $this->assertNull($gateway->get_organizer_id());
    }

    public function test_organizer_id_set_via_constructor(): void
    {
        $gateway = new StripePaymentGateway(42);

        $this->assertSame(42, $gateway->get_organizer_id());
    }

    public function test_set_organizer_id_method(): void
    {
        $gateway = new StripePaymentGateway();

        $gateway->set_organizer_id(99);

        $this->assertSame(99, $gateway->get_organizer_id());
    }

    public function test_publishable_key_returns_empty_without_keys(): void
    {
        $gateway = new StripePaymentGateway();

        $key = $gateway->get_publishable_key();

        $this->assertSame('', $key);
    }
}
