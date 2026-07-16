<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\EventTemplateOverrideService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

class EventTemplateOverrideServiceTest extends TestCase
{
    private EventTemplateOverrideService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        if (!defined('ARRAY_A')) {
            define('ARRAY_A', 'ARRAY_A');
        }

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturnUsing(function ($sql) { return $sql; })->byDefault();
        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn([])->byDefault();
        $GLOBALS['wpdb']->shouldReceive('get_var')->andReturn(0)->byDefault();
        $GLOBALS['wpdb']->shouldReceive('get_row')->andReturn(null)->byDefault();
        $GLOBALS['wpdb']->shouldReceive('esc_like')->andReturnUsing(function ($s) { return $s; })->byDefault();

        $this->service = new EventTemplateOverrideService();

        Functions\when('__')->returnArg();
        Functions\when('sanitize_key')->alias(function ($key) {
            return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $key));
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('error_log')->justReturn(true);
        Functions\when('wp_json_encode')->alias('json_encode');

        \Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // get_override()
    // ============================================================

    public function test_get_override_returns_null_for_empty_string(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        $this->assertNull($this->service->get_override(123));
    }

    public function test_get_override_returns_null_for_non_array(): void
    {
        Functions\when('get_post_meta')->justReturn('some_string');
        $this->assertNull($this->service->get_override(123));
    }

    public function test_get_override_returns_null_for_empty_array(): void
    {
        Functions\when('get_post_meta')->justReturn([]);
        $this->assertNull($this->service->get_override(123));
    }

    public function test_get_override_returns_null_for_false(): void
    {
        Functions\when('get_post_meta')->justReturn(false);
        $this->assertNull($this->service->get_override(123));
    }

    public function test_get_override_returns_array_when_valid(): void
    {
        $override = ['event_fields' => ['hidden' => ['event_capacity']]];
        Functions\when('get_post_meta')->justReturn($override);
        $this->assertSame($override, $this->service->get_override(123));
    }

    public function test_get_override_returns_array_when_only_registration_fields(): void
    {
        $override = ['registration_fields' => ['required' => ['first_name']]];
        Functions\when('get_post_meta')->justReturn($override);
        $this->assertSame($override, $this->service->get_override(456));
    }

    // ============================================================
    // has_override()
    // ============================================================

    public function test_has_override_returns_true_when_override_exists(): void
    {
        Functions\when('get_post_meta')->justReturn(['event_fields' => []]);
        $this->assertTrue($this->service->has_override(123));
    }

    public function test_has_override_returns_false_when_no_override(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        $this->assertFalse($this->service->has_override(123));
    }

    // ============================================================
    // is_applied_to_children()
    // ============================================================

    public function test_is_applied_to_children_returns_true(): void
    {
        Functions\when('get_post_meta')->justReturn('1');
        $this->assertTrue($this->service->is_applied_to_children(123));
    }

    public function test_is_applied_to_children_returns_false(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        $this->assertFalse($this->service->is_applied_to_children(123));
    }

    public function test_is_applied_to_children_returns_false_for_zero(): void
    {
        Functions\when('get_post_meta')->justReturn('0');
        $this->assertFalse($this->service->is_applied_to_children(123));
    }

    // ============================================================
    // get_resolved_config() — no override, has _event_field_config
    // ============================================================

    public function test_get_resolved_config_returns_field_config_directly_when_no_override(): void
    {
        $field_config = [
            'event_fields' => [
                'required' => ['event_start_date'],
                'optional' => [],
                'hidden'   => ['event_webinar_url'],
            ],
            'registration_fields' => [
                'required' => ['first_name'],
                'optional' => [],
                'hidden'   => [],
                'order'    => [],
                'field_overrides' => [],
            ],
        ];

        $return_map = [
            [123, '_event_template_override', true, ''],
            [123, '_event_field_config', true, $field_config],
            [123, '_event_default_values', true, ''],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($return_map) {
            foreach ($return_map as $map) {
                if ($pid === $map[0] && $key === $map[1] && $single === $map[2]) {
                    return $map[3];
                }
            }
            return '';
        });

        $result = $this->service->get_resolved_config(123);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('field_config', $result);
        $this->assertArrayHasKey('event_meta', $result);
        $this->assertArrayHasKey('attendance_options', $result);
        $this->assertSame($field_config, $result['field_config']);
    }

    // ============================================================
    // get_resolved_config() — no override, no _event_field_config
    // falls back to EventTypeRegistry defaults
    // ============================================================

