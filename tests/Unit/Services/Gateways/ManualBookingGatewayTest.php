<?php

namespace HMWEvents\Tests\Unit\Services\Gateways;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Registry\RegistrationFieldRegistry;
use HMWEvents\Services\Gateways\ManualBookingGateway;
use PHPUnit\Framework\TestCase;
use Patchwork;

class ManualBookingGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 4) . '/');
        }

        Functions\when('sanitize_key')->alias(function ($value) {
            $value = strtolower((string) $value);

            return preg_replace('/[^a-z0-9_\-]/', '', $value);
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('sanitize_textarea_field')->returnArg();
        Functions\when('__')->returnArg();
        Functions\when('_n')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('is_wp_error')->alias(function ($value) {
            return $value instanceof \WP_Error;
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invokePrivate(object $object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($object, $args);
    }

    public function test_normalize_attendees_sanitizes_roles_and_details(): void
    {
        \Patchwork\replace('HMWEvents\\Services\\EventFormFieldsResolver::for_event', function () {
            return [
                'allergies' => [
                    'key'          => 'allergies',
                    'type'         => 'textarea',
                    'source'       => RegistrationFieldRegistry::SOURCE_BOOKING_DETAILS,
                    'per_attendee' => true,
                ],
                'first_name' => [
                    'key'          => 'first_name',
                    'type'         => 'text',
                    'source'       => RegistrationFieldRegistry::SOURCE_REGISTRANT_META,
                    'per_attendee' => false,
                ],
            ];
        });

        $gateway = new ManualBookingGateway();

        $raw = [
            ['attendee_role' => 'child', 'date_of_birth' => '2020-01-01', 'child_name' => 'Bob', 'allergies' => 'nuts'],
            ['attendee_role' => 'ADULT'],
            'not-an-array',
        ];

        $result = $this->invokePrivate($gateway, 'normalize_attendees', [$raw, 1]);

        $this->assertCount(2, $result);
        $this->assertSame('child', $result[0]['role']);
        $this->assertSame('2020-01-01', $result[0]['date_of_birth']);
        $this->assertSame('Bob', $result[0]['child_name']);
        $this->assertSame('nuts', $result[0]['allergies']);

        $this->assertSame('adult', $result[1]['role']);
        $this->assertSame('', $result[1]['date_of_birth']);
        $this->assertArrayNotHasKey('allergies', $result[1]);
    }

    public function test_normalize_attendees_returns_empty_for_non_array(): void
    {
        $gateway = new ManualBookingGateway();

        $this->assertSame([], $this->invokePrivate($gateway, 'normalize_attendees', ['not-an-array', 1]));
    }

    public function test_normalize_attendees_captures_registrant_meta_per_attendee_fields(): void
    {
        \Patchwork\replace('HMWEvents\\Services\\EventFormFieldsResolver::for_event', function () {
            return [
                'first_name' => [
                    'key'          => 'first_name',
                    'type'         => 'text',
                    'source'       => RegistrationFieldRegistry::SOURCE_REGISTRANT_META,
                    'per_attendee' => true,
                ],
            ];
        });

        $gateway = new ManualBookingGateway();
        $raw = [['attendee_role' => 'adult', 'first_name' => 'Jane']];

        $result = $this->invokePrivate($gateway, 'normalize_attendees', [$raw, 1]);

        $this->assertSame('Jane', $result[0]['first_name']);
    }

    public function test_resolve_customer_field_falls_back_to_attendee_entry(): void
    {
        $gateway = new ManualBookingGateway();

        $booking_data = ['event_id' => 1];
        $attendees = [['first_name' => 'Jane', 'last_name' => 'Doe']];

        $first = $this->invokePrivate($gateway, 'resolve_customer_field', [$booking_data, 'first_name', $attendees]);
        $last  = $this->invokePrivate($gateway, 'resolve_customer_field', [$booking_data, 'last_name', $attendees]);
        $email = $this->invokePrivate($gateway, 'resolve_customer_field', [$booking_data, 'email', $attendees]);

        $this->assertSame('Jane', $first);
        $this->assertSame('Doe', $last);
        $this->assertSame('', $email);
    }

    public function test_resolve_customer_field_prefers_top_level_canonical(): void
    {
        $gateway = new ManualBookingGateway();

        $booking_data = ['customer_first_name' => 'TopLevel'];
        $attendees = [['first_name' => 'Attendee']];

        $result = $this->invokePrivate($gateway, 'resolve_customer_field', [$booking_data, 'first_name', $attendees]);

        $this->assertSame('TopLevel', $result);
    }

    public function test_validate_attendance_selection_rejects_unavailable_option(): void
    {
        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::get_active_attendance_options', function () {
            return [(object) ['option_type' => 'couple']];
        });
        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::resolve_attendance_option', function () {
            return null;
        });

        $gateway = new ManualBookingGateway();

        $result = $this->invokePrivate($gateway, 'validate_attendance_selection', [1, 'couple', []]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_attendance_type', $result->get_error_code());
    }

    public function test_validate_attendance_selection_enforces_couple_composition(): void
    {
        $row = (object) [
            'id'            => 1,
            'option_type'   => 'couple',
            'label'         => 'Couple',
            'price'         => 650.0,
            'price_mode'    => 'flat',
            'pricing_rules' => null,
            'capacity'      => null,
        ];

        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::get_active_attendance_options', function () use ($row) {
            return [$row];
        });
        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::resolve_attendance_option', function () use ($row) {
            return $row;
        });
        \Patchwork\replace('HMWEvents\\Services\\FormConfigResolver::resolve', function () {
            return null;
        });
        Functions\when('get_post')->justReturn((object) ['post_type' => 'hmw_event']);
        Functions\when('get_post_meta')->justReturn('');

        $gateway = new ManualBookingGateway();

        $result = $this->invokePrivate($gateway, 'validate_attendance_selection', [
            1,
            'couple',
            [['role' => 'adult']],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_attendee_count', $result->get_error_code());
    }

    public function test_validate_attendance_selection_accepts_parent_child_pair(): void
    {
        $row = (object) [
            'id'            => 1,
            'option_type'   => 'parent_child',
            'label'         => 'Parent + Child',
            'price'         => 0.0,
            'price_mode'    => 'per_attendee',
            'pricing_rules' => json_encode([
                ['role' => 'adult', 'price' => 100],
                ['role' => 'child', 'price' => 50],
            ]),
            'capacity'      => null,
        ];

        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::get_active_attendance_options', function () use ($row) {
            return [$row];
        });
        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::resolve_attendance_option', function () use ($row) {
            return $row;
        });
        \Patchwork\replace('HMWEvents\\Services\\FormConfigResolver::resolve', function () {
            return null;
        });
        Functions\when('get_post')->justReturn((object) ['post_type' => 'hmw_event']);
        Functions\when('get_post_meta')->justReturn('');

        $gateway = new ManualBookingGateway();

        $result = $this->invokePrivate($gateway, 'validate_attendance_selection', [
            1,
            'parent_child',
            [['role' => 'adult'], ['role' => 'child']],
        ]);

        $this->assertTrue($result);
    }

    public function test_extract_booking_details_builds_children_list_for_parent_child(): void
    {
        \Patchwork\replace('HMWEvents\\Services\\EventFormFieldsResolver::for_event', function () {
            return [
                'heard_about' => [
                    'key'          => 'heard_about',
                    'type'         => 'select',
                    'source'       => RegistrationFieldRegistry::SOURCE_BOOKING_DETAILS,
                    'per_attendee' => false,
                ],
                'allergies' => [
                    'key'          => 'allergies',
                    'type'         => 'textarea',
                    'source'       => RegistrationFieldRegistry::SOURCE_BOOKING_DETAILS,
                    'per_attendee' => true,
                ],
                'first_name' => [
                    'key'          => 'first_name',
                    'type'         => 'text',
                    'source'       => RegistrationFieldRegistry::SOURCE_REGISTRANT_META,
                    'per_attendee' => false,
                ],
            ];
        });
        \Patchwork\replace('HMWEvents\\Services\\FormConfigResolver::resolve', function () {
            return ['multi_booking' => ['enabled' => true, 'mode' => 'parent_children']];
        });

        $gateway = new ManualBookingGateway();

        $booking_data = [
            'event_id'    => 1,
            'heard_about' => 'google',
            'first_name'  => 'Jane',
        ];

        $attendees = [
            ['role' => 'adult', 'date_of_birth' => '1990-01-01', 'child_name' => '', 'allergies' => ''],
            ['role' => 'child', 'date_of_birth' => '2020-01-01', 'child_name' => 'Bob', 'allergies' => 'nuts'],
        ];

        $details = $this->invokePrivate($gateway, 'extract_booking_details', [$booking_data, $attendees]);

        $this->assertSame('google', $details['heard_about']);
        $this->assertArrayNotHasKey('first_name', $details);
        $this->assertArrayNotHasKey('allergies', $details);

        $this->assertArrayHasKey('children', $details);
        $this->assertCount(1, $details['children']);
        $this->assertSame('Bob', $details['children'][0]['child_name']);
        $this->assertSame('2020-01-01', $details['children'][0]['date_of_birth']);
        $this->assertSame('nuts', $details['children'][0]['allergies']);
    }
}
