<?php

/**
 * EventCategoryGrid shortcode tests — term resolution, upcoming date
 * collection, and card rendering.
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use HMWEvents\Shortcodes\EventCategoryGrid;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventCategoryGridTest extends TestCase
{
    private EventCategoryGrid $shortcode;

    private array $parents = [];
    private array $sessions = [];
    private array $start_dates = [];
    private array $permalinks = [];
    private array $thumbnails = [];
    private array $terms_by_id = [];
    private array $terms_by_slug = [];
    private array $terms_result = [];
    private array $captured_terms_args = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('sanitize_title')->alias(function ($value) {
            return strtolower(trim(preg_replace('/[^A-Za-z0-9\-_]/', '', str_replace(' ', '-', (string) $value)), '-'));
        });
        Functions\when('add_shortcode')->justReturn(null);
        Functions\when('add_action')->justReturn(null);
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts, $shortcode = '') {
            return array_merge($defaults, array_intersect_key((array) $atts, $defaults));
        });
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('date_i18n')->alias(function ($format, $time) {
            return date($format, $time);
        });
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_post')->alias(function ($id) {
            return new \WP_Post((object) [
                'ID' => (int) $id,
                'post_type' => 'hmw_event',
                'post_status' => 'publish',
            ]);
        });
        Functions\when('get_posts')->alias(function ($args = []) {
            $parent = (int) ($args['post_parent'] ?? 0);
            if ($parent === 0 && !empty($args['tax_query'])) {
                return $this->parents;
            }
            return $this->sessions[$parent] ?? [];
        });
        Functions\when('get_post_meta')->alias(function ($id, $key, $single = false) {
            if ($key === '_event_start_date') {
                return $this->start_dates[(int) $id] ?? '';
            }
            return '';
        });
        Functions\when('get_permalink')->alias(function ($id = 0) {
            return $this->permalinks[(int) $id] ?? ('https://example.com/event/' . (int) $id);
        });
        Functions\when('get_the_post_thumbnail_url')->alias(function ($id = 0, $size = '') {
            return $this->thumbnails[(int) $id] ?? false;
        });
        Functions\when('get_term')->alias(function ($id, $taxonomy = '') {
            return $this->terms_by_id[(int) $id] ?? null;
        });
        Functions\when('get_term_by')->alias(function ($field, $value, $taxonomy = '') {
            return $this->terms_by_slug[(string) $value] ?? false;
        });
        Functions\when('get_terms')->alias(function ($args = []) {
            $this->captured_terms_args = $args;
            return $this->terms_result;
        });
        Functions\when('get_term_link')->alias(function ($term) {
            return 'https://example.com/event-type/' . $term->slug;
        });
        Functions\when('term_description')->alias(function ($term = 0) {
            return 'Category description';
        });
        Functions\when('get_term_meta')->justReturn('');
        Functions\when('get_field')->justReturn('');
        Functions\when('wp_get_attachment_image')->alias(function ($id, $size = '') {
            return '<img src="attachment-' . (int) $id . '.jpg" />';
        });
        Functions\when('hmwevents_get_template_part')->alias(function ($slug, $name = null, $args = []) {
            echo '<div class="hmw-event-category-card"></div>';
        });

        $this->shortcode = new EventCategoryGrid();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    private function make_term(int $id, string $name, string $slug): \WP_Term
    {
        return new \WP_Term((object) [
            'term_id' => $id,
            'name' => $name,
            'slug' => $slug,
        ]);
    }

    private function make_post(int $id): \WP_Post
    {
        return new \WP_Post((object) [
            'ID' => $id,
            'post_type' => 'hmw_event',
            'post_status' => 'publish',
        ]);
    }

    // ============================================================
    // Term resolution
    // ============================================================

    public function test_resolve_terms_from_categories_attr_preserves_order_and_dedupes(): void
    {
        $playgroups = $this->make_term(12, 'Playgroups', 'playgroups');
        $webinars = $this->make_term(34, 'Webinars', 'webinars');

        $this->terms_by_id = [12 => $playgroups, 34 => $webinars];
        $this->terms_by_slug = ['webinars' => $webinars];

        $terms = $this->shortcode->resolve_terms(['categories' => '12, webinars, 12']);

        $this->assertSame([12, 34], array_map(fn($t) => $t->term_id, $terms));
    }

    public function test_resolve_terms_skips_unknown_entries(): void
    {
        $this->terms_by_id = [12 => $this->make_term(12, 'Playgroups', 'playgroups')];

        $terms = $this->shortcode->resolve_terms(['categories' => '12, 99, not-a-slug']);

        $this->assertCount(1, $terms);
        $this->assertSame(12, $terms[0]->term_id);
    }

    public function test_resolve_terms_from_parent_attr_queries_child_terms(): void
    {
        $parent = $this->make_term(35, 'Programs', 'programs');
        $this->terms_by_slug = ['programs' => $parent];
        $this->terms_result = [$this->make_term(36, 'Playgroups', 'playgroups')];

        $terms = $this->shortcode->resolve_terms(['parent' => 'programs']);

        $this->assertSame(35, $this->captured_terms_args['parent']);
        $this->assertSame(36, $terms[0]->term_id);
    }

    public function test_resolve_terms_returns_empty_for_unknown_parent(): void
    {
        $terms = $this->shortcode->resolve_terms(['parent' => 'missing']);

        $this->assertSame([], $terms);
    }

    // ============================================================
    // Upcoming dates
    // ============================================================

    public function test_standalone_event_contributes_own_start_date(): void
    {
        $this->parents = [$this->make_post(101)];
        $this->start_dates = [101 => '2026-03-05 10:00:00'];
        $this->permalinks = [101 => 'https://example.com/event/one'];

        $result = $this->shortcode->get_upcoming_dates(7, 3);

        $this->assertCount(1, $result['dates']);
        $this->assertSame('2026-03-05 10:00:00', $result['dates'][0]['date']);
        $this->assertSame('March 5th 2026', $result['dates'][0]['formatted']);
        $this->assertSame('https://example.com/event/one', $result['dates'][0]['url']);
    }

    public function test_recurring_parent_uses_session_dates_without_duplicating_own_date(): void
    {
        $this->parents = [$this->make_post(201), $this->make_post(202)];
        $this->sessions = [
            201 => [$this->make_post(211), $this->make_post(212), $this->make_post(213)],
        ];
        $this->start_dates = [
            201 => '2026-01-05 10:00:00',
            202 => '2026-01-15 10:00:00',
            211 => '2026-02-10 10:00:00',
            212 => '2026-01-20 10:00:00',
            213 => '2025-12-01 10:00:00',
        ];

        $result = $this->shortcode->get_upcoming_dates(7, 3);

        $dates = array_column($result['dates'], 'date');
        $this->assertSame(
            ['2026-01-15 10:00:00', '2026-01-20 10:00:00', '2026-02-10 10:00:00'],
            $dates
        );
    }

    public function test_session_dates_link_to_session_permalinks(): void
    {
        $this->parents = [$this->make_post(201)];
        $this->sessions = [201 => [$this->make_post(211)]];
        $this->start_dates = [211 => '2026-02-10 10:00:00'];
        $this->permalinks = [211 => 'https://example.com/event/session-211'];

        $result = $this->shortcode->get_upcoming_dates(7, 3);

        $this->assertSame('https://example.com/event/session-211', $result['dates'][0]['url']);
    }

    public function test_upcoming_dates_are_sliced_to_limit(): void
    {
        $this->parents = [$this->make_post(201)];
        $this->sessions = [201 => array_map(fn($i) => $this->make_post(300 + $i), range(1, 5))];
        foreach (range(1, 5) as $i) {
            $this->start_dates[300 + $i] = sprintf('2026-03-%02d 10:00:00', $i);
        }

        $result = $this->shortcode->get_upcoming_dates(7, 2);

        $this->assertCount(2, $result['dates']);
        $this->assertSame('2026-03-01 10:00:00', $result['dates'][0]['date']);
        $this->assertSame('2026-03-02 10:00:00', $result['dates'][1]['date']);
    }

    public function test_no_upcoming_dates_returns_empty_dates(): void
    {
        $this->parents = [];

        $result = $this->shortcode->get_upcoming_dates(7, 3);

        $this->assertSame([], $result['dates']);
    }

    // ============================================================
    // Card building
    // ============================================================

    public function test_card_is_null_when_empty_and_hide_empty_enabled(): void
    {
        $this->parents = [];
        $term = $this->make_term(7, 'Playgroups', 'playgroups');

        $card = $this->shortcode->get_category_card($term, ['hide_empty' => true]);

        $this->assertNull($card);
    }

    public function test_card_renders_when_empty_and_hide_empty_disabled(): void
    {
        $this->parents = [];
        $term = $this->make_term(7, 'Playgroups', 'playgroups');

        $card = $this->shortcode->get_category_card($term, ['hide_empty' => false]);

        $this->assertNotNull($card);
        $this->assertSame('Playgroups', $card['name']);
        $this->assertSame('https://example.com/event-type/playgroups', $card['url']);
        $this->assertSame([], $card['dates']);
    }

    public function test_card_includes_upcoming_dates(): void
    {
        $this->parents = [$this->make_post(101)];
        $this->start_dates = [101 => '2026-03-05 10:00:00'];
        $term = $this->make_term(7, 'Playgroups', 'playgroups');

        $card = $this->shortcode->get_category_card($term, ['limit' => 3]);

        $this->assertCount(1, $card['dates']);
        $this->assertSame('March 5th 2026', $card['dates'][0]['formatted']);
    }

    public function test_card_falls_back_to_event_thumbnail_for_image(): void
    {
        $this->parents = [$this->make_post(101)];
        $this->start_dates = [101 => '2026-03-05 10:00:00'];
        $this->thumbnails = [101 => 'https://example.com/images/event.jpg'];
        $term = $this->make_term(7, 'Playgroups', 'playgroups');

        $card = $this->shortcode->get_category_card($term, []);

        $this->assertStringContainsString('https://example.com/images/event.jpg', $card['image_html']);
    }

    // ============================================================
    // Rendering
    // ============================================================

    public function test_render_outputs_grid_and_cards(): void
    {
        $this->terms_by_id = [7 => $this->make_term(7, 'Programs', 'programs')];
        $this->terms_result = [$this->make_term(36, 'Playgroups', 'playgroups')];
        $this->parents = [$this->make_post(101)];
        $this->start_dates = [101 => '2026-03-05 10:00:00'];

        $html = $this->shortcode->render(['parent' => 7]);

        $this->assertStringContainsString('hmw-event-category-grid', $html);
        $this->assertStringContainsString('hmw-event-category-grid--cols-3', $html);
        $this->assertStringContainsString('hmw-event-category-card', $html);
    }

    public function test_render_supports_legacy_columns_class_string(): void
    {
        $this->terms_by_id = [7 => $this->make_term(7, 'Programs', 'programs')];
        $this->terms_result = [$this->make_term(36, 'Playgroups', 'playgroups')];
        $this->parents = [$this->make_post(101)];
        $this->start_dates = [101 => '2026-03-05 10:00:00'];

        $html = $this->shortcode->render(['parent' => 7, 'columns' => 'grid-cols-1 grid-cols-md-3']);

        $this->assertStringContainsString('hmw-event-category-grid--cols-3', $html);
    }

    public function test_render_returns_empty_string_when_no_terms(): void
    {
        $this->terms_by_id = [7 => $this->make_term(7, 'Programs', 'programs')];
        $this->terms_result = [];

        $html = $this->shortcode->render(['parent' => 7]);

        $this->assertSame('', $html);
    }

    public function test_render_returns_empty_string_when_all_categories_hidden(): void
    {
        $this->terms_by_id = [7 => $this->make_term(7, 'Programs', 'programs')];
        $this->terms_result = [$this->make_term(36, 'Playgroups', 'playgroups')];
        $this->parents = [];

        $html = $this->shortcode->render(['parent' => 7]);

        $this->assertSame('', $html);
    }

    // ============================================================
    // Registration
    // ============================================================

    public function test_register_adds_shortcodes(): void
    {
        $recorded_shortcodes = [];
        $recorded_actions = [];
        Functions\when('add_shortcode')->alias(function ($tag, $callback) use (&$recorded_shortcodes) {
            $recorded_shortcodes[] = $tag;
        });
        Functions\when('add_action')->alias(function ($hook, $callback, $priority = 10) use (&$recorded_actions) {
            $recorded_actions[$hook] = [$callback, $priority];
        });

        $this->shortcode->register();

        $this->assertSame(['hmw_event_categories'], $recorded_shortcodes);
        $this->assertSame('register_legacy_alias', $recorded_actions['init'][0][1]);
        $this->assertSame(20, $recorded_actions['init'][1]);
    }

    public function test_register_legacy_alias_adds_old_shortcode_name(): void
    {
        $recorded = [];
        Functions\when('add_shortcode')->alias(function ($tag, $callback) use (&$recorded) {
            $recorded[] = $tag;
        });

        $this->shortcode->register_legacy_alias();

        $this->assertSame(['event_categories_list'], $recorded);
    }
}
