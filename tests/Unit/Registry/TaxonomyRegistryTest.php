<?php
/**
 * Unit tests for the declarative TaxonomyRegistry.
 *
 * @package HMWEvents\Tests\Unit\Registry
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit\Registry;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Registry\TaxonomyRegistry;
use PHPUnit\Framework\TestCase;

class TaxonomyRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $this->reset_registry_cache();
    }

    protected function tearDown(): void
    {
        $this->reset_registry_cache();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function reset_registry_cache(): void
    {
        $cache = new \ReflectionProperty(TaxonomyRegistry::class, 'definitions');
        if (PHP_VERSION_ID < 80100) {
            $cache->setAccessible(true);
        }
        $cache->setValue(null, null);
    }

    private function patch_registry_filter(?\Closure $transform = null): void
    {
        Functions\when('apply_filters')->alias(function ($hook, $value) use ($transform) {
            if ($hook === 'hmwevents_taxonomies' && $transform) {
                return $transform($value);
            }

            return $value;
        });
    }

    // ============================================================
    // Defaults
    // ============================================================

    public function test_defaults_contain_exactly_the_three_bundled_taxonomies(): void
    {
        $this->assertSame(
            ['hmw_event_parenting_topic', 'hmw_event_professional_topic', 'hmw_event_program'],
            array_keys(TaxonomyRegistry::defaults())
        );
    }

    public function test_defaults_define_expected_event_field_keys(): void
    {
        $defaults = TaxonomyRegistry::defaults();

        $this->assertSame('event_parenting_topic', $defaults['hmw_event_parenting_topic']['event_field_key']);
        $this->assertSame('event_professional_topic', $defaults['hmw_event_professional_topic']['event_field_key']);
        $this->assertSame('event_program', $defaults['hmw_event_program']['event_field_key']);
    }

    public function test_all_returns_defaults_when_filter_is_untouched(): void
    {
        $this->assertSame(TaxonomyRegistry::defaults(), TaxonomyRegistry::all());
    }

    public function test_get_returns_null_for_unknown_slug(): void
    {
        $this->assertNull(TaxonomyRegistry::get('hmw_event_nonexistent'));
        $this->assertSame('Parenting Topics', TaxonomyRegistry::get('hmw_event_parenting_topic')['name']);
    }

    // ============================================================
    // Derived lookups
    // ============================================================

    public function test_filter_key_map_maps_parenting_topic_and_program(): void
    {
        $this->assertSame(
            ['hmw_event_parenting_topic' => 'topic', 'hmw_event_program' => 'program'],
            TaxonomyRegistry::filter_key_map()
        );
    }

    public function test_archive_taxonomies_exclude_non_public_taxonomies(): void
    {
        $this->assertSame(
            ['hmw_event_parenting_topic', 'hmw_event_program'],
            TaxonomyRegistry::archive_taxonomies()
        );
    }

    public function test_single_meta_taxonomies_includes_all_three_content_taxonomies(): void
    {
        $defs = TaxonomyRegistry::single_meta_taxonomies();

        $this->assertSame(
            ['hmw_event_parenting_topic', 'hmw_event_professional_topic', 'hmw_event_program'],
            array_keys($defs)
        );
        $this->assertTrue($defs['hmw_event_parenting_topic']['show_single_meta']);
        $this->assertTrue($defs['hmw_event_professional_topic']['show_single_meta']);
        $this->assertTrue($defs['hmw_event_program']['show_single_meta']);
    }

    // ============================================================
    // apply_to_type_configs
    // ============================================================

    public function test_apply_to_type_configs_applies_required_and_hidden_rules(): void
    {
        $configs = TaxonomyRegistry::apply_to_type_configs([
            'parent-course' => [
                'required_fields' => ['event_start_date'],
                'hidden_fields'   => ['event_webinar_url'],
            ],
            'professional-online' => [
                'required_fields' => ['event_start_date'],
                'hidden_fields'   => ['event_venue'],
            ],
            'custom-archetype' => [],
        ]);

        $parent = $configs['parent-course'];
        $this->assertSame(['event_start_date', 'event_parenting_topic'], $parent['required_fields']);
        $this->assertContains('event_professional_topic', $parent['hidden_fields']);
        $this->assertNotContains('event_parenting_topic', $parent['hidden_fields']);
        $this->assertNotContains('event_professional_topic', $parent['required_fields']);

        $pro = $configs['professional-online'];
        $this->assertSame(
            ['event_start_date', 'event_parenting_topic', 'event_professional_topic'],
            $pro['required_fields']
        );
        $this->assertSame(['event_venue'], $pro['hidden_fields']);

        $custom = $configs['custom-archetype'];
        $this->assertSame(['event_parenting_topic'], $custom['required_fields']);
        $this->assertArrayNotHasKey('hidden_fields', $custom);
        $this->assertNotContains('event_professional_topic', $custom['required_fields']);

        $this->assertNotContains('event_program', $parent['required_fields']);
        $this->assertNotContains('event_program', $pro['required_fields']);
    }

    public function test_apply_to_type_configs_does_not_duplicate_existing_keys(): void
    {
        $configs = TaxonomyRegistry::apply_to_type_configs([
            'parent-course' => [
                'required_fields' => ['event_parenting_topic', 'event_start_date'],
                'hidden_fields'   => ['event_professional_topic'],
            ],
        ]);

        $this->assertSame(
            ['event_parenting_topic', 'event_start_date'],
            $configs['parent-course']['required_fields']
        );
        $this->assertSame(
            ['event_professional_topic'],
            $configs['parent-course']['hidden_fields']
        );
    }

    public function test_apply_to_type_configs_with_empty_registry_leaves_configs_untouched(): void
    {
        $this->patch_registry_filter(fn (): array => []);

        $input = [
            'parent-course' => ['required_fields' => ['event_start_date']],
            'other'         => [],
        ];

        $this->assertSame($input, TaxonomyRegistry::apply_to_type_configs($input));
    }

    // ============================================================
    // Filter-driven customisation
    // ============================================================

    public function test_filter_can_add_custom_archive_taxonomy(): void
    {
        $this->patch_registry_filter(function (array $defs): array {
            $defs['hmw_event_region'] = [
                'name'     => 'Regions',
                'singular' => 'Region',
                'rewrite'  => 'event-region',
                'archive'  => true,
            ];

            return $defs;
        });

        $this->assertSame(
            ['hmw_event_parenting_topic', 'hmw_event_program', 'hmw_event_region'],
            TaxonomyRegistry::archive_taxonomies()
        );
    }

    // ============================================================
    // normalize
    // ============================================================

    public function test_normalize_defaults_publicly_queryable_to_public(): void
    {
        $private = TaxonomyRegistry::normalize(['name' => 'Custom', 'public' => false], 'hmw_event_custom');
        $this->assertFalse($private['publicly_queryable']);
        $this->assertFalse($private['public']);

        $public = TaxonomyRegistry::normalize(['name' => 'Custom'], 'hmw_event_custom');
        $this->assertTrue($public['publicly_queryable']);
        $this->assertTrue($public['public']);
        $this->assertSame('hmw_event_custom', $public['singular']);
    }

    public function test_normalize_keeps_explicit_query_var_false(): void
    {
        $explicit = TaxonomyRegistry::normalize(['query_var' => false], 'hmw_event_custom');
        $this->assertFalse($explicit['query_var']);

        $implicit = TaxonomyRegistry::normalize([], 'hmw_event_custom');
        $this->assertNull($implicit['query_var']);
    }
}
