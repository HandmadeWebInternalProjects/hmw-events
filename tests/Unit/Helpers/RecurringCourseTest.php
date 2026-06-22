<?php
/**
 * Tests for RecurringCourse Helper.
 *
 * Covers recurrence pattern creation, date calculation for all 6 recurrence
 * types (daily, weekly, fortnightly, monthly, bimonthly, custom), instance
 * generation, series updates, deactivation, and deletion.
 *
 * @package HMWEvents\Tests\Unit\Helpers
 */

namespace HMWEvents\Tests\Unit\Helpers;

use HMWEvents\Helpers\RecurringCourse;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test RecurringCourse helper methods.
 */
class RecurringCourseTest extends TestCase
{
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

        // Mock wpdb
        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $this->wpdb->insert_id = 1;
        $GLOBALS['wpdb'] = $this->wpdb;

        // No default mocks — each test defines exactly what it needs.
        // (Same pattern as the existing CourseTest.)
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

    // ------------------------------------------------------------------
    //  Helper: invoke private static method via reflection
    // ------------------------------------------------------------------

    /**
     * Call a private static method on RecurringCourse.
     *
     * @param string $methodName
     * @param mixed  ...$args
     * @return mixed
     */
    private static function callPrivateStatic(string $methodName, ...$args)
    {
        $ref = new \ReflectionClass(RecurringCourse::class);
        $method = $ref->getMethod($methodName);
        $method->setAccessible(true);
        return $method->invokeArgs(null, $args);
    }

