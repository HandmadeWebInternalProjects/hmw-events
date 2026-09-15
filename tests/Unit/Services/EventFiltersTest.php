<?php

/**
 * EventListingService event-listing filter tests (price, day, month).
 *
 * Covers parse_filter_params, build_query, query, event_day_where_sql,
 * month_label and render_active_filters for the newly added filters.
 *
 * @package HMWEvents\Tests
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\EventListingService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventFiltersTest extends TestCase
{
    private EventListingService $service;

    /** @var array<int, array{0: string, 1: callable}> */
    private array $added_filters = [];

    /** @var array<int, array{0: string, 1: callable}> */
    private array $removed_filters = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
        \WP_Query::$test_config = [];
        $_GET = [];
        $this->added_filters = [];
        $this->removed_filters = [];

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_terms')->justReturn([]);
        Functions\when('get_term_link')->alias(function ($term, $taxonomy = '') {
            $slug = is_object($term) ? ($term->slug ?? '') : (string) $term;
            return 'https://example.com/' . $taxonomy . '/' . $slug;
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

        Functions\when('add_filter')->alias(function ($hook, $callback, $priority = 10, $accepted_args = 1) {
            $this->added_filters[] = [$hook, $callback];
            return true;
        });
        Functions\when('remove_filter')->alias(function ($hook, $callback, $priority = 10) {
            $this->removed_filters[] = [$hook, $callback];
            return true;
        });

        $this->service = new EventListingService();
    }

    protected function tearDown(): void
    {
        \WP_Query::$test_config = [];
        $_GET = [];
        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
        $this->added_filters = [];
        $this->removed_filters = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Helpers
    // ============================================================

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

    private function remove_href(string $output): string
    {
        preg_match('/href="([^"]*)" class="hmw-active-filter__remove"/', $output, $m);
        return $m[1] ?? '';
    }

    private function meta_query_count(array $args): int
    {
        $count = 0;
        foreach ($args['meta_query'] as $k => $v) {
            if ($k !== 'relation') {
                $count++;
            }
        }
        return $count;
    }

    private function month_clause(array $args, string $value): ?array
    {
        foreach ($args['meta_query'] as $k => $clause) {
            if ($k === 'relation') {
                continue;
            }
            if (isset($clause['key'], $clause['value']) && $clause['value'] === $value) {
                return $clause;
            }
        }
        return null;
    }

    private function meta_query_groups(array $args): array
    {
        $groups = [];
        foreach ($args['meta_query'] as $k => $clause) {
            if ($k !== 'relation' && isset($clause['relation'])) {
                $groups[] = $clause;
            }
        }
        return $groups;
    }

    // ============================================================
    // parse_filter_params — ev_price
    // ============================================================

    public function test_parse_ev_price_free_checkbox_sets_free_only(): void
    {
        $_GET['ev_price'] = ['free'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertTrue($filters['free_only']);
        $this->assertArrayNotHasKey('paid_only', $filters);
    }

    public function test_parse_ev_price_paid_checkbox_sets_paid_only(): void
    {
        $_GET['ev_price'] = ['paid'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertTrue($filters['paid_only']);
        $this->assertArrayNotHasKey('free_only', $filters);
    }

    public function test_parse_ev_price_both_checked_sets_neither(): void
    {
        $_GET['ev_price'] = ['free', 'paid'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertArrayNotHasKey('free_only', $filters);
        $this->assertArrayNotHasKey('paid_only', $filters);
    }

    public function test_parse_ev_price_unknown_value_sets_neither(): void
    {
        $_GET['ev_price'] = ['sometimes'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertArrayNotHasKey('free_only', $filters);
        $this->assertArrayNotHasKey('paid_only', $filters);
    }

    public function test_parse_ev_price_legacy_string_values(): void
    {
        $_GET['ev_price'] = 'free';
        $filters = $this->parse_filter_params($this->filter_bar_atts());
        $this->assertTrue($filters['free_only']);
        $this->assertArrayNotHasKey('paid_only', $filters);

        $_GET['ev_price'] = 'paid';
        $filters = $this->parse_filter_params($this->filter_bar_atts());
        $this->assertTrue($filters['paid_only']);
        $this->assertArrayNotHasKey('free_only', $filters);
    }

    public function test_parse_atts_free_attr_wins_over_url_price(): void
    {
        $_GET['ev_price'] = ['paid'];

        $filters = $this->invoke_private('parse_filter_params', $this->filter_bar_atts(['free' => '1']));

        $this->assertTrue($filters['free_only']);
        $this->assertArrayNotHasKey('paid_only', $filters);
    }

    // ============================================================
    // parse_filter_params — ev_day
    // ============================================================

    public function test_parse_ev_day_weekday_keeps_single_value(): void
    {
        $_GET['ev_day'] = ['weekday'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertSame(['weekday'], $filters['event_day']);
    }

    public function test_parse_ev_day_weekend_keeps_single_value(): void
    {
        $_GET['ev_day'] = ['weekend'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertSame(['weekend'], $filters['event_day']);
    }

    public function test_parse_ev_day_both_values_drops_key(): void
    {
        $_GET['ev_day'] = ['weekday', 'weekend'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertArrayNotHasKey('event_day', $filters);
    }

    public function test_parse_ev_day_invalid_values_only_drops_key(): void
    {
        $_GET['ev_day'] = ['someday', 'tomorrow'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertArrayNotHasKey('event_day', $filters);
    }

    public function test_parse_ev_day_invalid_values_are_filtered_out(): void
    {
        $_GET['ev_day'] = ['bogus', 'weekend'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertSame(['weekend'], $filters['event_day']);
    }

    // ============================================================
    // parse_filter_params — ev_month
    // ============================================================

    public function test_parse_ev_month_subset_is_normalized(): void
    {
        $_GET['ev_month'] = ['12', '2', '2', '15', 'abc'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertSame([2, 12], $filters['months']);
    }

    public function test_parse_ev_month_all_twelve_drops_key(): void
    {
        $_GET['ev_month'] = range(1, 12);

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertArrayNotHasKey('months', $filters);
    }

    public function test_parse_ev_month_invalid_values_only_drops_key(): void
    {
        $_GET['ev_month'] = ['0', '13', 'abc'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertArrayNotHasKey('months', $filters);
    }

    public function test_parse_ev_month_eleven_values_are_kept(): void
    {
        $_GET['ev_month'] = range(2, 12);

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertSame(range(2, 12), $filters['months']);
    }

    // ============================================================
    // build_query — months
    // ============================================================

    public function test_build_query_single_month_appends_like_clause(): void
    {
        $args = $this->service->build_query(['months' => [7]]);

        $this->assertSame(
            ['key' => '_event_start_date', 'value' => '-07-', 'compare' => 'LIKE'],
            $this->month_clause($args, '-07-')
        );
        $this->assertSame(2, $this->meta_query_count($args));
    }

    public function test_build_query_multiple_months_grouped_with_or(): void
    {
        $args = $this->service->build_query(['months' => [3, 1]]);

        $groups = $this->meta_query_groups($args);

        $this->assertCount(1, $groups);
        $this->assertSame('OR', $groups[0]['relation']);
        $this->assertSame(['key' => '_event_start_date', 'value' => '-03-', 'compare' => 'LIKE'], $groups[0][0]);
        $this->assertSame(['key' => '_event_start_date', 'value' => '-01-', 'compare' => 'LIKE'], $groups[0][1]);
        $this->assertSame(2, $this->meta_query_count($args));
    }

    public function test_build_query_invalid_months_are_skipped(): void
    {
        $args = $this->service->build_query(['months' => [0, 5, 13]]);

        $this->assertNotNull($this->month_clause($args, '-05-'));
        $this->assertNull($this->month_clause($args, '-00-'));
        $this->assertNull($this->month_clause($args, '-13-'));
        $this->assertSame(2, $this->meta_query_count($args));
    }

    public function test_build_query_only_invalid_months_append_nothing(): void
    {
        $args = $this->service->build_query(['months' => [0, 13, 99]]);

        $this->assertSame(1, $this->meta_query_count($args));
        $this->assertArrayNotHasKey('relation', $args['meta_query']);
    }

    // ============================================================
    // build_query — event_day
    // ============================================================

    public function test_build_query_event_day_weekend_maps_to_dayofweek_1_and_7(): void
    {
        $args = $this->service->build_query(['event_day' => ['weekend']]);

        $this->assertSame([1, 7], $args['hmwevents_event_day']);
    }

    public function test_build_query_event_day_weekday_maps_to_dayofweek_2_to_6(): void
    {
        $args = $this->service->build_query(['event_day' => ['weekday']]);

        $this->assertSame([2, 3, 4, 5, 6], $args['hmwevents_event_day']);
    }

    public function test_build_query_event_day_both_values_drops_key(): void
    {
        $args = $this->service->build_query(['event_day' => ['weekday', 'weekend']]);

        $this->assertArrayNotHasKey('hmwevents_event_day', $args);
    }

    public function test_build_query_meta_relation_and_with_month_filter(): void
    {
        $args = $this->service->build_query([
            'date_from' => '2026-07-01',
            'months'    => [7],
        ]);

        $this->assertSame('AND', $args['meta_query']['relation'] ?? '');
        $this->assertSame(3, $this->meta_query_count($args));
        $this->assertNotNull($this->month_clause($args, '-07-'));
    }

    // ============================================================
    // query — posts_where filter wiring
    // ============================================================

    public function test_query_registers_and_removes_posts_where_filter_for_event_day(): void
    {
        $GLOBALS['wpdb'] = (object) ['postmeta' => 'wp_postmeta', 'posts' => 'wp_posts'];

        $query = $this->service->query(['event_day' => ['weekend']]);

        $this->assertCount(1, $this->added_filters);
        [$hook, $callback] = $this->added_filters[0];
        $this->assertSame('posts_where', $hook);
        $this->assertInstanceOf(\Closure::class, $callback);

        $this->assertCount(1, $this->removed_filters);
        $this->assertSame([$hook, $callback], $this->removed_filters[0]);

        $where = $callback(' AND (1=1) ');
        $this->assertStringStartsWith(' AND (1=1) ', $where);
        $this->assertStringContainsString("pm.meta_key = '_event_start_date'", $where);
        $this->assertStringContainsString('IN (1,7)', $where);

        $this->assertArrayNotHasKey('hmwevents_event_day', $query->query_vars);
        $this->assertSame('hmw_event', $query->query_vars['post_type']);
    }

    public function test_query_without_event_day_skips_where_filter(): void
    {
        $query = $this->service->query([]);

        $this->assertSame([], $this->added_filters);
        $this->assertSame([], $this->removed_filters);
        $this->assertArrayNotHasKey('hmwevents_event_day', $query->query_vars);
    }

    // ============================================================
    // event_day_where_sql
    // ============================================================

    public function test_event_day_where_sql_contains_expected_fragments(): void
    {
        $GLOBALS['wpdb'] = (object) ['postmeta' => 'wp_postmeta', 'posts' => 'wp_posts'];

        $sql = $this->invoke_private('event_day_where_sql', [1, 7]);

        $this->assertStringContainsString('DAYOFWEEK(', $sql);
        $this->assertStringContainsString("pm.meta_key = '_event_start_date'", $sql);
        $this->assertStringContainsString('pm.post_id = wp_posts.ID', $sql);
        $this->assertStringContainsString('wp_postmeta', $sql);
        $this->assertStringContainsString('wp_posts', $sql);
        $this->assertStringContainsString('IN (1,7)', $sql);
        $this->assertStringStartsWith(' AND (', $sql);
    }

    public function test_event_day_where_sql_renders_exact_in_list_for_weekdays(): void
    {
        $GLOBALS['wpdb'] = (object) ['postmeta' => 'wp_postmeta', 'posts' => 'wp_posts'];

        $sql = $this->invoke_private('event_day_where_sql', [2, 3, 4, 5, 6]);

        $this->assertStringContainsString('IN (2,3,4,5,6)', $sql);
        $this->assertStringNotContainsString('(1,', $sql);
        $this->assertStringNotContainsString(',1)', $sql);
    }

    // ============================================================
    // month_label
    // ============================================================

    public function test_month_label_returns_names_for_boundaries(): void
    {
        $this->assertSame('January', $this->invoke_private('month_label', 1));
        $this->assertSame('December', $this->invoke_private('month_label', 12));
    }

    public function test_month_label_returns_empty_out_of_range(): void
    {
        $this->assertSame('', $this->invoke_private('month_label', 0));
        $this->assertSame('', $this->invoke_private('month_label', 13));
    }

    // ============================================================
    // render_active_filters
    // ============================================================

    public function test_active_filters_month_chip_and_remove_url(): void
    {
        $_GET['ev_month'] = ['1'];

        $output = $this->render_active_filters(['months' => [1]]);

        $this->assertStringContainsString('Month: January', $output);
        $this->assertStringContainsString('hmw-active-filter__remove', $output);
        $this->assertStringContainsString('Remove Month filter', $output);
        $this->assertSame('https://example.com/events', $this->remove_href($output));
        $this->assertStringNotContainsString('ev_month', $output);
    }

    public function test_active_filters_event_day_chip(): void
    {
        $output = $this->render_active_filters(['event_day' => ['weekday']]);

        $this->assertStringContainsString('Event Day: Weekdays', $output);
        $this->assertStringContainsString('hmw-active-filter__remove', $output);
    }

    public function test_active_filters_free_only_uses_cost_group(): void
    {
        $output = $this->render_active_filters(['free_only' => true]);

        $this->assertStringContainsString('Cost: Free', $output);
        $this->assertStringContainsString('Remove Cost filter', $output);
        $this->assertStringNotContainsString('Price', $output);
    }

    public function test_active_filters_paid_only_uses_cost_group(): void
    {
        $output = $this->render_active_filters(['paid_only' => true]);

        $this->assertStringContainsString('Cost: Paid', $output);
        $this->assertStringContainsString('Remove Cost filter', $output);
        $this->assertStringNotContainsString('Price', $output);
    }

    public function test_active_filters_renders_nothing_without_filters(): void
    {
        $this->assertSame('', $this->render_active_filters([]));
    }
}
