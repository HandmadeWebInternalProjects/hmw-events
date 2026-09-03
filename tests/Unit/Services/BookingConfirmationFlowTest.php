<?php

/**
 * Tests for the booking confirmation flow.
 *
 * Covers the hand-off between RegistrationFormRenderer::handle_submission()
 * (which stores the gateway booking_number in SessionFlash, persists a one-time
 * confirmation token via set_transient(), and returns a redirect URL carrying
 * hmwevents_token) and BookingConfirmation::render() (which consumes the flash
 * value or resolves the one-time token fallback without requiring ?booking= in
 * the URL).
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\RegistrationFormRenderer;
use HMWEvents\Services\SessionFlash;
use HMWEvents\Shortcodes\BookingConfirmation;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Patchwork;
use Mockery;

class BookingConfirmationFlowTest extends TestCase
{
    private array $setTransientCalls   = [];
    private array $getTransientCalls   = [];
    private array $deletedTransientKeys = [];
    private array $transientStore      = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        // Avoid native session_start() in CLI — we manipulate $_SESSION directly.
        if (!defined('FLASH_INIT')) {
            define('FLASH_INIT', true);
        }

        if (!defined('MINUTE_IN_SECONDS')) {
            define('MINUTE_IN_SECONDS', 60);
        }

        $_SESSION = [];
        $_GET     = [];
        $_POST    = [];
        $_FILES   = [];

        $this->setTransientCalls    = [];
        $this->getTransientCalls    = [];
        $this->deletedTransientKeys = [];
        $this->transientStore       = [];

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
                if (strpos($result, '%s') !== false) {
                    $result = preg_replace('/%s/', "'" . addslashes((string) $v) . "'", $result, 1);
                } elseif (strpos($result, '%d') !== false) {
                    $result = preg_replace('/%d/', (string) (int) $v, $result, 1);
                } elseif (strpos($result, '%f') !== false) {
                    $result = preg_replace('/%f/', (string) (float) $v, $result, 1);
                }
            }

            return $result;
        });

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html_e')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_textarea')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_post')->justReturn(null);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_the_author_meta')->justReturn('Dr. Smith');
        Functions\when('get_post_field')->justReturn(1);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('admin_url')->justReturn('https://example.com/wp-admin/admin-ajax.php');
        Functions\when('apply_filters')->alias(function ($tag, $value, ...$args) {
            return $value;
        });
        Functions\when('error_log')->justReturn(true);

        Functions\when('wp_unslash')->returnArg();
        Functions\when('set_transient')->alias(function ($key, $value, $expiration = 0) {
            $this->setTransientCalls[] = [$key, $value, $expiration];
            $this->transientStore[$key] = $value;
            return true;
        });
        Functions\when('get_transient')->alias(function ($key) {
            $this->getTransientCalls[] = $key;
            return $this->transientStore[$key] ?? false;
        });
        Functions\when('delete_transient')->alias(function ($key) {
            $this->deletedTransientKeys[] = $key;
            unset($this->transientStore[$key]);
            return true;
        });
        Functions\when('add_query_arg')->alias(function ($key, $value, $url) {
            return $url . (str_contains($url, '?') ? '&' : '?') . $key . '=' . urlencode((string) $value);
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // SessionFlash behaviour
    // ============================================================

    public function test_session_flash_stores_and_retrieves_message(): void
    {
        $flash = new SessionFlash();
        $flash->message('hmwevents_confirmed_booking', 'BK-123');

        $this->assertTrue(SessionFlash::has('hmwevents_confirmed_booking'));
        $this->assertSame('BK-123', SessionFlash::get('hmwevents_confirmed_booking'));
    }

    public function test_session_flash_get_returns_default_for_missing_key(): void
    {
        $this->assertFalse(SessionFlash::has('hmwevents_confirmed_booking'));
        $this->assertSame('fallback', SessionFlash::get('hmwevents_confirmed_booking', 'fallback'));
    }

    public function test_session_flash_clear_removes_messages(): void
    {
        $flash = new SessionFlash();
        $flash->message('hmwevents_confirmed_booking', 'BK-123');

        SessionFlash::clear();

        $this->assertFalse(SessionFlash::has('hmwevents_confirmed_booking'));
    }

    // ============================================================
    // handle_submission() redirect + flash propagation
    // ============================================================

    public function test_handle_submission_stores_booking_number_and_returns_redirect(): void
    {
        $_POST['event_id']           = 123;
        $_POST['attendance_type']    = 'individual';
        $_POST['_hmwevents_nonce']   = 'abc123';

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('get_page_by_path')->justReturn((object) ['ID' => 5]);
        Functions\when('get_permalink')->justReturn('https://example.com/booking-confirmation');

        $captured = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$captured) {
            $captured = $data;
        });

        Patchwork\replace('HMWEvents\Services\FormConfigResolver::resolve', function ($event_id) {
            return ['sections' => [], 'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10]];
        });
        Patchwork\replace('HMWEvents\Services\FormSubmissionService::submit_and_pay', function ($form_data, $event_id) {
            return [
                'success'        => true,
                'booking_id'     => 99,
                'booking_number' => 'BK-123',
                'registrant_ids' => [20],
                'total'          => 100.0,
                'client_secret'  => 'cs_123',
                'intent_status'  => 'pending',
            ];
        });

        $renderer = new RegistrationFormRenderer();
        $renderer->handle_submission();

        $this->assertNotNull($captured, 'wp_send_json_success should have been called');
        $this->assertArrayHasKey('redirect', $captured);
        $this->assertStringContainsString('hmwevents_token=', $captured['redirect']);
        $this->assertStringNotContainsString('BK-123', $captured['redirect']);
        $this->assertSame('BK-123', SessionFlash::get('hmwevents_confirmed_booking'));
        $this->assertTrue(SessionFlash::has('hmwevents_confirmed_booking'));

        $this->assertCount(1, $this->setTransientCalls);
        $this->assertStringStartsWith('hmwevents_confirmation_', $this->setTransientCalls[0][0]);
        $this->assertSame('BK-123', $this->setTransientCalls[0][1]);
        $this->assertSame(600, $this->setTransientCalls[0][2]);
    }

    public function test_handle_submission_does_not_flash_when_booking_number_is_missing(): void
    {
        $_POST['event_id']         = 123;
        $_POST['attendance_type']  = 'individual';
        $_POST['_hmwevents_nonce'] = 'abc123';

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('get_page_by_path')->justReturn((object) ['ID' => 5]);
        Functions\when('get_permalink')->justReturn('https://example.com/booking-confirmation');

        $captured = null;
        Functions\when('wp_send_json_success')->alias(function ($data) use (&$captured) {
            $captured = $data;
        });

        Patchwork\replace('HMWEvents\Services\FormConfigResolver::resolve', function ($event_id) {
            return ['sections' => [], 'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10]];
        });
        Patchwork\replace('HMWEvents\Services\FormSubmissionService::submit_and_pay', function ($form_data, $event_id) {
            return [
                'success'    => true,
                'booking_id' => 99,
            ];
        });

        $renderer = new RegistrationFormRenderer();
        $renderer->handle_submission();

        $this->assertNotNull($captured);
        $this->assertFalse(SessionFlash::has('hmwevents_confirmed_booking'));
        $this->assertCount(0, $this->setTransientCalls);
        $this->assertArrayNotHasKey('redirect', $captured);
    }

    // ============================================================
    // BookingConfirmation::render() consumption
    // ============================================================

    private function booking_row(): object
    {
        return (object) [
            'id'                     => 1,
            'booking_number'         => 'BK-123',
            'booking_reference'      => 'BK-123',
            'total_amount'           => '100.00',
            'group_payment_type'     => 'full',
            'payment_status'         => 'paid',
            'event_name'             => 'Test Event',
            'event_id'               => 10,
            'registrant_name'        => 'Jane Doe',
            'registrant_post_id'     => 20,
            'paid_amount'            => '100.00',
            'gateway_transaction_id' => 'pi_123',
            'payment_date'           => '2026-01-01 00:00:00',
            'currency'               => 'AUD',
        ];
    }

    public function test_render_consumes_session_flash_without_get_booking(): void
    {
        $_SESSION['hmwevents_confirmed_booking'] = 'BK-123';
        unset($_GET['booking']);

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($this->booking_row(), null);

        $confirmation = new BookingConfirmation();
        $output = $confirmation->render([]);

        $this->assertStringContainsString('BK-123', $output);
        $this->assertStringContainsString('Booking Confirmed!', $output);
        $this->assertStringNotContainsString('No booking reference provided', $output);
        $this->assertStringNotContainsString('Booking Not Found', $output);

        $this->assertFalse(
            isset($_SESSION['hmwevents_confirmed_booking']),
            'Session flash should be consumed after render.'
        );
    }

    public function test_render_falls_back_to_get_booking_when_no_flash(): void
    {
        unset($_SESSION['hmwevents_confirmed_booking']);
        $_GET['booking'] = 'BK-GET-123';

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($this->booking_row(), null);

        $confirmation = new BookingConfirmation();
        $output = $confirmation->render([]);

        $this->assertStringContainsString('BK-123', $output);
        $this->assertStringContainsString('Booking Confirmed!', $output);
    }

    public function test_render_returns_error_when_no_flash_and_no_get_booking(): void
    {
        unset($_SESSION['hmwevents_confirmed_booking']);
        unset($_GET['booking']);

        $confirmation = new BookingConfirmation();
        $output = $confirmation->render([]);

        $this->assertStringContainsString('No booking reference provided', $output);
        $this->assertStringContainsString('Booking Not Found', $output);
    }

    public function test_render_returns_not_found_when_flash_booking_number_unknown(): void
    {
        $_SESSION['hmwevents_confirmed_booking'] = 'BK-MISSING';
        unset($_GET['booking']);

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(null);

        $confirmation = new BookingConfirmation();
        $output = $confirmation->render([]);

        $this->assertStringContainsString('Booking not found', $output);
        $this->assertStringContainsString('Booking Not Found', $output);
    }

    public function test_render_resolves_and_consumes_one_time_token_fallback(): void
    {
        unset($_SESSION['hmwevents_confirmed_booking']);
        unset($_GET['booking']);

        $token    = 'tok-abc123';
        $tokenKey = 'hmwevents_confirmation_' . hash('sha256', $token);

        $_GET['hmwevents_token'] = $token;
        $this->transientStore[$tokenKey] = 'BK-123';

        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn($this->booking_row(), null);

        $confirmation = new BookingConfirmation();
        $output = $confirmation->render([]);

        $this->assertStringContainsString('BK-123', $output);
        $this->assertStringContainsString('Booking Confirmed!', $output);
        $this->assertStringNotContainsString('No booking reference provided', $output);

        $this->assertContains($tokenKey, $this->getTransientCalls, 'get_transient should resolve the token key');
        $this->assertContains($tokenKey, $this->deletedTransientKeys, 'delete_transient should consume the one-time token');
    }

    public function test_render_returns_error_when_token_is_unknown(): void
    {
        unset($_SESSION['hmwevents_confirmed_booking']);
        unset($_GET['booking']);

        $_GET['hmwevents_token'] = 'tok-expired';

        $confirmation = new BookingConfirmation();
        $output = $confirmation->render([]);

        $this->assertStringContainsString('No booking reference provided', $output);
        $this->assertStringContainsString('Booking Not Found', $output);
        $this->assertContains(
            'hmwevents_confirmation_' . hash('sha256', 'tok-expired'),
            $this->deletedTransientKeys,
            'The one-time token should still be consumed even when it does not resolve.'
        );
    }
}
