<?php

namespace HMWEvents\Tests\Unit\Admin;

use HMWEvents\Admin\EventBookings;
use Mockery;
use Patchwork;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventBookingsTest extends TestCase
{
    /** @var array */
    private $originalPost = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $this->originalPost = $_POST;

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        Functions\when('__')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('wp_die')->justReturn(null);

        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            throw new \RuntimeException('SEND_JSON_ERROR:' . json_encode($data));
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            throw new \RuntimeException('SEND_JSON_SUCCESS:' . json_encode($data));
        });
    }

    protected function tearDown(): void
    {
        $_POST = $this->originalPost;
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function createHandler(): EventBookings
    {
        return new EventBookings();
    }

    // ================================================================
    // ajax_generate_token() — permission denied
    // ================================================================

    public function test_ajax_generate_token_blocks_users_without_capability(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(false);

        $handler = $this->createHandler();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEND_JSON_ERROR:');

        try {
            $handler->ajax_generate_token();
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringStartsWith('SEND_JSON_ERROR:', $msg);
            $data = json_decode(substr($msg, strlen('SEND_JSON_ERROR:')), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('message', $data);
            $this->assertStringContainsString('Permission denied', $data['message']);
            throw $e;
        }
    }

    // ================================================================
    // ajax_generate_token() — invalid event ID
    // ================================================================

    public function test_ajax_generate_token_requires_valid_event_id(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $_POST['event_id'] = 0;

        $handler = $this->createHandler();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEND_JSON_ERROR:');

        try {
            $handler->ajax_generate_token();
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringStartsWith('SEND_JSON_ERROR:', $msg);
            $data = json_decode(substr($msg, strlen('SEND_JSON_ERROR:')), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('message', $data);
            $this->assertStringContainsString('Invalid event', $data['message']);
            throw $e;
        }
    }

    // ================================================================
    // ajax_generate_token() — success
    // ================================================================

    public function test_ajax_generate_token_creates_token_and_returns_url(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $_POST['event_id'] = 123;
        $_POST['email']    = 'test@test.com';

        Patchwork\replace('HMWEvents\\Services\\InvitationTokenService::create', function () {
            return [
                'id'               => 1,
                'token'            => 'abc123abc123abc123abc123abc123abc123abc123abc123abc123abc123abcd',
                'event_post_id'    => 123,
                'recipient_email'  => 'test@test.com',
                'max_uses'         => 1,
                'expires_at'       => '2026-01-03 00:00:00',
                'registration_url' => 'https://example.com/event/test?token=abc123abc123',
            ];
        });

        $handler = $this->createHandler();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEND_JSON_SUCCESS:');

        try {
            $handler->ajax_generate_token();
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringStartsWith('SEND_JSON_SUCCESS:', $msg);
            $data = json_decode(substr($msg, strlen('SEND_JSON_SUCCESS:')), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('registration_url', $data);
            $this->assertArrayHasKey('token', $data);
            $this->assertArrayHasKey('message', $data);
            $this->assertStringContainsString('?token=', $data['registration_url']);
            $this->assertStringContainsString('abc123abc123', $data['registration_url']);
            throw $e;
        }
    }

    public function test_ajax_generate_token_handles_create_error(): void
    {
        Functions\when('check_ajax_referer')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);

        $_POST['event_id'] = 123;
        $_POST['email']    = 'test@test.com';

        Patchwork\replace('HMWEvents\\Services\\InvitationTokenService::create', function () {
            return new \WP_Error('db_error', 'Storage failure');
        });

        $handler = $this->createHandler();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SEND_JSON_ERROR:');

        try {
            $handler->ajax_generate_token();
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $this->assertStringStartsWith('SEND_JSON_ERROR:', $msg);
            $data = json_decode(substr($msg, strlen('SEND_JSON_ERROR:')), true);
            $this->assertIsArray($data);
            $this->assertArrayHasKey('message', $data);
            $this->assertStringContainsString('Storage failure', $data['message']);
            throw $e;
        }
    }
}
