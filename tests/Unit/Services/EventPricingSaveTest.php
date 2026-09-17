<?php

namespace HMWEvents\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Admin\EventPricing;
use PHPUnit\Framework\TestCase;
use Patchwork;

class EventPricingSaveTest extends TestCase
{
    private object $wpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $this->wpdb = new class {
            public array $updates = [];

            public function update($table, $data, $where, $format = [], $where_format = [])
            {
                $this->updates[] = [
                    'table'        => $table,
                    'data'         => $data,
                    'where'        => $where,
                    'format'       => $format,
                    'where_format' => $where_format,
                ];

                return 1;
            }

            public function prepare($query, ...$args)
            {
                return $query;
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        Functions\when('wp_verify_nonce')->justReturn(true);
        Functions\when('current_user_can')->justReturn(true);
        Functions\when('wp_unslash')->alias(fn($value) => $value);
        Functions\when('wp_kses_post')->alias(fn($value) => 'KSES:' . $value);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('sanitize_key')->alias(function ($value) {
            return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value));
        });
    }

    protected function tearDown(): void
    {
        unset(
            $GLOBALS['wpdb'],
            $_POST['hmwevents_event_pricing_nonce'],
            $_POST['hmwevents_attendance_options']
        );

        Patchwork\restoreAll();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_save_persists_sanitized_description_with_flat_pricing(): void
    {
        $_POST['hmwevents_event_pricing_nonce'] = 'valid-nonce';
        $_POST['hmwevents_attendance_options'] = [
            5 => [
                'price_mode'  => 'flat',
                'price'       => '100',
                'description' => '<ul><li>Includes catering</li></ul>',
            ],
        ];

        (new EventPricing())->save(123);

        $this->assertCount(1, $this->wpdb->updates);

        $update = $this->wpdb->updates[0];

        $this->assertSame('wp_hmwevents_event_attendance_options', $update['table']);

        $this->assertSame('KSES:<ul><li>Includes catering</li></ul>', $update['data']['description']);
        $this->assertSame(100.0, $update['data']['price']);
        $this->assertSame('flat', $update['data']['price_mode']);
        $this->assertNull($update['data']['pricing_rules']);
        $this->assertSame('2026-01-01 00:00:00', $update['data']['updated_at']);

        $this->assertSame(
            ['id' => 5, 'event_post_id' => 123, 'is_active' => 1],
            $update['where']
        );

        $this->assertSame(['%f', '%s', '%s', '%s', '%s'], $update['format']);
        $this->assertSame(['%d', '%d', '%d'], $update['where_format']);
    }

    public function test_save_defaults_description_to_sanitized_empty_string_when_absent(): void
    {
        $_POST['hmwevents_event_pricing_nonce'] = 'valid-nonce';
        $_POST['hmwevents_attendance_options'] = [
            7 => [
                'price_mode' => 'flat',
                'price'      => '50',
            ],
        ];

        (new EventPricing())->save(123);

        $this->assertCount(1, $this->wpdb->updates);
        $this->assertSame('KSES:', $this->wpdb->updates[0]['data']['description']);
        $this->assertSame(50.0, $this->wpdb->updates[0]['data']['price']);
    }

    public function test_save_skips_update_when_nonce_is_missing(): void
    {
        unset($_POST['hmwevents_event_pricing_nonce']);
        $_POST['hmwevents_attendance_options'] = [
            5 => ['price_mode' => 'flat', 'price' => '100'],
        ];

        (new EventPricing())->save(123);

        $this->assertSame([], $this->wpdb->updates);
    }

    public function test_save_skips_update_when_attendance_options_are_empty(): void
    {
        $_POST['hmwevents_event_pricing_nonce'] = 'valid-nonce';
        $_POST['hmwevents_attendance_options'] = [];

        (new EventPricing())->save(123);

        $this->assertSame([], $this->wpdb->updates);
    }
}
