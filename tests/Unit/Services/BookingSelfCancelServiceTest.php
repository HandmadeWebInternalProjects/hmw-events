<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\BookingSelfCancelService;
use Mockery;
use Patchwork;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class BookingSelfCancelServiceTest extends TestCase
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
                    $result = substr_replace($result, "'{$v}'", $pos, 2);
                    continue;
                }
                $pos = strpos($result, '%d');
                if ($pos !== false) {
                    $result = substr_replace($result, (int) $v, $pos, 2);
                }
            }
            return $result;
        });
        $GLOBALS['wpdb'] = $this->mockWpdb;

        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_html__')->alias(function ($text) {
            return $text;
        });
        Functions\when('error_log')->justReturn(true);
        Functions\when('do_action')->justReturn(true);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('add_action')->justReturn(true);
        Functions\when('add_query_arg')->alias(function (...$args) {
            if (is_array($args[0])) {
                $url = $args[1] ?? '';
                $query = http_build_query($args[0]);
                $separator = (strpos($url, '?') === false) ? '?' : '&';
                return $url . $separator . $query;
            }
            return ($args[2] ?? '') . '?' . $args[0] . '=' . $args[1];
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ================================================================
    // Test 1: generate_cancel_token()
    // ================================================================

    public function test_generate_cancel_token_stores_meta_and_returns_url(): void
    {
        Patchwork\replace('random_bytes', function (int $length): string {
            return str_repeat("\x00", $length);
        });

        $capturedBookingId = null;
        $capturedMetaKey   = null;
        $capturedToken     = null;

        Functions\when('update_post_meta')->alias(function ($id, $key, $value) use (&$capturedBookingId, &$capturedMetaKey, &$capturedToken) {
            $capturedBookingId = $id;
            $capturedMetaKey   = $key;
            $capturedToken     = $value;
            return true;
        });

        $service = new BookingSelfCancelService();
        $result  = $service->generate_cancel_token(42);

        $this->assertSame(42, $capturedBookingId);
        $this->assertSame('_cancel_token', $capturedMetaKey);
        $this->assertSame(64, strlen($capturedToken));
        $this->assertTrue(ctype_xdigit($capturedToken));
        $this->assertStringContainsString('hmw_cancel=', $result);
        $this->assertStringContainsString('booking_id=42', $result);
    }

    // ================================================================
    // Test 2: add_cancel_link() for free events
    // ================================================================

    public function test_add_cancel_link_for_free_event(): void
    {
        Patchwork\replace('random_bytes', function (int $length): string {
            return str_repeat("\x00", $length);
        });

        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_event_is_free') {
                return '1';
            }
            return '';
        });

        $template_data = ['event_post_id' => 5];

        $service = new BookingSelfCancelService();
        $result  = $service->add_cancel_link($template_data, 42);

        $this->assertArrayHasKey('cancel_link', $result);
        $this->assertNotEmpty($result['cancel_link']);
        $this->assertStringContainsString('hmw_cancel=', $result['cancel_link']);
        $this->assertStringContainsString('booking_id=42', $result['cancel_link']);
    }

    // ================================================================
    // Test 3: add_cancel_link() skips paid events
    // ================================================================

    public function test_add_cancel_link_skips_paid_events(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_event_is_free') {
                return '0';
            }
            if ($key === '_event_price') {
                return '100';
            }
            return '';
        });

        $template_data = ['event_post_id' => 5];

        $service = new BookingSelfCancelService();
        $result  = $service->add_cancel_link($template_data, 42);

        $this->assertArrayNotHasKey('cancel_link', $result);
    }

    // ================================================================
    // Test 4: maybe_handle_cancel() with valid token
    // ================================================================

    public function test_maybe_handle_cancel_with_valid_token(): void
    {
        $_GET['hmw_cancel'] = 'validtoken';
        $_GET['booking_id'] = '42';

        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_cancel_token') {
                return 'validtoken';
            }
            return '';
        });

        Functions\when('hash_equals')->justReturn(true);

        $bookingRow = (object) [
            'id'                      => 42,
            'status'                  => 'confirmed',
            'booking_number'          => 'BK-001',
            'event_post_id'           => 123,
            'registrant_email'        => 'test@example.com',
            'total_amount'            => '0.00',
            'gateway_transaction_id'  => '',
            'payment_status'          => 'complete',
            'group_payment_type'      => 'full',
            'created_at'              => '2026-01-01 00:00:00',
        ];

        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn($bookingRow);

        $this->mockWpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        Functions\when('wp_die')->alias(function ($msg) {
            throw new \RuntimeException('WP_DIE: ' . (is_string($msg) ? $msg : 'non-string-msg'));
        });

        $service = new BookingSelfCancelService();

        $caughtException = null;
        try {
            $service->maybe_handle_cancel();
        } catch (\RuntimeException $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException, 'Expected wp_die() to be called but it was not.');
        $this->assertStringContainsString('Booking Cancelled', $caughtException->getMessage());

        unset($_GET['hmw_cancel'], $_GET['booking_id']);
    }

    // ================================================================
    // Test 5: maybe_handle_cancel() with invalid token
    // ================================================================

    public function test_maybe_handle_cancel_with_invalid_token_rejected(): void
    {
        $_GET['hmw_cancel'] = 'badtoken';
        $_GET['booking_id'] = '42';

        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_cancel_token') {
                return 'differenttoken';
            }
            return '';
        });

        Functions\when('hash_equals')->justReturn(false);

        $dieMessage = null;
        Functions\when('wp_die')->alias(function ($msg) use (&$dieMessage) {
            $dieMessage = $msg;
            throw new \RuntimeException('WP_DIE: ' . $msg);
        });

        $service = new BookingSelfCancelService();

        $caughtException = null;
        try {
            $service->maybe_handle_cancel();
        } catch (\RuntimeException $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException, 'Expected wp_die() to be called but it was not.');
        $this->assertStringContainsString('Invalid cancellation link.', $caughtException->getMessage());

        unset($_GET['hmw_cancel'], $_GET['booking_id']);
    }

    // ================================================================
    // Test 6: maybe_handle_cancel() with no token does nothing
    // ================================================================

    public function test_maybe_handle_cancel_with_no_token_does_nothing(): void
    {
        $_GET = [];

        $getRowCalled    = false;
        $updateCalled    = false;
        $getMetaCalled   = false;
        $dieCalled       = false;

        $this->mockWpdb->shouldReceive('get_row')->never();
        $this->mockWpdb->shouldReceive('update')->never();

        Functions\when('get_post_meta')->alias(function () use (&$getMetaCalled) {
            $getMetaCalled = true;
            return '';
        });

        Functions\when('wp_die')->alias(function () use (&$dieCalled) {
            $dieCalled = true;
            throw new \RuntimeException('WP_DIE: unexpected');
        });

        Functions\when('hash_equals')->justReturn(false);

        $service = new BookingSelfCancelService();
        $service->maybe_handle_cancel();

        $this->assertFalse($dieCalled, 'wp_die should not be called when no token is present.');
    }

    // ================================================================
    // Test 7: get_booking() returns null for non-confirmed/deleted
    // ================================================================

    public function test_get_booking_returns_null_for_cancelled_or_deleted(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $service = new BookingSelfCancelService();

        $reflection = new \ReflectionClass($service);
        $method     = $reflection->getMethod('get_booking');
        $method->setAccessible(true);

        $result = $method->invoke($service, 42);

        $this->assertNull($result);
    }

    // ================================================================
    // Test 8: maybe_handle_cancel() when booking not found
    // ================================================================

    public function test_maybe_handle_cancel_when_booking_not_found(): void
    {
        $_GET['hmw_cancel'] = 'validtoken';
        $_GET['booking_id'] = '42';

        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($key === '_cancel_token') {
                return 'validtoken';
            }
            return '';
        });

        Functions\when('hash_equals')->justReturn(true);

        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $dieMessage = null;
        Functions\when('wp_die')->alias(function ($msg) use (&$dieMessage) {
            $dieMessage = $msg;
            throw new \RuntimeException('WP_DIE: ' . $msg);
        });

        $service = new BookingSelfCancelService();

        $caughtException = null;
        try {
            $service->maybe_handle_cancel();
        } catch (\RuntimeException $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException, 'Expected wp_die() to be called but it was not.');
        $this->assertStringContainsString('Booking not found.', $caughtException->getMessage());

        unset($_GET['hmw_cancel'], $_GET['booking_id']);
    }

    // ================================================================
    // Test 9: register() hooks init action
    // ================================================================

    public function test_register_hooks_init_action(): void
    {
        $hookedAction   = null;
        $hookedCallback = null;

        Functions\when('add_action')->alias(function ($tag, $callback) use (&$hookedAction, &$hookedCallback) {
            $hookedAction   = $tag;
            $hookedCallback = $callback;
            return true;
        });

        $service = new BookingSelfCancelService();
        $service->register();

        $this->assertSame('init', $hookedAction);
        $this->assertIsArray($hookedCallback);
        $this->assertSame($service, $hookedCallback[0]);
        $this->assertSame('maybe_handle_cancel', $hookedCallback[1]);
    }
}
