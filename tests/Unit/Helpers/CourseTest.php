<?php
/**
 * Tests for Course Helper.
 *
 * @package HMWEvents\Tests\Unit\Helpers
 */

namespace HMWEvents\Tests\Unit\Helpers;

use HMWEvents\Helpers\Course;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test Course Helper functionality.
 */
class CourseTest extends TestCase
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

        // Create wpdb mock
        $this->wpdb = Mockery::mock('wpdb');
        $this->wpdb->prefix = 'wp_';
        $GLOBALS['wpdb'] = $this->wpdb;

        // Don't set default get_field() - let each test define what it needs
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
     * Test getting course details with all fields populated.
     *
     * @covers \HMWEvents\Helpers\Course::get_course_details
     */
    public function test_get_course_details_returns_complete_data()
    {
        $course_id = 123;
        $mock_post = (object) [
            'ID' => $course_id,
            'post_title' => 'Calmbirth Course - Sydney',
            'post_type' => 'educator_course',
        ];

        Functions\expect('get_post')
            ->once()
            ->with($course_id)
            ->andReturn($mock_post);

        // Mock ACF field values
        $field_values = [
            'course_full_cost' => 450.00,
            'course_deposit_cost' => 150.00,
            'course_capacity' => 12,
            'course_educator_id' => 5,
            'course_start_date' => '2025-01-15 09:00:00',
            'course_end_date' => '2025-01-15 17:00:00',
            'course_location_address' => '123 Main St',
            'course_location_suburb' => 'Sydney',
            'course_location_state' => 'NSW',
            'course_location_postcode' => '2000',
            'course_status' => 'published',
            'course_is_external' => false,
            'course_external_url' => '',
        ];

        Functions\expect('get_field')
            ->andReturnUsing(function ($field, $post_id) use ($field_values, $course_id) {
                $this->assertEquals($course_id, $post_id);
                return $field_values[$field] ?? null;
            });

        $result = Course::get_course_details($course_id);

        $this->assertIsArray($result);
        $this->assertEquals($course_id, $result['id']);
        $this->assertEquals('Calmbirth Course - Sydney', $result['title']);
        $this->assertEquals(450.00, $result['course_full_cost']);
        $this->assertEquals(150.00, $result['course_deposit_cost']);
        $this->assertEquals(12, $result['course_capacity']);
        $this->assertEquals(5, $result['course_educator_id']);
        $this->assertEquals('2025-01-15 09:00:00', $result['course_start_date']);
        $this->assertEquals('2025-01-15 17:00:00', $result['course_end_date']);
        $this->assertEquals('123 Main St', $result['course_location_address_string']);
        $this->assertEquals('Sydney', $result['course_location_suburb']);
        $this->assertEquals('NSW', $result['course_location_state']);
        $this->assertEquals('2000', $result['course_location_postcode']);
        $this->assertEquals('published', $result['course_status']);
        $this->assertFalse($result['course_is_external']);
    }

    /**
     * Test getting course details with Google Map address format.
     *
     * @covers \HMWEvents\Helpers\Course::get_course_details
     */
    public function test_get_course_details_handles_google_map_address()
    {
        $course_id = 123;
        $mock_post = (object) [
            'ID' => $course_id,
            'post_title' => 'Test Course',
            'post_type' => 'educator_course',
        ];

        Functions\expect('get_post')
            ->once()
            ->with($course_id)
            ->andReturn($mock_post);

        $google_map_address = [
            'address' => '456 George St, Sydney NSW 2000',
            'lat' => -33.8688,
            'lng' => 151.2093,
        ];

        Functions\expect('get_field')
            ->andReturnUsing(function ($field) use ($google_map_address) {
                if ($field === 'course_location_address') {
                    return $google_map_address;
                }
                return null;
            });

        $result = Course::get_course_details($course_id);

        $this->assertIsArray($result['course_location_address']);
        $this->assertEquals('456 George St, Sydney NSW 2000', $result['course_location_address_string']);
    }

    /**
     * Test getting course details with missing optional fields.
     *
     * @covers \HMWEvents\Helpers\Course::get_course_details
     */
    public function test_get_course_details_with_missing_fields()
    {
        $course_id = 123;
        $mock_post = (object) [
            'ID' => $course_id,
            'post_title' => 'Minimal Course',
            'post_type' => 'educator_course',
        ];

        Functions\expect('get_post')
            ->once()
            ->with($course_id)
            ->andReturn($mock_post);

        Functions\expect('get_field')->andReturn(null);

        $result = Course::get_course_details($course_id);

        $this->assertIsArray($result);
        $this->assertEquals(0.0, $result['course_full_cost']);
        $this->assertEquals(0.0, $result['course_deposit_cost']);
        $this->assertEquals(0, $result['course_capacity']);
        $this->assertEquals(0, $result['course_educator_id']);
        $this->assertEquals('draft', $result['course_status']);
    }

    /**
     * Test getting course details for non-existent course.
     *
     * @covers \HMWEvents\Helpers\Course::get_course_details
     */
    public function test_get_course_details_returns_null_for_invalid_course()
    {
        Functions\expect('get_post')
            ->once()
            ->with(999)
            ->andReturn(null);

        $result = Course::get_course_details(999);

        $this->assertNull($result);
    }

    /**
     * Test getting course details for wrong post type.
     *
     * @covers \HMWEvents\Helpers\Course::get_course_details
     */
    public function test_get_course_details_returns_null_for_wrong_post_type()
    {
        $course_id = 123;
        $mock_post = (object) [
            'ID' => $course_id,
            'post_title' => 'Regular Post',
            'post_type' => 'post',
        ];

        Functions\expect('get_post')
            ->once()
            ->with($course_id)
            ->andReturn($mock_post);

        $result = Course::get_course_details($course_id);

        $this->assertNull($result);
    }

    /**
     * Test checking course availability with available spots.
     *
     * @covers \HMWEvents\Helpers\Course::check_course_availability
     */
    public function test_check_course_availability_returns_true_when_available()
    {
        $course_id = 123;
        $availability = (object) [
            'course_post_id' => $course_id,
            'available_count' => 5,
            'booked_count' => 7,
            'capacity' => 12,
        ];

        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->with(
                Mockery::pattern('/SELECT \* FROM .+ WHERE course_post_id = %d/s'),
                $course_id
            )
            ->andReturn('PREPARED_QUERY');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->with('PREPARED_QUERY')
            ->andReturn($availability);

        $result = Course::check_course_availability($course_id);

        $this->assertTrue($result);
    }

    /**
     * Test checking course availability with no spots available.
     *
     * @covers \HMWEvents\Helpers\Course::check_course_availability
     */
    public function test_check_course_availability_returns_false_when_full()
    {
        $course_id = 123;
        $availability = (object) [
            'course_post_id' => $course_id,
            'available_count' => 0,
            'booked_count' => 12,
            'capacity' => 12,
        ];

        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_QUERY');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn($availability);

        $result = Course::check_course_availability($course_id);

        $this->assertFalse($result);
    }

    /**
     * Test checking course availability with no availability record.
     *
     * @covers \HMWEvents\Helpers\Course::check_course_availability
     */
    public function test_check_course_availability_returns_false_when_no_record()
    {
        $course_id = 999;

        // Mock get_post to return null so the lazy init path (ensure_course_availability_row)
        // fails immediately via calculate_course_availability.
        Functions\expect('get_post')
            ->once()
            ->with($course_id)
            ->andReturn(null);

        $this->wpdb->shouldReceive('prepare')
            ->once()
            ->andReturn('PREPARED_QUERY');

        $this->wpdb->shouldReceive('get_row')
            ->once()
            ->andReturn(null);

        $result = Course::check_course_availability($course_id);

        $this->assertFalse($result);
    }

    /**
     * Test course details with external course.
     *
     * @covers \HMWEvents\Helpers\Course::get_course_details
     */
    public function test_get_course_details_with_external_course()
    {
        $course_id = 123;
        $mock_post = (object) [
            'ID' => $course_id,
            'post_title' => 'External Course',
            'post_type' => 'educator_course',
        ];

        Functions\expect('get_post')
            ->once()
            ->andReturn($mock_post);

        Functions\expect('get_field')
            ->andReturnUsing(function ($field) {
                if ($field === 'course_is_external') {
                    return true;
                }
                if ($field === 'course_external_url') {
                    return 'https://external-booking.com/course/123';
                }
                return null;
            });

        $result = Course::get_course_details($course_id);

        $this->assertTrue($result['course_is_external']);
        $this->assertEquals('https://external-booking.com/course/123', $result['course_external_url']);
    }

    /**
     * Test course details handles string values for numeric fields.
     *
     * @covers \HMWEvents\Helpers\Course::get_course_details
     */
    public function test_get_course_details_casts_numeric_fields()
    {
        $course_id = 123;
        $mock_post = (object) [
            'ID' => $course_id,
            'post_title' => 'Test Course',
            'post_type' => 'educator_course',
        ];

        Functions\expect('get_post')
            ->once()
            ->andReturn($mock_post);

        Functions\expect('get_field')
            ->andReturnUsing(function ($field) {
                // Return string values that should be cast
                if ($field === 'course_full_cost') {
                    return '450.50';
                }
                if ($field === 'course_deposit_cost') {
                    return '150.25';
                }
                if ($field === 'course_capacity') {
                    return '12';
                }
                if ($field === 'course_educator_id') {
                    return '5';
                }
                return null;
            });

        $result = Course::get_course_details($course_id);

        $this->assertIsFloat($result['course_full_cost']);
        $this->assertEquals(450.50, $result['course_full_cost']);
        $this->assertIsFloat($result['course_deposit_cost']);
        $this->assertEquals(150.25, $result['course_deposit_cost']);
        $this->assertIsInt($result['course_capacity']);
        $this->assertEquals(12, $result['course_capacity']);
        $this->assertIsInt($result['course_educator_id']);
        $this->assertEquals(5, $result['course_educator_id']);
    }
}
