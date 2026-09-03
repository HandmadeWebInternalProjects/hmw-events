<?php

/**
 * Tests for the multi-session group booking email trigger.
 *
 * Locks in the requirement that a group booking with N session rows queues
 * exactly ONE attendee confirmation (listing all sessions) and ONE organizer
 * notification, while reminders are still scheduled per session row.
 *
 * @package HMWEvents\Tests\Unit\Services\Emails
 */

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\EmailEventHooks;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class GroupBookingConfirmationTriggerTest extends TestCase
{
    public array $queued = [];

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

        Functions\when('__')->returnArg();
        Functions\when('get_option')->justReturn(false);
        Functions\when('error_log')->justReturn(true);
        Functions\when('do_action')->justReturn(null);

        Patchwork\replace('HMWEvents\Services\Emails\EmailService::__construct', function () {
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::queue_group_booking_confirmation', function ($primary_id, $booking_ids, $data = []) use ($test) {
            $test->queued[] = ['type' => 'group_confirmation', 'booking_ids' => $booking_ids];
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::queue_organizer_new_booking', function ($booking_id, $data = []) use ($test) {
            $test->queued[] = ['type' => 'organizer', 'booking_id' => $booking_id];
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::queue_course_reminder', function ($booking_id, $days = 7, $data = []) use ($test) {
            $test->queued[] = ['type' => 'reminder', 'booking_id' => $booking_id, 'days' => $days];
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::queue_post_course_email', function ($booking_id, $days = 1, $data = []) use ($test) {
            $test->queued[] = ['type' => 'post_event', 'booking_id' => $booking_id, 'days' => $days];
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function types(): array
    {
        return array_column($this->queued, 'type');
    }

    public function test_three_session_group_queues_exactly_one_confirmation()
    {
        $hooks = new EmailEventHooks();
        $hooks->on_group_booking_created(900, [61, 62, 63], 61, []);

        $confirmations = array_values(array_filter($this->queued, fn($q) => $q['type'] === 'group_confirmation'));
        $this->assertCount(1, $confirmations);
        $this->assertSame([61, 62, 63], $confirmations[0]['booking_ids']);
        $this->assertNotContains('confirmation', $this->types());
    }

    public function test_group_booking_notifies_organizer_once()
    {
        $hooks = new EmailEventHooks();
        $hooks->on_group_booking_created(900, [61, 62, 63], 61, []);

        $organizer = array_values(array_filter($this->queued, fn($q) => $q['type'] === 'organizer'));
        $this->assertCount(1, $organizer);
        $this->assertSame(61, $organizer[0]['booking_id']);
    }

    public function test_reminders_are_scheduled_per_session_row()
    {
        $hooks = new EmailEventHooks();
        $hooks->on_group_booking_created(900, [61, 62, 63], 61, []);

        $reminders = array_values(array_filter($this->queued, fn($q) => $q['type'] === 'reminder'));
        $reminder_bookings = array_unique(array_column($reminders, 'booking_id'));

        $this->assertCount(6, $reminders);
        $this->assertEqualsCanonicalizing([61, 62, 63], $reminder_bookings);
    }

    public function test_disabled_confirmation_skips_group_email()
    {
        Functions\when('get_option')->alias(function ($name, $default = false) {
            if ($name === 'hmwevents_disabled_emails') {
                return ['booking_confirmation'];
            }
            return $default;
        });

        $hooks = new EmailEventHooks();
        $hooks->on_group_booking_created(900, [61], 61, []);

        $this->assertNotContains('group_confirmation', $this->types());
        $this->assertContains('organizer', $this->types());
    }
}
