<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\EventFormFieldsResolver;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventFormFieldsResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        Functions\when('__')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_for_event_flattens_v3_sections_and_preserves_field_metadata(): void
    {
        $config = [
            '_event_template_override' => '',
            '_event_field_config' => [
                'registration_fields' => [
                    'sections' => [
                        [
                            'id' => 'sec_contact',
                            'label' => 'Contact',
                            'fields' => [
                                [
                                    'key' => 'first_name',
                                    'label' => 'First Name',
                                    'type' => 'text',
                                    'required' => true,
                                    'source' => 'registrant_meta',
                                    'meta_key' => 'registrant_first_name',
                                    'placeholder' => '',
                                ],
                                [
                                    'key' => 'heard_about',
                                    'label' => 'How did you hear about us?',
                                    'type' => 'select',
                                    'required' => false,
                                    'source' => 'booking_details',
                                    'meta_key' => null,
                                    'placeholder' => '',
                                    'options' => [
                                        ['value' => 'google', 'label' => 'Google'],
                                        ['value' => 'other', 'label' => 'Other'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single = false) use ($config) {
            return $config[$key] ?? '';
        });

        $fields = EventFormFieldsResolver::for_event(123);

        $this->assertArrayHasKey('first_name', $fields);
        $this->assertArrayHasKey('heard_about', $fields);

        $this->assertSame('First Name', $fields['first_name']['label']);
        $this->assertSame('text', $fields['first_name']['type']);
        $this->assertTrue($fields['first_name']['required']);
        $this->assertSame('registrant_meta', $fields['first_name']['source']);
        $this->assertSame('registrant_first_name', $fields['first_name']['meta_key']);

        $this->assertSame('select', $fields['heard_about']['type']);
        $this->assertSame('booking_details', $fields['heard_about']['source']);
        $this->assertNull($fields['heard_about']['meta_key']);
        $this->assertSame(
            [['value' => 'google', 'label' => 'Google'], ['value' => 'other', 'label' => 'Other']],
            $fields['heard_about']['options']
        );
    }

    public function test_for_event_prefers_template_override(): void
    {
        $override = [
            'registration_fields' => [
                'sections' => [
                    [
                        'id' => 'sec_override',
                        'fields' => [
                            ['key' => 'override_only', 'label' => 'Override Only', 'type' => 'text'],
                        ],
                    ],
                ],
            ],
        ];

        $field_config = [
            'registration_fields' => [
                'sections' => [
                    [
                        'id' => 'sec_config',
                        'fields' => [
                            ['key' => 'config_only', 'label' => 'Config Only', 'type' => 'text'],
                        ],
                    ],
                ],
            ],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single = false) use ($override, $field_config) {
            if ($key === '_event_template_override') {
                return $override;
            }
            if ($key === '_event_field_config') {
                return $field_config;
            }
            return '';
        });

        $fields = EventFormFieldsResolver::for_event(123);

        $this->assertArrayHasKey('override_only', $fields);
        $this->assertArrayNotHasKey('config_only', $fields);
    }

    public function test_for_event_falls_back_to_registry_when_no_v3_config(): void
    {
        Functions\when('get_post_meta')->justReturn('');

        $fields = EventFormFieldsResolver::for_event(123);

        $this->assertArrayHasKey('first_name', $fields);
        $this->assertArrayHasKey('dietary_requirements', $fields);
        $this->assertArrayNotHasKey('document_upload', $fields);

        $this->assertSame('registrant_meta', $fields['first_name']['source']);
        $this->assertSame('registrant_first_name', $fields['first_name']['meta_key']);
        $this->assertSame('booking_details', $fields['dietary_requirements']['source']);
    }

    public function test_canonical_field_name_maps_compatibility_fields(): void
    {
        $this->assertSame('customer_first_name', EventFormFieldsResolver::canonical_field_name('first_name'));
        $this->assertSame('customer_last_name', EventFormFieldsResolver::canonical_field_name('last_name'));
        $this->assertSame('registrant_email', EventFormFieldsResolver::canonical_field_name('email'));
        $this->assertSame('customer_phone', EventFormFieldsResolver::canonical_field_name('phone'));
        $this->assertSame('city', EventFormFieldsResolver::canonical_field_name('suburb'));
    }

    public function test_canonical_field_name_passes_through_custom_keys(): void
    {
        $this->assertSame('custom_field', EventFormFieldsResolver::canonical_field_name('custom_field'));
    }

    public function test_from_sections_skips_fields_without_key(): void
    {
        $sections = [
            [
                'fields' => [
                    ['label' => 'No key field', 'type' => 'text'],
                    ['key' => 'has_key', 'label' => 'Has Key', 'type' => 'text'],
                ],
            ],
        ];

        $fields = EventFormFieldsResolver::from_sections($sections);

        $this->assertCount(1, $fields);
        $this->assertArrayHasKey('has_key', $fields);
    }

    public function test_for_event_excludes_file_fields_from_v3_config(): void
    {
        $config = [
            '_event_template_override' => '',
            '_event_field_config' => [
                'registration_fields' => [
                    'sections' => [
                        [
                            'fields' => [
                                ['key' => 'document_upload', 'label' => 'Upload', 'type' => 'file', 'source' => 'booking_details'],
                                ['key' => 'first_name', 'label' => 'First Name', 'type' => 'text'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single = false) use ($config) {
            return $config[$key] ?? '';
        });

        $fields = EventFormFieldsResolver::for_event(123);

        $this->assertArrayHasKey('first_name', $fields);
        $this->assertArrayNotHasKey('document_upload', $fields);
    }

    public function test_from_sections_preserves_per_attendee_attendance_types_and_width(): void
    {
        Functions\when('sanitize_key')->alias(function ($value) {
            $value = strtolower((string) $value);

            return preg_replace('/[^a-z0-9_\-]/', '', $value);
        });

        $sections = [
            [
                'fields' => [
                    [
                        'key'              => 'child_allergies',
                        'label'            => 'Allergies',
                        'type'             => 'textarea',
                        'per_attendee'     => true,
                        'attendance_types' => ['parent_child', 'parent'],
                        'width'            => 'half',
                    ],
                    [
                        'key'   => 'notes',
                        'label' => 'Notes',
                        'type'  => 'textarea',
                    ],
                ],
            ],
        ];

        $fields = EventFormFieldsResolver::from_sections($sections);

        $this->assertTrue($fields['child_allergies']['per_attendee']);
        $this->assertSame(['parent_child', 'parent'], $fields['child_allergies']['attendance_types']);
        $this->assertSame('half', $fields['child_allergies']['width']);

        $this->assertFalse($fields['notes']['per_attendee']);
        $this->assertSame([], $fields['notes']['attendance_types']);
        $this->assertSame('full', $fields['notes']['width']);
    }
}