    public function test_get_resolved_config_falls_back_to_event_type_defaults(): void
    {
        $return_map = [
            [123, '_event_template_override', true, ''],
            [123, '_event_field_config', true, ''],
            [123, '_event_default_values', true, ''],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($return_map) {
            foreach ($return_map as $map) {
                if ($pid === $map[0] && $key === $map[1] && $single === $map[2]) {
                    return $map[3];
                }
            }
            return '';
        });

        Functions\when('wp_get_object_terms')->justReturn([
            (object) ['slug' => 'parent-one-off-free'],
        ]);

        $result = $this->service->get_resolved_config(123);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('field_config', $result);
        $this->assertArrayHasKey('event_meta', $result);
        $this->assertArrayHasKey('attendance_options', $result);

        $field_config = $result['field_config'];
        $this->assertArrayHasKey('event_fields', $field_config);
        $this->assertArrayHasKey('registration_fields', $field_config);
        $this->assertArrayHasKey('hidden', $field_config['event_fields']);
        $this->assertArrayHasKey('required', $field_config['event_fields']);
    }

    // ============================================================
    // get_resolved_config() — has override, resolves via TemplateResolver
    // ============================================================

    public function test_get_resolved_config_with_override_resolves_via_resolver(): void
    {
        $override = [
            'event_fields' => [
                'hidden' => ['event_webinar_url', 'event_price'],
            ],
        ];

        $field_config = [
            'event_fields' => [
                'required' => ['event_start_date'],
                'optional' => [],
                'hidden'   => ['event_capacity'],
            ],
            'registration_fields' => [
                'required' => ['first_name'],
                'optional' => [],
                'hidden'   => [],
                'order'    => [],
                'field_overrides' => [],
            ],
        ];

        $return_map = [
            [123, '_event_template_override', true, $override],
            [123, '_event_field_config', true, $field_config],
            [123, '_event_default_values', true, ''],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($return_map) {
            foreach ($return_map as $map) {
                if ($pid === $map[0] && $key === $map[1] && $single === $map[2]) {
                    return $map[3];
                }
            }
            return '';
        });

        $result = $this->service->get_resolved_config(123);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('field_config', $result);
        $event_fields = $result['field_config']['event_fields'];
        $this->assertSame(['event_webinar_url', 'event_price'], $event_fields['hidden']);
    }

    // ============================================================
    // get_resolved_config() — has override but no _event_field_config
    // uses event type defaults as base
    // ============================================================

    public function test_get_resolved_config_with_override_no_field_config_uses_type_defaults_as_base(): void
    {
        $override = [
            'event_fields' => [
                'hidden' => ['event_price'],
            ],
        ];

        $return_map = [
            [123, '_event_template_override', true, $override],
            [123, '_event_field_config', true, ''],
            [123, '_event_default_values', true, ''],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($return_map) {
            foreach ($return_map as $map) {
                if ($pid === $map[0] && $key === $map[1] && $single === $map[2]) {
                    return $map[3];
                }
            }
            return '';
        });

        Functions\when('wp_get_object_terms')->justReturn([
            (object) ['slug' => 'professional-in-person'],
        ]);

        $result = $this->service->get_resolved_config(123);

        $this->assertIsArray($result);
        $event_fields = $result['field_config']['event_fields'];
        $this->assertArrayHasKey('hidden', $event_fields);
        $this->assertContains('event_price', $event_fields['hidden']);
    }

    // ============================================================
    // get_resolved_config() — includes event_meta
    // ============================================================

    public function test_get_resolved_config_includes_event_meta(): void
    {
        $field_config = [
            'event_fields' => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => ['required' => [], 'optional' => [], 'hidden' => [], 'order' => [], 'field_overrides' => []],
        ];

        $event_meta = ['event_capacity' => 50, 'event_delivery_mode' => 'online'];

        $return_map = [
            [123, '_event_template_override', true, ''],
            [123, '_event_field_config', true, $field_config],
            [123, '_event_default_values', true, $event_meta],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($return_map) {
            foreach ($return_map as $map) {
                if ($pid === $map[0] && $key === $map[1] && $single === $map[2]) {
                    return $map[3];
                }
            }
            return '';
        });

        $result = $this->service->get_resolved_config(123);

        $this->assertSame($event_meta, $result['event_meta']);
    }

