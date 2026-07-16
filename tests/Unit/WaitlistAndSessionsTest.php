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
use Mockery;
use Patchwork;

class WaitlistAndSessionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
            $flat = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    foreach ($arg as $v) {
                        $flat[] = $v;
                    }
                } else {
                    $flat[] = $arg;
                }
            }
            $result = $query;
            foreach ($flat as $v) {
                $pos = strpos($result, '%s');
                if ($pos !== false) {
                    $result = substr_replace($result, "'" . $v . "'", $pos, 2);
                } else {
                    $pos = strpos($result, '%d');
                    if ($pos !== false) {
                        $result = substr_replace($result, (string) (int) $v, $pos, 2);
                    }
                }
            }
            return $result;
        });
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(0);

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
        Functions\when('do_action')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
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
            return (object) ['post_status' => 'publish'];
        });
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === '_event_is_invitation_only') return '1';
            return '';
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

    public function test_check_event_full_with_active_waitlist(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'publish'];
        });

        \Patchwork\replace('HMWEvents\\Services\\WaitlistService::count_for_event', function ($event_post_id) {
            return 5;
        });

        $service = new WaitlistService();
        $this->assertTrue($service->check_event_full(false, 1));
    }

    public function test_promote_entry_succeeds(): void
    {
        $entry = (object) [
            'id'             => 10,
            'event_post_id'  => 1,
            'status'         => 'waiting',
            'position'       => 1,
        ];

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($entry);
        $GLOBALS['wpdb']->shouldReceive('update')->andReturn(1);

        \Patchwork\replace('strtotime', function ($time) {
            if (str_contains($time, '+48 hours')) {
                return 1767398400;
            }
            return \strtotime($time);
        });

        $service = new WaitlistService();
        $result = $service->promote_entry(10, 1);

        $this->assertNotNull($result);
        $this->assertSame(10, $result->id);
    }

    public function test_promote_entry_returns_null_for_not_found(): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(null);

        $service = new WaitlistService();
        $result = $service->promote_entry(999, 1);

        $this->assertNull($result);
    }

    public function test_promote_entry_fires_action(): void
    {
        $entry = (object) [
            'id'             => 10,
            'event_post_id'  => 1,
            'status'         => 'waiting',
            'position'       => 1,
        ];

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($entry);
        $GLOBALS['wpdb']->shouldReceive('update')->andReturn(1);

        \Patchwork\replace('strtotime', function ($time) {
            if (str_contains($time, '+48 hours')) {
                return 1767398400;
            }
            return \strtotime($time);
        });

        $capturedAction = null;
        Functions\when('do_action')->alias(function (...$args) use (&$capturedAction) {
            $capturedAction = $args;
            return true;
        });

        $service = new WaitlistService();
        $result = $service->promote_entry(10, 1);

        $this->assertNotNull($result);
        $this->assertSame(10, $result->id);

        $this->assertNotNull($capturedAction, 'do_action should have been called');
        $this->assertSame('hmwevents_waitlist_promoted', $capturedAction[0], 'action name should match');
        $this->assertSame(10, $capturedAction[1]->id, 'promoted entry id should match');
        $this->assertSame(1, $capturedAction[2], 'event_post_id should match');
    }

    public function test_promote_entry_with_custom_expiry(): void
    {
        $entry = (object) [
            'id'             => 10,
            'event_post_id'  => 1,
            'status'         => 'waiting',
            'position'       => 1,
        ];

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($entry);

        $capturedUpdate = null;
        $GLOBALS['wpdb']->shouldReceive('update')
            ->andReturnUsing(function ($table, $data, $where, $format, $where_format) use (&$capturedUpdate) {
                $capturedUpdate = $data;
                return 1;
            });

        \Patchwork\replace('strtotime', function ($time) {
            if (str_contains($time, '+72 hours')) {
                return 1767484800;
            }
            if (str_contains($time, '+48 hours')) {
                return 1767398400;
            }
            return \strtotime($time);
        });

        $service = new WaitlistService();
        $result = $service->promote_entry(10, 1, 72);

        $this->assertNotNull($result);
        $this->assertSame(10, $result->id);

        $this->assertNotNull($capturedUpdate, 'update should have been called');
        $this->assertSame('notified', $capturedUpdate['status']);
        $this->assertNotEmpty($capturedUpdate['expires_at'], 'expires_at should be set');

        $expectedExpiresAt = date('Y-m-d H:i:s', 1767484800);
        $this->assertSame($expectedExpiresAt, $capturedUpdate['expires_at']);
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
