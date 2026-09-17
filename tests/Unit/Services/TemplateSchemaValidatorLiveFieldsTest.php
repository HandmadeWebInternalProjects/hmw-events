<?php

namespace HMWEvents\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Services\TemplateSchemaValidator;
use PHPUnit\Framework\TestCase;

class TemplateSchemaValidatorLiveFieldsTest extends TestCase
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

    /**
     * Must stay the first test in this class: it requires acf_get_field_groups
     * and acf_get_fields to be undefined so the validator takes its ACF-inactive
     * path. Brain Monkey stubs created by later tests permanently define these
     * functions for the rest of the process.
     */
    public function test_normalize_rejects_live_only_event_field_when_acf_functions_are_not_defined(): void
    {
        $this->assertFalse(function_exists('acf_get_field_groups'));
        $this->assertFalse(function_exists('acf_get_fields'));

        $validator = new TemplateSchemaValidator();

        $result = $validator->normalize($this->template_payload(['event_program']));

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_template_schema', $result->get_error_code());
        $this->assertStringContainsString('Unknown event field: event_program.', $result->get_error_message());
    }

    public function test_normalize_accepts_event_field_only_present_in_live_acf(): void
    {
        $this->define_acf_stubs([
            ['name' => '_event_program', 'type' => 'taxonomy'],
            ['name' => 'event_start_date', 'type' => 'date_time_picker'],
        ]);

        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize($this->template_payload(['event_program']));

        $this->assertIsArray($normalized);
        $this->assertSame(3, $normalized['schema_version']);
        $this->assertSame(['event_program'], $normalized['event_fields']['hidden']);
    }

    public function test_normalize_strips_event_prefix_from_live_acf_field_names(): void
    {
        $this->define_acf_stubs([
            ['name' => '_event_program', 'type' => 'taxonomy'],
        ]);

        $validator = new TemplateSchemaValidator();

        $normalized = $validator->normalize($this->template_payload(['event_program']));
        $this->assertIsArray($normalized);
        $this->assertSame(['event_program'], $normalized['event_fields']['hidden']);

        $prefixed = $validator->normalize($this->template_payload(['_event_program']));
        $this->assertIsArray($prefixed);
        $this->assertSame(['event_program'], $prefixed['event_fields']['hidden']);
    }

    /**
     * The ACF functions are defined with harmless default bodies before stubbing
     * them: Brain Monkey only redefines existing functions via Patchwork (restored
     * on tearDown), so after these tests the functions stay defined but return
     * empty results instead of the throwing placeholder Brain Monkey installs for
     * missing functions. Later tests in the suite (which run after this file and
     * call normalize()) therefore keep their pre-fix behaviour.
     */
    private function define_acf_stubs(array $fields): void
    {
        if (!function_exists('acf_get_field_groups')) {
            eval('namespace { function acf_get_field_groups($args = []) { return []; } }');
        }

        if (!function_exists('acf_get_fields')) {
            eval('namespace { function acf_get_fields($group_key) { return []; } }');
        }

        Functions\when('acf_get_field_groups')->justReturn([['key' => 'group_hmw_event_details']]);

        Functions\when('acf_get_fields')->alias(function ($group_key) use ($fields) {
            return $fields;
        });
    }

    private function template_payload(array $hidden_event_fields): array
    {
        return [
            'schema_version'      => 3,
            'template_version'    => 1,
            'post'                => [
                'title_pattern' => '',
                'post_content'  => '',
                'post_title'    => '',
            ],
            'event_fields'        => [
                'required' => [],
                'optional' => [],
                'hidden'   => $hidden_event_fields,
            ],
            'registration_fields' => [
                'sections'      => [],
                'multi_booking' => [
                    'enabled' => false,
                    'min'     => 1,
                    'max'     => 10,
                ],
            ],
            'defaults'            => [
                'event_meta'         => [],
                'registration'       => [
                    'attendance_default' => 'individual',
                    'field_overrides'    => [],
                ],
                'attendance_options' => [],
            ],
        ];
    }
}
