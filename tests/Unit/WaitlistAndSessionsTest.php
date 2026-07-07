<?php
/**
 * Phase 6+7 tests — Waitlist, Invitation Tokens, Sessions.
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use HMWEvents\Services\WaitlistService;
use HMWEvents\Services\InvitationTokenService;
use HMWEvents\Services\SessionService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class WaitlistAndSessionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_user_meta')->justReturn([]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('get_post')->justReturn(null);
        Functions\when('get_permalink')->justReturn('https://example.com/event/test');
        Functions\when('add_query_arg')->alias(function ($k, $v, $u) { return $u . '?' . $k . '=' . $v; });
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('gmdate')->alias('date');
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('wp_json_encode')->alias('json_encode');
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Waitlist Service
    // ============================================================

    public function test_waitlist_instantiable(): void
    {
        $service = new WaitlistService();
        $this->assertInstanceOf(WaitlistService::class, $service);
    }

    public function test_waitlist_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(WaitlistService::class, $components);
    }

    public function test_check_event_full_by_invitation(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'by_invitation'];
        });

        $service = new WaitlistService();
        $this->assertTrue($service->check_event_full(false, 1));
    }

    public function test_check_event_full_not_full(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'publish'];
        });

        $service = new WaitlistService();
        $this->assertFalse($service->check_event_full(false, 1));
    }

    // ============================================================
    // Invitation Token Service
    // ============================================================

    public function test_token_instantiable(): void
    {
        $service = new InvitationTokenService();
        $this->assertInstanceOf(InvitationTokenService::class, $service);
    }

    public function test_token_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(InvitationTokenService::class, $components);
    }

    public function test_validate_empty_token(): void
    {
        $service = new InvitationTokenService();
        $result = $service->validate('', 1);
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('token_missing', $result->get_error_code());
    }

    // ============================================================
    // Session Service
    // ============================================================

    public function test_session_instantiable(): void
    {
        $service = new SessionService();
        $this->assertInstanceOf(SessionService::class, $service);
    }

    public function test_session_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(SessionService::class, $components);
    }
}
