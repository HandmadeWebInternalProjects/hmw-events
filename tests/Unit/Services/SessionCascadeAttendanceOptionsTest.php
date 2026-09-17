<?php

namespace HMWEvents\Tests\Unit\Services;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HMWEvents\Services\SessionService;
use PHPUnit\Framework\TestCase;
use Patchwork;

class SessionCascadeAttendanceOptionsTest extends TestCase
{
    private object $wpdb;

    /**
     * Args of every get_posts() call, in order. cascade_to_children() issues
     * two shapes: get_sessions() (has post_parent) and get_cloned_sessions()
     * (has meta_query).
     */
    private array $get_posts_calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        Patchwork\replace(
            'HMWEvents\Services\DatabaseService::get_table_name',
            function ($name) {
                return 'wp_hmwevents_' . $name;
            }
        );

        $this->wpdb = new class {
            public string $prefix = 'wp_';
            public array $parent_rows_by_post = [];
            public array $child_rows_by_post = [];
            public array $selects = [];
            public array $inserts = [];
            public array $updates = [];
            public array $deletes = [];

            public function prepare($query, ...$args)
            {
                foreach ($args as $arg) {
                    $query = preg_replace('/%[dsf]/', (string) $arg, $query, 1);
                }

                return $query;
            }

            public function get_results($query)
            {
                $this->selects[] = $query;
                $post_id = $this->post_id_from($query);

                if (str_contains($query, 'ORDER BY')) {
                    return $this->parent_rows_by_post[$post_id] ?? [];
                }

                return $this->child_rows_by_post[$post_id] ?? [];
            }

            private function post_id_from(string $query): int
            {
                preg_match('/event_post_id = (\d+)/', $query, $m);

                return (int) ($m[1] ?? 0);
            }

            public function insert($table, array $data, array $formats = [])
            {
                $this->inserts[] = ['table' => $table, 'data' => $data, 'formats' => $formats];

                return 1;
            }

            public function update($table, array $data, array $where, array $formats = [], array $where_formats = [])
            {
                $this->updates[] = [
                    'table'         => $table,
                    'data'          => $data,
                    'where'         => $where,
                    'formats'       => $formats,
                    'where_formats' => $where_formats,
                ];

                return 1;
            }

            public function delete($table, $where, $formats = [])
            {
                $this->deletes[] = ['table' => $table, 'where' => $where, 'formats' => $formats];

                return 1;
            }

            public function esc_like($text)
            {
                return addcslashes((string) $text, '_%\\');
            }

            public function get_col($query = null)
            {
                return [];
            }
        };
        $GLOBALS['wpdb'] = $this->wpdb;

        Functions\when('get_post_meta')->justReturn('');
        Functions\when('update_post_meta')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('__')->returnArg();
        Functions\when('_n')->returnArg();
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Helpers
    // ============================================================

    private function set_children(array $ids, array $clone_ids = []): void
    {
        $children = array_map(static fn ($id) => (object) ['ID' => $id], $ids);
        $clones   = array_map(static fn ($id) => (object) ['ID' => $id], $clone_ids);
        $this->get_posts_calls = [];

        Functions\when('get_posts')->alias(function (array $args = []) use ($children, $clones) {
            $this->get_posts_calls[] = $args;

            return array_key_exists('meta_query', $args) ? $clones : $children;
        });
    }

    private function parent_row(array $overrides = []): object
    {
        return (object) array_merge([
            'option_type'   => 'individual',
            'option_key'    => 'two-day',
            'label'         => 'Two-Day',
            'description'   => '<ul><li>x</li></ul>',
            'price'         => 120.0,
            'price_mode'    => 'flat',
            'pricing_rules' => null,
            'capacity'      => 20,
            'sort_order'    => 1,
            'is_active'     => 1,
        ], $overrides);
    }

    private function child_row(int $id, string $option_key, string $option_type = 'individual'): object
    {
        return (object) ['id' => $id, 'option_key' => $option_key, 'option_type' => $option_type];
    }

    private function legacy_couple_parent_row(): object
    {
        return $this->parent_row([
            'option_type'   => 'couple',
            'option_key'    => '',
            'label'         => 'Couple',
            'description'   => '',
            'price'         => 650.0,
            'pricing_rules' => null,
            'capacity'      => null,
            'sort_order'    => 2,
        ]);
    }

    private function run_cascade(array $changes = ['event_venue' => 9]): int
    {
        return (new SessionService())->cascade_to_children(100, $changes);
    }

