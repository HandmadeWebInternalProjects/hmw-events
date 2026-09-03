<?php

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\Handlers\NotificationHandler;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Patchwork;

class NotificationHandlerTest extends TestCase
{
    /** @var \Mockery\MockInterface */
    private $mockWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $this->mockWpdb = Mockery::mock();
        $this->mockWpdb->prefix = 'wp_';
        $this->mockWpdb->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
            return $query;
        });
        $this->mockWpdb->shouldReceive('update')->andReturn(1);
        $this->mockWpdb->shouldReceive('get_row')->andReturn(null);
        $this->mockWpdb->shouldReceive('get_col')->andReturn([]);
        $GLOBALS['wpdb'] = $this->mockWpdb;

        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('error_log')->justReturn(true);
        Functions\when('do_action')->justReturn(true);
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('');
        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeHandlerWithInsertSpy(&$captured): NotificationHandler
    {
        $handler = new NotificationHandler();

        $repo = Mockery::mock(\HMWEvents\Services\Emails\EmailQueueRepository::class)->makePartial();
        $repo->shouldReceive('add')
            ->once()
            ->andReturnUsing(function ($data) use (&$captured) {
                $captured = $data;
                return 55;
            });

        $ref = new \ReflectionClass($handler);
        $prop = $ref->getProperty('queue_repo');
        $prop->setAccessible(true);
        $prop->setValue($handler, $repo);

        return $handler;
    }

    public function test_queue_notification_accepts_known_type(): void
    {
        $captured = null;
        $handler = $this->makeHandlerWithInsertSpy($captured);

        $result = $handler->queue_notification([
            'email_type'      => 'invitation_sent',
            'recipient_email' => 'person@example.com',
            'template_data'   => ['first_name' => 'Sam', 'event_title' => 'Course'],
        ]);

        $this->assertSame(55, $result);
        $this->assertSame('invitation_sent', $captured['email_type']);
        $this->assertNull($captured['booking_id']);
    }

    public function test_queue_notification_rejects_unknown_type(): void
    {
        $handler = new NotificationHandler();
        $result = $handler->queue_notification([
            'email_type'      => 'not_a_real_type',
            'recipient_email' => 'person@example.com',
        ]);

        $this->assertFalse($result);
    }

    public function test_queue_notification_rejects_empty_recipient(): void
    {
        $handler = new NotificationHandler();
        $result = $handler->queue_notification([
            'email_type'      => 'invoice_issued',
            'recipient_email' => '',
        ]);

        $this->assertFalse($result);
    }

    public function test_send_renders_builtin_default_when_no_template_row(): void
    {
        $email = (object) [
            'id'             => 9,
            'booking_id'     => null,
            'organizer_id'   => null,
            'recipient_email' => 'person@example.com',
            'template_key'   => 'invitation_sent',
            'email_type'     => 'invitation_sent',
            'template_data'  => json_encode([
                'first_name'       => 'Sam',
                'event_title'      => 'Parent Course',
                'registration_url' => 'https://example.com/register?token=abc',
            ]),
        ];

        $sentBody = null;

        $repo = Mockery::mock(\HMWEvents\Services\Emails\EmailQueueRepository::class)->makePartial();
        $repo->shouldReceive('mark_processing')->once()->with(9);
        $repo->shouldReceive('mark_sent')->once()->with(9);

        $handler = new NotificationHandler();

        $ref = new \ReflectionClass($handler);
        $prop = $ref->getProperty('queue_repo');
        $prop->setAccessible(true);
        $prop->setValue($handler, $repo);

        Functions\when('wp_mail')->alias(function ($to, $subject, $body) use (&$sentBody) {
            $sentBody = $body;
            return true;
        });

        $result = $handler->send($email);

        $this->assertTrue($result);
        $this->assertStringContainsString('https://example.com/register?token=abc', $sentBody);
    }
}
