<?php

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\EmailEventHooks;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EmailNotificationSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }

        Functions\when('__')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('error_log')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function getTestableInstance(): EmailEventHooks
    {
        return new class extends EmailEventHooks {
            public function __construct()
            {
                // Skip parent constructor to avoid deep dependency chain
                // (EmailService, repos, handlers all require global $wpdb)
            }
        };
    }

    private function invokeIsEmailEnabled(string $email_type): bool
    {
        $hooks = $this->getTestableInstance();

        $reflection = new \ReflectionClass(EmailEventHooks::class);
        $method = $reflection->getMethod('is_email_enabled');
        $method->setAccessible(true);

        return $method->invoke($hooks, $email_type);
    }

    public function test_is_email_enabled_returns_true_when_not_disabled(): void
    {
        Functions\when('get_option')
            ->alias(function ($option, $default = false) {
                if ($option === 'hmwevents_disabled_emails') {
                    return [];
                }
                return $default;
            });

        $result = $this->invokeIsEmailEnabled('booking_confirmation');
        $this->assertTrue($result);
    }

    public function test_is_email_enabled_returns_false_when_disabled(): void
    {
        Functions\when('get_option')
            ->alias(function ($option, $default = false) {
                if ($option === 'hmwevents_disabled_emails') {
                    return ['booking_confirmation'];
                }
                return $default;
            });

        $result = $this->invokeIsEmailEnabled('booking_confirmation');
        $this->assertFalse($result);
    }

    public function test_is_email_enabled_returns_true_for_other_type(): void
    {
        Functions\when('get_option')
            ->alias(function ($option, $default = false) {
                if ($option === 'hmwevents_disabled_emails') {
                    return ['booking_confirmation'];
                }
                return $default;
            });

        $result = $this->invokeIsEmailEnabled('new_booking_notify');
        $this->assertTrue($result);
    }

    public function test_notification_email_override_is_used(): void
    {
        Functions\when('get_post_meta')
            ->alias(function ($post_id, $key, $single) {
                if ($post_id === 5 && $key === '_event_notification_email') {
                    return 'admin@example.com';
                }
                return '';
            });

        Functions\when('is_email')
            ->alias(function ($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            });

        $result = $this->resolveNotificationEmail('user@example.com', 5);
        $this->assertSame('admin@example.com', $result);
    }

    public function test_notification_email_override_ignored_when_invalid(): void
    {
        Functions\when('get_post_meta')
            ->alias(function ($post_id, $key, $single) {
                if ($post_id === 5 && $key === '_event_notification_email') {
                    return 'not-an-email';
                }
                return '';
            });

        Functions\when('is_email')
            ->alias(function ($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            });

        $result = $this->resolveNotificationEmail('user@example.com', 5);
        $this->assertSame('user@example.com', $result);
    }

    public function test_notification_email_override_ignored_when_empty(): void
    {
        Functions\when('get_post_meta')
            ->alias(function ($post_id, $key, $single) {
                if ($post_id === 5 && $key === '_event_notification_email') {
                    return '';
                }
                return '';
            });

        Functions\when('is_email')
            ->alias(function ($email) {
                return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
            });

        $result = $this->resolveNotificationEmail('user@example.com', 5);
        $this->assertSame('user@example.com', $result);
    }

    public function test_is_email_enabled_handles_missing_option(): void
    {
        Functions\when('get_option')
            ->alias(function ($option, $default = false) {
                if ($option === 'hmwevents_disabled_emails') {
                    return false;
                }
                return $default;
            });

        $result = $this->invokeIsEmailEnabled('booking_confirmation');
        $this->assertTrue($result);
    }

    private function resolveNotificationEmail(string $educator_email, int $event_id): string
    {
        $override = get_post_meta($event_id, '_event_notification_email', true);
        if ($override && is_email($override)) {
            return $override;
        }
        return $educator_email;
    }
}
