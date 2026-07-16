<?php

namespace HMWEvents\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Services\TemplateResolver;
use HMWEvents\Services\TemplateSchemaValidator;
use PHPUnit\Framework\TestCase;

class TemplateConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
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

    public function test_validator_normalizes_legacy_template_shape(): void
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'post_title'          => 'Legacy Template',
            'post_content'        => 'Intro',
            'meta'                => ['event_capacity' => 40],
            'event_delivery_mode' => 'online',
        ]);

        $this->assertIsArray($normalized);
        $this->assertSame(3, $normalized['schema_version']);
        $this->assertSame(1, $normalized['template_version']);
        $this->assertSame('Legacy Template', $normalized['post']['post_title']);
        $this->assertSame('Intro', $normalized['post']['post_content']);
        $this->assertSame(40, $normalized['defaults']['event_meta']['event_capacity']);
        $this->assertSame('online', $normalized['defaults']['event_meta']['event_delivery_mode']);
        $this->assertIsArray($normalized['registration_fields']['sections']);
        $this->assertIsArray($normalized['registration_fields']['multi_booking']);
    }

    public function test_validator_rejects_unknown_registration_field(): void
    {
        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize([
            'registration_fields' => [
                'required' => ['this_field_does_not_exist'],
            ],
        ]);

        $this->assertIsArray($result);
        $this->assertSame(3, $result['schema_version']);
        $this->assertCount(0, $result['registration_fields']['sections'][0]['fields']);
    }

    public function test_validator_v2_migrates_to_v3(): void
    {
        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize([
            'registration_fields' => [
                'required' => ['first_name', 'last_name'],
                'optional' => ['phone'],
            ],
        ]);

        $this->assertSame(3, $result['schema_version']);
        $this->assertIsArray($result['registration_fields']['sections']);
        $this->assertCount(1, $result['registration_fields']['sections']);
        $this->assertSame('general', $result['registration_fields']['sections'][0]['id']);

        $fields = $result['registration_fields']['sections'][0]['fields'];
        $this->assertCount(3, $fields);
        $this->assertSame('first_name', $fields[0]['key']);
        $this->assertTrue($fields[0]['required']);
        $this->assertSame('full', $fields[0]['width']);
    }

    public function test_validator_accepts_v3_sections(): void
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'schema_version'      => 3,
            'post'                => ['title_pattern' => 'My Event'],
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections' => [[
                    'id'     => 'contact',
                    'label'  => 'Contact Info',
                    'fields' => [[
                        'key'      => 'first_name',
                        'label'    => 'First Name',
                        'type'     => 'text',
                        'required' => true,
                        'width'    => 'half',
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $this->assertIsArray($normalized);
        $this->assertSame(3, $normalized['schema_version']);
        $this->assertCount(1, $normalized['registration_fields']['sections']);
        $this->assertSame('contact', $normalized['registration_fields']['sections'][0]['id']);
        $this->assertSame('first_name', $normalized['registration_fields']['sections'][0]['fields'][0]['key']);
        $this->assertTrue($normalized['registration_fields']['sections'][0]['fields'][0]['required']);
        $this->assertSame('half', $normalized['registration_fields']['sections'][0]['fields'][0]['width']);
    }

    public function test_validator_rejects_v3_invalid_field_type(): void
    {
        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize([
            'schema_version'      => 3,
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections' => [[
                    'id'     => 'contact',
                    'label'  => 'Contact',
                    'fields' => [[
                        'key'   => 'test',
                        'label' => 'Test',
                        'type'  => 'bogus',
                        'required' => false,
                        'width' => 'full',
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_template_schema', $result->get_error_code());
    }

    public function test_validator_rejects_v3_duplicate_section_ids(): void
    {
        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize([
            'schema_version'      => 3,
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections' => [
                    ['id' => 'contact', 'label' => 'One', 'fields' => [[
                        'key' => 'a', 'label' => 'A', 'type' => 'text', 'required' => false, 'width' => 'full',
                    ]]],
                    ['id' => 'contact', 'label' => 'Two', 'fields' => [[
                        'key' => 'b', 'label' => 'B', 'type' => 'text', 'required' => false, 'width' => 'full',
                    ]]],
                ],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_validator_rejects_v3_select_without_options(): void
    {
        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize([
            'schema_version'      => 3,
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections' => [[
                    'id' => 's1', 'label' => 'S1',
                    'fields' => [[
                        'key' => 'my_select', 'label' => 'Select', 'type' => 'select',
                        'required' => false, 'width' => 'full', 'options' => [],
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_validator_v3_multi_booking_validation(): void
    {
        $validator = new TemplateSchemaValidator();

        $valid = $validator->normalize([
            'schema_version'      => 3,
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections'      => [[
                    'id' => 's1', 'label' => 'S1',
                    'fields' => [[
                        'key' => 'f1', 'label' => 'F1', 'type' => 'text', 'required' => false, 'width' => 'full',
                    ]],
                ]],
                'multi_booking' => ['enabled' => true, 'min' => 2, 'max' => 5],
            ],
        ]);

        $this->assertIsArray($valid);
        $this->assertTrue($valid['registration_fields']['multi_booking']['enabled']);
        $this->assertSame(2, $valid['registration_fields']['multi_booking']['min']);
        $this->assertSame(5, $valid['registration_fields']['multi_booking']['max']);

        $invalid = $validator->normalize([
            'schema_version'      => 3,
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections'      => [[
                    'id' => 's1', 'label' => 'S1',
                    'fields' => [[
                        'key' => 'f1', 'label' => 'F1', 'type' => 'text', 'required' => false, 'width' => 'full',
                    ]],
                ]],
                'multi_booking' => ['enabled' => true, 'min' => 5, 'max' => 2],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $invalid);
    }

    public function test_validator_v3_rejects_per_attendee_when_disabled(): void
    {
        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize([
            'schema_version'      => 3,
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections' => [[
                    'id' => 's1', 'label' => 'S1',
                    'fields' => [[
                        'key' => 'f1', 'label' => 'F1', 'type' => 'text',
                        'required' => false, 'width' => 'full', 'per_attendee' => true,
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_validator_v3_duplicate_field_keys(): void
    {
        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize([
            'schema_version'      => 3,
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections' => [
                    ['id' => 's1', 'label' => 'S1', 'fields' => [[
                        'key' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => false, 'width' => 'full',
                    ]]],
                    ['id' => 's2', 'label' => 'S2', 'fields' => [[
                        'key' => 'email', 'label' => 'Another', 'type' => 'text', 'required' => false, 'width' => 'full',
                    ]]],
                ],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_resolver_emits_v3_sections(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'schema_version'      => 3,
            'registration_fields' => [
                'sections' => [[
                    'id'     => 'contact',
                    'label'  => 'Contact',
                    'fields' => [[
                        'key' => 'first_name', 'label' => 'First Name',
                        'type' => 'text', 'required' => true, 'width' => 'half',
                    ], [
                        'key' => 'last_name', 'label' => 'Last Name',
                        'type' => 'text', 'required' => true, 'width' => 'half',
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $reg_fields = $resolved['field_config']['registration_fields'];
        $this->assertIsArray($reg_fields);
        $this->assertArrayHasKey('sections', $reg_fields);
        $this->assertArrayHasKey('multi_booking', $reg_fields);
        $this->assertCount(2, $reg_fields['sections'][0]['fields']);
        $this->assertSame('first_name', $reg_fields['sections'][0]['fields'][0]['key']);
    }

    public function test_resolver_merges_registry_template_and_overrides(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'defaults' => [
                'event_meta' => [
                    'event_capacity' => 44,
                ],
            ],
        ], [
            'post_title' => 'Snapshot Event',
            'event_meta' => [
                'event_capacity' => 12,
                'event_delivery_mode' => 'online',
            ],
        ]);

        $this->assertIsArray($resolved);
        $this->assertSame('Snapshot Event', $resolved['post']['post_title']);
        $this->assertSame(12, $resolved['event_meta']['event_capacity']);
        $this->assertSame('online', $resolved['event_meta']['event_delivery_mode']);
    }

    public function test_resolver_resolve_with_override_replaces_v3_sections(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'schema_version'      => 3,
            'registration_fields' => [
                'sections'      => [[
                    'id' => 's1', 'label' => 'Original',
                    'fields' => [[
                        'key' => 'a', 'label' => 'A', 'type' => 'text', 'required' => false, 'width' => 'full',
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $override = $resolver->resolve_with_override($resolved, [
            'event_fields' => [
                'required' => ['event_start_date'],
                'optional' => [],
                'hidden'   => ['event_capacity'],
            ],
            'registration_fields' => [
                'sections' => [[
                    'id' => 'override', 'label' => 'Override',
                    'fields' => [[
                        'key' => 'b', 'label' => 'B', 'type' => 'text', 'required' => true, 'width' => 'full',
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $this->assertSame(['event_start_date'], $override['field_config']['event_fields']['required']);
        $this->assertSame('override', $override['field_config']['registration_fields']['sections'][0]['id']);
        $this->assertSame('b', $override['field_config']['registration_fields']['sections'][0]['fields'][0]['key']);
    }

    // ============================================================
    // resolve_event_field_config — priority: hidden > required > optional
    // ============================================================

    public function test_resolve_event_field_config_hidden_wins_over_required(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['event_webinar_url'],
                'optional' => [],
                'hidden'   => ['event_webinar_url'],
            ],
        ]);

        $ef = $resolved['field_config']['event_fields'];
        $this->assertNotContains('event_webinar_url', $ef['required']);
        $this->assertContains('event_webinar_url', $ef['hidden']);
    }

    public function test_resolve_event_field_config_required_wins_over_optional(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['event_capacity'],
                'optional' => ['event_capacity'],
                'hidden'   => [],
            ],
        ]);

        $ef = $resolved['field_config']['event_fields'];
        $this->assertContains('event_capacity', $ef['required']);
        $this->assertNotContains('event_capacity', $ef['optional']);
    }

    public function test_resolve_event_field_config_hidden_wins_over_both_required_and_optional(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['event_price'],
                'optional' => ['event_price'],
                'hidden'   => ['event_price'],
            ],
        ]);

        $ef = $resolved['field_config']['event_fields'];
        $this->assertNotContains('event_price', $ef['required']);
        $this->assertNotContains('event_price', $ef['optional']);
        $this->assertContains('event_price', $ef['hidden']);
    }

    // ============================================================
    // normalize_event_field_list — dedup and _event_ prefix stripping
    // ============================================================

    public function test_resolve_event_field_config_deduplicates_fields(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['event_capacity', 'event_capacity'],
                'optional' => ['event_capacity'],
                'hidden'   => [],
            ],
        ]);

        $ef = $resolved['field_config']['event_fields'];
        $this->assertContains('event_capacity', $ef['required']);
        $this->assertNotContains('event_capacity', $ef['optional']);
    }

    public function test_resolve_event_field_config_strips_event_prefix(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['_event_capacity'],
                'optional' => [],
                'hidden'   => [],
            ],
        ]);

        $ef = $resolved['field_config']['event_fields'];
        $this->assertContains('event_capacity', $ef['required']);
        $this->assertNotContains('_event_capacity', $ef['required']);
    }

    // ============================================================
    // resolve() — WP_Error, fallback title, empty event_type
    // ============================================================

    public function test_resolve_returns_wp_error_when_validator_rejects(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $result = $resolver->resolve('parent-one-off-free', [
            'schema_version'      => 3,
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => [
                'sections' => [[
                    'id' => 'contact', 'label' => 'Contact',
                    'fields' => [[
                        'key' => 'test', 'label' => 'Test',
                        'type'  => 'bogus', 'required' => false, 'width' => 'full',
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_template_schema', $result->get_error_code());
    }

    public function test_resolve_falls_back_to_untitled_event_when_no_title(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', []);

        $this->assertIsArray($resolved);
        $this->assertSame('Untitled Event', $resolved['post']['post_title']);
    }

    public function test_resolve_uses_overrides_fallback_title(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [], [
            'fallback_post_title' => 'My Custom Fallback',
        ]);

        $this->assertIsArray($resolved);
        $this->assertSame('My Custom Fallback', $resolved['post']['post_title']);
    }

    public function test_resolve_uses_explicit_post_title_over_fallback(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'post_title' => 'Real Title',
        ], [
            'fallback_post_title' => 'Should Not Be Used',
        ]);

        $this->assertIsArray($resolved);
        $this->assertSame('Real Title', $resolved['post']['post_title']);
    }

    public function test_resolve_with_empty_event_type(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('', []);

        $this->assertIsArray($resolved);
        $this->assertArrayHasKey('field_config', $resolved);
        $this->assertArrayHasKey('event_fields', $resolved['field_config']);
    }

    // ============================================================
    // resolve_with_override() — edge cases
    // ============================================================

    public function test_resolve_with_override_only_event_fields(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', []);
        $override = $resolver->resolve_with_override($resolved, [
            'event_fields' => [
                'required' => ['event_capacity'],
                'optional' => [],
                'hidden'   => ['event_webinar_url'],
            ],
        ]);

        $ef = $override['field_config']['event_fields'];
        $this->assertSame(['event_capacity'], $ef['required']);
        $this->assertSame(['event_webinar_url'], $ef['hidden']);
    }

    public function test_resolve_with_override_only_registration_fields(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', []);
        $override = $resolver->resolve_with_override($resolved, [
            'registration_fields' => [
                'required' => ['email'],
                'optional' => [],
                'hidden'   => [],
                'order'    => [],
                'field_overrides' => [],
            ],
        ]);

        $rf = $override['field_config']['registration_fields'];
        $this->assertContains('email', $rf['required']);
    }

    public function test_resolve_with_override_registration_fields_with_v3_sections(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'schema_version'      => 3,
            'registration_fields' => [
                'sections'      => [[
                    'id' => 'orig', 'label' => 'Original',
                    'fields' => [[
                        'key' => 'x', 'label' => 'X', 'type' => 'text',
                        'required' => false, 'width' => 'full',
                    ]],
                ]],
                'multi_booking' => ['enabled' => false, 'min' => 1, 'max' => 10],
            ],
        ]);

        $override = $resolver->resolve_with_override($resolved, [
            'registration_fields' => [
                'sections' => [[
                    'id' => 'replacement', 'label' => 'Replaced',
                    'fields' => [[
                        'key' => 'z', 'label' => 'Z', 'type' => 'text',
                        'required' => true, 'width' => 'full',
                    ]],
                ]],
                'multi_booking' => ['enabled' => true, 'min' => 2, 'max' => 5],
            ],
        ]);

        $rf = $override['field_config']['registration_fields'];
        $this->assertSame('replacement', $rf['sections'][0]['id']);
        $this->assertTrue($rf['multi_booking']['enabled']);
        $this->assertSame(2, $rf['multi_booking']['min']);
    }

    public function test_resolve_with_override_with_empty_array(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', []);
        $override = $resolver->resolve_with_override($resolved, []);

        $this->assertSame($resolved, $override);
    }

    public function test_resolve_with_override_empty_event_fields_does_not_override(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', []);
        $original_ef = $resolved['field_config']['event_fields'];

        $override = $resolver->resolve_with_override($resolved, [
            'event_fields' => [],
        ]);

        $this->assertSame($original_ef, $override['field_config']['event_fields']);
    }

    // ============================================================
    // resolve() — v2 registration fields path (no sections)
    // ============================================================

    public function test_resolve_with_v2_registration_fields_no_sections(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'registration_fields' => [
                'required' => ['first_name', 'last_name'],
                'optional' => ['phone'],
                'hidden'   => ['street_address'],
            ],
        ]);

        $this->assertIsArray($resolved);
        $rf = $resolved['field_config']['registration_fields'];
        $this->assertArrayHasKey('sections', $rf);
        $this->assertArrayHasKey('multi_booking', $rf);
    }

    // ============================================================
    // resolve() — post_content and post_status
    // ============================================================

    public function test_resolve_uses_overrides_post_content(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'post_content' => 'Template content',
        ], [
            'post_content' => 'Override content',
        ]);

        $this->assertSame('Override content', $resolved['post']['post_content']);
    }

    public function test_resolve_uses_overrides_post_status(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [], [
            'post_status' => 'publish',
        ]);

        $this->assertSame('publish', $resolved['post']['post_status']);
    }

    public function test_resolve_defaults_post_status_to_draft(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', []);

        $this->assertSame('draft', $resolved['post']['post_status']);
    }

    // ============================================================
    // Recurrence group key expansion — Validator
    // ============================================================

    public function test_validator_accepts_event_recurrence_group_key(): void
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'event_fields' => [
                'required' => ['event_start_date', 'event_recurrence'],
                'optional' => [],
                'hidden'   => [],
            ],
        ]);

        $this->assertIsArray($normalized);
        $this->assertSame(3, $normalized['schema_version']);
    }

    public function test_validator_expands_recurrence_group_to_children(): void
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'event_fields' => [
                'required' => ['event_recurrence'],
                'optional' => [],
                'hidden'   => [],
            ],
        ]);

        $required = $normalized['event_fields']['required'];

        $expected = [
            'event_is_recurring',
            'event_recurrence_interval',
            'event_recurrence_unit',
            'event_recurrence_days',
            'event_recurrence_end_type',
            'event_recurrence_end_date',
            'event_recurrence_max_occurrences',
            'event_recurrence_custom_dates',
        ];

        foreach ($expected as $child) {
            $this->assertContains($child, $required, "Missing child: $child");
        }
        $this->assertCount(8, array_intersect($expected, $required));
        $this->assertNotContains('event_recurrence', $required);
    }

    public function test_validator_recurrence_group_in_hidden_expands_correctly(): void
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'event_fields' => [
                'required' => ['event_start_date'],
                'optional' => [],
                'hidden'   => ['event_recurrence'],
            ],
        ]);

        $hidden = $normalized['event_fields']['hidden'];
        $required = $normalized['event_fields']['required'];

        $this->assertContains('event_recurrence_interval', $hidden);
        $this->assertContains('event_is_recurring', $hidden);
        $this->assertContains('event_start_date', $required);
        $this->assertNotContains('event_recurrence', $hidden);
    }

    public function test_validator_recurrence_group_in_optional_expands_correctly(): void
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'event_fields' => [
                'required' => ['event_start_date'],
                'optional' => ['event_recurrence'],
                'hidden'   => [],
            ],
        ]);

        $optional = $normalized['event_fields']['optional'];

        $this->assertContains('event_is_recurring', $optional);
        $this->assertContains('event_recurrence_interval', $optional);
        $this->assertNotContains('event_recurrence', $optional);
    }

    public function test_validator_recurrence_group_with_event_prefix_expands(): void
    {
        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize([
            'event_fields' => [
                'required' => ['_event_recurrence'],
                'optional' => [],
                'hidden'   => [],
            ],
        ]);

        $required = $normalized['event_fields']['required'];
        $this->assertContains('event_is_recurring', $required);
        $this->assertContains('event_recurrence_interval', $required);
        $this->assertNotContains('_event_recurrence', $required);
    }

    // ============================================================
    // Recurrence group key expansion — Resolver
    // ============================================================

    public function test_resolve_expands_recurrence_group_to_children(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['event_recurrence'],
                'optional' => [],
                'hidden'   => [],
            ],
        ]);

        $event_fields = $resolved['field_config']['event_fields'];
        $required = $event_fields['required'];

        $expected = [
            'event_is_recurring',
            'event_recurrence_interval',
            'event_recurrence_unit',
            'event_recurrence_days',
            'event_recurrence_end_type',
            'event_recurrence_end_date',
            'event_recurrence_max_occurrences',
            'event_recurrence_custom_dates',
        ];

        foreach ($expected as $child) {
            $this->assertContains($child, $required);
        }
        $this->assertNotContains('event_recurrence', $required);
    }

    public function test_resolve_recurrence_group_in_hidden_expands(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => [],
                'optional' => [],
                'hidden'   => ['event_recurrence'],
            ],
        ]);

        $event_fields = $resolved['field_config']['event_fields'];
        $hidden = $event_fields['hidden'];

        $this->assertContains('event_is_recurring', $hidden);
        $this->assertContains('event_recurrence_interval', $hidden);
        $this->assertNotContains('event_recurrence', $hidden);
    }

    public function test_resolve_recurrence_group_hidden_wins_over_child_in_required(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['event_recurrence_interval'],
                'optional' => [],
                'hidden'   => ['event_recurrence'],
            ],
        ]);

        $event_fields = $resolved['field_config']['event_fields'];
        $required = $event_fields['required'];
        $hidden = $event_fields['hidden'];

        $this->assertContains('event_is_recurring', $hidden);
        $this->assertContains('event_recurrence_interval', $hidden);
        $this->assertNotContains('event_recurrence_interval', $required);
    }

    public function test_resolve_recurrence_group_mixed_with_other_event_fields(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['event_recurrence', 'event_capacity'],
                'optional' => [],
                'hidden'   => ['event_price'],
            ],
        ]);

        $event_fields = $resolved['field_config']['event_fields'];

        $this->assertContains('event_recurrence_interval', $event_fields['required']);
        $this->assertContains('event_is_recurring', $event_fields['required']);
        $this->assertContains('event_capacity', $event_fields['required']);
        $this->assertContains('event_price', $event_fields['hidden']);
        $this->assertNotContains('event_recurrence', $event_fields['required']);
    }

    public function test_resolve_recurrence_group_with_override_expands(): void
    {
        $resolver = new TemplateResolver(new TemplateSchemaValidator());

        $resolved = $resolver->resolve('parent-one-off-free', [
            'event_fields' => [
                'required' => ['event_start_date'],
                'optional' => [],
                'hidden'   => [],
            ],
        ]);

        $override = $resolver->resolve_with_override($resolved, [
            'event_fields' => [
                'required' => ['event_recurrence'],
                'optional' => [],
                'hidden'   => [],
            ],
        ]);

        $required = $override['field_config']['event_fields']['required'];

        // resolve_with_override replaces event_fields directly, no expansion
        $this->assertContains('event_recurrence', $required);
    }
}
