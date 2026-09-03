<?php

namespace HMWEvents\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Services\AttendancePricingService;
use PHPUnit\Framework\TestCase;
use Patchwork;

class AttendancePricingServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        Functions\when('sanitize_key')->alias(function ($value) {
            $value = strtolower((string) $value);

            return preg_replace('/[^a-z0-9_\-]/', '', $value);
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function mockCalculateTotalEnvironment(array $options, ?string $start_date = null): void
    {
        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::get_active_attendance_options', function () use ($options) {
            return $options;
        });

        \Patchwork\replace('HMWEvents\\Services\\FormConfigResolver::resolve', function () {
            return ['multi_booking' => ['max' => 10]];
        });

        Functions\when('get_post')->justReturn((object) ['post_type' => 'hmw_event']);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single = true) use ($start_date) {
            if ($key === '_event_start_date' && $start_date !== null) {
                return $start_date;
            }

            return '';
        });
        Functions\when('__')->returnArg();
        Functions\when('_n')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('is_wp_error')->alias(function ($value) {
            return $value instanceof \WP_Error;
        });
    }

    public function test_is_multi_options_returns_false_for_single_individual_option(): void
    {
        $options = [
            ['option_type' => 'individual', 'label' => 'Individual', 'price' => 100],
        ];

        $this->assertFalse(AttendancePricingService::is_multi_options($options));
    }

    public function test_is_multi_options_returns_true_for_multiple_options(): void
    {
        $options = [
            ['option_type' => 'individual', 'label' => 'Individual', 'price' => 100],
            ['option_type' => 'couple', 'label' => 'Couple', 'price' => 650],
        ];

        $this->assertTrue(AttendancePricingService::is_multi_options($options));
    }

    public function test_is_multi_options_returns_true_for_single_parent_child_option(): void
    {
        $options = [
            ['option_type' => 'parent_child', 'label' => 'Parent & Child', 'price' => 350],
        ];

        $this->assertTrue(AttendancePricingService::is_multi_options($options));
    }

    public function test_is_multi_options_returns_true_for_option_with_min_attendees_above_one(): void
    {
        $options = [
            [
                'option_type' => 'individual',
                'label'       => 'Individual',
                'price'       => 100,
                'composition' => ['min_attendees' => 2],
            ],
        ];

        $this->assertTrue(AttendancePricingService::is_multi_options($options));
    }

    public function test_normalize_mode_defaults_to_flat(): void
    {
        $this->assertSame(AttendancePricingService::MODE_FLAT, AttendancePricingService::normalize_mode(''));
        $this->assertSame(AttendancePricingService::MODE_FLAT, AttendancePricingService::normalize_mode('bogus'));
    }

    public function test_normalize_mode_infers_age_band_from_legacy_rules(): void
    {
        $rules = [['role' => 'any', 'min_age' => 0, 'max_age' => 5, 'price' => 0]];

        $this->assertSame(AttendancePricingService::MODE_AGE_BAND, AttendancePricingService::normalize_mode('', $rules));
        $this->assertSame(AttendancePricingService::MODE_PER_ATTENDEE, AttendancePricingService::normalize_mode('per_attendee', $rules));
    }

    public function test_price_for_role_returns_adult_and_child_prices(): void
    {
        $rules = [
            ['role' => 'adult', 'price' => 100],
            ['role' => 'child', 'price' => 50],
        ];

        $service = new AttendancePricingService();

        $this->assertSame(100.0, $service->price_for_role($rules, 'adult'));
        $this->assertSame(50.0, $service->price_for_role($rules, 'child'));
        $this->assertSame(100.0, $service->price_for_role($rules, 'anything-else'));
    }

    public function test_price_for_role_returns_null_for_unknown_role(): void
    {
        $rules = [
            ['role' => 'adult', 'price' => 100],
        ];

        $service = new AttendancePricingService();

        $this->assertNull($service->price_for_role($rules, 'child'));
    }

    public function test_price_for_attendee_matches_age_band(): void
    {
        $rules = [
            ['role' => 'any', 'min_age' => 0, 'max_age' => 5, 'price' => 0],
            ['role' => 'any', 'min_age' => 6, 'max_age' => 17, 'price' => 50],
            ['role' => 'adult', 'min_age' => 18, 'max_age' => null, 'price' => 100],
        ];

        $service = new AttendancePricingService();

        $this->assertSame(0.0, $service->price_for_attendee($rules, 'child', 3));
        $this->assertSame(50.0, $service->price_for_attendee($rules, 'adult', 10));
        $this->assertSame(100.0, $service->price_for_attendee($rules, 'adult', 30));
        $this->assertNull($service->price_for_attendee($rules, 'adult', null));
    }

    public function test_calculate_total_per_attendee_sums_role_prices(): void
    {
        $row = (object) [
            'id'            => 1,
            'option_type'   => 'parent_child',
            'label'         => 'Parent + Child',
            'price'         => 0.0,
            'price_mode'    => 'per_attendee',
            'pricing_rules' => json_encode([
                ['role' => 'adult', 'min_age' => 0, 'max_age' => null, 'price' => 100],
                ['role' => 'child', 'min_age' => 0, 'max_age' => null, 'price' => 50],
            ]),
            'capacity'      => null,
        ];

        $this->mockCalculateTotalEnvironment([$row]);

        $service = new AttendancePricingService();
        $result = $service->calculate_total(1, 'parent_child', [
            ['role' => 'adult'],
            ['role' => 'child'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(150.0, $result['total']);
        $this->assertSame(150.0, $result['base_amount']);
        $this->assertSame('per_attendee', $result['mode']);
    }

    public function test_calculate_total_flat_returns_base_price(): void
    {
        $row = (object) [
            'id'            => 1,
            'option_type'   => 'individual',
            'label'         => 'Individual',
            'price'         => 120.0,
            'price_mode'    => 'flat',
            'pricing_rules' => null,
            'capacity'      => null,
        ];

        $this->mockCalculateTotalEnvironment([$row]);

        $service = new AttendancePricingService();
        $result = $service->calculate_total(1, 'individual', [
            ['role' => 'adult'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(120.0, $result['total']);
        $this->assertSame(120.0, $result['base_amount']);
        $this->assertSame('flat', $result['mode']);
    }

    public function test_calculate_total_age_band_requires_dob(): void
    {
        $row = (object) [
            'id'            => 1,
            'option_type'   => 'individual',
            'label'         => 'Individual',
            'price'         => 0.0,
            'price_mode'    => 'age_band',
            'pricing_rules' => json_encode([
                ['role' => 'any', 'min_age' => 0, 'max_age' => 5, 'price' => 0],
                ['role' => 'any', 'min_age' => 6, 'max_age' => null, 'price' => 50],
            ]),
            'capacity'      => null,
        ];

        $this->mockCalculateTotalEnvironment([$row], '2026-08-17 10:00:00');

        $service = new AttendancePricingService();
        $result = $service->calculate_total(1, 'individual', [
            ['role' => 'adult'],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('missing_date_of_birth', $result->get_error_code());
    }

    public function test_resolve_default_option_type_returns_individual_when_no_requested(): void
    {
        $this->assertSame('individual', AttendancePricingService::resolve_default_option_type([], ''));
    }

    public function test_resolve_default_option_type_returns_requested_when_no_rows(): void
    {
        $this->assertSame('couple', AttendancePricingService::resolve_default_option_type([], 'couple'));
    }

    public function test_resolve_default_option_type_matches_requested_row(): void
    {
        $rows = [(object) ['option_type' => 'individual'], (object) ['option_type' => 'couple']];

        $this->assertSame('couple', AttendancePricingService::resolve_default_option_type($rows, 'couple'));
    }

    public function test_resolve_default_option_type_falls_back_to_first_row(): void
    {
        $rows = [(object) ['option_type' => 'individual'], (object) ['option_type' => 'couple']];

        $this->assertSame('individual', AttendancePricingService::resolve_default_option_type($rows, 'professional'));
    }

    public function test_build_selection_context_individual_flat(): void
    {
        $rows = [
            (object) [
                'id'            => 1,
                'option_type'   => 'individual',
                'label'         => 'Individual',
                'price'         => 120.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows, [
            '_event_is_free'   => '',
            '_event_price'     => 120.0,
            '_event_surcharge' => 5.0,
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'individual');

        $this->assertSame('individual', $ctx['default_option_type']);
        $this->assertFalse($ctx['is_free']);
        $this->assertSame(120.0, $ctx['base_price']);
        $this->assertSame(5.0, $ctx['surcharge']);
        $this->assertSame(120.0, $ctx['default_display_price']);
        $this->assertTrue($ctx['has_paid_option']);
        $this->assertFalse($ctx['has_age_pricing']);
        $this->assertFalse($ctx['effective_multi']);
        $this->assertSame(1, $ctx['min_attendees']);
        $this->assertSame(10, $ctx['max_attendees']);
        $this->assertSame(['adult'], $ctx['allowed_roles']);
        $this->assertCount(1, $ctx['options']);
        $this->assertSame('individual', $ctx['options'][0]['option_type']);
    }

    public function test_build_selection_context_parent_child_is_effective_multi(): void
    {
        $rows = [
            (object) [
                'id'            => 1,
                'option_type'   => 'parent_child',
                'label'         => 'Parent + Child',
                'price'         => 0.0,
                'price_mode'    => 'per_attendee',
                'pricing_rules' => json_encode([
                    ['role' => 'adult', 'min_age' => 0, 'max_age' => null, 'price' => 100],
                    ['role' => 'child', 'min_age' => 0, 'max_age' => null, 'price' => 50],
                ]),
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows, [
            '_event_is_free'   => '',
            '_event_price'     => 0.0,
            '_event_surcharge' => 0.0,
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'parent_child');

        $this->assertSame('parent_child', $ctx['default_option_type']);
        $this->assertTrue($ctx['effective_multi']);
        $this->assertSame(['adult', 'child'], $ctx['allowed_roles']);
        $this->assertSame(2, $ctx['min_attendees']);
        $this->assertTrue($ctx['has_paid_option']);
    }

    public function test_build_selection_context_detects_age_pricing(): void
    {
        $rows = [
            (object) [
                'id'            => 1,
                'option_type'   => 'individual',
                'label'         => 'Individual',
                'price'         => 0.0,
                'price_mode'    => 'age_band',
                'pricing_rules' => json_encode([
                    ['role' => 'any', 'min_age' => 0, 'max_age' => 5, 'price' => 0],
                    ['role' => 'any', 'min_age' => 6, 'max_age' => null, 'price' => 50],
                ]),
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows, [
            '_event_is_free'   => '',
            '_event_price'     => 0.0,
            '_event_surcharge' => 0.0,
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'individual');

        $this->assertTrue($ctx['has_age_pricing']);
        $this->assertSame('age_band', $ctx['options'][0]['price_mode']);
    }

    public function test_build_selection_context_free_event_has_zero_prices(): void
    {
        $rows = [
            (object) [
                'id'            => 1,
                'option_type'   => 'individual',
                'label'         => 'Individual',
                'price'         => 50.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows, [
            '_event_is_free'   => '1',
            '_event_price'     => 50.0,
            '_event_surcharge' => 2.0,
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'individual');

        $this->assertTrue($ctx['is_free']);
        $this->assertSame(0.0, $ctx['base_price']);
        $this->assertSame(0.0, $ctx['surcharge']);
        $this->assertSame(0.0, $ctx['default_display_price']);
        $this->assertFalse($ctx['has_paid_option']);
    }

    public function test_build_selection_context_honours_multi_enabled_baseline(): void
    {
        $rows = [
            (object) [
                'id'            => 1,
                'option_type'   => 'individual',
                'label'         => 'Individual',
                'price'         => 10.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows, [
            '_event_is_free'   => '',
            '_event_price'     => 10.0,
            '_event_surcharge' => 0.0,
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(
            1,
            'individual',
            ['enabled' => true, 'min' => 1, 'max' => 5],
            true
        );

        $this->assertTrue($ctx['effective_multi']);
        $this->assertSame(5, $ctx['max_attendees']);
    }

    private function mockSelectionContextEnvironment(array $rows, array $meta = []): void
    {
        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::get_active_attendance_options', function () use ($rows) {
            return $rows;
        });

        \Patchwork\replace('HMWEvents\\Services\\FormConfigResolver::resolve', function () {
            return ['multi_booking' => ['max' => 10]];
        });

        Functions\when('get_post')->justReturn((object) ['post_type' => 'hmw_event']);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single = true) use ($meta) {
            if (array_key_exists($key, $meta)) {
                return $meta[$key];
            }

            return '';
        });
        Functions\when('__')->returnArg();
        Functions\when('_n')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('is_wp_error')->alias(function ($value) {
            return $value instanceof \WP_Error;
        });
    }
}
