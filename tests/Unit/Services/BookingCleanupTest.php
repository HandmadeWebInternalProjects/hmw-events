<?php
/**
 * Tests for Booking Cleanup Service.
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\BookingCleanup;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Patchwork;

/**
 * Test Booking Cleanup Service functionality.
 */
class BookingCleanupTest extends TestCase
{
    /**
     * Service instance.
     *
     * @var BookingCleanup
     */
    private $service;

    /**
     * Mock wpdb instance.
     *
     * @var Mockery\MockInterface
     */
    private $wpdb;

    /**
     * Set up test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Create wpdb mock before constructing the service (constructor resolves table names)
        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $this->wpdb;

        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        $this->service = new BookingCleanup();

        // Mock common functions
        Functions\when('error_log')->justReturn(true);
        Functions\when('current_time')->alias(function ($type) {
            return $type === 'mysql' ? '2025-01-15 10:00:00' : strtotime('2025-01-15 10:00:00');
        });
    }

    /**
     * Tear down test environment.
     */
    protected function tearDown(): void
    {
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Test service registration hooks.
     *
     * @covers \HMWEvents\Services\BookingCleanup::register
     */
    public function test_register_adds_hooks()
    {
        Functions\expect('add_action')
            ->times(3)
            ->with(Mockery::anyOf('init', BookingCleanup::CRON_HOOK, BookingCleanup::PII_CRON_HOOK), Mockery::type('array'));

        $this->service->register();

        $this->assertTrue(true);
    }

    /**
     * Test scheduling cleanup when not already scheduled.
     *
     * @covers \HMWEvents\Services\BookingCleanup::schedule_cleanup
     */
    public function test_schedule_cleanup_creates_schedule()
    {
        Functions\expect('wp_next_scheduled')
            ->times(2)
            ->andReturnUsing(function ($hook) {
                return $hook === BookingCleanup::PII_CRON_HOOK ? false : false;
            });

        Functions\expect('strtotime')
            ->times(2)
            ->andReturn(1234567890);

        Functions\expect('wp_schedule_event')
            ->times(2)
            ->andReturn(true);

        $this->service->schedule_cleanup();

        $this->assertTrue(true);
    }

    /**
     * Test scheduling cleanup when already scheduled.
     *
     * @covers \HMWEvents\Services\BookingCleanup::schedule_cleanup
     */
    public function test_schedule_cleanup_skips_if_already_scheduled()
    {
        Functions\expect('wp_next_scheduled')
            ->times(2)
            ->andReturn(1234567890);

        Functions\expect('wp_schedule_event')
            ->never();

        $this->service->schedule_cleanup();

        $this->assertTrue(true);
    }

    /**
     * Test clearing scheduled cleanup.
     *
     * @covers \HMWEvents\Services\BookingCleanup::clear_schedule
     */
    public function test_clear_schedule_removes_scheduled_event()
    {
        $timestamp = 1234567890;

        Functions\expect('wp_next_scheduled')
            ->times(2)
            ->andReturn($timestamp);

        Functions\expect('wp_unschedule_event')
            ->times(2)
            ->andReturn(true);

        $this->service->clear_schedule();

        $this->assertTrue(true);
    }

    /**
     * Test clearing schedule when no event scheduled.
     *
     * @covers \HMWEvents\Services\BookingCleanup::clear_schedule
     */
    public function test_clear_schedule_handles_no_scheduled_event()
    {
        Functions\expect('wp_next_scheduled')
            ->times(2)
            ->andReturn(false);

        Functions\expect('wp_unschedule_event')
            ->never();

        $this->service->clear_schedule();

        $this->assertTrue(true);
    }

    /**
     * Test cleanup with no abandoned bookings.
     *
     * @covers \HMWEvents\Services\BookingCleanup::cleanup_abandoned_bookings
     */
    public function test_cleanup_with_no_abandoned_bookings()
    {
        Functions\expect('gmdate')
            ->once()
            ->with('Y-m-d H:i:s', Mockery::type('int'))
            ->andReturn('2025-01-14 10:00:00');

        Functions\expect('strtotime')
            ->once()
            ->with('-24 hours')
            ->andReturn(1234567890);

        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_QUERY');

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->with('PREPARED_QUERY')
            ->andReturn([]);

        $this->service->cleanup_abandoned_bookings();

        // Should log message about no bookings
        $this->assertTrue(true);
    }

    /**
     * Test cleanup of abandoned bookings.
     *
     * @covers \HMWEvents\Services\BookingCleanup::cleanup_abandoned_bookings
     */
    public function test_cleanup_processes_abandoned_bookings()
    {
        Functions\expect('gmdate')
            ->once()
            ->andReturn('2025-01-14 10:00:00');

        Functions\expect('strtotime')
            ->once()
            ->andReturn(1234567890);

        // Mock finding abandoned booking groups
        $abandoned_groups = [
            (object) ['id' => 1, 'customer_post_id' => 100],
            (object) ['id' => 2, 'customer_post_id' => 101],
        ];

        $this->wpdb->shouldReceive('prepare')
            ->andReturn('PREPARED_QUERY');

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($abandoned_groups);

        // Mock getting bookings for group 1
        $bookings_group_1 = [
            (object) [
                'id' => 10,
                'event_post_id' => 200,
                'ticket_quantity' => 2,
                'status' => 'pending',
            ],
        ];

        // Mock getting bookings for group 2
        $bookings_group_2 = [
            (object) [
                'id' => 11,
                'event_post_id' => 201,
                'ticket_quantity' => 1,
                'status' => 'pending',
            ],
        ];

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($bookings_group_1);

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($bookings_group_2);

        // Mock update operations
        $this->wpdb->shouldReceive('update')
            ->times(4); // 2 group updates + 2 booking updates

        $this->wpdb->shouldReceive('query')
            ->times(2); // 2 availability updates

        $this->wpdb->shouldReceive('insert')
            ->times(2); // 2 history entries

        $this->service->cleanup_abandoned_bookings();

        $this->assertTrue(true);
    }

    /**
     * Test cleanup updates booking group status.
     *
     * @covers \HMWEvents\Services\BookingCleanup::cleanup_abandoned_bookings
     */
    public function test_cleanup_updates_booking_group_status()
    {
        Functions\expect('gmdate')->andReturn('2025-01-14 10:00:00');
        Functions\expect('strtotime')->andReturn(1234567890);

        $abandoned_groups = [
            (object) ['id' => 1, 'customer_post_id' => 100],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturn('PREPARED_QUERY');
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($abandoned_groups);

        $bookings = [
            (object) [
                'id' => 10,
                'event_post_id' => 200,
                'ticket_quantity' => 2,
                'status' => 'pending',
            ],
        ];

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($bookings);

        // Assert booking group status is updated to 'cancelled'
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_hmwevents_booking_groups',
                ['payment_status' => 'cancelled'],
                ['id' => 1],
                ['%s'],
                ['%d']
            )
            ->andReturn(1);

        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $this->wpdb->shouldReceive('query')->once()->andReturn(1);
        $this->wpdb->shouldReceive('insert')->once()->andReturn(true);

        $this->service->cleanup_abandoned_bookings();

        $this->assertTrue(true);
    }

    /**
     * Test cleanup restores course availability.
     *
     * @covers \HMWEvents\Services\BookingCleanup::cleanup_abandoned_bookings
     */
    public function test_cleanup_restores_course_availability()
    {
        Functions\expect('gmdate')->andReturn('2025-01-14 10:00:00');
        Functions\expect('strtotime')->andReturn(1234567890);

        $abandoned_groups = [
            (object) ['id' => 1, 'customer_post_id' => 100],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturn('PREPARED_QUERY');
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($abandoned_groups);

        $bookings = [
            (object) [
                'id' => 10,
                'event_post_id' => 200,
                'ticket_quantity' => 3,
                'status' => 'pending',
            ],
        ];

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($bookings);

        $this->wpdb->shouldReceive('update')->twice()->andReturn(1);
        $this->wpdb->shouldReceive('insert')->once()->andReturn(true);

        // Assert availability is restored (query receives the prepared string)
        $this->wpdb->shouldReceive('query')
            ->once()
            ->with('PREPARED_QUERY')
            ->andReturn(1);

        $this->service->cleanup_abandoned_bookings();

        $this->assertTrue(true);
    }

    /**
     * Test cleanup adds booking history entries.
     *
     * @covers \HMWEvents\Services\BookingCleanup::cleanup_abandoned_bookings
     */
    public function test_cleanup_adds_booking_history()
    {
        Functions\expect('gmdate')->andReturn('2025-01-14 10:00:00');
        Functions\expect('strtotime')->andReturn(1234567890);

        $abandoned_groups = [
            (object) ['id' => 1, 'customer_post_id' => 100],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturn('PREPARED_QUERY');
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($abandoned_groups);

        $bookings = [
            (object) [
                'id' => 10,
                'event_post_id' => 200,
                'ticket_quantity' => 2,
                'status' => 'pending',
            ],
        ];

        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($bookings);

        $this->wpdb->shouldReceive('update')->twice()->andReturn(1);
        $this->wpdb->shouldReceive('query')->once()->andReturn(1);

        // Assert history entry is created
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                'wp_hmwevents_booking_history',
                [
                    'booking_id' => 10,
                    'previous_status' => 'pending',
                    'new_status' => 'cancelled',
                    'change_reason' => 'Automatic cancellation - payment not completed within 24 hours',
                    'created_at' => '2025-01-15 10:00:00',
                ],
                ['%d', '%s', '%s', '%s', '%s']
            )
            ->andReturn(true);

        $this->assertTrue(true);

        $this->service->cleanup_abandoned_bookings();
    }

    /**
     * Test manual cleanup triggers cleanup process.
     *
     * @covers \HMWEvents\Services\BookingCleanup::manual_cleanup
     */
    public function test_manual_cleanup_triggers_cleanup()
    {
        Functions\expect('gmdate')->andReturn('2025-01-14 10:00:00');
        Functions\expect('strtotime')->andReturn(1234567890);

        $this->wpdb->shouldReceive('prepare')->andReturn('PREPARED_QUERY');
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        $this->service->manual_cleanup();

        // Should trigger cleanup_abandoned_bookings
        $this->assertTrue(true);
    }

    /**
     * Test cleanup skips bookings already cancelled.
     *
     * @covers \HMWEvents\Services\BookingCleanup::cleanup_abandoned_bookings
     */
    public function test_cleanup_skips_cancelled_bookings()
    {
        Functions\expect('gmdate')->andReturn('2025-01-14 10:00:00');
        Functions\expect('strtotime')->andReturn(1234567890);

        $abandoned_groups = [
            (object) ['id' => 1, 'customer_post_id' => 100],
        ];

        $this->wpdb->shouldReceive('prepare')->andReturn('PREPARED_QUERY');
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn($abandoned_groups);

        // All bookings already cancelled
        $this->wpdb->shouldReceive('get_results')
            ->once()
            ->andReturn([]);

        // Should not update anything
        $this->wpdb->shouldReceive('update')->never();
        $this->wpdb->shouldReceive('query')->never();
        $this->wpdb->shouldReceive('insert')->never();

        $this->assertTrue(true);

        $this->service->cleanup_abandoned_bookings();
    }
}
