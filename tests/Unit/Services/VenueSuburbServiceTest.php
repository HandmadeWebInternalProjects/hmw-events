<?php

/**
 * VenueSuburbService unit tests.
 *
 * @package HMWEvents\Tests\Unit\Services
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\VenueSuburbService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class VenueSuburbServiceTest extends TestCase
{
    private VenueSuburbService $service;

    private object $wpdb;

    private array $updated_meta = [];

    private array $deleted_meta = [];

    private array $set_transient_calls = [];

    private array $deleted_transients = [];

    private int $cache_flushes = 0;

    private array $post_types = [];

    private array $post_meta = [];

    public bool|array $transient_value = false;

    public array $options = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $this->updated_meta = [];
        $this->deleted_meta = [];
        $this->set_transient_calls = [];
        $this->deleted_transients = [];
        $this->cache_flushes = 0;
        $this->post_types = [];
        $this->post_meta = [];

        $service = $this;
        $this->wpdb = new class($service) {
            private $test;

            public $postmeta = 'wp_postmeta';
            public $posts = 'wp_posts';
            public $term_taxonomy = 'wp_term_taxonomy';
            public $term_relationships = 'wp_term_relationships';
            public $terms = 'wp_terms';

            public array $sql_log = [];
            public array $col_results = [];

            public function __construct($test)
            {
                $this->test = $test;
            }

            public function prepare(string $query, ...$args): string
            {
                if (count($args) === 1 && is_array($args[0])) {
                    $args = $args[0];
                }

                return vsprintf($query, $args);
            }

            public function get_col(string $query): array
            {
                $this->sql_log[] = $query;
                return $this->col_results[$query] ?? $this->col_results['__default__'] ?? [];
            }

            public function query(string $sql): int
            {
                $this->sql_log[] = $sql;
                return 1;
            }
        };

        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\when('get_transient')->alias(function ($key) use ($service) {
            return $service->transient_value;
        });
        Functions\when('set_transient')->alias(function ($key, $value, $ttl) use ($service) {
            $service->set_transient_calls[] = [$key, $value, $ttl];
            return true;
        });
        Functions\when('delete_transient')->alias(function ($key) use ($service) {
            $service->deleted_transients[] = $key;
            $service->transient_value = false;
            $service->cache_flushes++;
            return true;
        });
        Functions\when('get_post')->alias(function ($id) use ($service) {
            $type = $service->post_types[(int) $id] ?? null;
            if ($type === null) {
                return null;
            }
            return new \WP_Post((object) [
                'ID'          => (int) $id,
                'post_type'   => $type,
                'post_status' => 'publish',
                'post_title'  => 'Post ' . $id,
            ]);
        });
        Functions\when('get_post_meta')->alias(function ($id, $key, $single = true) use ($service) {
            return $service->post_meta[(int) $id][$key] ?? '';
        });
        Functions\when('update_post_meta')->alias(function ($id, $key, $value) use ($service) {
            $service->updated_meta[] = [(int) $id, $key, $value];
            return true;
        });
        Functions\when('delete_post_meta')->alias(function ($id, $key) use ($service) {
            $service->deleted_meta[] = [(int) $id, $key];
            return true;
        });
        Functions\when('get_option')->alias(function ($key) use ($service) {
            return $service->options[$key] ?? false;
        });
        Functions\when('update_option')->alias(function ($key, $value) use ($service) {
            $service->options[$key] = $value;
            return true;
        });

        $this->transient_value = false;
        $this->options = [];

        $this->service = new VenueSuburbService();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function venue_meta(int $venue_id, array $map_field): void
    {
        $this->post_meta[$venue_id]['_venue_address'] = $map_field;
    }

    private function make_map_field(string $city): array
    {
        return [
            'address' => '1 Main St, ' . $city . ' NSW 2750, Australia',
            'city'    => $city,
            'state'   => 'New South Wales',
            'lat'     => '-33.75',
            'lng'     => '150.69',
        ];
    }

    // ============================================================
    // Suburb extraction
    // ============================================================

    public function test_suburb_for_venue_extracts_city_from_map_field(): void
    {
        $this->venue_meta(55, $this->make_map_field('Penrith'));

        $this->assertSame('Penrith', $this->service->suburb_for_venue(55));
    }

    public function test_suburb_for_venue_empty_without_map_field(): void
    {
        $this->assertSame('', $this->service->suburb_for_venue(999));
    }

    public function test_suburb_from_address_ignores_non_array_input(): void
    {
        $this->assertSame('', $this->service->suburb_from_address('1 Main St'));
        $this->assertSame('', $this->service->suburb_from_address(null));
    }

    // ============================================================
    // Event meta writes
    // ============================================================

    public function test_write_event_suburb_updates_meta(): void
    {
        $this->service->write_event_suburb(7, 'Penrith');

        $this->assertSame([[7, '_event_venue_suburb', 'Penrith']], $this->updated_meta);
        $this->assertSame([], $this->deleted_meta);
    }

    public function test_write_event_suburb_deletes_meta_when_empty(): void
    {
        $this->service->write_event_suburb(7, '');

        $this->assertSame([[7, '_event_venue_suburb']], $this->deleted_meta);
        $this->assertSame([], $this->updated_meta);
    }

    // ============================================================
    // Venue propagation
    // ============================================================

    public function test_propagate_venue_suburb_updates_all_referencing_events(): void
    {
        $this->venue_meta(55, $this->make_map_field('Penrith'));
        $this->wpdb->col_results['__default__'] = ['11', '12'];

        $updated = $this->service->propagate_venue_suburb(55);

        $this->assertSame(2, $updated);
        $this->assertSame(
            [
                [11, '_event_venue_suburb', 'Penrith'],
                [12, '_event_venue_suburb', 'Penrith'],
            ],
            $this->updated_meta
        );
        $this->assertSame(1, $this->cache_flushes);
    }

    // ============================================================
    // acf/save_post handling
    // ============================================================

    public function test_handle_acf_save_updates_event_suburb(): void
    {
        $this->post_types[7] = 'hmw_event';
        $this->post_types[55] = 'event_location';
        $this->post_meta[7]['_event_venue'] = '55';
        $this->venue_meta(55, $this->make_map_field('Castle Hill'));

        $this->service->handle_acf_save(7);

        $this->assertSame([[7, '_event_venue_suburb', 'Castle Hill']], $this->updated_meta);
        $this->assertSame(1, $this->cache_flushes);
    }

    public function test_handle_acf_save_propagates_venue_to_events(): void
    {
        $this->post_types[55] = 'event_location';
        $this->venue_meta(55, $this->make_map_field('Penrith'));
        $this->wpdb->col_results['__default__'] = ['11'];

        $this->service->handle_acf_save(55);

        $this->assertSame([[11, '_event_venue_suburb', 'Penrith']], $this->updated_meta);
    }

    public function test_handle_acf_save_ignores_non_numeric_and_other_post_types(): void
    {
        $this->post_types[99] = 'post';

        $this->service->handle_acf_save('options');
        $this->service->handle_acf_save(0);
        $this->service->handle_acf_save(99);
        $this->service->handle_acf_save(12345);

        $this->assertSame([], $this->updated_meta);
        $this->assertSame([], $this->deleted_meta);
        $this->assertSame(0, $this->cache_flushes);
    }

    // ============================================================
    // Suburb list + caching
    // ============================================================

    public function test_get_available_suburbs_returns_cached_list_without_query(): void
    {
        $this->transient_value = ['Penrith', 'Castle Hill'];

        $suburbs = $this->service->get_available_suburbs();

        $this->assertSame(['Penrith', 'Castle Hill'], $suburbs);
        $this->assertSame([], $this->wpdb->sql_log);
        $this->assertSame([], $this->set_transient_calls);
    }

    public function test_get_available_suburbs_queries_and_caches_when_miss(): void
    {
        $this->wpdb->col_results['__default__'] = ['Penrith', 'Castle Hill', ''];

        $suburbs = $this->service->get_available_suburbs();

        $this->assertSame(['Penrith', 'Castle Hill'], $suburbs);
        $this->assertCount(1, $this->set_transient_calls);
        [$key, $value, $ttl] = $this->set_transient_calls[0];
        $this->assertSame('hmwevents_venue_suburbs', $key);
        $this->assertSame(['Penrith', 'Castle Hill'], $value);
        $this->assertSame(12 * HOUR_IN_SECONDS, $ttl);
    }

    public function test_flush_cache_deletes_transient(): void
    {
        $this->service->flush_cache();

        $this->assertSame(['hmwevents_venue_suburbs'], $this->deleted_transients);
    }

    // ============================================================
    // Upgrade routine
    // ============================================================

    public function test_upgrade_routine_backfills_and_sets_flag(): void
    {
        $this->post_types[7] = 'hmw_event';
        $this->post_types[55] = 'event_location';
        $this->post_meta[7]['_event_venue'] = '55';
        $this->venue_meta(55, $this->make_map_field('Penrith'));
        $this->wpdb->col_results['__default__'] = ['7'];

        $this->service->run_upgrade_routine();

        $this->assertSame([[7, '_event_venue_suburb', 'Penrith']], $this->updated_meta);
        $this->assertSame(1, $this->options['hmwevents_venue_suburbs_migrated'] ?? null);
    }

    public function test_upgrade_routine_short_circuits_when_flag_set(): void
    {
        $this->options['hmwevents_venue_suburbs_migrated'] = 1;

        $this->service->run_upgrade_routine();

        $this->assertSame([], $this->updated_meta);
        $this->assertSame([], $this->wpdb->sql_log);
    }
}
