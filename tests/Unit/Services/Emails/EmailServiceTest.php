<?php

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\EmailService;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EmailServiceTest extends TestCase
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
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('error_log')->justReturn(true);
        Functions\when('get_option')->justReturn(false);

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
    }

    protected function tearDown(): void
    {
        \Patchwork\restoreAll();
        unset($GLOBALS['wpdb']);
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mockBooking(int $event_id = 9, int $customer_id = 5): object
    {
        return (object) [
            'booking_number'     => 'BN-123',
            'booking_amount'     => 100.00,
            'event_post_id'      => $event_id,
            'registrant_post_id' => $customer_id,
            'booking_group_id'   => 7,
        ];
    }

    private function setWpdb(?object $booking): void
    {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
            return $query;
        });
        $wpdb->shouldReceive('get_row')->andReturn($booking);
        $GLOBALS['wpdb'] = $wpdb;
    }

    private function mockService(): \Mockery\MockInterface
    {
        return Mockery::mock(EmailService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
    }

    public function test_queue_payment_receipt_queues_payment_received_notification(): void
    {
        $this->setWpdb($this->mockBooking());

        \Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function () {
            return 'wp_hmwevents_bookings';
        });

        Functions\when('get_post_meta')->alias(function ($id, $key, $single = true) {
            $map = [
                'registrant_email'      => 'jane@example.com',
                'registrant_first_name' => 'Jane',
            ];
            return $map[$key] ?? '';
        });
        Functions\when('get_post')->justReturn((object) ['post_author' => 3]);
        Functions\when('get_the_title')->justReturn('Calmbirth Weekend Course');

        $service = $this->mockService();
        $service->shouldReceive('queue_notification')
            ->once()
            ->with(Mockery::on(function ($data) {
                return $data['email_type'] === 'payment_received'
                    && $data['recipient_email'] === 'jane@example.com'
                    && $data['booking_id'] === 123
                    && $data['organizer_id'] === 3
                    && $data['template_data']['event_title'] === 'Calmbirth Weekend Course'
                    && $data['template_data']['amount'] === '100.00';
            }))
            ->andReturn(456);
        $service->shouldReceive('attach_receipt_pdf')
            ->once()
            ->with(456, 7)
            ->andReturnNull();

        $result = $service->queue_payment_receipt(123);

        $this->assertSame(456, $result);
    }

    public function test_queue_payment_receipt_returns_false_when_booking_missing(): void
    {
        $this->setWpdb(null);

        \Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function () {
            return 'wp_hmwevents_bookings';
        });

        $service = $this->mockService();
        $service->shouldReceive('queue_notification')->never();
        $service->shouldReceive('attach_receipt_pdf')->never();

        $this->assertFalse($service->queue_payment_receipt(123));
    }

    public function test_queue_payment_receipt_returns_false_when_no_email(): void
    {
        $this->setWpdb($this->mockBooking());

        \Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function () {
            return 'wp_hmwevents_bookings';
        });

        Functions\when('get_post_meta')->justReturn('');

        $service = $this->mockService();
        $service->shouldReceive('queue_notification')->never();
        $service->shouldReceive('attach_receipt_pdf')->never();

        $this->assertFalse($service->queue_payment_receipt(123));
    }
}
