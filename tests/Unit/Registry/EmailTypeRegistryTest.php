<?php

namespace HMWEvents\Tests\Unit\Registry;

use HMWEvents\Registry\EmailTypeRegistry;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EmailTypeRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_all_returns_expected_thirteen_types(): void
    {
        $expected = [
            'booking_confirmation',
            'new_booking_notify',
            'payment_received',
            'booking_cancelled',
            'reminder_7_days',
            'reminder_1_day',
            'post_event',
            'invitation_sent',
            'waitlist_joined',
            'waitlist_promotion',
            'invoice_issued',
            'refund_issued',
            'event_changed',
        ];

        $this->assertSame($expected, array_keys(EmailTypeRegistry::all()));
    }

    public function test_all_labels_are_non_empty_strings(): void
    {
        foreach (EmailTypeRegistry::all() as $label) {
            $this->assertIsString($label);
            $this->assertNotSame('', $label);
        }
    }

    public function test_keys_matches_all_keys(): void
    {
        $this->assertSame(array_keys(EmailTypeRegistry::all()), EmailTypeRegistry::keys());
    }

    public function test_is_valid_accepts_canonical_keys(): void
    {
        $this->assertTrue(EmailTypeRegistry::is_valid('booking_confirmation'));
        $this->assertTrue(EmailTypeRegistry::is_valid('event_changed'));
        $this->assertTrue(EmailTypeRegistry::is_valid('waitlist_promotion'));
    }

    public function test_is_valid_rejects_legacy_alias_and_unknown_keys(): void
    {
        $this->assertFalse(EmailTypeRegistry::is_valid('course_changed'));
        $this->assertFalse(EmailTypeRegistry::is_valid('not_a_type'));
    }

    public function test_canonical_resolves_legacy_alias(): void
    {
        $this->assertSame('event_changed', EmailTypeRegistry::canonical('course_changed'));
        $this->assertSame('event_changed', EmailTypeRegistry::canonical('event_changed'));
    }

    public function test_canonical_passes_unknown_keys_through(): void
    {
        $this->assertSame('not_a_type', EmailTypeRegistry::canonical('not_a_type'));
    }

    public function test_is_legacy_identifies_aliases_only(): void
    {
        $this->assertTrue(EmailTypeRegistry::is_legacy('course_changed'));
        $this->assertFalse(EmailTypeRegistry::is_legacy('event_changed'));
        $this->assertFalse(EmailTypeRegistry::is_legacy('not_a_type'));
    }

    public function test_label_returns_label_for_canonical_key(): void
    {
        $this->assertSame(
            EmailTypeRegistry::all()['booking_confirmation'],
            EmailTypeRegistry::label('booking_confirmation')
        );
    }

    public function test_label_resolves_legacy_key_through_alias(): void
    {
        $this->assertSame(
            EmailTypeRegistry::label('event_changed'),
            EmailTypeRegistry::label('course_changed')
        );
    }

    public function test_label_falls_back_to_raw_key_for_unknown_types(): void
    {
        $this->assertSame('not_a_type', EmailTypeRegistry::label('not_a_type'));
    }
}
