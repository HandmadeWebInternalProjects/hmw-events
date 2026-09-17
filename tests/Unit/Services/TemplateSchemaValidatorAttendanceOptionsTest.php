<?php

namespace HMWEvents\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Services\TemplateResolver;
use HMWEvents\Services\TemplateSchemaValidator;
use PHPUnit\Framework\TestCase;

class TemplateSchemaValidatorAttendanceOptionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        Functions\when('__')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_key')->alias(function ($value) {
            $value = strtolower((string) $value);
            return preg_replace('/[^a-z0-9_\-]/', '', $value);
        });
        Functions\when('is_wp_error')->alias(function ($value) {
            return $value instanceof \WP_Error;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function normalizeOptions(array $options): array
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'schema_version' => 3,
            'defaults'       => ['attendance_options' => $options],
        ]);

        $this->assertIsArray($normalized);

        return $normalized['defaults']['attendance_options'];
    }

    private function normalizeRaw(array $template_data)
    {
        $validator = new TemplateSchemaValidator();

        return $validator->normalize($template_data);
    }

    public function test_normalize_preserves_capacity(): void
    {
        $options = $this->normalizeOptions([
            ['option_type' => 'couple', 'label' => 'Couple', 'price' => 650, 'capacity' => 8],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('couple', $options[0]['option_type']);
        $this->assertSame('Couple', $options[0]['label']);
        $this->assertSame(650.0, $options[0]['price']);
        $this->assertSame(8, $options[0]['capacity']);
    }

    public function test_normalize_converts_empty_capacity_to_null(): void
    {
        $options = $this->normalizeOptions([
            ['option_type' => 'individual', 'label' => 'Individual', 'price' => 0, 'capacity' => ''],
            ['option_type' => 'couple', 'label' => 'Couple', 'price' => 650],
        ]);

        $this->assertCount(2, $options);
        $this->assertNull($options[0]['capacity']);
        $this->assertNull($options[1]['capacity']);
    }

    public function test_normalize_coerces_negative_capacity_to_zero(): void
    {
        $options = $this->normalizeOptions([
            ['option_type' => 'parent', 'label' => 'Parent', 'price' => 350, 'capacity' => -5],
        ]);

        $this->assertSame(0, $options[0]['capacity']);
    }

    public function test_normalize_drops_options_with_empty_label(): void
    {
        $options = $this->normalizeOptions([
            ['option_type' => 'parent', 'label' => '', 'price' => 350],
            ['option_type' => 'couple', 'label' => 'Couple', 'price' => 650, 'capacity' => 4],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('couple', $options[0]['option_type']);
        $this->assertSame(4, $options[0]['capacity']);
    }

    public function test_resolve_retains_template_capacity(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-course', [
            'schema_version' => 3,
            'defaults'       => [
                'attendance_options' => [
                    ['option_type' => 'couple', 'label' => 'Couple', 'price' => 650, 'capacity' => 10],
                ],
            ],
        ]);

        $options = $resolved['defaults']['attendance_options'];

        $this->assertCount(1, $options);
        $this->assertSame(650.0, $options[0]['price']);
        $this->assertSame(10, $options[0]['capacity']);
    }

    public function test_resolve_registry_fallback_includes_null_capacity(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-course', ['schema_version' => 3]);

        $options = $resolved['defaults']['attendance_options'];

        $this->assertCount(3, $options);

        $types = array_column($options, 'option_type');
        $this->assertSame(['parent', 'parent_child', 'couple'], $types);

        foreach ($options as $option) {
            $this->assertArrayHasKey('capacity', $option);
            $this->assertNull($option['capacity']);
        }
    }

    public function test_normalize_preserves_per_attendee_mode_and_rules(): void
    {
        $options = $this->normalizeOptions([
            [
                'option_type'   => 'parent_child',
                'label'         => 'Parent + Child',
                'price'         => 0,
                'price_mode'    => 'per_attendee',
                'pricing_rules' => [
                    ['role' => 'adult', 'price' => 100],
                    ['role' => 'child', 'price' => 50],
                ],
            ],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('per_attendee', $options[0]['price_mode']);
        $this->assertCount(2, $options[0]['pricing_rules']);
        $this->assertSame('adult', $options[0]['pricing_rules'][0]['role']);
        $this->assertSame(100.0, $options[0]['pricing_rules'][0]['price']);
        $this->assertSame('child', $options[0]['pricing_rules'][1]['role']);
        $this->assertSame(50.0, $options[0]['pricing_rules'][1]['price']);
    }

    public function test_normalize_preserves_age_band_mode(): void
    {
        $options = $this->normalizeOptions([
            [
                'option_type'   => 'individual',
                'label'         => 'Individual',
                'price'         => 0,
                'price_mode'    => 'age_band',
                'pricing_rules' => [
                    ['role' => 'any', 'min_age' => 0, 'max_age' => 5, 'price' => 0],
                    ['role' => 'any', 'min_age' => 6, 'max_age' => null, 'price' => 50],
                ],
            ],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('age_band', $options[0]['price_mode']);
        $this->assertCount(2, $options[0]['pricing_rules']);
        $this->assertSame(0, $options[0]['pricing_rules'][0]['min_age']);
        $this->assertSame(5, $options[0]['pricing_rules'][0]['max_age']);
    }

    public function test_normalize_defaults_mode_to_flat_when_no_rules(): void
    {
        $options = $this->normalizeOptions([
            ['option_type' => 'individual', 'label' => 'Individual', 'price' => 100],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('flat', $options[0]['price_mode']);
        $this->assertSame([], $options[0]['pricing_rules']);
    }

    public function test_normalize_infers_age_band_from_legacy_rules(): void
    {
        $options = $this->normalizeOptions([
            [
                'option_type'   => 'individual',
                'label'         => 'Individual',
                'price'         => 0,
                'pricing_rules' => [
                    ['role' => 'any', 'min_age' => 0, 'max_age' => 12, 'price' => 20],
                ],
            ],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('age_band', $options[0]['price_mode']);
    }

    public function test_normalize_coerces_invalid_price_mode_to_flat(): void
    {
        $options = $this->normalizeOptions([
            ['option_type' => 'individual', 'label' => 'Individual', 'price' => 10, 'price_mode' => 'bogus'],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('flat', $options[0]['price_mode']);
    }

    public function test_normalize_rejects_age_band_rule_with_max_below_min(): void
    {
        $result = $this->normalizeRaw([
            'schema_version' => 3,
            'defaults'       => [
                'attendance_options' => [
                    [
                        'option_type'   => 'individual',
                        'label'         => 'Individual',
                        'price'         => 0,
                        'price_mode'    => 'age_band',
                        'pricing_rules' => [
                            ['role' => 'any', 'min_age' => 10, 'max_age' => 5, 'price' => 20],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_template_schema', $result->get_error_code());
    }

    public function test_normalize_preserves_description_trimmed(): void
    {
        $options = $this->normalizeOptions([
            ['option_type' => 'individual', 'label' => 'Individual', 'price' => 120, 'description' => '  Includes catering + resources  '],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('Includes catering + resources', $options[0]['description']);
    }

    public function test_normalize_defaults_description_to_empty_string_when_absent(): void
    {
        $options = $this->normalizeOptions([
            ['option_type' => 'individual', 'label' => 'Individual', 'price' => 120],
        ]);

        $this->assertCount(1, $options);
        $this->assertArrayHasKey('description', $options[0]);
        $this->assertSame('', $options[0]['description']);
    }

    public function test_normalize_preserves_description_alongside_other_fields(): void
    {
        $options = $this->normalizeOptions([
            [
                'option_type'   => 'parent_child',
                'label'         => 'Parent + Child',
                'description'   => '  <p>Two full-day sessions</p>  ',
                'price'         => 0,
                'price_mode'    => 'per_attendee',
                'pricing_rules' => [
                    ['role' => 'adult', 'price' => 100],
                    ['role' => 'child', 'price' => 50],
                ],
                'capacity'      => 8,
            ],
        ]);

        $this->assertCount(1, $options);
        $this->assertSame('parent_child', $options[0]['option_type']);
        $this->assertSame('Parent + Child', $options[0]['label']);
        $this->assertSame('<p>Two full-day sessions</p>', $options[0]['description']);
        $this->assertSame(0.0, $options[0]['price']);
        $this->assertSame('per_attendee', $options[0]['price_mode']);
        $this->assertSame(8, $options[0]['capacity']);
        $this->assertCount(2, $options[0]['pricing_rules']);
    }
}
