<?php

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\EmailEventHooks;
use HMWEvents\Services\Emails\EmailService;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EmailEventHooksReceiptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html_e')->justReturn('');
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('do_action')->justReturn(null);
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('get_option')->justReturn(false);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_on_payment_received_queues_booking_confirmation(): void
    {
        $booking_id = 123;

        $emailServiceMock = Mockery::mock(EmailService::class);
        $emailServiceMock->shouldReceive('queue_booking_confirmation')
            ->once()
            ->with($booking_id)
            ->andReturn(456);

        $hooks = new EmailEventHooks();

        $reflection = new \ReflectionProperty(EmailEventHooks::class, 'email_service');
        $reflection->setAccessible(true);
        $reflection->setValue($hooks, $emailServiceMock);

        $hooks->on_payment_received($booking_id, [
            'payment_status' => 'paid',
            'amount'         => 100.00,
        ]);

        $this->assertTrue(true);
    }

    public function test_on_payment_received_called_without_data_extra_param(): void
    {
        $booking_id = 42;

        $emailServiceMock = Mockery::mock(EmailService::class);
        $emailServiceMock->shouldReceive('queue_booking_confirmation')
            ->once()
            ->with($booking_id)
            ->andReturn(99);

        $hooks = new EmailEventHooks();

        $reflection = new \ReflectionProperty(EmailEventHooks::class, 'email_service');
        $reflection->setAccessible(true);
        $reflection->setValue($hooks, $emailServiceMock);

        $hooks->on_payment_received($booking_id, []);

        $this->assertTrue(true);
    }

    public function test_constructor_initializes_email_service(): void
    {
        $hooks = new EmailEventHooks();

        $service = $hooks->get_email_service();

        $this->assertInstanceOf(EmailService::class, $service);
    }

    public function test_register_hooks_payment_received_action(): void
    {
        $registered_hooks = [];

        Functions\when('add_action')->alias(function ($tag, $callback, $priority, $accepted_args) use (&$registered_hooks) {
            $registered_hooks[] = ['tag' => $tag, 'priority' => $priority, 'accepted_args' => $accepted_args];
            return true;
        });

        $hooks = new EmailEventHooks();
        $hooks->register();

        $payment_hooks = array_filter($registered_hooks, function ($h) {
            return $h['tag'] === 'hmwevents_payment_received';
        });

        $this->assertCount(1, $payment_hooks, 'hmwevents_payment_received should be registered');
        $payment_hook = reset($payment_hooks);
        $this->assertSame(10, $payment_hook['priority']);
        $this->assertSame(2, $payment_hook['accepted_args']);
    }
}
