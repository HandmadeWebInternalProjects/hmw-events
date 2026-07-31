<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\ACF;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class ACFTest extends TestCase
{
    private ACF $acf;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $this->acf = new ACF();

        Functions\when('__')->returnArg();
        Functions\when('sanitize_key')->alias(function ($key) {
            return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $key));
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('error_log')->justReturn(true);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('get_the_ID')->justReturn(0);
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('add_meta_box')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Helper: set up GET post param and mock get_post_type for hmw_event.
     */
    private function stubPostContext(int $post_id = 1): void
    {
        $_GET['post'] = $post_id;
        Functions\when('get_post_type')->justReturn('hmw_event');
    }

    /**
     * Helper: tear down GET param.
     */
    private function clearPostContext(): void
    {
        $_GET['post'] = null;
    }

    /**
     * Helper: set up get_post_meta return map for field config tests.
     */
    private function stubMetaMap(array $map): void
    {
        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($map) {
            foreach ($map as $entry) {
                if ($pid === $entry[0] && $key === $entry[1] && $single === $entry[2]) {
                    return $entry[3];
                }
            }
            return '';
        });
    }

    // ============================================================
    // apply_event_field_config() — early returns
    // ============================================================

    public function test_apply_event_field_config_returns_unchanged_for_non_array(): void
    {
        $this->assertSame('not_an_array', $this->acf->apply_event_field_config('not_an_array'));
        $this->assertSame(null, $this->acf->apply_event_field_config(null));
        $this->assertSame(42, $this->acf->apply_event_field_config(42));
    }

    public function test_apply_event_field_config_returns_unchanged_when_missing_name(): void
    {
        $this->stubPostContext();
        $field = ['key' => 'field_abc', '_name' => ''];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertSame($field, $result);
        $this->clearPostContext();
    }

    public function test_apply_event_field_config_returns_unchanged_when_no_name_and_no_acf_key(): void
    {
        $this->stubPostContext();
        $field = ['key' => 'field_abc'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertSame($field, $result);
        $this->clearPostContext();
    }

    public function test_apply_event_field_config_returns_unchanged_for_wrong_post_type(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('post');

        $field = ['_name' => 'event_start_date', 'key' => 'field_event_start_date'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertSame($field, $result);

        $_GET['post'] = null;
    }

    public function test_apply_event_field_config_returns_unchanged_for_non_event_field(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, ['event_fields' => ['required' => [], 'optional' => [], 'hidden' => []]]],
        ]);

        $field = ['_name' => 'some_custom_field', 'key' => 'field_some_custom_field'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertSame($field, $result);
        $this->clearPostContext();
    }

    // ============================================================
    // apply_event_field_config() — hidden fields
    // ============================================================

    public function test_apply_event_field_config_adds_hidden_class_for_hidden_field(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => [],
                    'hidden'   => ['event_webinar_url'],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_webinar_url', 'key' => 'field_event_webinar_url'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    // ============================================================
    // apply_event_field_config() — required fields
    // ============================================================

    public function test_apply_event_field_config_sets_required_for_required_field(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => ['event_start_date'],
                    'optional' => [],
                    'hidden'   => [],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_start_date', 'key' => 'field_event_start_date', 'required' => 0];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertSame(1, $result['required']);
        $this->clearPostContext();
    }

    // ============================================================
    // apply_event_field_config() — optional fields
    // ============================================================

    public function test_apply_event_field_config_sets_required_zero_for_optional_field(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => ['event_venue_name'],
                    'hidden'   => [],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_venue_name', 'key' => 'field_event_venue_name', 'required' => 1];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    // ============================================================
    // apply_event_field_config() — no config found
    // ============================================================

    public function test_apply_event_field_config_returns_unchanged_when_no_config(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, ''],
        ]);
        Functions\when('wp_get_object_terms')->justReturn([]);

        $field = ['_name' => 'event_start_date', 'key' => 'field_event_start_date'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertSame($field, $result);
        $this->clearPostContext();
    }

    // ============================================================
    // get_event_field_config() — override takes priority over snapshot
    // ============================================================

    public function test_get_event_field_config_override_takes_priority(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, [
                'event_fields' => [
                    'required' => ['event_start_date'],
                    'optional' => [],
                    'hidden'   => ['event_price'],
                ],
            ]],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => ['event_end_date'],
                    'optional' => [],
                    'hidden'   => ['event_webinar_url'],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_price', 'key' => 'field_event_price'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    // ============================================================
    // get_event_field_config() — snapshot takes priority
    // ============================================================

    public function test_get_event_field_config_snapshot_takes_priority_when_no_override(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => ['event_end_date'],
                    'optional' => [],
                    'hidden'   => ['event_webinar_url'],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_webinar_url', 'key' => 'field_event_webinar_url'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    // ============================================================
    // get_event_field_config() — falls back to EventTypeRegistry
    // ============================================================

    public function test_get_event_field_config_falls_back_to_registry(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, ''],
        ]);
        Functions\when('wp_get_object_terms')->justReturn(['parenting-webinar']);

        $field = ['_name' => 'event_price', 'key' => 'field_event_price'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    public function test_get_event_field_config_returns_null_when_no_terms(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, ''],
        ]);
        Functions\when('wp_get_object_terms')->justReturn([]);

        $field = ['_name' => 'event_start_date', 'key' => 'field_event_start_date'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertSame($field, $result);
        $this->clearPostContext();
    }

    public function test_get_event_field_config_returns_null_on_wp_error_terms(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, ''],
        ]);

        $error = new \WP_Error('term_error', 'Could not get terms');
        Functions\when('wp_get_object_terms')->justReturn($error);

        $field = ['_name' => 'event_start_date', 'key' => 'field_event_start_date'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertSame($field, $result);
        $this->clearPostContext();
    }

    // ============================================================
    // hide_recurrence_on_children()
    // ============================================================

    public function test_hide_recurrence_on_children_returns_unchanged_for_non_hmw_event(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('post');

        $field = ['key' => 'field_event_is_recurring'];
        $result = $this->acf->hide_recurrence_on_children($field);
        $this->assertSame($field, $result);
        $_GET['post'] = null;
    }

    public function test_hide_recurrence_on_children_returns_false_for_child_session(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('hmw_event');
        Functions\when('get_post_meta')->justReturn('1');

        $field = ['key' => 'field_event_is_recurring'];
        $result = $this->acf->hide_recurrence_on_children($field);
        $this->assertFalse($result);
        $_GET['post'] = null;
    }

    public function test_hide_recurrence_on_children_returns_false_for_cloned_event(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('hmw_event');

        $meta_map = [
            [1, '_is_child_session', true, ''],
            [1, '_cloned_from', true, '99'],
        ];
        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($meta_map) {
            foreach ($meta_map as $m) {
                if ($pid === $m[0] && $key === $m[1] && $single === $m[2]) {
                    return $m[3];
                }
            }
            return '';
        });

        $field = ['key' => 'field_event_recurrence_interval'];
        $result = $this->acf->hide_recurrence_on_children($field);
        $this->assertFalse($result);
        $_GET['post'] = null;
    }

    public function test_hide_recurrence_on_children_returns_unchanged_for_non_recurrence_field(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('hmw_event');
        Functions\when('get_post_meta')->justReturn('1');

        $field = ['key' => 'field_event_start_date'];
        $result = $this->acf->hide_recurrence_on_children($field);
        $this->assertSame($field, $result);
        $_GET['post'] = null;
    }

    public function test_hide_recurrence_on_children_returns_unchanged_for_non_child_non_clone(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('hmw_event');
        Functions\when('get_post_meta')->justReturn('');

        $field = ['key' => 'field_event_is_recurring'];
        $result = $this->acf->hide_recurrence_on_children($field);
        $this->assertSame($field, $result);
        $_GET['post'] = null;
    }

    public function test_hide_recurrence_on_children_hides_field_event_recurrence_unit(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('hmw_event');
        Functions\when('get_post_meta')->justReturn('1');

        $field = ['key' => 'field_event_recurrence_unit'];
        $result = $this->acf->hide_recurrence_on_children($field);
        $this->assertFalse($result);
        $_GET['post'] = null;
    }

    public function test_hide_recurrence_on_children_hides_field_event_recurrence_days(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('hmw_event');
        Functions\when('get_post_meta')->justReturn('1');

        $field = ['key' => 'field_event_recurrence_days'];
        $result = $this->acf->hide_recurrence_on_children($field);
        $this->assertFalse($result);
        $_GET['post'] = null;
    }

    // ============================================================
    // resolve_field_meta_name() — tested via apply_event_field_config
    // ============================================================

    public function test_resolve_field_meta_name_uses_name_field(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => [],
                    'hidden'   => ['event_venue_name'],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_venue_name'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    public function test_resolve_field_meta_name_uses_name_when_no__name(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => [],
                    'hidden'   => ['event_price'],
                ],
            ]],
        ]);

        $field = ['name' => 'event_price'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    public function test_resolve_field_meta_name_parses_acf_bracket_format(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => [],
                    'hidden'   => [],
                ],
            ]],
        ]);

        // acf[field_event_capacity] resolves to ACF key field_event_capacity,
        // which does not start with "event_" — so the field passes through unchanged.
        $field = ['name' => 'acf[field_event_capacity]'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertSame($field, $result);
        $this->clearPostContext();
    }

    // ============================================================
    // resolve_post_id() — tested via apply_event_field_config
    // ============================================================

    public function test_resolve_post_id_from_global_post(): void
    {
        global $post;
        $post = new \WP_Post((object) ['ID' => 42]);

        Functions\when('get_post_type')->justReturn('hmw_event');
        $this->stubMetaMap([
            [42, '_event_template_override', true, ''],
            [42, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => [],
                    'hidden'   => ['event_price'],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_price', 'key' => 'field_event_price'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);

        $post = null;
    }

    // ============================================================
    // normalize_event_field_key() — tested via apply_event_field_config
    // ============================================================

    public function test_normalize_event_field_key_strips_event_prefix(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => [],
                    'hidden'   => ['event_webinar_url'],
                ],
            ]],
        ]);

        $field = ['_name' => '_event_webinar_url'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    public function test_normalize_event_field_key_returns_unchanged_for_non_prefixed(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => [],
                    'hidden'   => ['event_price'],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_price'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    // ============================================================
    // normalize_event_field_keys() — tested via apply_event_field_config
    // ============================================================

    public function test_normalize_event_field_keys_strips_prefixes_from_all_keys(): void
    {
        $this->stubPostContext();
        $this->stubMetaMap([
            [1, '_event_template_override', true, ''],
            [1, '_event_field_config', true, [
                'event_fields' => [
                    'required' => [],
                    'optional' => [],
                    'hidden'   => ['_event_webinar_url', 'event_price'],
                ],
            ]],
        ]);

        $field = ['_name' => 'event_webinar_url'];
        $result = $this->acf->apply_event_field_config($field);
        $this->assertIsArray($result);
        $this->assertStringContainsString('hmwevents-hidden-by-type', $result['wrapper']['class'] ?? '');
        $this->assertSame(0, $result['required']);
        $this->clearPostContext();
    }

    // ============================================================
    // hide_recurrence_on_children() additional coverage
    // ============================================================

    public function test_hide_recurrence_on_children_hides_all_eight_fields(): void
    {
        $recurrence_keys = [
            'field_event_is_recurring',
            'field_event_recurrence_interval',
            'field_event_recurrence_unit',
            'field_event_recurrence_days',
            'field_event_recurrence_end_type',
            'field_event_recurrence_end_date',
            'field_event_recurrence_max_occurrences',
            'field_event_recurrence_custom_dates',
        ];

        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('hmw_event');
        Functions\when('get_post_meta')->justReturn('1');

        foreach ($recurrence_keys as $key) {
            $field = ['key' => $key];
            $result = $this->acf->hide_recurrence_on_children($field);
            $this->assertFalse($result, "Field {$key} should be hidden on child sessions");
        }

        $_GET['post'] = null;
    }

    public function test_hide_recurrence_on_children_clone_also_checks_parent(): void
    {
        $_GET['post'] = 1;
        Functions\when('get_post_type')->justReturn('hmw_event');

        $meta_map = [
            [1, '_is_child_session', true, ''],
            [1, '_cloned_from', true, '5'],
        ];
        Functions\when('get_post_meta')->alias(function ($pid, $key, $single) use ($meta_map) {
            foreach ($meta_map as $m) {
                if ($pid === $m[0] && $key === $m[1] && $single === $m[2]) {
                    return $m[3];
                }
            }
            return '';
        });

        $field = ['key' => 'field_event_recurrence_max_occurrences'];
        $result = $this->acf->hide_recurrence_on_children($field);
        $this->assertFalse($result);
        $_GET['post'] = null;
    }
}
