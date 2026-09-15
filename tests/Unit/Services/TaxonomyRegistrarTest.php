<?php
/**
 * Unit tests for the declarative TaxonomyRegistrar service.
 *
 * @package HMWEvents\Tests\Unit\Services
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Registry\TaxonomyRegistry;
use HMWEvents\Services\TaxonomyRegistrar;
use PHPUnit\Framework\TestCase;

class TaxonomyRegistrarTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $this->reset_registry_cache();

        Functions\when('__')->returnArg();
        Functions\when('_x')->returnArg();
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

    private function record_hooks(array &$actions, array &$filters): void
    {
        Functions\when('add_action')->alias(
            function ($hook, $callback, $priority = 10, $accepted_args = 1) use (&$actions) {
                $actions[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
                return true;
            }
        );

        Functions\when('add_filter')->alias(
            function ($hook, $callback, $priority = 10, $accepted_args = 1) use (&$filters) {
                $filters[] = ['hook' => $hook, 'callback' => $callback, 'priority' => $priority];
                return true;
            }
        );
    }

    private function record_register_taxonomy(array &$registered): void
    {
        Functions\when('register_taxonomy')->alias(
            function ($taxonomy, $object_type, $args = []) use (&$registered) {
                $registered[$taxonomy] = [$object_type, $args];
                return true;
            }
        );
    }

    // ============================================================
    // register() hook wiring
    // ============================================================

    public function test_inject_acf_fields_returns_early_without_acf_add_local_field(): void
    {
        if (function_exists('acf_add_local_field')) {
            $this->markTestSkipped('acf_add_local_field was already defined by an earlier test.');
        }

        $this->addToAssertionCount(1);

        $this->assertNull((new TaxonomyRegistrar())->inject_acf_fields());
    }

    public function test_register_wires_init_and_archive_filter_hooks(): void
    {
        $actions = [];
        $filters = [];
        $this->record_hooks($actions, $filters);

        $registrar = new TaxonomyRegistrar();
        $registrar->register();

        $this->assertSame(['init', 'init', 'acf/init'], array_column($actions, 'hook'));
        $this->assertSame([$registrar, 'register_taxonomies'], $actions[0]['callback']);
        $this->assertSame(10, $actions[0]['priority']);
        $this->assertSame([$registrar, 'insert_default_terms'], $actions[1]['callback']);
        $this->assertSame(20, $actions[1]['priority']);
        $this->assertSame([$registrar, 'inject_acf_fields'], $actions[2]['callback']);

        $this->assertSame('hmwevents_event_archive_taxonomies', $filters[0]['hook']);
        $this->assertSame([$registrar, 'append_archive_taxonomies'], $filters[0]['callback']);
    }

    public function test_register_injects_acf_fields_immediately_when_acf_is_initialized(): void
    {
        $actions = [];
        $filters = [];
        $this->record_hooks($actions, $filters);

        Functions\when('did_action')->justReturn(2);
        Functions\expect('acf_add_local_field')->times(3)->andReturn(true);

        (new TaxonomyRegistrar())->register();

        $this->assertNotContains('acf/init', array_column($actions, 'hook'));
    }

    // ============================================================
    // register_taxonomies()
    // ============================================================

    public function test_register_taxonomies_registers_all_content_taxonomies_for_hmw_event(): void
    {
        $registered = [];
        $this->record_register_taxonomy($registered);

        (new TaxonomyRegistrar())->register_taxonomies();

        $this->assertSame(
            ['hmw_event_parenting_topic', 'hmw_event_professional_topic', 'hmw_event_program'],
            array_keys($registered)
        );

        [$object_type, $args] = $registered['hmw_event_parenting_topic'];
        $this->assertSame(['hmw_event'], $object_type);
        $this->assertTrue($args['hierarchical']);
        $this->assertTrue($args['public']);
        $this->assertTrue($args['publicly_queryable']);
        $this->assertTrue($args['show_in_nav_menus']);
        $this->assertTrue($args['show_in_rest']);
        $this->assertTrue($args['show_ui']);
        $this->assertTrue($args['show_in_menu']);
        $this->assertTrue($args['query_var']);
        $this->assertFalse($args['show_in_quick_edit']);
        $this->assertFalse($args['meta_box_cb']);
        $this->assertSame(
            ['slug' => 'event-parenting-topic', 'with_front' => false, 'hierarchical' => true],
            $args['rewrite']
        );
        $this->assertSame('manage_categories', $args['capabilities']['manage_terms']);
        $this->assertSame('manage_categories', $args['capabilities']['delete_terms']);
        $this->assertSame('Parenting Topics', $args['labels']['name']);
        $this->assertSame('Parenting Topic', $args['labels']['singular_name']);
        $this->assertArrayHasKey('parent_item', $args['labels']);
    }

    public function test_register_taxonomies_configures_professional_topic_as_private(): void
    {
        $registered = [];
        $this->record_register_taxonomy($registered);

        (new TaxonomyRegistrar())->register_taxonomies();

        $args = $registered['hmw_event_professional_topic'][1];
        $this->assertFalse($args['public']);
        $this->assertFalse($args['publicly_queryable']);
        $this->assertFalse($args['query_var']);
        $this->assertFalse($args['rewrite']);
        $this->assertFalse($args['show_in_nav_menus']);
    }

    public function test_register_taxonomies_builds_program_rewrite(): void
    {
        $registered = [];
        $this->record_register_taxonomy($registered);

        (new TaxonomyRegistrar())->register_taxonomies();

        $args = $registered['hmw_event_program'][1];
        $this->assertSame(
            ['slug' => 'event-program', 'with_front' => false, 'hierarchical' => true],
            $args['rewrite']
        );
    }

    // ============================================================
    // insert_default_terms()
    // ============================================================

    public function test_insert_default_terms_inserts_all_missing_terms(): void
    {
        $inserted = [];
        $flags    = [];

        Functions\when('get_option')->justReturn(false);
        Functions\when('term_exists')->justReturn(false);
        Functions\when('update_option')->alias(
            function ($option, $value) use (&$flags) {
                $flags[] = $option;
                return true;
            }
        );
        Functions\expect('wp_insert_term')->times(9)->andReturnUsing(
            function ($term, $taxonomy, $args = []) use (&$inserted) {
                $inserted[$taxonomy][$args['slug']] = $term;
                return ['term_id' => 123, 'term_taxonomy_id' => 123];
            }
        );

        (new TaxonomyRegistrar())->insert_default_terms();

        $this->assertSame(
            [
                'sleep'             => 'Sleep',
                'feeding'           => 'Feeding',
                'toddler-behaviour' => 'Toddler Behaviour',
                'mental-health'     => 'Mental Health',
            ],
            $inserted['hmw_event_parenting_topic']
        );
        $this->assertSame(
            ['pcit' => 'PCIT', 'family-partnership-model' => 'Family Partnership Model'],
            $inserted['hmw_event_professional_topic']
        );
        $this->assertSame(
            [
                'circle-of-security'     => 'Circle of Security',
                'bringing-up-great-kids' => 'Bringing Up Great Kids',
                'first-steps-count'      => 'First Steps Count',
            ],
            $inserted['hmw_event_program']
        );
        $this->assertSame(
            [
                'hmwevents_parenting_topics_inserted',
                'hmwevents_professional_topics_inserted',
                'hmwevents_programs_inserted',
            ],
            $flags
        );
    }

    public function test_insert_default_terms_skips_taxonomies_whose_option_flag_is_set(): void
    {
        Functions\when('get_option')->justReturn(true);
        Functions\when('wp_insert_term')->alias(
            function () {
                throw new \RuntimeException('wp_insert_term must not be called');
            }
        );
        Functions\when('update_option')->alias(
            function () {
                throw new \RuntimeException('update_option must not be called');
            }
        );

        $this->addToAssertionCount(1);
        (new TaxonomyRegistrar())->insert_default_terms();
    }

    public function test_insert_default_terms_skips_terms_that_already_exist(): void
    {
        $inserted = [];

        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('term_exists')->alias(
            function ($term_slug, $taxonomy) {
                return $taxonomy === 'hmw_event_parenting_topic' && $term_slug === 'sleep'
                    ? ['term_id' => 9]
                    : false;
            }
        );
        Functions\expect('wp_insert_term')->times(8)->andReturnUsing(
            function ($term, $taxonomy, $args = []) use (&$inserted) {
                $inserted[] = $args['slug'];
                return ['term_id' => 10];
            }
        );

        (new TaxonomyRegistrar())->insert_default_terms();

        $this->assertSame(8, count($inserted));
        $this->assertNotContains('sleep', $inserted);
        $this->assertContains('feeding', $inserted);
        $this->assertContains('pcit', $inserted);
        $this->assertContains('first-steps-count', $inserted);
    }

    // ============================================================
    // inject_acf_fields()
    // ============================================================

    public function test_inject_acf_fields_adds_one_local_field_per_definition(): void
    {
        $fields = [];

        Functions\expect('acf_add_local_field')->times(3)->andReturnUsing(
            function (array $field) use (&$fields) {
                $fields[$field['name']] = $field;
                return true;
            }
        );

        (new TaxonomyRegistrar())->inject_acf_fields();

        $parenting = $fields['_event_parenting_topic'];
        $this->assertSame('field_event_parenting_topic', $parenting['key']);
        $this->assertSame('Parenting Topic', $parenting['label']);
        $this->assertSame('taxonomy', $parenting['type']);
        $this->assertSame('hmw_event_parenting_topic', $parenting['taxonomy']);
        $this->assertSame('checkbox', $parenting['field_type']);
        $this->assertSame('group_hmw_event_details', $parenting['parent']);
        $this->assertSame(1, $parenting['save_terms']);
        $this->assertSame(1, $parenting['load_terms']);
        $this->assertSame(0, $parenting['create_terms']);
        $this->assertSame(10, $parenting['menu_order']);

        $professional = $fields['_event_professional_topic'];
        $this->assertSame('field_event_professional_topic', $professional['key']);
        $this->assertSame(11, $professional['menu_order']);

        $program = $fields['_event_program'];
        $this->assertSame('field_event_program', $program['key']);
        $this->assertSame(12, $program['menu_order']);
    }

    // ============================================================
    // append_archive_taxonomies()
    // ============================================================

    public function test_append_archive_taxonomies_merges_and_dedupes_platform_taxonomies(): void
    {
        $registrar = new TaxonomyRegistrar();

        $this->assertSame(
            ['hmw_event_type', 'hmw_event_parenting_topic', 'hmw_event_program'],
            $registrar->append_archive_taxonomies(['hmw_event_type', 'hmw_event_parenting_topic'])
        );

        $this->assertSame(
            ['hmw_event_type', 'hmw_event_audience', 'hmw_event_parenting_topic', 'hmw_event_program'],
            $registrar->append_archive_taxonomies(['hmw_event_type', 'hmw_event_audience'])
        );
    }
}
