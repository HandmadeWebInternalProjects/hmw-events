<?php

/**
 * Tests for EmailService::queue_group_booking_confirmation() — the real
 * method body (handler replaced at the seam), covering the session list
 * construction for multi-session confirmation emails.
 *
 * @package HMWEvents\Tests\Unit\Services\Emails
 */

namespace HMWEvents\Tests\Unit\Services\Emails;

use HMWEvents\Services\Emails\EmailService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

if (!class_exists('FakeGroupEmailWpdb')) {
    class FakeGroupEmailWpdb
    {
        public $prefix = 'wp_';
        public $insert_id = 1;
        public $last_error = '';

        public function prepare($query, ...$args)
        {
            foreach ($args as $arg) {
                $pos = strpos($query, '%d');
                if ($pos === false) {
                    $pos = strpos($query, '%s');
                }
                if ($pos === false) {
                    break;
                }
                $query = substr_replace($query, is_numeric($arg) ? (string) (int) $arg : "'" . $arg . "'", $pos, 2);
            }
            return $query;
        }

        public function get_row($sql)
        {
            if (str_contains($sql, 'booking_groups')) {
                return (object) [
                    'booking_reference' => 'BKG-9',
                    'total_amount'      => '300.00',
                    'currency'          => 'AUD',
                ];
            }
            if (str_contains($sql, 'WHERE id = 3')) {
                return (object) [
                    'booking_number'      => 'CB-1',
                    'registrant_post_id'  => 77,
                    'event_post_id'       => 201,
                    'booking_group_id'    => 10,
                ];
            }
            return null;
        }

        public function get_var($sql)
        {
            if (str_contains($sql, 'WHERE id = 3')) {
                return 201;
            }
            if (str_contains($sql, 'WHERE id = 4')) {
                return 202;
            }
            return null;
        }
    }
}

class GroupBookingConfirmationEmailTest extends TestCase
{
    public array $queued = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $test = $this;
        $this->queued = [];

        $GLOBALS['wpdb'] = new FakeGroupEmailWpdb();

        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_option')->justReturn('j F Y H:i');
        Functions\when('date_i18n')->justReturn('3 August 2026');
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_get_post_parent_id')->alias(function ($id) {
            return $id === 202 ? 100 : 0;
        });
        Functions\when('get_post')->alias(function ($id) {
            $posts = [
                201 => ['post_type' => 'hmw_event', 'post_parent' => 0, 'post_title' => 'Series Parent', 'post_author' => 5],
                202 => ['post_type' => 'hmw_event', 'post_parent' => 100, 'post_title' => 'Session B', 'post_author' => 5],
            ];
            if (!isset($posts[$id])) {
                return null;
            }
            return new \WP_Post((object) array_merge($posts[$id], ['ID' => $id]));
        });
        Functions\when('get_the_title')->justReturn('Series Parent');
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === 'registrant_email') {
                return 'jane@example.com';
            }
            if ($key === 'registrant_first_name') {
                return 'Jane';
            }
            if ($key === '_event_start_date') {
                return '2026-08-03 11:15:00';
            }
            return '';
        });

        Patchwork\replace('HMWEvents\Services\Emails\EmailService::__construct', function () {
        });
        Patchwork\replace('HMWEvents\Services\Emails\EmailService::get_handler', function ($type) use ($test) {
            return new class($test) {
                private $test;
                public function __construct($test)
                {
                    $this->test = $test;
                }
                public function queue_notification(array $data)
                {
                    $this->test->queued[] = $data;
                    return 1;
                }
            };
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_queues_one_multiple_confirmation_with_session_list()
    {
        $service = new EmailService();
        $queue_id = $service->queue_group_booking_confirmation(3, [3, 4], []);

        $this->assertSame(1, $queue_id);
        $this->assertCount(1, $this->queued);

        $data = $this->queued[0];
        $this->assertSame('booking_confirmation_multiple', $data['email_type']);
        $this->assertSame('jane@example.com', $data['recipient_email']);
        $this->assertSame('Jane', $data['template_data']['first_name']);
        $this->assertSame('2', $data['template_data']['session_count']);
        $this->assertSame('BKG-9', $data['template_data']['booking_reference']);
        $this->assertStringContainsString('Series Parent', $data['template_data']['session_list']);
        $this->assertStringContainsString('Session B', $data['template_data']['session_list']);
        $this->assertStringContainsString('$300.00', $data['template_data']['amount']);
    }

    public function test_returns_false_without_recipient_email()
    {
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) {
            if ($key === 'registrant_email') {
                return '';
            }
            return '';
        });

        $service = new EmailService();

        $this->assertFalse($service->queue_group_booking_confirmation(3, [3], []));
    }
}
