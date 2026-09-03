<?php
/**
 * EventListingService tests — build_query, sort, card data, session dates.
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use HMWEvents\Services\EventListingService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventListingServiceTest extends TestCase
{
    private EventListingService $service;

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
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_permalink')->justReturn('https://example.com/event/test');
        Functions\when('date_i18n')->alias(function ($format, $time) { return date($format, $time); });
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_the_post_thumbnail_url')->justReturn('');
        Functions\when('wp_trim_words')->returnArg(1);
        Functions\when('get_terms')->justReturn([]);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_pagenum_link')->justReturn('https://example.com/page/1');
        Functions\when('paginate_links')->justReturn('');
        Functions\when('add_query_arg')->alias(function ($arg1, ...$rest) {
            if (is_array($arg1)) {
                return $rest[0] ?? '';
            }
            return implode('', $rest) . '?' . $arg1;
        });
        Functions\when('checked')->alias(function ($checked, $current = true) {
            return $checked == $current ? 'checked="checked"' : '';
        });
        Functions\when('selected')->alias(function ($selected, $current = true) {
            return $selected == $current ? 'selected="selected"' : '';
        });

        $this->service = new EventListingService();

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
    }

    protected function tearDown(): void
    {
        unset($_GET['ev_type']);
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // build_query — default behavior
    // ============================================================

    public function test_build_query_defaults(): void
    {
        $args = $this->service->build_query([]);

        $this->assertEquals('hmw_event', $args['post_type']);
        $this->assertEquals(['publish', 'fully_booked'], $args['post_status']);
        $this->assertEquals(12, $args['posts_per_page']);
        $this->assertEquals(0, $args['post_parent']);
        $this->assertEquals('_event_start_date', $args['meta_key']);
        $this->assertEquals('meta_value', $args['orderby']);
        $this->assertEquals('ASC', $args['order']);
    }

    public function test_build_query_custom_posts_per_page(): void
    {
        $args = $this->service->build_query(['posts_per_page' => 24]);
        $this->assertEquals(24, $args['posts_per_page']);
    }

    public function test_build_query_page_param(): void
    {
        $args = $this->service->build_query(['page' => 3]);
        $this->assertEquals(3, $args['paged']);

        $args = $this->service->build_query(['page' => 0]);
        $this->assertEquals(1, $args['paged']);
    }

    // ============================================================
    // build_query — sort
    // ============================================================

    public function test_build_query_sort_date_asc(): void
    {
        $args = $this->service->build_query(['sort' => 'date_asc']);
        $this->assertEquals('meta_value', $args['orderby']);
        $this->assertEquals('_event_start_date', $args['meta_key']);
        $this->assertEquals('ASC', $args['order']);
    }

    public function test_build_query_sort_date_desc(): void
    {
        $args = $this->service->build_query(['sort' => 'date_desc']);
        $this->assertEquals('meta_value', $args['orderby']);
        $this->assertEquals('_event_start_date', $args['meta_key']);
        $this->assertEquals('DESC', $args['order']);
    }

    public function test_build_query_sort_price_asc(): void
    {
        $args = $this->service->build_query(['sort' => 'price_asc']);
        $this->assertEquals('meta_value_num', $args['orderby']);
        $this->assertEquals('_event_price', $args['meta_key']);
        $this->assertEquals('ASC', $args['order']);
    }

    public function test_build_query_sort_price_desc(): void
    {
        $args = $this->service->build_query(['sort' => 'price_desc']);
        $this->assertEquals('meta_value_num', $args['orderby']);
        $this->assertEquals('_event_price', $args['meta_key']);
        $this->assertEquals('DESC', $args['order']);
    }

    public function test_build_query_sort_title_asc(): void
    {
        $args = $this->service->build_query(['sort' => 'title_asc']);
        $this->assertEquals('title', $args['orderby']);
        $this->assertArrayNotHasKey('meta_key', $args);
        $this->assertEquals('ASC', $args['order']);
    }

    public function test_build_query_sort_title_desc(): void
    {
        $args = $this->service->build_query(['sort' => 'title_desc']);
        $this->assertEquals('title', $args['orderby']);
        $this->assertArrayNotHasKey('meta_key', $args);
        $this->assertEquals('DESC', $args['order']);
    }

    public function test_build_query_sort_defaults_to_date_asc(): void
    {
        $args = $this->service->build_query([]);
        $this->assertEquals('meta_value', $args['orderby']);
        $this->assertEquals('_event_start_date', $args['meta_key']);
        $this->assertEquals('ASC', $args['order']);
    }

    // ============================================================
    // build_query — filters
    // ============================================================

    private function meta_query_count(array $args): int
    {
        $mq = $args['meta_query'] ?? [];
        $count = 0;
        foreach ($mq as $k => $v) {
            if ($k !== 'relation') {
                $count++;
            }
        }
        return $count;
    }

    private function tax_query_count(array $args): int
    {
        $tq = $args['tax_query'] ?? [];
        $count = 0;
        foreach ($tq as $k => $v) {
            if ($k !== 'relation') {
                $count++;
            }
        }
        return $count;
    }

    public function test_build_query_free_only(): void
    {
        $args = $this->service->build_query(['free_only' => true]);
        $this->assertNotEmpty($args['meta_query']);
        $this->assertEquals(2, $this->meta_query_count($args));
    }

    public function test_build_query_paid_only(): void
    {
        $args = $this->service->build_query(['paid_only' => true]);
        $this->assertNotEmpty($args['meta_query']);

        $entry = null;
        foreach ($args['meta_query'] as $k => $v) {
            if ($k !== 'relation' && isset($v['key']) && $v['key'] === '_event_price') {
                $entry = $v;
                break;
            }
        }
        $this->assertNotNull($entry);
        $this->assertEquals('_event_price', $entry['key']);
        $this->assertEquals('>', $entry['compare']);
        $this->assertEquals('NUMERIC', $entry['type']);
    }

    public function test_build_query_date_range(): void
    {
        $args = $this->service->build_query([
            'date_from' => '2026-07-01',
            'date_to'   => '2026-07-31',
        ]);

        $this->assertEquals(3, $this->meta_query_count($args));
    }

    public function test_build_query_event_type_tax(): void
    {
        $args = $this->service->build_query(['event_type' => ['parent-one-off-free', 'parenting-webinar']]);
        $this->assertEquals(1, $this->tax_query_count($args));
        $this->assertEquals('hmw_event_type', $args['tax_query'][0]['taxonomy']);
        $this->assertEquals(['parent-one-off-free', 'parenting-webinar'], $args['tax_query'][0]['terms']);
    }

    public function test_build_query_delivery_mode_tax(): void
    {
        $args = $this->service->build_query(['delivery_mode' => ['online']]);
        $this->assertEquals(1, $this->tax_query_count($args));
        $this->assertEquals('hmw_event_delivery_mode', $args['tax_query'][0]['taxonomy']);
    }

    public function test_build_query_state_tax(): void
    {
        $args = $this->service->build_query(['state' => ['nsw']]);
        $this->assertEquals(1, $this->tax_query_count($args));
        $this->assertEquals('hmw_event_state', $args['tax_query'][0]['taxonomy']);
    }

    public function test_build_query_audience_tax(): void
    {
        $args = $this->service->build_query(['audience' => ['parents']]);
        $this->assertEquals(1, $this->tax_query_count($args));
        $this->assertEquals('hmw_event_audience', $args['tax_query'][0]['taxonomy']);
    }

    public function test_build_query_topic_filter(): void
    {
        $args = $this->service->build_query(['topic' => ['parenting', 'sleep']]);
        $this->assertEquals(1, $this->tax_query_count($args));
        $this->assertEquals('hmw_event_topic', $args['tax_query'][0]['taxonomy']);
        $this->assertEquals('slug', $args['tax_query'][0]['field']);
        $this->assertEquals(['parenting', 'sleep'], $args['tax_query'][0]['terms']);
    }

    public function test_build_query_search(): void
    {
        $args = $this->service->build_query(['search' => 'birth preparation']);
        $this->assertEquals('birth preparation', $args['s']);
    }

    public function test_build_query_multiple_meta_queries_relation(): void
    {
        $args = $this->service->build_query([
            'date_from' => '2026-07-01',
            'free_only' => true,
        ]);

        $this->assertEquals('AND', $args['meta_query']['relation'] ?? '');
        $this->assertEquals(3, $this->meta_query_count($args));
    }

    public function test_build_query_multiple_tax_queries_relation(): void
    {
        $args = $this->service->build_query([
            'event_type'    => ['parent-one-off-free'],
            'delivery_mode' => ['online'],
        ]);

        $this->assertEquals('AND', $args['tax_query']['relation'] ?? '');
        $this->assertEquals(2, $this->tax_query_count($args));
    }

    // ============================================================
    // get_event_card — basic data
    // ============================================================

    public function test_get_event_card_returns_array(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($id === 42) {
                return $key === '_venue_address' ? ['address' => '123 Test St'] : '';
            }
            return match ($key) {
                '_event_start_date' => '2026-07-15 09:00:00',
                '_event_end_date'   => '2026-07-16 17:00:00',
                '_event_price'      => '150.00',
                '_event_venue'      => 42,
                '_event_capacity'   => '20',
                '_event_webinar_url' => 'https://zoom.us/test',
                '_event_is_free'    => '0',
                default             => '',
            };
        });

        Functions\when('get_post')->alias(function ($id) {
            if ($id === 42) {
                return new \WP_Post((object) [
                    'ID'         => 42,
                    'post_type'  => 'event_location',
                    'post_status' => 'publish',
                    'post_title' => 'Test Venue',
                ]);
            }
            return new \WP_Post((object) [
                'ID'         => (int) $id,
                'post_type'  => 'hmw_event',
                'post_status' => 'publish',
                'post_title' => 'Test Event',
            ]);
        });

        Functions\when('wp_get_object_terms')->alias(function ($id, $tax, $args) {
            return match ($tax) {
                'hmw_event_type' => ['parent-one-off-free'],
                'hmw_event_audience' => ['parents'],
                'hmw_event_delivery_mode' => ['in-person'],
                default => [],
            };
        });

        $post = (object) [
            'ID'           => 1,
            'post_title'   => 'Test Event',
            'post_excerpt' => 'Test excerpt.',
            'post_content' => 'Test content.',
            'post_status'  => 'publish',
        ];

        $card = $this->service->get_event_card($post);

        $this->assertIsArray($card);
        $this->assertEquals(1, $card['id']);
        $this->assertEquals('Test Event', $card['title']);
        $this->assertEquals('15 Jul 2026', $card['formatted_date']);
        $this->assertEquals(150.0, $card['price']);
        $this->assertFalse($card['is_free']);
        $this->assertEquals('Test Venue', $card['venue']);
        $this->assertEquals('123 Test St', $card['venue_address']);
        $this->assertEquals('in-person', $card['delivery_mode']);
        $this->assertEquals(20, $card['capacity']);
        $this->assertEquals(['parent-one-off-free'], $card['event_types']);
        $this->assertEquals(['parents'], $card['audiences']);
        $this->assertNotEmpty($card['badges']);
        $this->assertEquals('https://example.com/event/test', $card['permalink']);
    }

    public function test_get_event_card_free_event(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_event_start_date' => '2026-08-01 10:00:00',
                '_event_end_date'   => '',
                '_event_price'      => '0',
                '_event_is_free'    => '1',
                default             => '',
            };
        });

        Functions\when('wp_get_object_terms')->justReturn([]);

        $post = (object) [
            'ID'          => 2,
            'post_title'  => 'Free Event',
            'post_content' => 'Free content.',
            'post_excerpt' => '',
            'post_status' => 'publish',
        ];

        $card = $this->service->get_event_card($post);
        $this->assertTrue($card['is_free']);

        $has_free_badge = false;
        foreach ($card['badges'] as $badge) {
            if ($badge['class'] === 'badge--free') {
                $has_free_badge = true;
                break;
            }
        }
        $this->assertTrue($has_free_badge);
    }

    public function test_get_event_card_fully_booked(): void
    {
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('wp_get_object_terms')->justReturn([]);

        $post = (object) [
            'ID'           => 3,
            'post_title'   => 'Full Event',
            'post_excerpt' => '',
            'post_content' => '',
            'post_status'  => 'fully_booked',
        ];

        $card = $this->service->get_event_card($post);

        $has_full_badge = false;
        foreach ($card['badges'] as $badge) {
            if ($badge['class'] === 'badge--full') {
                $has_full_badge = true;
                break;
            }
        }
        $this->assertTrue($has_full_badge);
    }

    public function test_get_event_card_delivery_modes(): void
    {
        $modes = ['online' => 'badge--online', 'hybrid' => 'badge--hybrid'];

        foreach ($modes as $slug => $badge_class) {
            Functions\when('get_post_meta')->justReturn('');
            Functions\when('wp_get_object_terms')->alias(function ($id, $tax, $args) use ($slug) {
                return $tax === 'hmw_event_delivery_mode' ? [$slug] : [];
            });

            $post = (object) [
                'ID'           => 4,
                'post_title'   => 'Mode Test',
                'post_excerpt' => '',
                'post_content' => '',
                'post_status'  => 'publish',
            ];

            $card = $this->service->get_event_card($post);
            $this->assertEquals($slug, $card['delivery_mode']);

            $has_badge = false;
            foreach ($card['badges'] as $badge) {
                if ($badge['class'] === $badge_class) {
                    $has_badge = true;
                    break;
                }
            }
            $this->assertTrue($has_badge);
        }
    }

    // ============================================================
    // get_event_card — multi-session badge
    // ============================================================

    public function test_get_event_card_includes_multi_session_badge(): void
    {
        // IDs 100 & 101 are child sessions, ID 1 is the parent event.
        // The Multi-Session badge requires session_count > 1.
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            if ($id === 100 || $id === 101) {
                return $key === '_event_start_date' ? '2026-07-15 09:00:00' : '';
            }
            return match ($key) {
                '_event_start_date' => '2026-07-01 09:00:00',
                '_event_end_date'   => '',
                '_event_price'      => '150.00',
                '_event_is_free'    => '0',
                default             => '',
            };
        });

        Functions\when('wp_get_object_terms')->justReturn([]);

        $children = [
            (object) ['ID' => 100, 'post_type' => 'hmw_event', 'post_parent' => 1],
            (object) ['ID' => 101, 'post_type' => 'hmw_event', 'post_parent' => 1],
        ];
        Functions\when('get_posts')->alias(function ($args) use ($children) {
            if (($args['post_parent'] ?? 0) === 1) {
                return $children;
            }
            return [];
        });

        $post = (object) [
            'ID'           => 1,
            'post_title'   => 'Multi Session Event',
            'post_excerpt' => 'Has sessions.',
            'post_content' => 'Content.',
            'post_status'  => 'publish',
        ];

        $card = $this->service->get_event_card($post);

        $has_multi = false;
        foreach ($card['badges'] as $badge) {
            if ($badge['label'] === 'Multi-Session') {
                $has_multi = true;
                break;
            }
        }
        $this->assertTrue($has_multi, 'Multi-Session badge should be present');
        $this->assertTrue($card['is_multi_session']);
        $this->assertEquals(2, $card['session_count']);
    }

    public function test_get_event_card_excludes_multi_session_badge_for_single(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_event_start_date' => '2026-07-01 09:00:00',
                '_event_end_date'   => '',
                '_event_price'      => '150.00',
                '_event_is_free'    => '0',
                default             => '',
            };
        });

        Functions\when('wp_get_object_terms')->justReturn([]);

        $post = (object) [
            'ID'           => 2,
            'post_title'   => 'Single Event',
            'post_excerpt' => 'No sessions.',
            'post_content' => 'Content.',
            'post_status'  => 'publish',
        ];

        $card = $this->service->get_event_card($post);

        $has_multi = false;
        foreach ($card['badges'] as $badge) {
            if ($badge['label'] === 'Multi-Session') {
                $has_multi = true;
                break;
            }
        }
        $this->assertFalse($has_multi, 'Multi-Session badge should not be present');
        $this->assertFalse($card['is_multi_session']);
        $this->assertEquals(0, $card['session_count']);
    }

    // ============================================================
    // EventListingService in components
    // ============================================================

    public function test_event_listing_service_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(EventListingService::class, $components);
    }

    // ============================================================
    // parse_filter_params — event_type_filter_parent
    // ============================================================

    private function parse_filter_params(array $atts): array
    {
        $method = new \ReflectionMethod(EventListingService::class, 'parse_filter_params');
        $method->setAccessible(true);
        return $method->invoke($this->service, $atts);
    }

    private function filter_bar_atts(array $overrides = []): array
    {
        return array_merge([
            'type'         => '',
            'audience'     => '',
            'topic'        => '',
            'mode'         => '',
            'state'        => '',
            'free'         => '',
            'limit'        => 12,
            'show_filters' => 'yes',
            'sort'         => 'date',
            'sort_order'   => 'ASC',
            'event_type_filter_parent' => '',
        ], $overrides);
    }

    public function test_type_attr_only_hides_type_filter(): void
    {
        $filters = $this->parse_filter_params($this->filter_bar_atts(['type' => 'programs']));

        $this->assertFalse($filters['show_type_filter']);
        $this->assertSame(['programs'], $filters['event_type']);
        $this->assertArrayNotHasKey('type_filter_parent', $filters);
    }

    public function test_type_attr_with_filter_parent_keeps_filter_visible(): void
    {
        $filters = $this->parse_filter_params($this->filter_bar_atts([
            'type' => 'programs',
            'event_type_filter_parent' => '23',
        ]));

        $this->assertTrue($filters['show_type_filter']);
        $this->assertSame(['programs'], $filters['event_type']);
        $this->assertSame(23, $filters['type_filter_parent']);
        $this->assertTrue($filters['event_type_is_restriction']);
    }

    public function test_url_ev_type_overrides_type_restriction_when_parent_set(): void
    {
        $_GET['ev_type'] = ['workshops'];

        $filters = $this->parse_filter_params($this->filter_bar_atts([
            'type' => 'programs',
            'event_type_filter_parent' => '23',
        ]));

        $this->assertSame(['workshops'], $filters['event_type']);
        $this->assertTrue($filters['show_type_filter']);
        $this->assertSame(23, $filters['type_filter_parent']);
        $this->assertArrayNotHasKey('event_type_is_restriction', $filters);
    }

    public function test_url_ev_type_applies_without_type_attr(): void
    {
        $_GET['ev_type'] = ['workshops'];

        $filters = $this->parse_filter_params($this->filter_bar_atts());

        $this->assertSame(['workshops'], $filters['event_type']);
        $this->assertTrue($filters['show_type_filter']);
    }

    public function test_filter_parent_without_type_or_url_sets_parent_only(): void
    {
        $filters = $this->parse_filter_params($this->filter_bar_atts([
            'event_type_filter_parent' => '23',
        ]));

        $this->assertArrayNotHasKey('event_type', $filters);
        $this->assertSame(23, $filters['type_filter_parent']);
        $this->assertTrue($filters['show_type_filter']);
    }

    public function test_invalid_filter_parent_treated_as_absent(): void
    {
        foreach (['abc', '0'] as $parent) {
            $filters = $this->parse_filter_params($this->filter_bar_atts([
                'type' => 'programs',
                'event_type_filter_parent' => $parent,
            ]));

            $this->assertArrayNotHasKey('type_filter_parent', $filters);
            $this->assertFalse($filters['show_type_filter']);
            $this->assertSame(['programs'], $filters['event_type']);
        }
    }

    // ============================================================
    // get_filter_terms — parent arg
    // ============================================================

    private function get_filter_terms(string $taxonomy, int $parent_id = 0): array
    {
        $method = new \ReflectionMethod(EventListingService::class, 'get_filter_terms');
        $method->setAccessible(true);
        return $method->invoke($this->service, $taxonomy, $parent_id);
    }

    public function test_get_filter_terms_passes_parent_arg(): void
    {
        $captured = [];
        Functions\when('get_terms')->alias(function ($args) use (&$captured) {
            $captured[] = $args;
            return [];
        });

        $this->get_filter_terms('hmw_event_type', 23);

        $this->assertSame([
            'taxonomy'   => 'hmw_event_type',
            'hide_empty' => true,
            'parent'     => 23,
        ], $captured[0]);
    }

    public function test_get_filter_terms_omits_parent_by_default(): void
    {
        $captured = [];
        Functions\when('get_terms')->alias(function ($args) use (&$captured) {
            $captured[] = $args;
            return [];
        });

        $this->get_filter_terms('hmw_event_type');

        $this->assertSame([
            'taxonomy'   => 'hmw_event_type',
            'hide_empty' => true,
        ], $captured[0]);
        $this->assertArrayNotHasKey('parent', $captured[0]);
    }

    public function test_get_filter_terms_returns_empty_for_wp_error(): void
    {
        Functions\when('get_terms')->justReturn(new \WP_Error('invalid_taxonomy'));

        $this->assertSame([], $this->get_filter_terms('hmw_event_type'));
    }

    public function test_get_filter_terms_returns_empty_for_no_terms(): void
    {
        Functions\when('get_terms')->justReturn([]);

        $this->assertSame([], $this->get_filter_terms('hmw_event_type'));
    }

    public function test_get_filter_terms_returns_terms(): void
    {
        $terms = [
            (object) ['name' => 'Workshops', 'slug' => 'workshops'],
            (object) ['name' => 'Webinars', 'slug' => 'webinars'],
        ];
        Functions\when('get_terms')->justReturn($terms);

        $this->assertSame($terms, $this->get_filter_terms('hmw_event_type', 23));
    }

    // ============================================================
    // render_active_filters — type chip gating
    // ============================================================

    private function render_active_filters(array $filters): string
    {
        $method = new \ReflectionMethod(EventListingService::class, 'render_active_filters');
        $method->setAccessible(true);
        ob_start();
        $method->invoke($this->service, $filters);
        return (string) ob_get_clean();
    }

    public function test_active_filters_skip_type_chips_when_restriction_set(): void
    {
        Functions\when('get_terms')->justReturn([
            (object) ['name' => 'Programs', 'slug' => 'programs'],
        ]);

        $output = $this->render_active_filters([
            'event_type'                => ['programs'],
            'show_type_filter'          => true,
            'event_type_is_restriction' => true,
        ]);

        $this->assertSame('', $output);
    }

    public function test_active_filters_render_type_chip_without_restriction(): void
    {
        Functions\when('get_terms')->justReturn([
            (object) ['name' => 'Workshops', 'slug' => 'workshops'],
        ]);

        $output = $this->render_active_filters([
            'event_type'       => ['workshops'],
            'show_type_filter' => true,
        ]);

        $this->assertStringContainsString('Type', $output);
        $this->assertStringContainsString('Workshops', $output);
    }
}
