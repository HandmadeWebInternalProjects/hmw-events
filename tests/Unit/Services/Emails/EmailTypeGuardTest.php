<?php

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\EmailEventHooks;
use HMWEvents\Services\Emails\Handlers\StatusChangeHandler;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class EmailTypeGuardTest extends TestCase
{
    public array $queued = [];
    public array $cancelled = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';

        $test = $this;
        $this->queued = [];
        $this->cancelled = [];

        Functions\when('__')->returnArg();
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $default;
        });
        Functions\when('error_log')->justReturn(true);
        Functions\when('do_action')->justReturn(null);
        Functions\when('get_the_title')->justReturn('Test Event');
        Functions\when('get_permalink')->justReturn('https://example.com/event');
        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        Patchwork\replace('HMWEvents\Services\Emails\EmailService::__construct', function () {
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::queue_notification', function ($data) use ($test) {
            $test->queued[] = ['method' => 'queue_notification', 'data' => $data];
            return 1;
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::queue_status_change_email', function ($booking_id, $status_type, $additional_data = []) use ($test) {
            $test->queued[] = ['method' => 'queue_status_change_email', 'booking_id' => $booking_id, 'status_type' => $status_type, 'data' => $additional_data];
            return 1;
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::cancel_pending_emails', function ($booking_id, $exclude_types = [], $reason = '') use ($test) {
            $test->cancelled[] = ['booking_id' => $booking_id, 'exclude_types' => $exclude_types];
            return 1;
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invokeIsEmailEnabled(string $email_type): bool
    {
        $hooks = new EmailEventHooks();

        $reflection = new \ReflectionClass(EmailEventHooks::class);
        $method = $reflection->getMethod('is_email_enabled');

        return $method->invoke($hooks, $email_type);
    }

    private function disableOption(array $types): void
    {
        Functions\when('get_option')->alias(function ($name, $default = false) use ($types) {
            if ($name === 'hmwevents_disabled_emails') {
                return $types;
            }
            return $default;
        });
    }

    public function test_event_changed_guard_respects_disabled_option(): void
    {
        $this->disableOption(['event_changed']);

        $this->assertFalse($this->invokeIsEmailEnabled('event_changed'));
        $this->assertFalse($this->invokeIsEmailEnabled('course_changed'));
    }

    public function test_legacy_option_value_matches_canonical_request(): void
    {
        $this->disableOption(['course_changed']);

        $this->assertFalse($this->invokeIsEmailEnabled('event_changed'));
    }

    public function test_unknown_email_types_fail_open(): void
    {
        $this->disableOption(['not_a_type']);

        $this->assertTrue($this->invokeIsEmailEnabled('not_a_type'));
    }

    public function test_waitlist_promotion_is_decoupled_from_invitation_sent(): void
    {
        $this->disableOption(['invitation_sent']);

        $entry = (object) ['recipient_email' => 'parent@example.com', 'position' => 2];
        (new EmailEventHooks())->on_v2_waitlist_promoted($entry, 12);

        $this->assertCount(1, $this->queued);
        $this->assertSame('waitlist_promotion', $this->queued[0]['data']['email_type']);
    }

    public function test_disabled_waitlist_promotion_suppresses_promotion_email(): void
    {
        $this->disableOption(['waitlist_promotion']);

        $entry = (object) ['recipient_email' => 'parent@example.com', 'position' => 2];
        (new EmailEventHooks())->on_v2_waitlist_promoted($entry, 12);

        $this->assertSame([], $this->queued);
    }

    public function test_invoice_issued_guard_suppresses_invoice_email(): void
    {
        $this->disableOption(['invoice_issued']);

        (new EmailEventHooks())->on_v2_invoice_created(12, ['amount' => 100], ['meta' => ['registrant_email' => 'parent@example.com']]);

        $this->assertSame([], $this->queued);
    }

    public function test_enabled_invoice_issued_queues_invoice_email(): void
    {
        (new EmailEventHooks())->on_v2_invoice_created(12, ['amount' => 100], ['meta' => ['registrant_email' => 'parent@example.com']]);

        $this->assertCount(1, $this->queued);
        $this->assertSame('invoice_issued', $this->queued[0]['data']['email_type']);
    }

    public function test_disabled_refund_issued_still_cancels_pending_emails(): void
    {
        $this->disableOption(['refund_issued']);

        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturn('prepared-sql');
        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn((object) ['currency' => 'AUD']);

        (new EmailEventHooks())->on_refund_issued(55, 50.0, 'change of plans');

        $this->assertSame([], $this->queued);
        $this->assertCount(1, $this->cancelled);
        $this->assertContains('refund_issued', $this->cancelled[0]['exclude_types']);
    }

    public function test_enabled_refund_issued_queues_refund_email(): void
    {
        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturn('prepared-sql');
        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn((object) ['currency' => 'AUD']);

        (new EmailEventHooks())->on_refund_issued(55, 50.0, 'change of plans');

        $this->assertCount(1, $this->queued);
        $this->assertSame('queue_status_change_email', $this->queued[0]['method']);
        $this->assertSame(StatusChangeHandler::TYPE_REFUND_ISSUED, $this->queued[0]['status_type']);
    }

    public function test_disabled_event_changed_suppresses_change_emails(): void
    {
        $this->disableOption(['event_changed']);

        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturn('prepared-sql');
        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn([(object) ['id' => 7]]);

        (new EmailEventHooks())->on_event_changed(12, ['event_start_date' => '2026-09-01'], []);

        $this->assertSame([], $this->queued);
    }

    public function test_enabled_event_changed_queues_status_change_email(): void
    {
        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturn('prepared-sql');
        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn([(object) ['id' => 7]]);

        (new EmailEventHooks())->on_event_changed(12, ['event_start_date' => '2026-09-01'], []);

        $this->assertCount(1, $this->queued);
        $this->assertSame(7, $this->queued[0]['booking_id']);
        $this->assertSame(StatusChangeHandler::TYPE_EVENT_CHANGED, $this->queued[0]['status_type']);
    }
}
