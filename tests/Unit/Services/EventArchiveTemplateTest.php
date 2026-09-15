<?php

/**
 * Event archive template selection and term archive listings tests.
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\EventListingService;
use HMWEvents\Services\Hooks;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventArchiveTemplateTest extends TestCase
{
    private bool $enabled = true;
    private bool $is_tax = false;
    private string $taxonomy = '';
    private bool $is_post_type_archive = false;
    private string $native_template = '';
    private string $plugin_template = '';
    private array $captured_native_candidates = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $this->enabled = true;
        $this->is_tax = false;
        $this->taxonomy = '';
        $this->is_post_type_archive = false;
        $this->native_template = '';
        $this->plugin_template = '';
        $this->captured_native_candidates = [];

        Functions\when('apply_filters')->alias(function ($tag, $value, ...$args) {
            if ($tag === 'hmwevents_event_archive_enabled') {
                return $this->enabled;
            }
            return $value;
        });
        Functions\when('is_tax')->alias(function ($taxonomies = '') {
            if (!$this->is_tax) {
                return false;
            }
            if ($taxonomies === '' || $taxonomies === []) {
                return true;
            }
            return in_array($this->taxonomy, (array) $taxonomies, true);
        });
        Functions\when('is_post_type_archive')->alias(function ($post_types = '') {
            if (!$this->is_post_type_archive) {
                return false;
            }
            return $post_types === '' || in_array('hmw_event', (array) $post_types, true);
        });
        Functions\when('get_queried_object')->alias(function () {
            $term = new \WP_Term((object) [
                'term_id' => 7,
                'name' => 'Webinars',
                'slug' => 'webinars',
                'taxonomy' => $this->taxonomy,
            ]);
            return $term;
        });
        Functions\when('locate_template')->alias(function ($candidates) {
            $this->captured_native_candidates = (array) $candidates;
            return in_array(basename($this->native_template), (array) $candidates, true) ? $this->native_template : '';
        });
        Functions\when('hmwevents_locate_template')->alias(function ($name) {
            return $this->plugin_template;
        });
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_returns_template_unchanged_for_non_event_requests(): void
    {
        $template = '/theme/archive.php';

        $this->assertSame($template, Hooks::event_archive_template($template));
        $this->assertSame([], $this->captured_native_candidates);
    }

    public function test_returns_native_taxonomy_template_when_theme_provides_one(): void
    {
        $this->is_tax = true;
        $this->taxonomy = 'hmw_event_type';
        $this->native_template = '/theme/taxonomy-hmw_event_type.php';

        $result = Hooks::event_archive_template('/theme/archive.php');

        $this->assertSame('/theme/taxonomy-hmw_event_type.php', $result);
        $this->assertSame(['taxonomy-hmw_event_type.php'], $this->captured_native_candidates);
    }

    public function test_returns_plugin_template_for_event_taxonomy_without_theme_override(): void
    {
        $this->is_tax = true;
        $this->taxonomy = 'hmw_event_type';
        $this->plugin_template = __FILE__;

        $result = Hooks::event_archive_template('/theme/taxonomy.php');

        $this->assertSame(__FILE__, $result);
    }

    public function test_ignores_generic_taxonomy_templates(): void
    {
        $this->is_tax = true;
        $this->taxonomy = 'hmw_event_type';
        $this->native_template = '/theme/taxonomy.php';
        $this->plugin_template = __FILE__;

        $result = Hooks::event_archive_template('/theme/archive.php');

        $this->assertSame(__FILE__, $result);
        $this->assertSame(['taxonomy-hmw_event_type.php'], $this->captured_native_candidates);
    }

    public function test_handles_post_type_archive(): void
    {
        $this->is_post_type_archive = true;
        $this->plugin_template = __FILE__;

        $result = Hooks::event_archive_template('/theme/archive.php');

        $this->assertSame(__FILE__, $result);
        $this->assertSame(['archive-hmw_event.php'], $this->captured_native_candidates);
    }

    public function test_ignores_non_event_taxonomies(): void
    {
        $this->is_tax = true;
        $this->taxonomy = 'category';

        $this->assertSame('/theme/archive.php', Hooks::event_archive_template('/theme/archive.php'));
    }

    public function test_can_be_disabled_via_filter(): void
    {
        $this->enabled = false;
        $this->is_tax = true;
        $this->taxonomy = 'hmw_event_type';
        $this->plugin_template = __FILE__;

        $this->assertSame('/theme/archive.php', Hooks::event_archive_template('/theme/archive.php'));
    }

    // ============================================================
    // EventListingService::render_term_listings
    // ============================================================

    public function test_term_listings_uses_term_url_as_pagination_base(): void
    {
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, array_intersect_key((array) $atts, $defaults));
        });
        Functions\when('hmwevents_get_template_part')->alias(function ($slug, $name = null, $args = []) {
            $template = HMWEvents_ABSPATH . 'src/views/' . $slug . '.php';
            if (file_exists($template)) {
                load_template($template, false, $args);
            }
        });
        Functions\when('load_template')->alias(function ($path, $require_once = false, $args = []) {
            include $path;
        });
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_post')->alias(function ($id) {
            return new \WP_Post((object) [
                'ID' => (int) $id,
                'post_type' => 'hmw_event',
                'post_status' => 'publish',
                'post_title' => 'Test Event',
                'post_excerpt' => 'Test excerpt',
                'post_content' => 'Test content',
            ]);
        });
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('get_the_post_thumbnail_url')->justReturn(false);
        Functions\when('wp_trim_words')->alias(function ($text, $num = 55, $more = null) {
            return $text;
        });
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('date_i18n')->alias(function ($format, $time) {
            return date($format, $time);
        });
        Functions\when('get_term_link')->justReturn('https://example.com/event-type/webinars');
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_permalink')->alias(function () {
            return 'https://example.com/wrong-permalink';
        });
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html_e')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_reset_postdata')->justReturn();
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('add_query_arg')->alias(function ($arg1, ...$rest) {
            if (is_array($arg1)) {
                return ($rest[0] ?? '') . '?' . http_build_query($arg1);
            }
            return implode('', $rest) . '?' . $arg1;
        });

        $captured_base = '';
        Functions\when('paginate_links')->alias(function ($args) use (&$captured_base) {
            $captured_base = $args['base'] ?? '';
            return '<ul class="page-numbers"></ul>';
        });

        \WP_Query::$test_config = [
            'posts' => [new \WP_Post((object) [
                'ID' => 101,
                'post_type' => 'hmw_event',
                'post_status' => 'publish',
                'post_title' => 'Test Event',
                'post_excerpt' => 'Test excerpt',
                'post_content' => 'Test content',
            ])],
            'found_posts' => 30,
            'max_num_pages' => 3,
        ];

        $term = new \WP_Term((object) [
            'term_id' => 7,
            'name' => 'Webinars',
            'slug' => 'webinars',
            'taxonomy' => 'hmw_event_type',
        ]);

        $service = new EventListingService();
        $html = $service->render_term_listings($term);

        \WP_Query::$test_config = [];

        $this->assertStringContainsString('hmw-event-listings-wrapper', $html);
        $this->assertStringContainsString('event-type/webinars', $captured_base);
        $this->assertStringNotContainsString('wrong-permalink', $captured_base);
    }

    public function test_term_listings_atts_can_be_overridden(): void
    {
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, array_intersect_key((array) $atts, $defaults));
        });
        Functions\when('hmwevents_get_template_part')->alias(function ($slug, $name = null, $args = []) {
            $template = HMWEvents_ABSPATH . 'src/views/' . $slug . '.php';
            if (file_exists($template)) {
                load_template($template, false, $args);
            }
        });
        Functions\when('load_template')->alias(function ($path, $require_once = false, $args = []) {
            include $path;
        });
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_term_link')->justReturn('https://example.com/event-type/webinars');
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html_e')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_reset_postdata')->justReturn();

        \WP_Query::$test_config = ['posts' => []];

        $term = new \WP_Term((object) [
            'term_id' => 7,
            'name' => 'Webinars',
            'slug' => 'webinars',
            'taxonomy' => 'hmw_event_type',
        ]);

        $service = new EventListingService();
        $html = $service->render_term_listings($term, ['limit' => 6]);

        \WP_Query::$test_config = [];

        $this->assertStringContainsString('hmw-event-listings-wrapper', $html);
    }
}