    public function test_get_resolved_config_handles_non_array_event_meta(): void
    {
        $field_config = [
            'event_fields' => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => ['required' => [], 'optional' => [], 'hidden' => [], 'order' => [], 'field_overrides' => []],
        ];

        $return_map = [
            [123, '_event_template_override', true, ''],
            [123, '_event_field_config', true, $field_config],
            [123, '_event_default_values', true, 'not_an_array'],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($return_map) {
            foreach ($return_map as $map) {
                if ($pid === $map[0] && $key === $map[1] && $single === $map[2]) {
                    return $map[3];
                }
            }
            return '';
        });

        $result = $this->service->get_resolved_config(123);

        $this->assertSame([], $result['event_meta']);
    }

    // ============================================================
    // get_resolved_config() — includes attendance options from DB
    // ============================================================

    public function test_get_resolved_config_includes_attendance_options(): void
    {
        $field_config = [
            'event_fields' => ['required' => [], 'optional' => [], 'hidden' => []],
            'registration_fields' => ['required' => [], 'optional' => [], 'hidden' => [], 'order' => [], 'field_overrides' => []],
        ];

        $return_map = [
            [123, '_event_template_override', true, ''],
            [123, '_event_field_config', true, $field_config],
            [123, '_event_default_values', true, ''],
        ];

        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($return_map) {
            foreach ($return_map as $map) {
                if ($pid === $map[0] && $key === $map[1] && $single === $map[2]) {
                    return $map[3];
                }
            }
            return '';
        });

        $db_rows = [
            ['option_type' => 'individual', 'label' => 'Individual', 'price' => '100.00'],
            ['option_type' => 'couple', 'label' => 'Couple', 'price' => '180.00'],
        ];

        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn($db_rows);

        $result = $this->service->get_resolved_config(123);

        $this->assertIsArray($result['attendance_options']);
        $this->assertCount(2, $result['attendance_options']);
        $this->assertSame('individual', $result['attendance_options'][0]['option_type']);
        $this->assertSame('Individual', $result['attendance_options'][0]['label']);
        $this->assertSame(100.0, $result['attendance_options'][0]['price']);
        $this->assertSame('couple', $result['attendance_options'][1]['option_type']);
        $this->assertSame(180.0, $result['attendance_options'][1]['price']);
    }

    // ============================================================
    // save_override()
    // ============================================================

    public function test_save_override_saves_both_meta_keys(): void
    {
        $override = [
            'event_fields' => [
                'hidden' => ['event_capacity'],
            ],
        ];

        Functions\when('get_posts')->justReturn([456, 789]);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');

        $this->service->save_override(123, $override, true);
        $this->assertTrue(true);
    }

    public function test_save_override_bails_when_both_fields_empty(): void
    {
        Functions\when('update_post_meta')->justReturn(true);

        $this->service->save_override(123, [], false);
        $this->assertTrue(true);
    }

    public function test_save_override_bails_when_event_fields_and_registration_fields_empty(): void
    {
        Functions\when('update_post_meta')->justReturn(true);

        $this->service->save_override(123, ['event_fields' => [], 'registration_fields' => []], false);
        $this->assertTrue(true);
    }

    public function test_save_override_with_registration_fields_only(): void
    {
        $override = [
            'registration_fields' => [
                'required' => ['first_name', 'email'],
            ],
        ];

        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');

        $this->service->save_override(123, $override, false);
        $this->assertTrue(true);
    }

    public function test_save_override_with_apply_to_children_false_no_cascade(): void
    {
        $override = [
            'event_fields' => [
                'required' => ['event_start_date'],
            ],
        ];

        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('delete_post_meta')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');

        $this->service->save_override(123, $override, false);
        $this->assertTrue(true);
    }

    // ============================================================
    // reset_override()
    // ============================================================

    public function test_reset_override_deletes_meta_on_parent(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('delete_post_meta')->justReturn(true);

        $this->service->reset_override(123);
        $this->assertTrue(true);
    }

    public function test_reset_override_deletes_meta_on_children_too(): void
    {
        Functions\when('get_posts')->justReturn([456, 789]);
        Functions\when('delete_post_meta')->justReturn(true);

        $this->service->reset_override(123);
        $this->assertTrue(true);
    }

    // ============================================================
    // cascade_to_children()
    // ============================================================

    public function test_cascade_to_children_copies_meta_to_all_children(): void
    {
        $override = ['event_fields' => ['hidden' => ['event_price']]];

        Functions\when('get_posts')->justReturn([456, 789]);
        Functions\when('update_post_meta')->justReturn(true);

        $this->service->cascade_to_children(123, $override, true);
        $this->assertTrue(true);
    }

    public function test_cascade_to_children_noops_when_no_children(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('update_post_meta')->justReturn(true);

        $this->service->cascade_to_children(123, [], false);
        $this->assertTrue(true);
    }
}
