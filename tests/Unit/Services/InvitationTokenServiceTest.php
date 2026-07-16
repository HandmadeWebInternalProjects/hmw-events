<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\InvitationTokenService;
use Mockery;
use Patchwork;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class InvitationTokenServiceTest extends TestCase
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

        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_current_user_id')->justReturn(1);
        Functions\when('get_permalink')->justReturn('https://example.com/event/test');
        Functions\when('add_query_arg')->alias(function ($k, $v, $u) {
            return $u . '?' . $k . '=' . $v;
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('error_log')->justReturn(true);
        Functions\when('gmdate')->alias('date');
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('do_action')->justReturn(true);
        Functions\when('home_url')->justReturn('https://example.com');
        Functions\when('get_post')->justReturn(null);
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ================================================================
    // create()
    // ================================================================

    public function test_create_generates_valid_token(): void
    {
        $this->mockWpdb->shouldReceive('insert')
            ->once()
            ->andReturn(1);
        $this->mockWpdb->insert_id = 42;

        $service = new InvitationTokenService();
        $result  = $service->create(123, 'test@example.com');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('token', $result);
        $this->assertArrayHasKey('registration_url', $result);
        $this->assertArrayHasKey('event_post_id', $result);
        $this->assertArrayHasKey('recipient_email', $result);
        $this->assertArrayHasKey('max_uses', $result);
        $this->assertArrayHasKey('expires_at', $result);
        $this->assertSame(64, strlen($result['token']));
        $this->assertTrue(ctype_xdigit($result['token']));
        $this->assertSame(123, $result['event_post_id']);
        $this->assertSame('test@example.com', $result['recipient_email']);
        $this->assertSame(1, $result['max_uses']);
        $this->assertNotEmpty($result['expires_at']);
        $this->assertSame(42, $result['id']);
        $this->assertStringContainsString('?token=', $result['registration_url']);
    }

    public function test_create_defaults_max_uses_to_one(): void
    {
        $this->mockWpdb->shouldReceive('insert')->once()->andReturn(1);
        $this->mockWpdb->insert_id = 1;

        $service = new InvitationTokenService();
        $result  = $service->create(1, '');

        $this->assertSame(1, $result['max_uses']);
    }

    public function test_create_returns_wp_error_on_db_failure(): void
    {
        $this->mockWpdb->shouldReceive('insert')->once()->andReturn(false);

        $service = new InvitationTokenService();
        $result  = $service->create(1, '');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('db_error', $result->get_error_code());
    }

    // ================================================================
    // validate()
    // ================================================================

    public function test_validate_passes_for_valid_token(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')),
                'use_count'  => 0,
                'max_uses'   => 1,
            ]);

        $service = new InvitationTokenService();
        $result  = $service->validate('validtoken', 123);

        $this->assertTrue($result);
    }

    public function test_validate_fails_for_expired_token(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 1,
                'expires_at' => '2020-01-01 00:00:00',
                'use_count'  => 0,
                'max_uses'   => 1,
            ]);

        $service = new InvitationTokenService();
        $result  = $service->validate('expiredtoken', 123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('token_expired', $result->get_error_code());
    }

    public function test_validate_fails_for_exhausted_token(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')),
                'use_count'  => 5,
                'max_uses'   => 5,
            ]);

        $service = new InvitationTokenService();
        $result  = $service->validate('usedtoken', 123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('token_exhausted', $result->get_error_code());
    }

    public function test_validate_fails_for_inactive_token(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 0,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')),
                'use_count'  => 0,
                'max_uses'   => 1,
            ]);

        $service = new InvitationTokenService();
        $result  = $service->validate('disabledtoken', 123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('token_disabled', $result->get_error_code());
    }

    public function test_validate_fails_for_empty_token_string(): void
    {
        $service = new InvitationTokenService();
        $result  = $service->validate('', 123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('token_missing', $result->get_error_code());
    }

    public function test_validate_fails_for_nonexistent_token(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $service = new InvitationTokenService();
        $result  = $service->validate('badtoken', 123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('token_invalid', $result->get_error_code());
    }

    // ================================================================
    // consume()
    // ================================================================

    public function test_consume_increments_use_count_for_valid_token(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')),
                'use_count'  => 0,
                'max_uses'   => 1,
            ]);
        $this->mockWpdb->shouldReceive('query')
            ->once()
            ->andReturn(1);

        $service = new InvitationTokenService();
        $result  = $service->consume('token', 123);

        $this->assertTrue($result);
    }

    public function test_consume_fails_for_invalid_token(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $service = new InvitationTokenService();
        $result  = $service->consume('badtoken', 123);

        $this->assertFalse($result);
    }

    public function test_consume_fails_for_expired_token(): void
    {
        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 1,
                'expires_at' => '2020-01-01 00:00:00',
                'use_count'  => 0,
                'max_uses'   => 1,
            ]);

        $service = new InvitationTokenService();
        $result  = $service->consume('expiredtoken', 123);

        $this->assertFalse($result);
    }

    // ================================================================
    // deactivate()
    // ================================================================

    public function test_deactivate_works(): void
    {
        $this->mockWpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $service = new InvitationTokenService();
        $result  = $service->deactivate('token', 123);

        $this->assertTrue($result);
    }

    public function test_deactivate_returns_false_on_failure(): void
    {
        $this->mockWpdb->shouldReceive('update')
            ->once()
            ->andReturn(0);

        $service = new InvitationTokenService();
        $result  = $service->deactivate('token', 123);

        $this->assertFalse($result);
    }

    // ================================================================
    // validate_token_access()
    // ================================================================

    public function test_validate_token_access_only_blocks_by_invitation_events(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'publish'];
        });

        $service = new InvitationTokenService();
        $result  = $service->validate_token_access(true, 123, []);

        $this->assertTrue($result);
    }

    public function test_validate_token_access_passes_through_can_register(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'publish'];
        });

        $service = new InvitationTokenService();
        $result  = $service->validate_token_access(false, 123, []);

        $this->assertFalse($result);
    }

    public function test_validate_token_access_blocks_invalid_token_for_by_invitation(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'by_invitation'];
        });

        $service = new InvitationTokenService();
        $result  = $service->validate_token_access(true, 123, []);

        $this->assertFalse($result);
    }

    public function test_validate_token_access_allows_valid_token_for_by_invitation(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'by_invitation'];
        });

        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')),
                'use_count'  => 0,
                'max_uses'   => 1,
            ]);

        $service  = new InvitationTokenService();
        $result   = $service->validate_token_access(true, 123, ['token' => 'validtoken']);

        $this->assertTrue($result);
    }

    public function test_validate_token_access_uses_get_token_fallback(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'by_invitation'];
        });

        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')),
                'use_count'  => 0,
                'max_uses'   => 1,
            ]);

        $_GET['token'] = 'fromget';

        $service = new InvitationTokenService();
        $result  = $service->validate_token_access(true, 123, []);

        $this->assertTrue($result);

        unset($_GET['token']);
    }

    public function test_validate_token_access_context_takes_priority_over_get(): void
    {
        Functions\when('get_post')->alias(function ($id) {
            return (object) ['post_status' => 'by_invitation'];
        });

        $this->mockWpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'is_active'  => 1,
                'expires_at' => date('Y-m-d H:i:s', strtotime('+48 hours')),
                'use_count'  => 0,
                'max_uses'   => 1,
            ]);

        $_GET['token'] = 'fromget';

        $service = new InvitationTokenService();
        $result  = $service->validate_token_access(true, 123, ['token' => 'fromcontext']);

        $this->assertTrue($result);

        unset($_GET['token']);
    }

    // ================================================================
    // get_for_event()
    // ================================================================

    public function test_get_for_event_returns_tokens(): void
    {
        $expected = [
            (object) ['id' => 1, 'token' => 'aaa', 'event_post_id' => 123],
            (object) ['id' => 2, 'token' => 'bbb', 'event_post_id' => 123],
        ];

        $this->mockWpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($expected);

        $service = new InvitationTokenService();
        $result  = $service->get_for_event(123);

        $this->assertIsArray($result);
        $this->assertCount(2, $result);
        $this->assertSame('aaa', $result[0]->token);
        $this->assertSame('bbb', $result[1]->token);
    }

    public function test_get_for_event_returns_empty_array_when_none(): void
    {
        $this->mockWpdb->shouldReceive('get_results')
            ->once()
            ->andReturn(null);

        $service = new InvitationTokenService();
        $result  = $service->get_for_event(123);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }
}
