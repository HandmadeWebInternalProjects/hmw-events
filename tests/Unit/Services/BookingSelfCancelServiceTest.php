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
        Functions\when('sanitize_key')->returnArg();
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
        Functions\when('get_post')->alias(function ($id) {
            return new \WP_Post((object) [
                'ID' => $id,
                'post_type' => 'hmw_event',
                'post_status' => 'publish',
            ]);
        });
        Functions\when('add_action')->justReturn(true);
        Functions\when('get_the_title')->justReturn('Test Event');
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
    // Test 1: generate_cancel_link()
    // ================================================================

    public function test_generate_cancel_link_stores_token_and_returns_anchor(): void
    {
        Patchwork\replace('random_bytes', function (int $length): string {
            return str_repeat("\x00", $length);
        });

        $capturedTable = null;
        $capturedData  = null;

        $this->mockWpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function ($table, $data, $where) use (&$capturedTable, &$capturedData) {
                $capturedTable = $table;
                $capturedData  = $data;
                return 1;
            });

        $service = new BookingSelfCancelService();
        $result  = $service->generate_cancel_link(42);

        $this->assertSame('wp_hmwevents_bookings', $capturedTable);
        $this->assertArrayHasKey('cancel_token', $capturedData);
        $this->assertSame(64, strlen($capturedData['cancel_token']));
        $this->assertTrue(ctype_xdigit($capturedData['cancel_token']));
        $this->assertStringContainsString('<a href=', $result);
        $this->assertStringContainsString('hmw_cancel=', $result);
        $this->assertStringContainsString('booking_id=42', $result);
    }

    // ================================================================
    // Test 4: maybe_handle_cancel() with valid token
    // ================================================================

    public function test_maybe_handle_cancel_with_valid_token(): void
    {
        $_GET['hmw_cancel'] = 'validtoken';
        $_GET['booking_id'] = '42';
        $_POST['hmw_cancel_confirm'] = '1';

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

        $cancelledData = null;

        $this->mockWpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('validtoken');

        $this->mockWpdb->shouldReceive('get_row')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($sql) use ($bookingRow) {
                if (str_contains($sql, 'booking_groups')) {
                    return (object) ['id' => 10, 'booking_type' => 'single', 'booking_reference' => 'BKG-1'];
                }
                if (str_contains($sql, 'SELECT booking_group_id FROM')) {
                    return (object) ['booking_group_id' => 10];
                }
                return $bookingRow;
            });

        $this->mockWpdb->shouldReceive('update')
            ->once()
            ->andReturnUsing(function ($table, $data) use (&$cancelledData) {
                $cancelledData = $data;
                return 1;
            });

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
        $this->assertSame('cancelled', $cancelledData['status']);
        $this->assertNull($cancelledData['cancel_token']);

        unset($_GET['hmw_cancel'], $_GET['booking_id'], $_POST['hmw_cancel_confirm']);
    }

    // ================================================================
    // Test 4b: maybe_handle_cancel() without POST confirm shows
    // confirmation page and does not cancel
    // ================================================================

    public function test_maybe_handle_cancel_without_confirm_shows_confirmation_page(): void
    {
        $_GET['hmw_cancel'] = 'validtoken';
        $_GET['booking_id'] = '42';
        unset($_POST['hmw_cancel_confirm']);

        Functions\when('hash_equals')->justReturn(true);

        $bookingRow = (object) [
            'id'             => 42,
            'status'         => 'confirmed',
            'booking_number' => 'BK-001',
            'event_post_id'  => 123,
        ];

        $this->mockWpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('validtoken');

        $this->mockWpdb->shouldReceive('get_row')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($sql) use ($bookingRow) {
                if (str_contains($sql, 'booking_groups')) {
                    return (object) ['id' => 10, 'booking_type' => 'single', 'booking_reference' => 'BKG-1'];
                }
                if (str_contains($sql, 'SELECT booking_group_id FROM')) {
                    return (object) ['booking_group_id' => 10];
                }
                return $bookingRow;
            });

        $this->mockWpdb->shouldReceive('update')->never();

        $dieMessage = null;
        Functions\when('wp_die')->alias(function ($msg) use (&$dieMessage) {
            $dieMessage = $msg;
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
        $confirmationHtml = (string) $dieMessage;
        $this->assertStringContainsString('Cancel Your Booking', $confirmationHtml);
        $this->assertStringContainsString('name="hmw_cancel_confirm"', $confirmationHtml);
        $this->assertStringContainsString('Yes, Cancel My Booking', $confirmationHtml);
        $this->assertStringContainsString('No, Keep My Booking', $confirmationHtml);
        $this->assertStringContainsString('BK-001', $confirmationHtml);
        $this->assertStringContainsString('Test Event', $confirmationHtml);

        unset($_GET['hmw_cancel'], $_GET['booking_id']);
    }

    // ================================================================
    // Test 5: maybe_handle_cancel() with invalid token
    // ================================================================

    public function test_maybe_handle_cancel_with_invalid_token_rejected(): void
    {
        $_GET['hmw_cancel'] = 'badtoken';
        $_GET['booking_id'] = '42';

        Functions\when('hash_equals')->justReturn(false);

        $this->mockWpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('differenttoken');

        $this->mockWpdb->shouldReceive('get_row')->never();
        $this->mockWpdb->shouldReceive('update')->never();

        Functions\when('wp_die')->alias(function ($msg) {
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

        $dieCalled = false;

        $this->mockWpdb->shouldReceive('get_var')->never();
        $this->mockWpdb->shouldReceive('get_row')->never();
        $this->mockWpdb->shouldReceive('update')->never();

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

        Functions\when('hash_equals')->justReturn(true);

        $this->mockWpdb->shouldReceive('get_var')
            ->once()
            ->andReturn('validtoken');

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