    // ============================================================
    // SessionService::cascade_to_children() — attendance options
    // ============================================================

    public function test_cascade_inserts_parent_rows_for_child_without_existing_rows(): void
    {
        $this->set_children([201]);
        $this->wpdb->parent_rows_by_post[100] = [
            $this->parent_row(),
            $this->legacy_couple_parent_row(),
        ];

        $result = $this->run_cascade();

        $this->assertSame(1, $result);
        $this->assertCount(2, $this->wpdb->inserts);
        $this->assertSame([], $this->wpdb->updates);
        $this->assertSame([], $this->wpdb->deletes);

        $first = $this->wpdb->inserts[0];
        $this->assertSame('wp_hmwevents_event_attendance_options', $first['table']);
        $this->assertSame(201, $first['data']['event_post_id']);
        $this->assertSame('two-day', $first['data']['option_key']);
        $this->assertSame('individual', $first['data']['option_type']);
        $this->assertSame('Two-Day', $first['data']['label']);
        $this->assertSame('<ul><li>x</li></ul>', $first['data']['description']);
        $this->assertSame(120.0, $first['data']['price']);
        $this->assertSame('flat', $first['data']['price_mode']);
        $this->assertNull($first['data']['pricing_rules']);
        $this->assertSame(20, $first['data']['capacity']);
        $this->assertSame(1, $first['data']['sort_order']);
        $this->assertSame(1, $first['data']['is_active']);
        $this->assertSame('2026-01-01 00:00:00', $first['data']['updated_at']);
        $this->assertSame('2026-01-01 00:00:00', $first['data']['created_at']);
        $this->assertSame(
            ['%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s'],
            $first['formats']
        );

        $second = $this->wpdb->inserts[1];
        $this->assertSame('wp_hmwevents_event_attendance_options', $second['table']);
        $this->assertSame(201, $second['data']['event_post_id']);
        $this->assertSame('couple', $second['data']['option_key'], 'legacy row without option_key falls back to option_type');
        $this->assertSame('couple', $second['data']['option_type']);
        $this->assertSame('Couple', $second['data']['label']);
        $this->assertSame(650.0, $second['data']['price']);
        $this->assertNull($second['data']['capacity']);
        $this->assertSame(2, $second['data']['sort_order']);
        $this->assertSame(1, $second['data']['is_active']);
    }

    public function test_cascade_updates_existing_child_row_by_key_and_never_deletes(): void
    {
        $this->set_children([202]);
        $this->wpdb->parent_rows_by_post[100] = [
            $this->parent_row(),
            $this->legacy_couple_parent_row(),
        ];
        $this->wpdb->child_rows_by_post[202] = [
            $this->child_row(77, 'two-day'),
        ];

        $result = $this->run_cascade();

        $this->assertSame(1, $result);

        $this->assertCount(1, $this->wpdb->updates);
        $update = $this->wpdb->updates[0];
        $this->assertSame('wp_hmwevents_event_attendance_options', $update['table']);
        $this->assertSame(['id' => 77], $update['where']);
        $this->assertSame(['%d'], $update['where_formats']);
        $this->assertSame(
            [
                'option_type'   => 'individual',
                'label'         => 'Two-Day',
                'description'   => '<ul><li>x</li></ul>',
                'price'         => 120.0,
                'price_mode'    => 'flat',
                'pricing_rules' => null,
                'capacity'      => 20,
                'sort_order'    => 1,
                'is_active'     => 1,
                'updated_at'    => '2026-01-01 00:00:00',
            ],
            $update['data']
        );
        $this->assertSame(
            ['%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%d', '%s'],
            $update['formats']
        );

        $this->assertCount(1, $this->wpdb->inserts, 'only the unmatched legacy couple row is inserted');
        $this->assertSame('couple', $this->wpdb->inserts[0]['data']['option_key']);
        $this->assertSame(202, $this->wpdb->inserts[0]['data']['event_post_id']);

        $this->assertSame([], $this->wpdb->deletes, 'cascade must never delete child rows');
    }

