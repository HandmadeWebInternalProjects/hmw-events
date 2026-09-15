<?php
/**
 * Event card taxonomy & delivery-mode pill tests.
 *
 * Covers EventListingService::get_event_card() delivery-mode label/icon
 * resolution, audience term link lists, and the ACF SVG upload
 * capability gates.
 *
 * @package HMWEvents\Tests\Unit\Services
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\ACF;
use HMWEvents\Services\EventListingService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class EventCardTaxonomyTest extends TestCase
{
    private EventListingService $service;
    private ACF $acf;
    private bool $user_can_manage_options = false;
    private array $temp_files = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
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
        Functions\when('wp_get_object_terms')->justReturn([]);
        Functions\when('get_field')->justReturn(false);
        Functions\when('wp_get_attachment_url')->justReturn('');
        Functions\when('get_post_mime_type')->justReturn('');
        Functions\when('get_attached_file')->justReturn('');
        Functions\when('get_term_link')->alias(function ($term, $taxonomy = '') {
            $slug = is_object($term) ? ($term->slug ?? '') : (string) $term;
            return 'https://example.com/' . $taxonomy . '/' . $slug;
        });
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
        Functions\when('wp_doing_ajax')->justReturn(false);
        Functions\when('wp_get_referer')->justReturn(false);
        Functions\when('home_url')->alias(function ($path = '') {
            return 'https://example.com' . $path;
        });
        Functions\when('wp_parse_url')->alias(function ($url, $component = -1) {
            return parse_url($url, $component);
        });
        Functions\when('esc_url_raw')->returnArg();
        Functions\when('wp_json_encode')->alias(function ($data, $flags = 0) {
            return json_encode($data, $flags);
        });
        Functions\when('wp_unslash')->returnArg();
        Functions\when('get_posts')->justReturn([]);
        Functions\when('get_post')->alias(function ($id) {
            if (!$id) {
                return null;
            }
            return new \WP_Post((object) [
                'ID'          => (int) $id,
                'post_type'   => 'hmw_event',
                'post_status' => 'publish',
                'post_title'  => 'Test Event',
            ]);
        });
        Functions\when('current_user_can')->alias(function (string $capability): bool {
            return $capability === 'manage_options' && $this->user_can_manage_options;
        });
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'svg', 'type' => 'image/svg+xml']);

        $this->service = new EventListingService();
        $this->acf = new ACF();
    }

    protected function tearDown(): void
    {
        foreach ($this->temp_files as $file) {
            if (is_string($file) && is_file($file)) {
                unlink($file);
            }
        }
        $this->temp_files = [];

        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function setCanManageOptions(bool $allowed): void
    {
        $this->user_can_manage_options = $allowed;
    }

    private function stubEventMeta(): void
    {
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_event_start_date' => '2026-07-15 09:00:00',
                '_event_end_date'   => '2026-07-16 17:00:00',
                '_event_price'      => '150.00',
                '_event_capacity'   => '20',
                default             => '',
            };
        });
    }

    private function deliveryTerm(int $term_id = 5): object
    {
        return (object) [
            'term_id' => $term_id,
            'slug'    => 'online',
            'name'    => 'Virtual Delivery',
        ];
    }

    private function audienceTerm(int $term_id = 3): object
    {
        return (object) [
            'term_id' => $term_id,
            'slug'    => 'parents',
            'name'    => 'Parents',
        ];
    }

    private function stubTermsForCard(?object $delivery = null, ?object $audience = null): void
    {
        Functions\when('wp_get_object_terms')->alias(function ($id, $tax, $args = []) use ($delivery, $audience) {
            $fields = $args['fields'] ?? '';
            return match ($tax) {
                'hmw_event_type'          => [],
                'hmw_event_audience'      => $fields === 'slugs'
                    ? ($audience ? [$audience->slug] : [])
                    : ($audience ? [$audience] : []),
                'hmw_event_delivery_mode' => $delivery ? [$delivery] : [],
                default                   => [],
            };
        });
    }

    private function makeCard(): array
    {
        $post = (object) [
            'ID'           => 7,
            'post_title'   => 'Test Event',
            'post_excerpt' => 'Excerpt text.',
            'post_content' => 'Full content.',
            'post_status'  => 'publish',
        ];

        return $this->service->get_event_card($post);
    }

    private function assertNoDeliveryModeBadges(array $card): void
    {
        foreach ($card['badges'] as $badge) {
            $this->assertStringStartsNotWith('badge--', (string) $badge['class']);
        }
        $this->assertSame([], $card['badges']);
    }

    private function createTempSvgFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'hmw_svg_test_');
        file_put_contents($path, $contents);
        $this->temp_files[] = $path;
        return $path;
    }

    // ============================================================
    // get_event_card — delivery mode resolution
    // ============================================================

    public function test_delivery_mode_from_term_object_uses_term_name_and_no_badges(): void
    {
        $this->stubEventMeta();
        $this->stubTermsForCard($this->deliveryTerm());

        $card = $this->makeCard();

        $this->assertSame('online', $card['delivery_mode']);
        $this->assertSame('Virtual Delivery', $card['delivery_mode_label']);
        $this->assertNoDeliveryModeBadges($card);
    }

    public function test_delivery_mode_fallback_when_no_delivery_terms(): void
    {
        $this->stubEventMeta();
        $this->stubTermsForCard();

        $card = $this->makeCard();

        $this->assertSame('in-person', $card['delivery_mode']);
        $this->assertSame('In Person', $card['delivery_mode_label']);
        $this->assertSame('', $card['delivery_mode_icon']);
        $this->assertNoDeliveryModeBadges($card);
    }

    // ============================================================
    // get_event_card — delivery mode icon
    // ============================================================

    public function test_delivery_mode_icon_renders_sanitized_inline_svg(): void
    {
        $this->stubEventMeta();
        $this->stubTermsForCard($this->deliveryTerm());

        $svg_path = $this->createTempSvgFile(
            '<svg xmlns="http://www.w3.org/2000/svg" onload="evil()"><script>alert(1)</script><circle r="5"/></svg>'
        );

        $captured_field_args = [];
        Functions\when('get_field')->alias(function ($selector, $context = null) use (&$captured_field_args) {
            $captured_field_args[] = [$selector, $context];
            return ['ID' => 9, 'url' => 'https://example.com/wp-content/uploads/2026/01/icon.svg'];
        });
        Functions\when('get_post_mime_type')->justReturn('image/svg+xml');
        Functions\when('get_attached_file')->justReturn($svg_path);

        $card = $this->makeCard();
        $icon = $card['delivery_mode_icon'];

        $this->assertSame([['icon', 'hmw_event_delivery_mode_5']], $captured_field_args);
        $this->assertStringStartsWith('<span class="hmw-delivery-pill__icon" aria-hidden="true">', $icon);
        $this->assertStringEndsWith('</span>', $icon);
        $this->assertStringContainsString('<circle r="5"/>', $icon);
        $this->assertStringNotContainsString('onload', $icon);
        $this->assertStringNotContainsString('<script', $icon);
    }

    public function test_delivery_mode_icon_renders_img_tag_for_non_svg(): void
    {
        $this->stubEventMeta();
        $this->stubTermsForCard($this->deliveryTerm());

        Functions\when('get_field')->justReturn(['ID' => 9, 'url' => 'https://example.com/icon.png']);
        Functions\when('get_post_mime_type')->justReturn('image/png');

        $card = $this->makeCard();

        $this->assertSame(
            '<img class="hmw-delivery-pill__icon" src="https://example.com/icon.png" alt="" aria-hidden="true" loading="lazy" />',
            $card['delivery_mode_icon']
        );
    }

    public function test_delivery_mode_icon_empty_when_no_acf_icon(): void
    {
        $this->stubEventMeta();
        $this->stubTermsForCard($this->deliveryTerm());
        Functions\when('get_field')->justReturn(false);

        $card = $this->makeCard();

        $this->assertSame('', $card['delivery_mode_icon']);
    }

    // ============================================================
    // get_event_card — audience term link lists and venue suburb
    // ============================================================

    public function test_audience_terms_and_suburb_from_term_objects(): void
    {
        $this->stubEventMeta();
        $this->stubTermsForCard(null, $this->audienceTerm());

        $card = $this->makeCard();

        $this->assertSame([
            [
                'name' => 'Parents',
                'slug' => 'parents',
                'link' => 'https://example.com/hmw_event_audience/parents',
            ],
        ], $card['audience_terms']);
        $this->assertNull($card['suburb']);
    }

    public function test_card_suburb_uses_denormalised_meta_when_present(): void
    {
        $this->stubEventMeta();
        $this->stubTermsForCard(null, $this->audienceTerm());
        Functions\when('get_post_meta')->alias(function ($id, $key, $single) {
            return match ($key) {
                '_event_start_date'    => '2026-07-15 09:00:00',
                '_event_end_date'      => '2026-07-16 17:00:00',
                '_event_price'         => '150.00',
                '_event_capacity'      => '20',
                '_event_venue_suburb'  => 'Penrith',
                default                => '',
            };
        });

        $card = $this->makeCard();

        $this->assertSame('Penrith', $card['suburb']);
    }

    public function test_audience_terms_link_empty_when_get_term_link_errors(): void
    {
        $this->stubEventMeta();
        $this->stubTermsForCard(null, $this->audienceTerm());
        Functions\when('get_term_link')->justReturn(new \WP_Error('invalid_term', 'Term does not exist'));

        $card = $this->makeCard();

        $this->assertCount(1, $card['audience_terms']);
        $this->assertSame('Parents', $card['audience_terms'][0]['name']);
        $this->assertSame('parents', $card['audience_terms'][0]['slug']);
        $this->assertSame('', $card['audience_terms'][0]['link']);
    }

    public function test_audience_terms_empty_when_object_terms_is_wp_error(): void
    {
        $this->stubEventMeta();
        Functions\when('wp_get_object_terms')->alias(function ($id, $tax, $args = []) {
            if ($tax === 'hmw_event_audience' && ($args['fields'] ?? '') === '') {
                return new \WP_Error('terms_error', 'Could not retrieve terms');
            }
            return [];
        });

        $card = $this->makeCard();

        $this->assertSame([], $card['audience_terms']);
    }

    // ============================================================
    // ACF — allow_svg_uploads()
    // ============================================================

    public function test_allow_svg_uploads_adds_svg_mimes_for_admins(): void
    {
        $this->setCanManageOptions(true);

        $result = $this->acf->allow_svg_uploads(['png' => 'image/png', 'jpg' => 'image/jpeg']);

        $this->assertSame('image/svg+xml', $result['svg']);
        $this->assertSame('image/svg+xml', $result['svgz']);
        $this->assertSame('image/png', $result['png']);
        $this->assertSame('image/jpeg', $result['jpg']);
    }

    public function test_allow_svg_uploads_returns_input_unchanged_without_capability(): void
    {
        $this->setCanManageOptions(false);

        $input = ['png' => 'image/png', 'jpg' => 'image/jpeg'];
        $result = $this->acf->allow_svg_uploads($input);

        $this->assertSame($input, $result);
        $this->assertArrayNotHasKey('svg', $result);
        $this->assertArrayNotHasKey('svgz', $result);
    }

    // ============================================================
    // ACF — fix_svg_filetype()
    // ============================================================

    public function test_fix_svg_filetype_forces_svg_ext_and_type_for_admins(): void
    {
        $this->setCanManageOptions(true);
        Functions\when('wp_check_filetype')->justReturn(['ext' => 'svg', 'type' => 'image/svg+xml']);

        $result = $this->acf->fix_svg_filetype(
            ['ext' => 'jpg', 'type' => 'image/jpeg'],
            '/tmp/upload.tmp',
            'icon.svg',
            ['svg' => 'image/svg+xml']
        );

        $this->assertSame('svg', $result['ext']);
        $this->assertSame('image/svg+xml', $result['type']);
    }

    public function test_fix_svg_filetype_returns_data_unchanged_without_capability(): void
    {
        $this->setCanManageOptions(false);

        $data = ['ext' => 'jpg', 'type' => 'image/jpeg'];
        $result = $this->acf->fix_svg_filetype($data, '/tmp/upload.tmp', 'icon.svg', []);

        $this->assertSame($data, $result);
    }

    public function test_fix_svg_filetype_detects_svg_by_filename(): void
    {
        $this->setCanManageOptions(true);
        Functions\when('wp_check_filetype')->justReturn(['ext' => false, 'type' => false]);

        $result = $this->acf->fix_svg_filetype(
            ['ext' => false, 'type' => false],
            '/tmp/upload.tmp',
            'ICON.SVG',
            []
        );

        $this->assertSame('svg', $result['ext']);
        $this->assertSame('image/svg+xml', $result['type']);
    }
}
