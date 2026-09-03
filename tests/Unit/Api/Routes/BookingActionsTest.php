<?php

namespace HMWEvents\Tests\Unit\Api\Routes;

use HMWEvents\Api\Routes\BookingActions;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class BookingActionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }

        Functions\when('register_rest_route')->justReturn(true);
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_sanitize_attendees_arg_returns_empty_for_non_array(): void
    {
        $route = new BookingActions();

        $this->assertSame([], $route->sanitize_attendees_arg('not-an-array'));
        $this->assertSame([], $route->sanitize_attendees_arg(null));
    }

    public function test_sanitize_attendees_arg_sanitizes_nested_values_and_preserves_shape(): void
    {
        $route = new BookingActions();

        $raw = [
            ['attendee_role' => 'child', 'date_of_birth' => '2020-01-01', 'allergies' => 'nuts'],
            ['attendee_role' => 'adult'],
            'not-an-array',
        ];

        $result = $route->sanitize_attendees_arg($raw);

        $this->assertCount(3, $result);
        $this->assertSame('child', $result[0]['attendee_role']);
        $this->assertSame('2020-01-01', $result[0]['date_of_birth']);
        $this->assertSame('adult', $result[1]['attendee_role']);
        $this->assertSame([], $result[2]);
    }
}