    // ------------------------------------------------------------------
    //  calculate_occurrences — DAILY
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_daily_recurrence_generates_correct_dates()
    {
        $pattern = (object) [
            'recurrence_type' => 'daily',
            'recurrence_days' => '',
            'start_date' => '2026-06-01',
            'end_date' => null,
            'max_occurrences' => 5,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(5, $dates);
        $this->assertEquals('2026-06-01 09:00:00', $dates[0]->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-06-02 09:00:00', $dates[1]->format('Y-m-d H:i:s'));
        $this->assertEquals('2026-06-05 09:00:00', $dates[4]->format('Y-m-d H:i:s'));
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_daily_recurrence_respects_end_date()
    {
        $pattern = (object) [
            'recurrence_type' => 'daily',
            'recurrence_days' => '',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-04', // end_date comparison is strict >, so need +1
            'max_occurrences' => 52,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        // With end_date 2026-06-04 00:00:00 and current at 2026-06-04 09:00:00,
        // the > check breaks, so we get: June 1, 2, 3
        $this->assertCount(3, $dates);
        $this->assertEquals('2026-06-01', $dates[0]->format('Y-m-d'));
        $this->assertEquals('2026-06-03', $dates[2]->format('Y-m-d'));
    }

    // ------------------------------------------------------------------
    //  calculate_occurrences — WEEKLY (same day)
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_weekly_same_day_recurrence_generates_correct_dates()
    {
        $pattern = (object) [
            'recurrence_type' => 'weekly',
            'recurrence_days' => '',
            'start_date' => '2026-06-01', // Monday
            'end_date' => null,
            'max_occurrences' => 4,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(4, $dates);
        $this->assertEquals('2026-06-01', $dates[0]->format('Y-m-d')); // Mon
        $this->assertEquals('2026-06-08', $dates[1]->format('Y-m-d')); // Mon
        $this->assertEquals('2026-06-15', $dates[2]->format('Y-m-d')); // Mon
        $this->assertEquals('2026-06-22', $dates[3]->format('Y-m-d')); // Mon
    }

    // ------------------------------------------------------------------
    //  calculate_occurrences — WEEKLY (specific days)
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_weekly_specific_days_recurrence()
    {
        // Days 1=Mon, 3=Wed, 5=Fri — starting from Monday
        $pattern = (object) [
            'recurrence_type' => 'weekly',
            'recurrence_days' => '1,3,5',
            'start_date' => '2026-06-01', // Monday
            'end_date' => null,
            'max_occurrences' => 6,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(6, $dates);
        // Week 1: Mon, Wed, Fri
        $this->assertEquals('2026-06-01', $dates[0]->format('Y-m-d')); // Mon
        $this->assertEquals('2026-06-03', $dates[1]->format('Y-m-d')); // Wed
        $this->assertEquals('2026-06-05', $dates[2]->format('Y-m-d')); // Fri
        // Week 2: Mon, Wed, Fri
        $this->assertEquals('2026-06-08', $dates[3]->format('Y-m-d')); // Mon
        $this->assertEquals('2026-06-10', $dates[4]->format('Y-m-d')); // Wed
        $this->assertEquals('2026-06-12', $dates[5]->format('Y-m-d')); // Fri
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_weekly_specific_days_starting_midweek()
    {
        // Days 1=Mon, 3=Wed, 5=Fri — starting from Wednesday
        $pattern = (object) [
            'recurrence_type' => 'weekly',
            'recurrence_days' => '1,3,5',
            'start_date' => '2026-06-03', // Wednesday
            'end_date' => null,
            'max_occurrences' => 4,
        ];
        $start_dt = new \DateTime('2026-06-03 10:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(4, $dates);
        // First week: Wed (3), Fri (5)
        $this->assertEquals('2026-06-03', $dates[0]->format('Y-m-d')); // Wed
        $this->assertEquals('2026-06-05', $dates[1]->format('Y-m-d')); // Fri
        // Second week: Mon (1), Wed (3)
        $this->assertEquals('2026-06-08', $dates[2]->format('Y-m-d')); // Mon
        $this->assertEquals('2026-06-10', $dates[3]->format('Y-m-d')); // Wed
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_weekly_specific_days_starting_friday()
    {
        // Days 1=Mon, 3=Wed, 5=Fri — starting from Friday
        $pattern = (object) [
            'recurrence_type' => 'weekly',
            'recurrence_days' => '1,3,5',
            'start_date' => '2026-06-05', // Friday
            'end_date' => null,
            'max_occurrences' => 3,
        ];
        $start_dt = new \DateTime('2026-06-05 10:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(3, $dates);
        // First: Fri (5)
        $this->assertEquals('2026-06-05', $dates[0]->format('Y-m-d'));
        // Next week: Mon, Wed
        $this->assertEquals('2026-06-08', $dates[1]->format('Y-m-d')); // Mon
        $this->assertEquals('2026-06-10', $dates[2]->format('Y-m-d')); // Wed
    }

    // ------------------------------------------------------------------
    //  calculate_occurrences — FORTNIGHTLY (same day)
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_fortnightly_same_day_recurrence()
    {
        $pattern = (object) [
            'recurrence_type' => 'fortnightly',
            'recurrence_days' => '',
            'start_date' => '2026-06-01', // Monday
            'end_date' => null,
            'max_occurrences' => 3,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(3, $dates);
        $this->assertEquals('2026-06-01', $dates[0]->format('Y-m-d'));
        $this->assertEquals('2026-06-15', $dates[1]->format('Y-m-d'));
        $this->assertEquals('2026-06-29', $dates[2]->format('Y-m-d'));
    }

    // ------------------------------------------------------------------
    //  calculate_occurrences — FORTNIGHTLY (specific days)
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_fortnightly_specific_days_recurrence()
    {
        // Days 1=Mon, 3=Wed — starting Monday
        $pattern = (object) [
            'recurrence_type' => 'fortnightly',
            'recurrence_days' => '1,3',
            'start_date' => '2026-06-01', // Monday
            'end_date' => null,
            'max_occurrences' => 4,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(4, $dates);
        // Fortnight 1: Mon, Wed
        $this->assertEquals('2026-06-01', $dates[0]->format('Y-m-d'));
        $this->assertEquals('2026-06-03', $dates[1]->format('Y-m-d'));
        // Fortnight 2: Mon, Wed
        $this->assertEquals('2026-06-15', $dates[2]->format('Y-m-d'));
        $this->assertEquals('2026-06-17', $dates[3]->format('Y-m-d'));
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_fortnightly_specific_days_starting_wednesday()
    {
        // Days 1=Mon, 3=Wed, 5=Fri — starting Wednesday
        $pattern = (object) [
            'recurrence_type' => 'fortnightly',
            'recurrence_days' => '1,3,5',
            'start_date' => '2026-06-03', // Wednesday
            'end_date' => null,
            'max_occurrences' => 3,
        ];
        $start_dt = new \DateTime('2026-06-03 10:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(3, $dates);
        // Fortnight 1: Wed, Fri
        $this->assertEquals('2026-06-03', $dates[0]->format('Y-m-d'));
        $this->assertEquals('2026-06-05', $dates[1]->format('Y-m-d'));
        // Fortnight 2: Mon
        $this->assertEquals('2026-06-15', $dates[2]->format('Y-m-d'));
    }

    // ------------------------------------------------------------------
    //  calculate_occurrences — MONTHLY
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_monthly_recurrence()
    {
        $pattern = (object) [
            'recurrence_type' => 'monthly',
            'recurrence_days' => '',
            'start_date' => '2026-06-01',
            'end_date' => null,
            'max_occurrences' => 3,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(3, $dates);
        $this->assertEquals('2026-06-01', $dates[0]->format('Y-m-d'));
        $this->assertEquals('2026-07-01', $dates[1]->format('Y-m-d'));
        $this->assertEquals('2026-08-01', $dates[2]->format('Y-m-d'));
    }

    // ------------------------------------------------------------------
    //  calculate_occurrences — BIMONTHLY
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_bimonthly_recurrence()
    {
        $pattern = (object) [
            'recurrence_type' => 'bimonthly',
            'recurrence_days' => '',
            'start_date' => '2026-06-01',
            'end_date' => null,
            'max_occurrences' => 3,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertCount(3, $dates);
        $this->assertEquals('2026-06-01', $dates[0]->format('Y-m-d'));
        $this->assertEquals('2026-08-01', $dates[1]->format('Y-m-d'));
        $this->assertEquals('2026-10-01', $dates[2]->format('Y-m-d'));
    }

    // ------------------------------------------------------------------
    //  calculate_occurrences — preserves time from start_dt
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_occurrences_preserve_template_start_time()
    {
        $pattern = (object) [
            'recurrence_type' => 'daily',
            'recurrence_days' => '',
            'start_date' => '2026-06-01',
            'end_date' => null,
            'max_occurrences' => 2,
        ];
        $start_dt = new \DateTime('2026-06-01 14:30:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        $this->assertEquals('14:30:00', $dates[0]->format('H:i:s'));
        $this->assertEquals('14:30:00', $dates[1]->format('H:i:s'));
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_occurrences
     */
    public function test_occurrences_stops_for_unknown_recurrence_type()
    {
        $pattern = (object) [
            'recurrence_type' => 'invalid_type',
            'recurrence_days' => '',
            'start_date' => '2026-06-01',
            'end_date' => null,
            'max_occurrences' => 10,
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        $dates = self::callPrivateStatic('calculate_occurrences', $pattern, $start_dt);

        // Should only produce the first occurrence then break
        $this->assertCount(1, $dates);
    }

    // ------------------------------------------------------------------
    //  calculate_custom_occurrences
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_custom_occurrences
     */
    public function test_custom_occurrences_from_acf_dates()
    {
        $pattern = (object) [
            'course_template_id' => 42,
            'recurrence_type' => 'custom',
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        // Mock get_post and get_field
        Functions\expect('get_post')
            ->once()
            ->with(42)
            ->andReturn((object) ['ID' => 42]);

        Functions\expect('get_field')
            ->once()
            ->with('course_custom_dates', 42)
            ->andReturn([
                ['date' => '2026-06-10'],
                ['date' => '2026-06-05'],
                ['date' => '2026-06-15'],
            ]);

        $dates = self::callPrivateStatic('calculate_custom_occurrences', $pattern, $start_dt);

        $this->assertCount(3, $dates);
        // Should be sorted
        $this->assertEquals('2026-06-05', $dates[0]->format('Y-m-d'));
        $this->assertEquals('2026-06-10', $dates[1]->format('Y-m-d'));
        $this->assertEquals('2026-06-15', $dates[2]->format('Y-m-d'));
        // Time should match template
        $this->assertEquals('09:00:00', $dates[0]->format('H:i:s'));
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_custom_occurrences
     */
    public function test_custom_occurrences_returns_empty_when_template_missing()
    {
        $pattern = (object) [
            'course_template_id' => 999,
            'recurrence_type' => 'custom',
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        Functions\expect('get_post')
            ->once()
            ->with(999)
            ->andReturn(null);

        $dates = self::callPrivateStatic('calculate_custom_occurrences', $pattern, $start_dt);

        $this->assertEmpty($dates);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::calculate_custom_occurrences
     */
    public function test_custom_occurrences_skips_empty_date_rows()
    {
        $pattern = (object) [
            'course_template_id' => 42,
            'recurrence_type' => 'custom',
        ];
        $start_dt = new \DateTime('2026-06-01 09:00:00');

        Functions\expect('get_post')
            ->once()
            ->andReturn((object) ['ID' => 42]);

        Functions\expect('get_field')
            ->once()
            ->andReturn([
                ['date' => '2026-06-10'],
                ['date' => ''],
                ['date' => '2026-06-15'],
            ]);

        $dates = self::callPrivateStatic('calculate_custom_occurrences', $pattern, $start_dt);

        $this->assertCount(2, $dates);
    }

    // ------------------------------------------------------------------
    //  create_recurring_series
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::create_recurring_series
     */
    public function test_create_recurring_series_success()
    {
        $template_post_id = 100;
        $config = [
            'type' => 'weekly',
            'days' => '2,4',
            'start_date' => '2026-06-02',
            'end_date' => '2026-06-30',
            'max_occurrences' => 8,
        ];

        // sanitize_title used inside wp_insert_post for post_name
        Functions\expect('sanitize_title')->zeroOrMoreTimes()->andReturnUsing(function ($title) {
            return strtolower(str_replace([' ', '_'], '-', $title));
        });

        // Validate template post
        Functions\expect('get_post')
            ->once()
            ->with(100)
            ->andReturn((object) [
                'ID' => 100,
                'post_type' => 'educator_course',
                'post_title' => 'Test Course',
                'post_content' => 'Course content',
                'post_author' => 1,
                'post_name' => 'test-course',
            ]);

        Functions\expect('update_post_meta')
            ->once()
            ->with(100, 'course_template_id', 100);

        // DB insert for recurrence pattern
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->andReturn(true);
        $this->wpdb->insert_id = 1;

        Functions\expect('update_post_meta')
            ->once()
            ->with(100, 'course_recurrence_id', 1);

        // Mock generate_instances internals
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_SQL');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'course_template_id' => 100,
                'recurrence_type' => 'weekly',
                'recurrence_days' => '2,4',
                'start_date' => '2026-06-02',
                'end_date' => '2026-06-30',
                'max_occurrences' => 8,
                'is_active' => 1,
            ]);

        // get_post for template (called again inside generate_instances)
        Functions\expect('get_post')
            ->once()
            ->with(100)
            ->andReturn((object) [
                'ID' => 100,
                'post_type' => 'educator_course',
                'post_title' => 'Test Course',
                'post_content' => 'Course content',
                'post_author' => 1,
                'post_name' => 'test-course',
            ]);

        // Template meta — use andReturnUsing to handle different arg patterns
        Functions\expect('get_post_meta')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () {
                $args = func_get_args();
                $id = $args[0] ?? 0;
                $key = $args[1] ?? null;
                $single = $args[2] ?? false;

                if ($id === 100 && $key === 'course_start_date') {
                    return '2026-06-02 09:00:00';
                }
                if ($id === 100 && $key === 'course_end_date') {
                    return '2026-06-02 17:00:00';
                }
                if ($id === 201 && $key === 'course_capacity') {
                    return '20';
                }
                // get_post_meta($id) with no key — returns all meta array
                if ($key === null) {
                    return ['course_capacity' => ['20'], 'course_full_cost' => ['450']];
                }
                return $single ? '' : [];
            });

        // Check for existing instances — called once per occurrence
        Functions\expect('get_posts')
            ->zeroOrMoreTimes()
            ->andReturn([]);

        // wp_insert_post for instance creation — called once per occurrence
        Functions\expect('wp_insert_post')
            ->zeroOrMoreTimes()
            ->andReturn(201);

        Functions\expect('update_post_meta')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        Functions\expect('maybe_unserialize')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function ($v) { return $v; });

        Functions\expect('get_object_taxonomies')
            ->zeroOrMoreTimes()
            ->andReturn(['category']);

        Functions\expect('wp_get_object_terms')
            ->zeroOrMoreTimes()
            ->with(100, 'category', ['fields' => 'ids'])
            ->andReturn([1, 2]);

        Functions\expect('wp_set_object_terms')
            ->zeroOrMoreTimes()
            ->with(201, [1, 2], 'category');

        $this->wpdb->shouldReceive('replace')
            ->zeroOrMoreTimes()
            ->andReturn(true);

        $result = RecurringCourse::create_recurring_series($template_post_id, $config);

        $this->assertEquals(1, $result);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::create_recurring_series
     */
    public function test_create_recurring_series_returns_false_for_invalid_post()
    {
        Functions\expect('get_post')
            ->once()
            ->with(999)
            ->andReturn(null);

        $result = RecurringCourse::create_recurring_series(999, []);

        $this->assertFalse($result);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::create_recurring_series
     */
    public function test_create_recurring_series_returns_false_for_wrong_post_type()
    {
        Functions\expect('get_post')
            ->once()
            ->with(100)
            ->andReturn((object) [
                'ID' => 100,
                'post_type' => 'post', // Not educator_course
            ]);

        $result = RecurringCourse::create_recurring_series(100, []);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    //  generate_instances — skips existing dates
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::generate_instances
     */
    public function test_generate_instances_skips_existing_dates()
    {
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_SQL');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) [
                'id' => 1,
                'course_template_id' => 100,
                'recurrence_type' => 'daily',
                'recurrence_days' => '',
                'start_date' => '2026-06-01',
                'end_date' => null,
                'max_occurrences' => 3,
                'is_active' => 1,
            ]);

        Functions\expect('get_post')
            ->once()
            ->with(100)
            ->andReturn((object) [
                'ID' => 100,
                'post_type' => 'educator_course',
                'post_title' => 'Test Course',
                'post_content' => 'Content',
                'post_author' => 1,
                'post_name' => 'test-course',
            ]);

        Functions\expect('get_post_meta')
            ->once()
            ->with(100, 'course_start_date', true)
            ->andReturn('2026-06-01 09:00:00');

        Functions\expect('get_post_meta')
            ->once()
            ->with(100, 'course_end_date', true)
            ->andReturn(null);

        // All 3 occurrences already exist — get_posts called once per occurrence
        Functions\expect('get_posts')
            ->times(3)
            ->andReturn([(object) ['ID' => 200]]);

        $result = RecurringCourse::generate_instances(1);

        $this->assertEquals(0, $result);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::generate_instances
     */
    public function test_generate_instances_returns_zero_for_missing_pattern()
    {
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_SQL');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $result = RecurringCourse::generate_instances(999);

        $this->assertEquals(0, $result);
    }

    // ------------------------------------------------------------------
    //  delete_recurrence_pattern
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::delete_recurrence_pattern
     */
    public function test_delete_recurrence_pattern()
    {
        $this->wpdb->shouldReceive('delete')
            ->once()
            ->with('wp_educator_course_recurrence', ['id' => 1], ['%d'])
            ->andReturn(1);

        $result = RecurringCourse::delete_recurrence_pattern(1);

        $this->assertTrue((bool) $result);
    }

    // ------------------------------------------------------------------
    //  get_series_instances
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::get_series_instances
     */
    public function test_get_series_instances_returns_all()
    {
        $expected_posts = [
            (object) ['ID' => 201, 'post_title' => 'Course - 01-06-2026'],
            (object) ['ID' => 202, 'post_title' => 'Course - 08-06-2026'],
        ];

        Functions\expect('get_posts')
            ->once()
            ->andReturn($expected_posts);

        $result = RecurringCourse::get_series_instances(1, false);

        $this->assertCount(2, $result);
        $this->assertEquals(201, $result[0]->ID);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::get_series_instances
     */
    public function test_get_series_instances_future_only()
    {
        Functions\expect('current_time')
            ->once()
            ->andReturn('2026-06-05 12:00:00');

        Functions\expect('get_posts')
            ->once()
            ->andReturn([]);

        $result = RecurringCourse::get_series_instances(1, true);

        $this->assertEmpty($result);
    }

    // ------------------------------------------------------------------
    //  update_series
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::update_series
     */
    public function test_update_series_updates_all_instances()
    {
        Functions\expect('get_posts')
            ->once()
            ->andReturn([201, 202, 203]);

        Functions\expect('update_post_meta')
            ->times(3)
            ->with(Mockery::anyOf(201, 202, 203), 'course_full_cost', 500);

        $result = RecurringCourse::update_series(1, ['course_full_cost' => 500], false);

        $this->assertEquals(3, $result);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::update_series
     */
    public function test_update_series_future_only()
    {
        Functions\expect('current_time')
            ->once()
            ->andReturn('2026-06-05 12:00:00');

        Functions\expect('get_posts')
            ->once()
            ->andReturn([205]);

        Functions\expect('update_post_meta')
            ->once()
            ->with(205, 'course_full_cost', 600);

        $result = RecurringCourse::update_series(1, ['course_full_cost' => 600], true);

        $this->assertEquals(1, $result);
    }

    // ------------------------------------------------------------------
    //  deactivate_series
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::deactivate_series
     */
    public function test_deactivate_series()
    {
        // Deactivate the pattern
        $this->wpdb->shouldReceive('update')
            ->once()
            ->ordered()
            ->with('wp_educator_course_recurrence', ['is_active' => 0], ['id' => 1], ['%d'], ['%d'])
            ->andReturn(1);

        // delete_series internals:
        //  1st prepare: get the pattern
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->ordered()
            ->andReturn('PREPARED_SQL');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->ordered()
            ->andReturn((object) ['course_template_id' => 100]);

        Functions\expect('current_time')
            ->once()
            ->andReturn('2026-06-05 12:00:00');

        Functions\expect('get_posts')
            ->once()
            ->andReturn([201]);

        // 2nd prepare: check bookings
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->ordered()
            ->andReturn('PREPARED_SQL');

        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->ordered()
            ->andReturn(1); // has bookings → trash

        Functions\expect('wp_trash_post')
            ->once()
            ->with(201)
            ->andReturn(true);

        // Second update call from delete_series (deactivate again)
        $this->wpdb->shouldReceive('update')
            ->once()
            ->ordered()
            ->with('wp_educator_course_recurrence', ['is_active' => 0], ['id' => 1], ['%d'], ['%d'])
            ->andReturn(1);

        $result = RecurringCourse::deactivate_series(1);

        $this->assertEquals(1, $result);
    }

    // ------------------------------------------------------------------
    //  delete_series
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::delete_series
     */
    public function test_delete_series_without_deleting_instances()
    {
        // Just deactivate the pattern, no instance deletion
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with('wp_educator_course_recurrence', ['is_active' => 0], ['id' => 1], ['%d'], ['%d'])
            ->andReturn(1);

        $result = RecurringCourse::delete_series(1, false);

        $this->assertEquals(0, $result);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::delete_series
     */
    public function test_delete_series_hard_deletes_unbooked_instances()
    {
        $this->wpdb->shouldReceive('prepare')
            ->twice()
            ->andReturn('PREPARED_SQL');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) ['course_template_id' => 100]);

        Functions\expect('get_posts')
            ->once()
            ->andReturn([201]);

        $this->wpdb->shouldReceive('get_var')
            ->once()
            ->andReturn(0); // no bookings → hard delete

        Functions\expect('wp_delete_post')
            ->once()
            ->with(201, true)
            ->andReturn(true);

        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $result = RecurringCourse::delete_series(1, true, false);

        $this->assertEquals(1, $result);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::delete_series
     */
    public function test_delete_series_skips_template_post()
    {
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_SQL');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn((object) ['course_template_id' => 100]);

        Functions\expect('get_posts')
            ->once()
            ->andReturn([100]); // Same as template ID — should be skipped

        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturn(1);

        $result = RecurringCourse::delete_series(1, true, false);

        $this->assertEquals(0, $result);
    }

    // ------------------------------------------------------------------
    //  regenerate_series
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::regenerate_series
     */
    public function test_regenerate_series()
    {
        // Flow: regenerate_series → get pattern → delete_series(true,true) → get pattern
        //   → (no instances) → deactivate pattern → update pattern
        //   → generate_instances → get pattern → create instances

        // Use zeroOrMoreTimes for repeated calls; final assertion validates success
        $this->wpdb->shouldReceive('prepare')->zeroOrMoreTimes()->andReturn('PREPARED_SQL');
        $this->wpdb->shouldReceive('get_row')->zeroOrMoreTimes()->andReturn((object) [
            'id' => 1,
            'course_template_id' => 100,
            'recurrence_type' => 'weekly',
            'recurrence_days' => '1',
            'start_date' => '2026-06-01',
            'end_date' => null,
            'max_occurrences' => 2,
            'is_active' => 1,
        ]);
        $this->wpdb->shouldReceive('update')->zeroOrMoreTimes()->andReturn(1);
        $this->wpdb->shouldReceive('replace')->zeroOrMoreTimes()->andReturn(true);

        // get_posts for delete_series (returns no instances to delete)
        Functions\expect('get_posts')->zeroOrMoreTimes()->andReturn([]);

        // generate_instances needs a template post
        Functions\expect('get_post')->zeroOrMoreTimes()->andReturn((object) [
            'ID' => 100,
            'post_type' => 'educator_course',
            'post_title' => 'Test Course',
            'post_content' => 'Content',
            'post_author' => 1,
            'post_name' => 'test-course',
        ]);

        // get_post_meta — unified handler for all calls
        Functions\expect('get_post_meta')
            ->zeroOrMoreTimes()
            ->andReturnUsing(function () {
                $args = func_get_args();
                $key = $args[1] ?? null;
                $single = $args[2] ?? false;

                if ($single && $key === 'course_start_date') {
                    return '2026-06-01 09:00:00';
                }
                if ($single && $key === 'course_capacity') {
                    return '20';
                }
                if ($key === null) {
                    return ['course_capacity' => ['20']];
                }
                return $single ? '' : [];
            });

        // Instance creation
        Functions\expect('update_post_meta')->zeroOrMoreTimes()->andReturn(true);
        Functions\expect('get_object_taxonomies')->zeroOrMoreTimes()->andReturn([]);
        Functions\expect('current_time')->zeroOrMoreTimes()->andReturn('2026-06-05 12:00:00');
        Functions\expect('maybe_unserialize')->zeroOrMoreTimes()->andReturnUsing(function ($v) { return $v; });
        Functions\expect('sanitize_title')->zeroOrMoreTimes()->andReturnUsing(function ($title) {
            return strtolower(str_replace([' ', '_'], '-', $title));
        });
        Functions\expect('wp_insert_post')->zeroOrMoreTimes()->andReturn(201);

        $new_config = [
            'type' => 'weekly',
            'days' => '1,3',
            'end_date' => null,
            'max_occurrences' => 2,
        ];

        $result = RecurringCourse::regenerate_series(1, $new_config);

        // With max_occurrences=2, 2 new instances are created
        $this->assertEquals(2, $result);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::regenerate_series
     */
    public function test_regenerate_series_returns_zero_for_missing_pattern()
    {
        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_SQL');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $result = RecurringCourse::regenerate_series(999, []);

        $this->assertEquals(0, $result);
    }

    // ------------------------------------------------------------------
    //  update_pattern
    // ------------------------------------------------------------------

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::update_pattern
     */
    public function test_update_pattern()
    {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                'wp_educator_course_recurrence',
                Mockery::on(function ($data) {
                    return $data['recurrence_type'] === 'monthly'
                        && $data['max_occurrences'] === 12;
                }),
                ['id' => 1],
                ['%s', '%s', '%s', '%d'],
                ['%d']
            )
            ->andReturn(1);

        $result = RecurringCourse::update_pattern(1, [
            'type' => 'monthly',
            'days' => '',
            'end_date' => null,
            'max_occurrences' => 12,
        ]);

        $this->assertTrue($result);
    }

    /**
     * @covers \HMWEvents\Helpers\RecurringCourse::update_pattern
     */
    public function test_update_pattern_returns_false_on_failure()
    {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->andReturn(false);

        $result = RecurringCourse::update_pattern(999, []);

        $this->assertFalse($result);
    }
}
