<?php

/**
 * Baseline tests for the booking_created email trigger.
 *
 * Documents today's behaviour: one confirmation + one organizer
 * notification + one reminder schedule PER booking row. The multi-session
 * booking feature must produce a single confirmation per group instead —
 * these tests pin the trigger so that change is caught if it regresses.
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

class BookingCreatedTriggerTest extends TestCase
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
        Functions\when('get_option')->alias(function ($name, $default = false) {
            return $default;
        });
        Functions\when('error_log')->justReturn(true);
        Functions\when('do_action')->justReturn(null);

        Patchwork\replace('HMWEvents\Services\Emails\EmailService::__construct', function () {
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::queue_booking_confirmation', function ($booking_id, $data = []) use ($test) {
            $test->queued[] = ['type' => 'confirmation', 'booking_id' => $booking_id];
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

    public function test_booking_created_queues_one_confirmation_per_booking()
    {
        $hooks = new EmailEventHooks();
        $hooks->on_booking_created(55, []);

        $this->assertContains('confirmation', $this->types());
        $this->assertSame(55, $this->queued[0]['booking_id']);
        $this->assertEquals(
            1,
            count(array_filter($this->queued, fn($q) => $q['type'] === 'confirmation'))
        );
    }

    public function test_booking_created_also_notifies_organizer_and_schedules_reminders()
    {
        $hooks = new EmailEventHooks();
        $hooks->on_booking_created(55, []);

        $this->assertEquals(
            1,
            count(array_filter($this->queued, fn($q) => $q['type'] === 'organizer'))
        );
        $this->assertEquals(
            1,
            count(array_filter($this->queued, fn($q) => $q['type'] === 'reminder' && $q['days'] === 7))
        );
        $this->assertEquals(
            1,
            count(array_filter($this->queued, fn($q) => $q['type'] === 'reminder' && $q['days'] === 1))
        );
        $this->assertEquals(
            1,
            count(array_filter($this->queued, fn($q) => $q['type'] === 'post_event'))
        );
    }

    public function test_disabled_confirmation_still_notifies_organizer()
    {
        Functions\when('get_option')->alias(function ($name, $default = false) {
            if ($name === 'hmwevents_disabled_emails') {
                return ['booking_confirmation'];
            }
            return $default;
        });

        $hooks = new EmailEventHooks();
        $hooks->on_booking_created(55, []);

        $this->assertNotContains('confirmation', $this->types());
        $this->assertContains('organizer', $this->types());
    }

    public function test_three_booking_rows_would_queue_three_confirmations_today()
    {
        $hooks = new EmailEventHooks();

        $hooks->on_booking_created(61, []);
        $hooks->on_booking_created(62, []);
        $hooks->on_booking_created(63, []);

        $confirmations = array_filter($this->queued, fn($q) => $q['type'] === 'confirmation');
        $this->assertCount(3, $confirmations);
        $this->assertSame(
            [61, 62, 63],
            array_values(array_column($confirmations, 'booking_id'))
        );
    }
}
