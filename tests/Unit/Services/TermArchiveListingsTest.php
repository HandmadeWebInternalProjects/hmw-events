<?php

/**
 * Term archive listings tests for EventListingService.
 *
 * Covers render_term_listings taxonomy-to-attribute mapping and the
 * att-driven restriction flags in parse_filter_params / render_active_filters.
 *
 * @package HMWEvents\Tests\Unit\Services
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\EventListingService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class TermArchiveListingsTest extends TestCase
{
    private EventListingService $service;

    /** @var array<int, array> */
    private array $captured_get_terms_args = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
        \WP_Query::$test_config = [];
        \WP_Query::$instances = [];
        $_GET = [];
        $this->captured_get_terms_args = [];

        Functions\when('__')->returnArg();
        Functions\when('_x')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_html_e')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_terms')->alias(function ($args) {
            $this->captured_get_terms_args[] = $args;
            return [];
        });
        Functions\when('get_term_link')->alias(function ($term, $taxonomy = '') {
            $slug = is_object($term) ? ($term->slug ?? '') : (string) $term;
            return 'https://example.com/archives/' . $slug;
        });
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_permalink')->justReturn('https://example.com/events');
        Functions\when('home_url')->alias(function ($path = '') {
            return 'https://example.com' . $path;
        });
        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('wp_get_referer')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('add_query_arg')->alias(function ($arg1, ...$rest) {
            if (is_array($arg1)) {
                $url   = (string) ($rest[0] ?? '');
                $query = http_build_query($arg1);

                if ($query === '') {
                    return $url;
                }

                return $url . (str_contains($url, '?') ? '&' : '?') . $query;
            }

            $url   = (string) ($rest[1] ?? '');
            $query = $arg1 . '=' . rawurlencode((string) ($rest[0] ?? ''));

            return $url . (str_contains($url, '?') ? '&' : '?') . $query;
        });
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge($defaults, array_intersect_key((array) $atts, $defaults));
        });
        Functions\when('wp_reset_postdata')->justReturn();

        $this->service = new EventListingService();
    }

    protected function tearDown(): void
    {
        \WP_Query::$test_config = [];
        \WP_Query::$instances = [];
        $_GET = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function make_term(string $taxonomy, string $slug): \WP_Term
    {
        return new \WP_Term((object) [
            'term_id'  => 7,
            'name'     => ucwords(str_replace('-', ' ', $slug)),
            'slug'     => $slug,
            'taxonomy' => $taxonomy,
        ]);
    }

    private function render_term_listings(object $term, array $atts = []): string
    {
        \WP_Query::$test_config = ['posts' => []];

        return $this->service->render_term_listings($term, $atts);
    }

    private function last_query_vars(): array
    {
        $this->assertNotEmpty(
            \WP_Query::$instances,
            'Expected render_term_listings() to instantiate WP_Query'
        );

        $last = \WP_Query::$instances[array_key_last(\WP_Query::$instances)];

        return $last->query_vars;
    }

    private function tax_query_entries(array $query_vars): array
    {
        $entries = [];
        foreach ($query_vars['tax_query'] ?? [] as $key => $clause) {
            if ($key !== 'relation' && is_array($clause)) {
                $entries[] = $clause;
            }
        }
        return $entries;
    }

    private function invoke_private(string $method, mixed ...$args)
    {
        $ref = new \ReflectionMethod(EventListingService::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->service, ...$args);
    }

    private function parse_filter_params(array $atts): array
    {
        return $this->invoke_private('parse_filter_params', $atts);
    }

    private function filter_bar_atts(array $overrides = []): array
    {
        return array_merge([
            'type'         => '',
            'audience'     => '',
            'topic'        => '',
            'program'      => '',
            'mode'         => '',
            'location'     => '',
            'state'        => '',
            'free'         => '',
            'limit'        => 12,
            'show_filters' => 'yes',
            'sort'         => 'date',
            'sort_order'   => 'ASC',
            'event_type_filter_parent' => '',
        ], $overrides);
    }

    private function render_active_filters(array $filters): string
    {
        $ref = new \ReflectionMethod(EventListingService::class, 'render_active_filters');
        $ref->setAccessible(true);
        ob_start();
        $ref->invoke($this->service, $filters);
        return (string) ob_get_clean();
    }

    private function stub_get_terms_returning(object $term): void
    {
        Functions\when('get_terms')->alias(function ($args) use ($term) {
            $this->captured_get_terms_args[] = $args;
            return [$term];
        });
    }

    // ============================================================
    // render_term_listings — taxonomy mapping into the query
    // ============================================================

    public static function term_taxonomy_provider(): array
    {
        return [
            'delivery mode' => ['hmw_event_delivery_mode', 'in-person'],
            'topic'         => ['hmw_event_parenting_topic', 'sleep'],
            'audience'      => ['hmw_event_audience', 'parents'],
            'event type'    => ['hmw_event_type', 'webinars'],
        ];
    }

    public static function non_type_term_provider(): array
    {
        return [
            'delivery mode' => ['hmw_event_delivery_mode', 'in-person'],
            'topic'         => ['hmw_event_parenting_topic', 'sleep'],
            'audience'      => ['hmw_event_audience', 'parents'],
        ];
    }

    /**
     * @dataProvider term_taxonomy_provider
     */
    public function test_render_term_listings_filters_by_term_taxonomy(string $taxonomy, string $slug): void
    {
        $this->render_term_listings($this->make_term($taxonomy, $slug));

        $entries = $this->tax_query_entries($this->last_query_vars());

        $this->assertCount(1, $entries);
        $this->assertSame($taxonomy, $entries[0]['taxonomy']);
        $this->assertSame('slug', $entries[0]['field']);
        $this->assertSame([$slug], $entries[0]['terms']);
    }

    /**
     * @dataProvider non_type_term_provider
     */
    public function test_render_term_listings_does_not_filter_by_event_type_for_other_taxonomies(string $taxonomy, string $slug): void
    {
        $this->render_term_listings($this->make_term($taxonomy, $slug));

        foreach ($this->tax_query_entries($this->last_query_vars()) as $entry) {
            $this->assertNotSame('hmw_event_type', $entry['taxonomy']);
        }
    }

    public function test_render_term_listings_disables_filter_bar(): void
    {
        $html = $this->render_term_listings($this->make_term('hmw_event_delivery_mode', 'in-person'));

        $this->assertStringContainsString('hmw-event-listings-wrapper', $html);
        $this->assertStringNotContainsString('hmw-event-filters', $html);
    }

    // ============================================================
    // parse_filter_params — att restriction flags
    // ============================================================

    public function test_topic_att_sets_topic_filter_and_restriction_flag(): void
    {
        $filters = $this->parse_filter_params($this->filter_bar_atts(['topic' => 'sleep']));

        $this->assertSame(['sleep'], $filters['topic']);
        $this->assertTrue($filters['topic_is_restriction']);
    }

    public function test_mode_att_sets_delivery_mode_filter_and_restriction_flag(): void
    {
        $filters = $this->parse_filter_params($this->filter_bar_atts(['mode' => 'in-person']));

        $this->assertSame(['in-person'], $filters['delivery_mode']);
        $this->assertTrue($filters['delivery_mode_is_restriction']);
    }

    public function test_location_att_sets_location_filter_and_restriction_flag(): void
    {
        $filters = $this->parse_filter_params($this->filter_bar_atts(['location' => 'Penrith']));

        $this->assertSame(['Penrith'], $filters['location']);
        $this->assertTrue($filters['location_is_restriction']);
    }

    public function test_legacy_state_att_maps_to_location_restriction(): void
    {
        $filters = $this->parse_filter_params($this->filter_bar_atts(['state' => 'nsw']));

        $this->assertSame(['nsw'], $filters['location']);
        $this->assertTrue($filters['location_is_restriction']);
    }

    public function test_url_topic_param_ignored_when_topic_att_set(): void
    {
        $_GET['ev_topic'] = ['routines'];

        $filters = $this->parse_filter_params($this->filter_bar_atts(['topic' => 'sleep']));

        $this->assertSame(['sleep'], $filters['topic']);
        $this->assertTrue($filters['topic_is_restriction']);
    }

    public function test_url_mode_param_ignored_when_mode_att_set(): void
    {
        $_GET['ev_mode'] = ['online'];

        $filters = $this->parse_filter_params($this->filter_bar_atts(['mode' => 'in-person']));

        $this->assertSame(['in-person'], $filters['delivery_mode']);
        $this->assertTrue($filters['delivery_mode_is_restriction']);
    }

    public function test_url_location_param_ignored_when_location_att_set(): void
    {
        $_GET['ev_location'] = ['Castle Hill'];

        $filters = $this->parse_filter_params($this->filter_bar_atts(['location' => 'Penrith']));

        $this->assertSame(['Penrith'], $filters['location']);
        $this->assertTrue($filters['location_is_restriction']);
    }

    public function test_legacy_url_state_param_used_when_location_att_absent(): void
    {
        $_GET['ev_state'] = ['nsw'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertSame(['nsw'], $filters['location']);
        $this->assertArrayNotHasKey('location_is_restriction', $filters);
    }

    public function test_no_restriction_flags_without_atts(): void
    {
        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertArrayNotHasKey('topic_is_restriction', $filters);
        $this->assertArrayNotHasKey('delivery_mode_is_restriction', $filters);
        $this->assertArrayNotHasKey('location_is_restriction', $filters);
        $this->assertArrayNotHasKey('topic', $filters);
        $this->assertArrayNotHasKey('delivery_mode', $filters);
        $this->assertArrayNotHasKey('location', $filters);
    }

    public function test_url_params_set_filters_without_restriction_flags(): void
    {
        $_GET['ev_topic'] = ['routines'];
        $_GET['ev_mode']  = ['online'];
        $_GET['ev_location'] = ['Penrith'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertSame(['routines'], $filters['topic']);
        $this->assertSame(['online'], $filters['delivery_mode']);
        $this->assertSame(['Penrith'], $filters['location']);
        $this->assertArrayNotHasKey('topic_is_restriction', $filters);
        $this->assertArrayNotHasKey('delivery_mode_is_restriction', $filters);
        $this->assertArrayNotHasKey('location_is_restriction', $filters);
    }

    // ============================================================
    // render_active_filters — chip gating for restriction flags
    // ============================================================

    public function test_active_filters_render_mode_chip_without_restriction(): void
    {
        $this->stub_get_terms_returning((object) ['name' => 'In Person', 'slug' => 'in-person']);

        $output = $this->render_active_filters(['delivery_mode' => ['in-person']]);

        $this->assertStringContainsString('Mode: In Person', $output);
        $this->assertStringContainsString('hmw-active-filter__remove', $output);
        $this->assertSame(
            'hmw_event_delivery_mode',
            $this->captured_get_terms_args[0]['taxonomy'] ?? null
        );
    }

    public function test_active_filters_render_topic_chip_without_restriction(): void
    {
        $this->stub_get_terms_returning((object) ['name' => 'Sleep', 'slug' => 'sleep']);

        $output = $this->render_active_filters(['topic' => ['sleep']]);

        $this->assertStringContainsString('Parenting Topics: Sleep', $output);
        $this->assertStringContainsString('hmw-active-filter__remove', $output);
        $this->assertSame(
            'hmw_event_parenting_topic',
            $this->captured_get_terms_args[0]['taxonomy'] ?? null
        );
    }

    public function test_active_filters_render_location_chip_without_restriction(): void
    {
        $output = $this->render_active_filters(['location' => ['Penrith']]);

        $this->assertStringContainsString('Location: Penrith', $output);
        $this->assertStringContainsString('hmw-active-filter__remove', $output);
        $this->assertSame([], $this->captured_get_terms_args);
    }

    public function test_active_filters_skip_mode_chip_when_restriction(): void
    {
        $output = $this->render_active_filters([
            'delivery_mode'                => ['in-person'],
            'delivery_mode_is_restriction' => true,
        ]);

        $this->assertSame('', $output);
        $this->assertSame([], $this->captured_get_terms_args);
    }

    public function test_active_filters_skip_topic_chip_when_restriction(): void
    {
        $output = $this->render_active_filters([
            'topic'                => ['sleep'],
            'topic_is_restriction' => true,
        ]);

        $this->assertSame('', $output);
        $this->assertSame([], $this->captured_get_terms_args);
    }

    public function test_active_filters_skip_location_chip_when_restriction(): void
    {
        $output = $this->render_active_filters([
            'location'                => ['Penrith'],
            'location_is_restriction' => true,
        ]);

        $this->assertSame('', $output);
        $this->assertSame([], $this->captured_get_terms_args);
    }
}
