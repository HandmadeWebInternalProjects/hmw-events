<?php

namespace HMWEvents\Tests\Unit\Admin;

use HMWEvents\Admin\EventListColumns;
use HMWEvents\PostTypes\Event;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

class EventListColumnsTest extends TestCase
{
    private EventListColumns $columns;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        Functions\when('__')->returnArg();
        Functions\when('esc_attr')->returnArg();

        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive('prepare')->andReturnUsing(function ($query) {
            return $query;
        });
        $wpdb->shouldReceive('get_results')->andReturn([])->byDefault();

        $GLOBALS['wpdb'] = $wpdb;

        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        $this->columns = new EventListColumns();
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wp_query']);
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function stub_main_query(array $post_ids): void
    {
        $GLOBALS['wp_query'] = (object) [
            'posts' => array_map(
                fn($id) => new \WP_Post((object) ['ID' => $id]),
                $post_ids
            ),
        ];
    }

    private function stub_event_capacity($capacity): void
    {
        Functions\when('get_post')->justReturn(new \WP_Post((object) [
            'ID'        => 10,
            'post_type' => Event::POST_TYPE,
        ]));
        Functions\when('get_post_meta')->alias(function ($post_id, $key, $single) use ($capacity) {
            return $key === '_event_capacity' ? $capacity : '';
        });
    }

    private function stub_counts(array $counts): void
    {
        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturnUsing(function () use ($counts) {
            $rows = [];
            foreach ($counts as $id => $booked) {
                $rows[] = (object) ['event_post_id' => $id, 'booked_people' => $booked];
            }
            return $rows;
        });
    }

    public function test_register_hooks_list_table_filters(): void
    {
        Functions\expect('add_filter')
            ->once()
            ->with('manage_hmw_event_posts_columns', [$this->columns, 'add_bookings_column'])
            ->andReturn(true);
        Functions\expect('add_action')
            ->once()
            ->with('manage_hmw_event_posts_custom_column', [$this->columns, 'render_bookings_column'], 10, 2)
            ->andReturn(true);

        $this->columns->register();

        $this->addToAssertionCount(1);
    }

    public function test_add_bookings_column_inserts_after_title(): void
    {
        $columns = $this->columns->add_bookings_column([
            'cb'    => '<input type="checkbox" />',
            'title' => 'Title',
            'date'  => 'Date',
        ]);

        $this->assertSame(['cb', 'title', 'hmwevents_bookings', 'date'], array_keys($columns));
        $this->assertSame('Bookings', $columns['hmwevents_bookings']);
        $this->assertSame('Title', $columns['title']);
        $this->assertSame('Date', $columns['date']);
    }

    public function test_add_bookings_column_appends_when_no_title(): void
    {
        $columns = $this->columns->add_bookings_column([
            'cb'     => '<input type="checkbox" />',
            'author' => 'Author',
        ]);

        $this->assertSame(['cb', 'author', 'hmwevents_bookings'], array_keys($columns));
        $this->assertSame('Bookings', $columns['hmwevents_bookings']);
    }

    public function test_render_ignores_other_columns(): void
    {
        ob_start();
        $this->columns->render_bookings_column('title', 10);

        $this->assertSame('', ob_get_clean());
    }

    public function test_render_shows_dash_without_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        ob_start();
        $this->columns->render_bookings_column('hmwevents_bookings', 10);

        $this->assertSame('<span aria-hidden="true">—</span>', ob_get_clean());
    }

    public function test_render_shows_booked_of_capacity(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $this->stub_event_capacity('20');
        $this->stub_counts([10 => 12]);
        $this->stub_main_query([10]);

        ob_start();
        $this->columns->render_bookings_column('hmwevents_bookings', 10);
        $output = ob_get_clean();

        $this->assertStringContainsString('<strong>12</strong> / 20', $output);
        $this->assertStringContainsString('class="hmwevents-capacity-badge"', $output);
        $this->assertStringNotContainsString('hmwevents-capacity-badge--full', $output);
        $this->assertStringNotContainsString('hmwevents-capacity-badge--nearly-full', $output);
    }

    public function test_render_marks_full_when_capacity_reached(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $this->stub_event_capacity('20');
        $this->stub_counts([10 => 22]);
        $this->stub_main_query([10]);

        ob_start();
        $this->columns->render_bookings_column('hmwevents_bookings', 10);
        $output = ob_get_clean();

        $this->assertStringContainsString('hmwevents-capacity-badge--full', $output);
    }

    public function test_render_marks_nearly_full_within_ten_percent(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $this->stub_event_capacity('20');
        $this->stub_counts([10 => 18]);
        $this->stub_main_query([10]);

        ob_start();
        $this->columns->render_bookings_column('hmwevents_bookings', 10);
        $output = ob_get_clean();

        $this->assertStringContainsString('hmwevents-capacity-badge--nearly-full', $output);
    }

    public function test_render_shows_booked_only_without_capacity(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $this->stub_event_capacity('');
        $this->stub_counts([10 => 3]);
        $this->stub_main_query([10]);

        ob_start();
        $this->columns->render_bookings_column('hmwevents_bookings', 10);

        $this->assertSame('<span class="hmwevents-capacity-badge">3</span>', ob_get_clean());
    }

    public function test_render_batches_counts_into_single_query(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $this->stub_event_capacity('20');
        $this->stub_main_query([10, 11]);

        $captured = null;
        $GLOBALS['wpdb']->shouldReceive('get_results')->once()->andReturnUsing(function ($sql) use (&$captured) {
            $captured = $sql;
            return [
                (object) ['event_post_id' => 10, 'booked_people' => '12'],
                (object) ['event_post_id' => 11, 'booked_people' => '19'],
            ];
        });

        ob_start();
        $this->columns->render_bookings_column('hmwevents_bookings', 10);
        ob_end_clean();
        ob_start();
        $this->columns->render_bookings_column('hmwevents_bookings', 11);
        ob_end_clean();

        $this->assertIsString($captured);
        $this->assertStringContainsString('IN (%d,%d)', $captured);
    }
}
