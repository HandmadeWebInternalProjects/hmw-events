<?php

/**
 * EventListingService AJAX endpoint, atts sanitising, pagination and
 * filterable layout tests.
 *
 * @package HMWEvents\Tests
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\EventListingService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventListingAjaxTest extends TestCase
{
    private EventListingService $service;

    /** @var array{success: mixed, error: mixed} */
    private array $json = ['success' => null, 'error' => null];

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
        $this->json = ['success' => null, 'error' => null];

        Functions\when('__')->returnArg();
        Functions\when('_n')->alias(function ($single, $plural, $number) {
            return (int) $number === 1 ? $single : $plural;
        });
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('esc_html_e')->alias(function ($text, $domain = null) {
            echo $text;
        });
        Functions\when('esc_attr_e')->alias(function ($text, $domain = null) {
            echo $text;
        });
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('wp_json_encode')->alias(function ($data, $flags = 0) {
            return json_encode($data, $flags);
        });
        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('home_url')->alias(function ($path = '') {
            return 'https://example.com' . $path;
        });
        Functions\when('wp_get_referer')->justReturn(false);
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('get_option')->justReturn(false);
        Functions\when('get_permalink')->justReturn('https://example.com/events');
        Functions\when('date_i18n')->alias(function ($format, $time) {
            return date($format, $time);
        });
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('get_the_post_thumbnail_url')->justReturn('');
        Functions\when('wp_trim_words')->returnArg(1);
        Functions\when('get_terms')->justReturn([]);
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('get_transient')->justReturn([]);
        Functions\when('set_transient')->justReturn(true);
        Functions\when('delete_transient')->justReturn(true);
        Functions\when('get_post')->alias(function ($id) {
            return new \WP_Post((object) [
                'ID'         => (int) $id,
                'post_type'  => 'hmw_event',
                'post_status' => 'publish',
                'post_title' => 'Test Event',
                'post_excerpt' => '',
                'post_content' => '',
            ]);
        });
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('shortcode_atts')->alias(function ($defaults, $atts) {
            return array_merge((array) $defaults, array_intersect_key((array) $atts, (array) $defaults));
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
        Functions\when('wp_reset_postdata')->justReturn(null);
        Functions\when('checked')->alias(function ($checked, $current = true) {
            return $checked == $current ? 'checked="checked"' : '';
        });
        Functions\when('selected')->alias(function ($selected, $current = true) {
            return $selected == $current ? 'selected="selected"' : '';
        });
        Functions\when('paginate_links')->justReturn('');
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

        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            $this->json['success'] = $data;
            return true;
        });
        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            $this->json['error'] = $data;
            return true;
        });

        $this->service = new EventListingService();
    }

    protected function tearDown(): void
    {
        \WP_Query::$test_config = [];
        $_GET = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function invoke_private(string $method, mixed ...$args)
    {
        return $this->private_method($method)->invoke($this->service, ...$args);
    }

    private function private_method(string $method): \ReflectionMethod
    {
        $ref = new \ReflectionMethod(EventListingService::class, $method);
        $ref->setAccessible(true);
        return $ref;
    }

    private function event_post(int $id = 55): \WP_Post
    {
        return new \WP_Post((object) [
            'ID'           => $id,
            'post_type'    => 'hmw_event',
            'post_status'  => 'publish',
            'post_title'   => 'Free Webinar',
            'post_excerpt' => '',
            'post_content' => '',
        ]);
    }

    // ============================================================
    // sanitize_atts
    // ============================================================

    public function test_sanitize_atts_drops_unknown_keys_and_keeps_whitelist(): void
    {
        $atts = $this->invoke_private('sanitize_atts', ['bogus' => 'x', 'limit' => 10]);

        $this->assertArrayNotHasKey('bogus', $atts);

        foreach ([
            'type', 'audience', 'topic', 'program', 'mode', 'state', 'location',
            'free', 'limit', 'show_filters', 'sort', 'sort_order', 'event_type_filter_parent',
        ] as $key) {
            $this->assertArrayHasKey($key, $atts);
        }

        $this->assertSame(10, $atts['limit']);
        $this->assertSame('yes', $atts['show_filters']);
    }

    public function test_sanitize_atts_clamps_limit(): void
    {
        $this->assertSame(1, $this->invoke_private('sanitize_atts', ['limit' => 0])['limit']);
        $this->assertSame(1, $this->invoke_private('sanitize_atts', ['limit' => -5])['limit']);
        $this->assertSame(48, $this->invoke_private('sanitize_atts', ['limit' => 999])['limit']);
        $this->assertSame(12, $this->invoke_private('sanitize_atts', ['limit' => 'not-a-number'])['limit']);
        $this->assertSame(24, $this->invoke_private('sanitize_atts', ['limit' => '24'])['limit']);
    }

    public function test_sanitize_atts_invalid_sort_and_order_fall_back_to_defaults(): void
    {
        $atts = $this->invoke_private('sanitize_atts', [
            'sort'       => 'bogus',
            'sort_order' => 'bogus',
        ]);

        $this->assertSame('date', $atts['sort']);
        $this->assertSame('ASC', $atts['sort_order']);
    }

    public function test_sanitize_atts_normalizes_free_show_filters_and_order_case(): void
    {
        $atts = $this->invoke_private('sanitize_atts', [
            'free'         => 'yes',
            'show_filters' => 'true',
            'sort'         => 'price',
            'sort_order'   => 'desc',
            'type'         => 'workshops',
        ]);

        $this->assertSame('1', $atts['free']);
        $this->assertSame('yes', $atts['show_filters']);
        $this->assertSame('price', $atts['sort']);
        $this->assertSame('DESC', $atts['sort_order']);
        $this->assertSame('workshops', $atts['type']);

        $atts = $this->invoke_private('sanitize_atts', [
            'free'         => '0',
            'show_filters' => 'no',
        ]);

        $this->assertSame('', $atts['free']);
        $this->assertSame('no', $atts['show_filters']);
    }

    // ============================================================
    // decode_atts_payload
    // ============================================================

    public function test_decode_atts_payload_round_trips_valid_payload(): void
    {
        $raw = base64_encode((string) json_encode([
            'limit' => 999,
            'type'  => 'workshops',
        ]));

        $atts = $this->invoke_private('decode_atts_payload', $raw);

        $this->assertIsArray($atts);
        $this->assertSame(48, $atts['limit']);
        $this->assertSame('workshops', $atts['type']);
        $this->assertSame('date', $atts['sort']);
        $this->assertSame('ASC', $atts['sort_order']);
    }

    public function test_decode_atts_payload_returns_null_for_garbage_base64(): void
    {
        $this->assertNull($this->invoke_private('decode_atts_payload', '%%%'));
        $this->assertNull($this->invoke_private('decode_atts_payload', 'not-base64!!'));
    }

    public function test_decode_atts_payload_returns_null_for_non_json_base64(): void
    {
        $raw = base64_encode('not-json');

        $this->assertNull($this->invoke_private('decode_atts_payload', $raw));
    }

    public function test_decode_atts_payload_returns_null_for_empty_or_null_input(): void
    {
        $this->assertNull($this->invoke_private('decode_atts_payload', null));
        $this->assertNull($this->invoke_private('decode_atts_payload', ''));
    }

    // ============================================================
    // ajax_filter — happy path
    // ============================================================

    public function test_ajax_filter_success_payload_shape_and_fragment(): void
    {
        Functions\when('wp_doing_ajax')->justReturn(true);

        \WP_Query::$test_config = [
            'posts'       => [$this->event_post(55)],
            'found_posts' => 5,
            'max_num_pages' => 1,
        ];

        $_GET['hmw_atts'] = base64_encode((string) json_encode([
            'limit'        => '24',
            'show_filters' => 'yes',
        ]));
        $_GET['ev_price'] = 'free';

        $this->service->ajax_filter();

        $this->assertNotNull($this->json['success']);
        $this->assertNull($this->json['error']);

        $payload = $this->json['success'];

        $this->assertArrayHasKey('html', $payload);
        $this->assertArrayHasKey('url', $payload);
        $this->assertSame(5, $payload['found_posts']);
        $this->assertSame(1, $payload['total_pages']);

        $html = (string) $payload['html'];

        $this->assertStringContainsString('hmw-results-bar', $html);
        $this->assertStringContainsString('hmw-active-filters', $html);
        $this->assertStringContainsString('hmw-event-listings', $html);
        $this->assertStringContainsString('hmw-event-card', $html);
        $this->assertStringNotContainsString('hmw-event-filters__form', $html);

        $url = (string) $payload['url'];

        $this->assertStringContainsString('ev_price=free', $url);
        $this->assertStringNotContainsString('action=', $url);
        $this->assertStringNotContainsString('hmw_atts', $url);
    }

    public function test_ajax_filter_success_url_omits_atts_level_free_restriction(): void
    {
        Functions\when('wp_doing_ajax')->justReturn(true);

        \WP_Query::$test_config = [
            'posts'       => [$this->event_post(56)],
            'found_posts' => 1,
            'max_num_pages' => 1,
        ];

        $_GET['hmw_atts'] = base64_encode((string) json_encode([
            'free' => '1',
        ]));

        $this->service->ajax_filter();

        $this->assertNotNull($this->json['success']);
        $this->assertStringNotContainsString('ev_price', (string) $this->json['success']['url']);
    }

    // ============================================================
    // ajax_filter — invalid payload
    // ============================================================

    public function test_ajax_filter_returns_error_for_invalid_atts_payload(): void
    {
        $_GET['hmw_atts'] = '%%%';

        $this->service->ajax_filter();

        $this->assertNotNull($this->json['error']);
        $this->assertNull($this->json['success']);
        $this->assertArrayHasKey('message', $this->json['error']);
    }

    // ============================================================
    // render_pagination
    // ============================================================

    public function test_render_pagination_builds_base_from_ev_params_and_pg_token(): void
    {
        $_GET['ev_price'] = 'free';
        $_GET['pg']       = 2;

        \WP_Query::$test_config = [
            'posts'         => [],
            'found_posts'   => 25,
            'max_num_pages' => 3,
        ];

        $captured = [];
        Functions\when('paginate_links')->alias(function ($args) use (&$captured) {
            $captured[] = $args;
            return '<ul class="page-numbers"><li>2</li></ul>';
        });

        $query = $this->service->query(['page' => 2]);

        ob_start();
        $this->invoke_private('render_pagination', $query);
        $output = (string) ob_get_clean();

        $this->assertNotEmpty($captured);

        $args = $captured[0];

        $this->assertSame(2, $args['current']);
        $this->assertSame(3, $args['total']);
        $this->assertSame('list', $args['type']);
        $this->assertSame('', $args['format']);

        $this->assertStringContainsString('ev_price=free', $args['base']);
        $this->assertStringContainsString('pg=%#%', $args['base']);
        $this->assertStringNotContainsString('paged=', $args['base']);

        $this->assertStringContainsString('hmw-pagination', $output);
        $this->assertStringContainsString('page-numbers', $output);
    }

    public function test_render_pagination_outputs_nothing_for_single_page(): void
    {
        \WP_Query::$test_config = [
            'posts'         => [],
            'found_posts'   => 3,
            'max_num_pages' => 1,
        ];

        $query = $this->service->query([]);

        $method = $this->private_method('render_pagination');

        ob_start();
        $method->invoke($this->service, $query);
        $output = (string) ob_get_clean();

        $this->assertSame('', $output);
    }

    // ============================================================
    // render_listings — filterable layout
    // ============================================================

    public function test_render_listings_with_filters_renders_layout_and_atts_payload(): void
    {
        \WP_Query::$test_config = [
            'posts'         => [],
            'found_posts'   => 0,
            'max_num_pages' => 1,
        ];

        $html = $this->service->render_listings(['show_filters' => 'yes']);

        $this->assertStringContainsString('hmw-event-listings-wrapper', $html);
        $this->assertStringContainsString('hmw-event-listings-layout', $html);
        $this->assertStringContainsString('hmw-event-listings-layout__main', $html);
        $this->assertStringContainsString('hmw-event-listings-region', $html);
        $this->assertStringContainsString('id="hmw-event-listings-region"', $html);
        $this->assertStringContainsString('aria-live="polite"', $html);
        $this->assertStringContainsString('hmw-event-listings-layout__sidebar', $html);
        $this->assertStringContainsString('hmw-event-filters__form', $html);
        $this->assertStringContainsString('hmw-no-events', $html);
        $this->assertStringContainsString('data-hmw-base-url="https://example.com/events"', $html);

        $this->assertMatchesRegularExpression('/data-hmw-atts="([A-Za-z0-9+\/=]+)"/', $html);

        if (preg_match('/data-hmw-atts="([A-Za-z0-9+\/=]+)"/', $html, $matches)) {
            $decoded = json_decode((string) base64_decode($matches[1], true), true);

            $this->assertIsArray($decoded);
            $this->assertSame('yes', $decoded['show_filters']);
            $this->assertSame(12, $decoded['limit']);
        }
    }

    public function test_render_listings_without_filters_has_no_sidebar_or_atts_payload(): void
    {
        \WP_Query::$test_config = [
            'posts'         => [],
            'found_posts'   => 0,
            'max_num_pages' => 1,
        ];

        $html = $this->service->render_listings(['show_filters' => 'no']);

        $this->assertStringContainsString('hmw-event-listings-wrapper', $html);
        $this->assertStringContainsString('hmw-no-events', $html);
        $this->assertStringNotContainsString('hmw-event-listings-layout__sidebar', $html);
        $this->assertStringNotContainsString('hmw-event-filters__form', $html);
        $this->assertStringNotContainsString('data-hmw-atts', $html);
    }
}
