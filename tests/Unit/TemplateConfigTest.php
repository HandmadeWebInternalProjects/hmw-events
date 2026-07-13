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
}
