<?php

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Helpers\EventFieldConfig;
use HMWEvents\Services\SessionService;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class SessionCloneSerializationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturn([]);

        Functions\when('__')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('get_option')->justReturn(false);
        Functions\when('error_log')->justReturn(true);
        Functions\when('do_action')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('maybe_unserialize')->alias(function ($original) {
            if (!is_string($original) || $original === '') {
                return $original;
            }
            $unserialized = @unserialize($original);
            return ($unserialized === false && $original !== 'b:0;') ? $original : $unserialized;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // EventFieldConfig::normalize()
    // ============================================================

    public function test_normalize_returns_arrays_untouched(): void
    {
        $value = ['event_fields' => ['hidden' => ['event_price']]];
        $this->assertSame($value, EventFieldConfig::normalize($value));
    }

    public function test_normalize_unserializes_serialized_strings(): void
    {
        $array  = ['event_fields' => ['hidden' => ['event_price']]];
        $result = EventFieldConfig::normalize(serialize($array));
        $this->assertSame($array, $result);
    }

    public function test_normalize_unserializes_doubly_serialized_strings(): void
    {
        $array = ['registration_fields' => ['sections' => []]];
        $once  = serialize($array);
        $this->assertIsString($once);

        $decoded = unserialize($once);
        $this->assertSame($array, $decoded);
    }

    public function test_normalize_decodes_json_strings(): void
    {
        $array  = ['schema_version' => 3];
        $result = EventFieldConfig::normalize(json_encode($array));
        $this->assertSame($array, $result);
    }

    public function test_normalize_returns_null_for_garbage_strings(): void
    {
        $this->assertNull(EventFieldConfig::normalize('not_an_array'));
        $this->assertNull(EventFieldConfig::normalize('{invalid json'));
        $this->assertNull(EventFieldConfig::normalize(''));
    }

    public function test_normalize_returns_null_for_non_string_non_array_scalars(): void
    {
        $this->assertNull(EventFieldConfig::normalize(null));
        $this->assertNull(EventFieldConfig::normalize(false));
        $this->assertNull(EventFieldConfig::normalize(123));
    }

    public function test_read_normalizes_corrupted_meta_value(): void
    {
        $stored = serialize(['event_fields' => ['hidden' => []]]);

        Functions\when('get_post_meta')->alias(function ($id, $key, $single) use ($stored) {
            if ($key === '_event_field_config') {
                return $stored;
            }
            return '';
        });

        $result = EventFieldConfig::read(42);
        $this->assertSame(['event_fields' => ['hidden' => []]], $result);
    }

    // ============================================================
    // SessionService::create_child_session() serialization fix
    // ============================================================

    private function makeParentPost(int $id): \WP_Post
    {
        return new \WP_Post((object) [
            'ID'           => $id,
            'post_type'    => 'hmw_event',
            'post_status'  => 'publish',
            'post_title'   => 'Parent Walk Ins',
            'post_content' => '',
            'post_author'  => 2,
            'post_parent'  => 0,
        ]);
    }

    public function test_create_child_session_stores_unserialized_field_config(): void
    {
        $parentId = 5735;
        $childId  = 5954;

        $configArray = [
            'event_fields'        => ['required' => [], 'optional' => [], 'hidden' => ['event_price']],
            'registration_fields' => ['sections' => [['id' => 'sec_x', 'label' => 'S', 'fields' => []]]],
        ];
        $serializedConfig = serialize($configArray);

        Functions\when('get_post')->alias(function ($id) use ($parentId) {
            return $id === $parentId ? $this->makeParentPost($parentId) : null;
        });

        Functions\when('get_post_meta')->alias(function ($id, $key = '', $single = false) use ($parentId, $serializedConfig) {
            if ($id === $parentId && $key === '') {
                return [
                    '_event_start_date'   => [$serializedConfig],
                    '_event_field_config' => [$serializedConfig],
                    '_event_default_values' => [serialize(['registration' => ['attendance_default' => 'individual']])],
                    '_event_is_recurring' => ['1'],
                    '_event_price'        => ['198'],
                ];
            }
            if ($key === '_event_start_date') {
                return '2026-09-01 10:00:00';
            }
            if ($key === '_event_end_date') {
                return '2026-09-01 11:00:00';
            }
            return '';
        });

        $copied = [];
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) use (&$copied) {
            $copied[$key] = $value;
            return true;
        });

        Functions\when('wp_insert_post')->alias(function ($args) use ($childId) {
            return $childId;
        });
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('wp_set_object_terms')->justReturn(true);
        Functions\when('get_post_thumbnail_id')->justReturn(0);

        $service = new SessionService();

        $method = new \ReflectionMethod($service, 'create_child_session');
        $method->setAccessible(true);

        $parent = get_post($parentId);
        $result = $method->invoke($service, $parent, ['start' => '2026-09-01', 'end' => '2026-09-02'], 1);

        $this->assertSame($childId, $result);
        $this->assertIsArray($copied['_event_field_config'] ?? null, 'field config must be stored unserialized');
        $this->assertSame($configArray, $copied['_event_field_config']);
        $this->assertIsArray($copied['_event_default_values'] ?? null);
        $this->assertSame('198', $copied['_event_price']);

        $this->assertArrayNotHasKey('_event_is_recurring', $copied);
    }

    // ============================================================
    // SessionService::sync_event_type_to_children()
    // ============================================================

    public function test_sync_event_type_propagates_changed_type_to_children(): void
    {
        $parentId = 5735;

        $childA = new \WP_Post((object) ['ID' => 5954]);
        $childB = new \WP_Post((object) ['ID' => 5955]);

        Functions\when('get_posts')->justReturn([$childA, $childB]);

        $setCalls = [];
        Functions\when('wp_get_object_terms')->alias(function ($id, $tax, $args = []) use ($parentId) {
            if ($id === $parentId && $tax === 'hmw_event_type') {
                return ['parent-walk-in'];
            }
            if ($tax === 'hmw_event_type') {
                return $id === 5954 ? ['parent-walk-in'] : ['professional-in-person'];
            }
            return [];
        });

        Functions\when('wp_set_object_terms')->alias(function ($id, $terms, $tax) use (&$setCalls) {
            $setCalls[] = [$id, $terms, $tax];
            return true;
        });

        $service = new SessionService();

        $method = new \ReflectionMethod($service, 'sync_event_type_to_children');
        $method->setAccessible(true);
        $method->invoke($service, $parentId);

        $this->assertCount(1, $setCalls, 'only the drifted child should be updated');
        $this->assertSame(5955, $setCalls[0][0]);
        $this->assertSame(['parent-walk-in'], $setCalls[0][1]);
        $this->assertSame('hmw_event_type', $setCalls[0][2]);
    }

    public function test_sync_event_type_skips_when_parent_has_no_type(): void
    {
        Functions\when('get_posts')->justReturn([]);
        Functions\when('wp_get_object_terms')->justReturn([]);

        $setCalled = false;
        Functions\when('wp_set_object_terms')->alias(function () use (&$setCalled) {
            $setCalled = true;
            return true;
        });

        $service = new SessionService();

        $method = new \ReflectionMethod($service, 'sync_event_type_to_children');
        $method->setAccessible(true);
        $method->invoke($service, 5735);

        $this->assertFalse($setCalled);
    }
}
