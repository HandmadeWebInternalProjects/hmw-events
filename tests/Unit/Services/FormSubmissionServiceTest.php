<?php

/**
 * Tests for FormSubmissionService booking confirmation result.
 *
 * Focused coverage for the v3 submission pipeline's successful result payload,
 * specifically that the booking_number produced by the payment gateway is
 * preserved in the result returned to RegistrationFormRenderer::handle_submission().
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\FormSubmissionService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Patchwork;

class FormSubmissionServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $_GET   = [];
        $_POST  = [];
        $_FILES = [];

        Functions\when('__')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_post_meta')->justReturn('jane@example.com');
        Functions\when('get_the_title')->justReturn('Jane Doe');
        Functions\when('wp_insert_post')->alias(function ($data) {
            return 123;
        });
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('error_log')->justReturn(true);

        Patchwork\replace('HMWEvents\Services\EventDataService::get_is_free', function ($event_id) {
            return false;
        });
        Patchwork\replace('HMWEvents\Helpers\EventHelper::get_active_attendance_options', function ($event_id) {
            return [];
        });
        Patchwork\replace('HMWEvents\Helpers\EventHelper::check_booking_capacity', function ($event_id, $attendance_type, $bookings_requested, $people_requested) {
            return true;
        });
        Patchwork\replace('HMWEvents\Services\AttendancePricingService::resolve_configuration', function ($event_id, $option_type) {
            return [
                'composition' => [
                    'min_attendees' => 1,
                    'max_attendees' => 1,
                    'min_children'  => 0,
                    'min_adults'    => 1,
                ],
            ];
        });
        Patchwork\replace('HMWEvents\Services\AttendancePricingService::validate_composition', function ($composition, $attendees) {
            return true;
        });
        Patchwork\replace('HMWEvents\Services\AttendancePricingService::calculate_total', function ($event_id, $option_type, $attendees, $composition_override) {
            return [
                'total'       => 100.0,
                'base_amount' => 100.0,
                'surcharge'   => 0.0,
            ];
        });
        Patchwork\replace('HMWEvents\Services\PaymentGateway::__construct', function () {
        });
        Patchwork\replace('HMWEvents\Services\PaymentGateway::process_new_booking', function ($booking_data) {
            return [
                'booking_id'     => 99,
                'booking_number' => 'BK-2024-0001',
                'client_secret'  => 'cs_test_123',
                'intent_status'  => 'pending',
            ];
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function invoke_process(array $normalized, array $config): array
    {
        $service = new FormSubmissionService();
        $method  = new \ReflectionMethod(FormSubmissionService::class, 'process');
        $method->setAccessible(true);

        return $method->invoke($service, 123, $normalized, $config, false, []);
    }

    private function normalized_submission(): array
    {
        return [
            'event_id'        => 123,
            'attendance_type' => 'individual',
            'attendee_count'  => 1,
            'attendees'       => [
                [
                    'attendee_role' => 'adult',
                    'first_name'    => 'Jane',
                    'last_name'     => 'Doe',
                    'email'         => 'jane@example.com',
                ],
            ],
            'global'          => [],
        ];
    }

    private function single_field_config(): array
    {
        return [
            'sections'      => [
                [
                    'fields' => [
                        [
                            'key'          => 'first_name',
                            'label'        => 'First Name',
                            'type'         => 'text',
                            'required'     => true,
                            'source'       => 'registrant_meta',
                            'meta_key'     => 'registrant_first_name',
                            'per_attendee' => false,
                        ],
                        [
                            'key'          => 'last_name',
                            'label'        => 'Last Name',
                            'type'         => 'text',
                            'required'     => true,
                            'source'       => 'registrant_meta',
                            'meta_key'     => 'registrant_last_name',
                            'per_attendee' => false,
                        ],
                        [
                            'key'          => 'email',
                            'label'        => 'Email',
                            'type'         => 'email',
                            'required'     => true,
                            'source'       => 'registrant_meta',
                            'meta_key'     => 'registrant_email',
                            'per_attendee' => false,
                        ],
                    ],
                ],
            ],
            'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
        ];
    }

    public function test_successful_result_preserves_booking_number(): void
    {
        $result = $this->invoke_process($this->normalized_submission(), $this->single_field_config());

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('booking_number', $result);
        $this->assertSame('BK-2024-0001', $result['booking_number']);
    }

    public function test_successful_result_includes_booking_id_and_registrant_ids(): void
    {
        $result = $this->invoke_process($this->normalized_submission(), $this->single_field_config());

        $this->assertSame(99, $result['booking_id']);
        $this->assertSame([123], $result['registrant_ids']);
        $this->assertSame(100.0, $result['total']);
    }

    public function test_booking_number_falls_back_to_empty_string_when_gateway_omits_it(): void
    {
        Patchwork\replace('HMWEvents\Services\PaymentGateway::process_new_booking', function ($booking_data) {
            return ['booking_id' => 99];
        });

        $result = $this->invoke_process($this->normalized_submission(), $this->single_field_config());

        $this->assertArrayHasKey('booking_number', $result);
        $this->assertSame('', $result['booking_number']);
        $this->assertSame(99, $result['booking_id']);
    }

    public function test_submit_and_pay_rejects_when_bookings_disabled(): void
    {
        Patchwork\replace('HMWEvents\Services\EventDataService::bookings_enabled', function ($event_id) {
            return false;
        });

        $service = new FormSubmissionService();
        $result  = $service->submit_and_pay($this->normalized_submission(), 123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('bookings_disabled', $result->get_error_code());
    }

    public function test_multi_session_rows_receive_surcharge_on_primary_row(): void
    {
        $captured = [];

        Patchwork\replace('HMWEvents\Services\EventDataService::get_surcharge', function ($event_id) {
            return 5.0;
        });
        Patchwork\replace('HMWEvents\Services\EventDataService::get_session_booking_mode', function ($event_id) {
            return 'individual';
        });
        Patchwork\replace('HMWEvents\Services\SessionBookingService::expand_selection', function ($event_id, $picked, $mode = null, $attendees = 1) {
            return [
                'rows'         => [['event_post_id' => 101, 'booking_amount' => 100.0]],
                'total'        => 100.0,
                'booking_type' => 'package',
                'session_ids'  => [101],
                'slot'         => null,
            ];
        });
        Patchwork\replace('HMWEvents\Services\PaymentGateway::process_new_booking', function ($payload) use (&$captured) {
            $captured[] = $payload;
            return [
                'booking_id'     => 99,
                'booking_number' => 'BK-2024-0001',
            ];
        });

        $normalized                  = $this->normalized_submission();
        $normalized['selected_sessions'] = [101];

        $result = $this->invoke_process($normalized, $this->single_field_config());

        $this->assertSame(105.0, $result['total']);
        $this->assertCount(1, $captured);
        $this->assertSame('package', $captured[0]['booking_type']);
        $this->assertSame(105.0, $captured[0]['session_rows'][0]['booking_amount']);
        $this->assertSame(123, $captured[0]['group_metadata']['series_root']);
        $this->assertSame([101], $captured[0]['group_metadata']['session_ids']);
    }

    public function test_selected_sessions_are_ignored_in_track_mode(): void
    {
        $captured = [];

        Patchwork\replace('HMWEvents\Services\EventDataService::get_session_booking_mode', function ($event_id) {
            return 'track';
        });
        Patchwork\replace('HMWEvents\Services\SessionBookingService::expand_selection', function ($event_id, $picked, $mode = null, $attendees = 1) {
            return [
                'rows'         => [['event_post_id' => 101, 'booking_amount' => 100.0]],
                'total'        => 100.0,
                'booking_type' => 'recurring',
                'session_ids'  => [101],
                'slot'         => '10:00:00',
            ];
        });
        Patchwork\replace('HMWEvents\Services\PaymentGateway::process_new_booking', function ($payload) use (&$captured) {
            $captured[] = $payload;
            return [
                'booking_id'     => 99,
                'booking_number' => 'BK-2024-0001',
            ];
        });

        $normalized                      = $this->normalized_submission();
        $normalized['selected_sessions'] = [101];

        $result = $this->invoke_process($normalized, $this->single_field_config());

        $this->assertIsArray($result);
        $this->assertCount(1, $captured);
        $this->assertArrayNotHasKey('session_rows', $captured[0]);
        $this->assertArrayNotHasKey('booking_type', $captured[0]);
        $this->assertArrayNotHasKey('group_metadata', $captured[0]);
    }

    private function invoke_extract_payment_type(array $normalized, bool $allow_net_terms = true): string
    {
        Patchwork\replace('HMWEvents\Services\EventDataService::get_allow_net_terms', function ($event_id) use ($allow_net_terms) {
            return $allow_net_terms;
        });

        $service = new FormSubmissionService();
        $method  = new \ReflectionMethod(FormSubmissionService::class, 'extract_payment_type');
        $method->setAccessible(true);

        return $method->invoke($service, $normalized, 123);
    }

    public function test_extract_payment_type_returns_net_terms_when_allowed(): void
    {
        $result = $this->invoke_extract_payment_type([
            'attendees' => [['payment_type' => 'net_terms']],
            'global'    => [],
        ]);

        $this->assertSame('net_terms', $result);
    }

    public function test_extract_payment_type_reads_global_for_multi_booking(): void
    {
        $result = $this->invoke_extract_payment_type([
            'attendees' => [[]],
            'global'    => ['payment_type' => 'net_terms'],
        ]);

        $this->assertSame('net_terms', $result);
    }

    public function test_extract_payment_type_falls_back_to_full_when_net_terms_disallowed(): void
    {
        $result = $this->invoke_extract_payment_type([
            'attendees' => [['payment_type' => 'net_terms']],
            'global'    => [],
        ], false);

        $this->assertSame('full', $result);
    }

    public function test_extract_payment_type_defaults_to_full_for_unknown_value(): void
    {
        $result = $this->invoke_extract_payment_type([
            'attendees' => [['payment_type' => 'bitcoin']],
            'global'    => [],
        ]);

        $this->assertSame('full', $result);
    }

    public function test_extract_payment_type_defaults_to_full_when_missing(): void
    {
        $result = $this->invoke_extract_payment_type([
            'attendees' => [[]],
            'global'    => [],
        ]);

        $this->assertSame('full', $result);
    }
}
