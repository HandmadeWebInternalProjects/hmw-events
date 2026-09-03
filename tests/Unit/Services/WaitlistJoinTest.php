<?php

/**
 * Tests for WaitlistService::join().
 *
 * @package HMWEvents\Tests
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\WaitlistService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class WaitlistJoinTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->postmeta = 'wp_postmeta';
        $GLOBALS['wpdb']->insert_id = 42;
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

        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->alias(function ($email) {
            return filter_var(trim((string) $email), FILTER_SANITIZE_EMAIL);
        });
        Functions\when('is_email')->alias(function ($email) {
            return filter_var((string) $email, FILTER_VALIDATE_EMAIL) ? $email : false;
        });
        Functions\when('get_post')->justReturn(null);
        Functions\when('wp_insert_post')->justReturn(77);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('wp_delete_post')->justReturn(77);
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('do_action')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeEventPost(): object
    {
        return (object) [
            'ID'        => 5,
            'post_type' => 'hmw_event',
        ];
    }

    public function test_join_rejects_invalid_email(): void
    {
        $service = new WaitlistService();
        $result = $service->join(5, 'Jane', 'Doe', 'not-an-email');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_email', $result->get_error_code());
    }

    public function test_join_rejects_unknown_event(): void
    {
        $GLOBALS['wpdb']->shouldNotReceive('insert');

        $service = new WaitlistService();
        $result = $service->join(5, 'Jane', 'Doe', 'jane@example.com');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_event', $result->get_error_code());
    }

    public function test_join_rejects_duplicate_active_entry(): void
    {
        Functions\when('get_post')->justReturn($this->makeEventPost());

        $GLOBALS['wpdb']->shouldReceive('get_var')->once()->andReturn(9);
        $GLOBALS['wpdb']->shouldNotReceive('insert');

        $service = new WaitlistService();
        $result = $service->join(5, 'Jane', 'Doe', 'jane@example.com');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('already_waitlisted', $result->get_error_code());
    }

    public function test_join_creates_registrant_and_entry_at_next_position(): void
    {
        Functions\when('get_post')->justReturn($this->makeEventPost());

        $capturedInsert = null;
        $capturedRegistrantArgs = null;
        $GLOBALS['wpdb']->shouldReceive('get_var')->twice()->andReturn(0, 4);
        $GLOBALS['wpdb']->shouldReceive('insert')
            ->once()
            ->andReturnUsing(function ($table, $data) use (&$capturedInsert) {
                $capturedInsert = ['table' => $table, 'data' => $data];
                return 1;
            });

        Functions\when('wp_insert_post')->alias(function ($args) use (&$capturedRegistrantArgs) {
            $capturedRegistrantArgs = $args;
            return 77;
        });

        $capturedAction = null;
        Functions\when('do_action')->alias(function (...$args) use (&$capturedAction) {
            $capturedAction = $args;
            return true;
        });

        $service = new WaitlistService();
        $result = $service->join(5, 'Jane', 'Doe', 'jane@example.com');

        $this->assertNotInstanceOf(\WP_Error::class, $result);
        $this->assertSame(5, $result->position, 'position should be MAX(position) + 1');
        $this->assertSame('waiting', $result->status);
        $this->assertSame('jane@example.com', $result->recipient_email);
        $this->assertSame(42, $result->id);

        $this->assertSame('hmw_registrant', $capturedRegistrantArgs['post_type']);
        $this->assertSame([
            'registrant_first_name' => 'Jane',
            'registrant_last_name'  => 'Doe',
            'registrant_email'      => 'jane@example.com',
            '_event_id'             => 5,
            '_waitlisted'           => 1,
        ], $capturedRegistrantArgs['meta_input']);

        $this->assertSame('waiting', $capturedInsert['data']['status']);
        $this->assertSame(5, $capturedInsert['data']['event_post_id']);
        $this->assertSame(77, $capturedInsert['data']['registrant_post_id']);
        $this->assertSame(5, $capturedInsert['data']['position']);

        $this->assertNotNull($capturedAction, 'joined action should have fired');
        $this->assertSame(WaitlistService::JOINED_HOOK, $capturedAction[0]);
        $this->assertSame(5, $capturedAction[2]);
    }

    public function test_join_cleans_up_registrant_when_insert_fails(): void
    {
        Functions\when('get_post')->justReturn($this->makeEventPost());

        $deletedId = null;
        $GLOBALS['wpdb']->shouldReceive('get_var')->twice()->andReturn(0, 4);
        $GLOBALS['wpdb']->shouldReceive('insert')->once()->andReturn(0);

        Functions\when('wp_delete_post')->alias(function ($id) use (&$deletedId) {
            $deletedId = $id;
            return 77;
        });

        $service = new WaitlistService();
        $result = $service->join(5, 'Jane', 'Doe', 'jane@example.com');

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('waitlist_failed', $result->get_error_code());
        $this->assertSame(77, $deletedId, 'orphan registrant should be deleted');
    }
}