    public function test_cascade_deactivates_child_rows_missing_from_parent(): void
    {
        $this->set_children([202]);
        $this->wpdb->parent_rows_by_post[100] = [
            $this->parent_row(),
        ];
        $this->wpdb->child_rows_by_post[202] = [
            $this->child_row(77, 'two-day'),
            $this->child_row(88, 'extra', 'professional'),
        ];

        $result = $this->run_cascade();

        $this->assertSame(1, $result);
        $this->assertCount(2, $this->wpdb->updates, 'matching row updated with parent values, orphan row deactivated');

        $this->assertSame(['id' => 77], $this->wpdb->updates[0]['where']);
        $this->assertSame('Two-Day', $this->wpdb->updates[0]['data']['label']);
        $this->assertSame(120.0, $this->wpdb->updates[0]['data']['price']);

        $deactivation = $this->wpdb->updates[1];
        $this->assertSame('wp_hmwevents_event_attendance_options', $deactivation['table']);
        $this->assertSame(
            ['is_active' => 0, 'updated_at' => '2026-01-01 00:00:00'],
            $deactivation['data'],
            'orphan row is only deactivated, never rewritten with parent values'
        );
        $this->assertSame(['id' => 88, 'is_active' => 1], $deactivation['where']);
        $this->assertSame(['%d', '%s'], $deactivation['formats']);
        $this->assertSame(['%d', '%d'], $deactivation['where_formats']);

        $this->assertSame([], $this->wpdb->inserts, 'orphan row is not re-inserted with parent values');
        $this->assertSame([], $this->wpdb->deletes, 'orphan row is never deleted so booking references survive');
    }

    public function test_cascade_returns_early_when_parent_has_no_rows(): void
    {
        $this->set_children([201, 202]);

        $result = $this->run_cascade();

        $this->assertSame(2, $result);
        $this->assertCount(1, $this->wpdb->selects, 'only the parent SELECT runs; no per-child queries');
        $this->assertStringContainsString('wp_hmwevents_event_attendance_options', $this->wpdb->selects[0]);
        $this->assertStringContainsString('event_post_id = 100', $this->wpdb->selects[0]);
        $this->assertSame([], $this->wpdb->inserts);
        $this->assertSame([], $this->wpdb->updates);
        $this->assertSame([], $this->wpdb->deletes);
    }

    public function test_cascade_includes_standalone_clone_sessions(): void
    {
        $this->set_children([201], [301]);
        $this->wpdb->parent_rows_by_post[100] = [
            $this->parent_row(),
        ];

        $result = $this->run_cascade();

        $this->assertSame(2, $result, 'post_parent child and _cloned_from clone both count as updated children');

        $this->assertCount(2, $this->get_posts_calls, 'one lookup for post_parent children, one for _cloned_from clones');
        $this->assertSame(100, $this->get_posts_calls[0]['post_parent']);
        $this->assertSame('_cloned_from', $this->get_posts_calls[1]['meta_query'][0]['key']);
        $this->assertSame('100', $this->get_posts_calls[1]['meta_query'][0]['value']);

        $this->assertCount(2, $this->wpdb->inserts, 'both children receive the parent rows');
        $this->assertSame(201, $this->wpdb->inserts[0]['data']['event_post_id']);
        $this->assertSame('two-day', $this->wpdb->inserts[0]['data']['option_key']);
        $this->assertSame(301, $this->wpdb->inserts[1]['data']['event_post_id']);
        $this->assertSame('two-day', $this->wpdb->inserts[1]['data']['option_key']);
        $this->assertSame([], $this->wpdb->updates);
        $this->assertSame([], $this->wpdb->deletes);
    }

    public function test_sync_attendance_options_to_mirrors_parent_rows_onto_single_session(): void
    {
        $this->set_children([999]);
        $this->wpdb->parent_rows_by_post[100] = [
            $this->parent_row(),
            $this->legacy_couple_parent_row(),
        ];

        (new SessionService())->sync_attendance_options_to(100, 205);

        $this->assertSame([], $this->get_posts_calls, 'single-session sync never fetches child lists');
        $this->assertCount(2, $this->wpdb->selects, 'parent SELECT plus one SELECT for the target session');
        $this->assertCount(2, $this->wpdb->inserts, 'one insert per parent row');
        $this->assertSame('two-day', $this->wpdb->inserts[0]['data']['option_key']);
        $this->assertSame(205, $this->wpdb->inserts[0]['data']['event_post_id']);
        $this->assertSame('couple', $this->wpdb->inserts[1]['data']['option_key'], 'legacy key fallback applies on sync too');
        $this->assertSame(205, $this->wpdb->inserts[1]['data']['event_post_id']);
        $this->assertSame([], $this->wpdb->updates);
        $this->assertSame([], $this->wpdb->deletes);
    }
}
