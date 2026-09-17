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

    private function mockCalculateTotalEnvironment(array $options, ?string $start_date = null, array $meta = []): void
    {
        \Patchwork\replace('HMWEvents\\Helpers\\EventHelper::get_active_attendance_options', function () use ($options) {
            return $options;
        });

        \Patchwork\replace('HMWEvents\\Services\\FormConfigResolver::resolve', function () {
            return ['multi_booking' => ['max' => 10]];
        });

        Functions\when('get_post')->justReturn((object) ['post_type' => 'hmw_event']);
        Functions\when('get_post_meta')->alias(function ($id, $key, $single = true) use ($start_date, $meta) {
            if (array_key_exists($key, $meta)) {
                return $meta[$key];
            }

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

    public function test_calculate_total_flat_applies_percent_surcharge(): void
    {
        $row = (object) [
            'id'            => 1,
            'option_type'   => 'individual',
            'label'         => 'Individual',
            'price'         => 250.0,
            'price_mode'    => 'flat',
            'pricing_rules' => null,
            'capacity'      => null,
        ];

        $this->mockCalculateTotalEnvironment([$row], null, [
            '_event_surcharge'      => 2,
            '_event_surcharge_type' => 'percent',
        ]);

        $service = new AttendancePricingService();
        $result = $service->calculate_total(1, 'individual', [
            ['role' => 'adult'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(255.0, $result['total']);
        $this->assertSame(250.0, $result['base_amount']);
        $this->assertSame(5.0, $result['surcharge']);
        $this->assertSame('flat', $result['mode']);
    }

    public function test_calculate_total_per_attendee_applies_percent_surcharge(): void
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

        $this->mockCalculateTotalEnvironment([$row], null, [
            '_event_surcharge'      => 10,
            '_event_surcharge_type' => 'percent',
        ]);

        $service = new AttendancePricingService();
        $result = $service->calculate_total(1, 'parent_child', [
            ['role' => 'adult'],
            ['role' => 'child'],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(150.0, $result['base_amount']);
        $this->assertSame(15.0, $result['surcharge']);
        $this->assertSame(165.0, $result['total']);
        $this->assertSame('per_attendee', $result['mode']);
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

    public function test_resolve_default_option_key_returns_individual_when_no_requested(): void
    {
        $this->assertSame('individual', AttendancePricingService::resolve_default_option_key([], ''));
    }

    public function test_resolve_default_option_key_returns_requested_when_no_rows(): void
    {
        $this->assertSame('couple', AttendancePricingService::resolve_default_option_key([], 'couple'));
    }

    public function test_resolve_default_option_key_matches_requested_row_key(): void
    {
        $rows = [(object) ['option_key' => 'individual'], (object) ['option_key' => 'couple']];

        $this->assertSame('couple', AttendancePricingService::resolve_default_option_key($rows, 'couple'));
    }

    public function test_resolve_default_option_key_matches_requested_legacy_option_type(): void
    {
        $rows = [(object) ['option_type' => 'individual'], (object) ['option_type' => 'couple']];

        $this->assertSame('couple', AttendancePricingService::resolve_default_option_key($rows, 'couple'));
    }

    public function test_resolve_default_option_key_falls_back_to_first_row_key(): void
    {
        $rows = [(object) ['option_key' => 'individual'], (object) ['option_key' => 'couple']];

        $this->assertSame('individual', AttendancePricingService::resolve_default_option_key($rows, 'professional'));
    }

    public function test_resolve_default_option_key_falls_back_to_first_row_type(): void
    {
        $rows = [(object) ['option_type' => 'individual'], (object) ['option_type' => 'couple']];

        $this->assertSame('individual', AttendancePricingService::resolve_default_option_key($rows, 'professional'));
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
        $this->assertSame('individual', $ctx['default_option_key']);
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
        $this->assertSame('individual', $ctx['options'][0]['option_key']);
    }

    public function test_build_selection_context_exposes_explicit_option_keys(): void
    {
        $rows = [
            (object) [
                'id'            => 1,
                'option_type'   => 'individual',
                'option_key'    => 'early-bird',
                'label'         => 'Early Bird',
                'price'         => 90.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
            (object) [
                'id'            => 2,
                'option_type'   => 'couple',
                'option_key'    => 'couple',
                'label'         => 'Couple',
                'price'         => 650.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows, [
            '_event_is_free'   => '',
            '_event_price'     => 90.0,
            '_event_surcharge' => 0.0,
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'early-bird');

        $this->assertSame('early-bird', $ctx['default_option_key']);
        $this->assertSame('individual', $ctx['default_option_type']);
        $this->assertCount(2, $ctx['options']);
        $this->assertSame('early-bird', $ctx['options'][0]['option_key']);
        $this->assertSame('couple', $ctx['options'][1]['option_key']);
        $this->assertSame(90.0, $ctx['default_display_price']);
    }

    public function test_build_selection_context_individual_percent_surcharge(): void
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
            '_event_is_free'        => '',
            '_event_price'          => 120.0,
            '_event_surcharge'      => 2.0,
            '_event_surcharge_type' => 'percent',
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'individual');

        $this->assertSame(2.4, $ctx['surcharge']);
        $this->assertSame('percent', $ctx['surcharge_mode']);
        $this->assertSame(2.0, $ctx['surcharge_rate']);
        $this->assertSame(120.0, $ctx['default_display_price']);
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

    public function test_build_selection_context_free_event_reports_flat_mode_and_zero_rate(): void
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
            '_event_is_free'        => '1',
            '_event_price'          => 50.0,
            '_event_surcharge'      => 2.0,
            '_event_surcharge_type' => 'percent',
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'individual');

        $this->assertTrue($ctx['is_free']);
        $this->assertSame(0.0, $ctx['surcharge']);
        $this->assertSame('flat', $ctx['surcharge_mode']);
        $this->assertSame(0.0, $ctx['surcharge_rate']);
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

    public function test_build_selection_context_passes_description_through(): void
    {
        $rows = [
            (object) [
                'id'            => 1,
                'option_type'   => 'individual',
                'label'         => 'Two-Day',
                'description'   => '<p>Two full-day sessions</p>',
                'price'         => 120.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows, [
            '_event_is_free'   => '',
            '_event_price'     => 120.0,
            '_event_surcharge' => 0.0,
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'individual');

        $this->assertCount(1, $ctx['options']);
        $this->assertSame('<p>Two full-day sessions</p>', $ctx['options'][0]['description']);
    }

    public function test_build_selection_context_row_without_description_yields_empty_string(): void
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
            '_event_surcharge' => 0.0,
        ]);

        $ctx = (new AttendancePricingService())->build_selection_context(1, 'individual');

        $this->assertCount(1, $ctx['options']);
        $this->assertArrayHasKey('description', $ctx['options'][0]);
        $this->assertSame('', $ctx['options'][0]['description']);
    }

    public function test_resolve_all_falls_back_to_default_values_with_description(): void
    {
        $this->mockSelectionContextEnvironment([], [
            '_event_default_values' => [
                'attendance_options' => [
                    [
                        'option_type' => 'couple',
                        'label'       => 'Couple',
                        'description' => '<p>Bring your partner</p>',
                        'price'       => 650,
                    ],
                ],
            ],
        ]);

        $result = (new AttendancePricingService())->resolve_all(1);

        $this->assertCount(1, $result);
        $this->assertSame('couple', $result[0]['option_type']);
        $this->assertSame(0, $result[0]['id']);
        $this->assertSame('<p>Bring your partner</p>', $result[0]['description']);
        $this->assertSame(650.0, $result[0]['base_price']);
    }

    public function test_resolve_all_falls_back_to_option_type_when_row_key_missing(): void
    {
        $rows = [
            (object) [
                'id'            => 31,
                'option_type'   => 'couple',
                'label'         => 'Couple',
                'price'         => 650.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows);

        $result = (new AttendancePricingService())->resolve_all(1);

        $this->assertCount(1, $result);
        $this->assertSame('couple', $result[0]['option_key']);
    }

    public function test_resolve_configuration_matches_option_key_before_option_type(): void
    {
        $rows = [
            (object) [
                'id'            => 11,
                'option_type'   => 'professional',
                'option_key'    => 'early-bird',
                'label'         => 'Early Bird',
                'price'         => 90.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
            (object) [
                'id'            => 12,
                'option_type'   => 'professional',
                'option_key'    => 'professional',
                'label'         => 'Professional',
                'price'         => 120.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows);

        $service = new AttendancePricingService();

        $early_bird = $service->resolve_configuration(1, 'early-bird');
        $this->assertSame(11, $early_bird['id']);
        $this->assertSame('Early Bird', $early_bird['label']);
        $this->assertSame('early-bird', $early_bird['option_key']);

        $professional = $service->resolve_configuration(1, 'professional');
        $this->assertSame(12, $professional['id']);
        $this->assertSame('Professional', $professional['label']);
    }

    public function test_resolve_configuration_matches_legacy_type_when_key_empty(): void
    {
        $rows = [
            (object) [
                'id'            => 21,
                'option_type'   => 'individual',
                'option_key'    => '',
                'label'         => 'Individual',
                'price'         => 120.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => null,
            ],
        ];

        $this->mockSelectionContextEnvironment($rows);

        $config = (new AttendancePricingService())->resolve_configuration(1, 'individual');

        $this->assertSame(21, $config['id']);
        $this->assertSame('Individual', $config['label']);
        $this->assertSame('individual', $config['option_key']);
    }

    public function test_insert_attendance_option_persists_description(): void
    {
        $wpdb = $this->makeWpdbFake();
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');

        AttendancePricingService::insert_attendance_option(5, [
            'option_type' => 'individual',
            'label'       => 'Two-Day',
            'description' => '<p>Two full-day sessions</p>',
            'price'       => 120,
        ], 3);

        $this->assertCount(1, $wpdb->inserts);
        $this->assertSame('wp_hmwevents_event_attendance_options', $wpdb->inserts[0]['table']);
        $this->assertSame('<p>Two full-day sessions</p>', $wpdb->inserts[0]['data']['description']);
        $this->assertSame('two-day', $wpdb->inserts[0]['data']['option_key']);
    }

    public function test_insert_attendance_option_persists_preset_option_key(): void
    {
        $wpdb = $this->makeWpdbFake();
        $GLOBALS['wpdb'] = $wpdb;

        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');

        AttendancePricingService::insert_attendance_option(5, [
            'option_type' => 'individual',
            'option_key'  => 'promo',
            'label'       => 'Early Bird',
            'price'       => 90,
        ]);

        $this->assertCount(1, $wpdb->inserts);
        $this->assertSame('promo', $wpdb->inserts[0]['data']['option_key']);
    }

    public function test_generate_unique_option_key_appends_next_free_suffix(): void
    {
        $GLOBALS['wpdb'] = $this->makeWpdbFake(['couple', 'couple-2']);

        $this->assertSame('couple-3', AttendancePricingService::generate_unique_option_key(5, 'couple'));
    }

    public function test_generate_unique_option_key_returns_base_when_no_keys_taken(): void
    {
        $GLOBALS['wpdb'] = $this->makeWpdbFake([]);

        $this->assertSame('couple', AttendancePricingService::generate_unique_option_key(5, 'couple'));
    }

    public function test_generate_unique_option_key_returns_base_when_not_in_taken_list(): void
    {
        $GLOBALS['wpdb'] = $this->makeWpdbFake(['couple-2']);

        $this->assertSame('couple', AttendancePricingService::generate_unique_option_key(5, 'couple'));
    }

    private function makeWpdbFake(array $taken_keys = []): object
    {
        return new class($taken_keys) {
            public string $prefix = 'wp_';
            public array $inserts = [];
            private array $taken;

            public function __construct(array $taken = [])
            {
                $this->taken = $taken;
            }

            public function insert($table, $data, $formats = [])
            {
                $this->inserts[] = ['table' => $table, 'data' => $data, 'formats' => $formats];

                return 1;
            }

            public function prepare($query, ...$args)
            {
                return $query;
            }

            public function esc_like($text)
            {
                return addcslashes((string) $text, '_%\\');
            }

            public function get_col($query = null)
            {
                return $this->taken;
            }
        };
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
