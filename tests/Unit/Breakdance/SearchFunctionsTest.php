<?php
/**
 * Tests for Educator Search Functions.
 *
 * @package HMWEvents\Tests\Unit\Breakdance
 */

namespace HMWEvents\Tests\Unit\Breakdance;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

// Include the functions file
require_once __DIR__ . '/../../../src/Breakdance/elements/Educator_Search_Results/search-functions.php';

/**
 * Test Educator Search functionality.
 */
class SearchFunctionsTest extends TestCase
{
    /**
     * Set up test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // Mock WordPress time function
        Functions\when('current_time')->justReturn('2025-01-15 10:00:00');
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
     * Test search by location returns educators with distance.
     *
     * @covers search_educators_by_location
     */
    public function test_search_educators_by_location_returns_educators_with_distance()
    {
        // Mock LocationManager
        $location_manager = Mockery::mock('overload:HandmadeWeb\Locations\BusinessLogic\LocationManager');
        
        // Create mock courses with distance
        $mock_courses = [
            (object) [
                'ID' => 101,
                'post_author' => 1,
                'distance' => 5.2,
            ],
            (object) [
                'ID' => 102,
                'post_author' => 2,
                'distance' => 12.8,
            ],
            (object) [
                'ID' => 103,
                'post_author' => 1, // Same educator, farther course
                'distance' => 15.3,
            ],
        ];

        $location_manager->shouldReceive('find')
            ->once()
            ->with('2000', 2500, Mockery::type('array'))
            ->andReturn(['posts' => $mock_courses]);

        // Source calls get_users once with 'include' (sorted: educator 1 at 5.2, educator 2 at 12.8)
        $mock_educators = [
            (object) ['ID' => 1, 'display_name' => 'Educator One'],
            (object) ['ID' => 2, 'display_name' => 'Educator Two'],
        ];

        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['role'] === 'educator'
                    && $args['include'] === [1, 2];
            }))
            ->andReturn($mock_educators);

        $result = search_educators_by_location('2000', 1, 10);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('educators', $result);
        $this->assertArrayHasKey('show_distance', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('max_pages', $result);
        $this->assertTrue($result['show_distance']);
        $this->assertEquals(2, $result['total']);
        $this->assertEquals(1, $result['max_pages']);
        $this->assertCount(2, $result['educators']);
        
        // Check that educators are sorted by distance
        $this->assertEquals(1, $result['educators'][0]->ID);
        $this->assertEquals(5.2, $result['educators'][0]->distance);
        $this->assertEquals(2, $result['educators'][1]->ID);
        $this->assertEquals(12.8, $result['educators'][1]->distance);
    }

    /**
     * Test search by location with no results.
     *
     * @covers search_educators_by_location
     */
    public function test_search_educators_by_location_handles_no_results()
    {
        $location_manager = Mockery::mock('overload:HandmadeWeb\Locations\BusinessLogic\LocationManager');
        
        $location_manager->shouldReceive('find')
            ->once()
            ->andReturn(['posts' => []]);

        $result = search_educators_by_location('9999', 1, 10);

        $this->assertEquals([], $result['educators']);
        $this->assertTrue($result['show_distance']);
        $this->assertEquals(0, $result['total']);
        $this->assertEquals(0, $result['max_pages']);
    }

    /**
     * Test search by location handles WP_Error.
     *
     * @covers search_educators_by_location
     */
    public function test_search_educators_by_location_handles_error()
    {
        $location_manager = Mockery::mock('overload:HandmadeWeb\Locations\BusinessLogic\LocationManager');
        
        $wp_error = Mockery::mock('WP_Error');
        
        $location_manager->shouldReceive('find')
            ->once()
            ->andReturn($wp_error);

        Functions\expect('is_wp_error')
            ->once()
            ->with($wp_error)
            ->andReturn(true);

        $result = search_educators_by_location('invalid', 1, 10);

        $this->assertEquals([], $result['educators']);
        $this->assertEquals(0, $result['total']);
    }

    /**
     * Test search by state returns correct educators.
     *
     * @covers search_educators_by_state
     */
    public function test_search_educators_by_state_returns_educators()
    {
        $mock_educators = [
            (object) ['ID' => 1, 'display_name' => 'NSW Educator 1'],
            (object) ['ID' => 2, 'display_name' => 'NSW Educator 2'],
        ];

        // Mock for total count
        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['role'] === 'educator'
                    && $args['fields'] === 'ID'
                    && $args['meta_query'][0]['key'] === 'educator_state'
                    && $args['meta_query'][0]['value'] === 'NSW';
            }))
            ->andReturn([1, 2, 3, 4, 5]);

        // Mock for paginated results
        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['role'] === 'educator'
                    && $args['number'] === 10
                    && $args['offset'] === 0;
            }))
            ->andReturn($mock_educators);

        $result = search_educators_by_state('NSW', 1, 10);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('educators', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('max_pages', $result);
        $this->assertCount(2, $result['educators']);
        $this->assertEquals(5, $result['total']);
        $this->assertEquals(1, $result['max_pages']);
    }

    /**
     * Test search by state with pagination.
     *
     * @covers search_educators_by_state
     */
    public function test_search_educators_by_state_handles_pagination()
    {
        // Mock for total count
        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['fields'] === 'ID';
            }))
            ->andReturn(range(1, 25)); // 25 educators total

        // Mock for page 2 results
        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['number'] === 10
                    && $args['offset'] === 10; // Page 2, offset 10
            }))
            ->andReturn([
                (object) ['ID' => 11, 'display_name' => 'Educator 11'],
                (object) ['ID' => 12, 'display_name' => 'Educator 12'],
            ]);

        $result = search_educators_by_state('VIC', 2, 10);

        $this->assertEquals(25, $result['total']);
        $this->assertEquals(3, $result['max_pages']); // ceil(25/10) = 3
        $this->assertCount(2, $result['educators']);
    }

    /**
     * Test search by name returns matching educators.
     *
     * @covers search_educators_by_name
     */
    public function test_search_educators_by_name_returns_matching_educators()
    {
        $mock_educators = [
            (object) ['ID' => 1, 'display_name' => 'Sarah Smith'],
            (object) ['ID' => 2, 'display_name' => 'Sarah Jones'],
        ];

        // Mock for total count
        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['role'] === 'educator'
                    && $args['fields'] === 'ID'
                    && $args['search'] === '*Sarah*';
            }))
            ->andReturn([1, 2, 3]);

        // Mock for results
        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['search'] === '*Sarah*'
                    && $args['number'] === 10;
            }))
            ->andReturn($mock_educators);

        $result = search_educators_by_name('Sarah', 1, 10);

        $this->assertIsArray($result);
        $this->assertCount(2, $result['educators']);
        $this->assertEquals(3, $result['total']);
        $this->assertEquals(1, $result['max_pages']);
    }

    /**
     * Test search by hospital certification.
     *
     * @covers search_educators_by_hospital
     */
    public function test_search_educators_by_hospital_returns_certified_educators()
    {
        $mock_educators = [
            (object) ['ID' => 1, 'display_name' => 'Hospital Certified 1'],
            (object) ['ID' => 2, 'display_name' => 'Hospital Certified 2'],
        ];

        // Mock for total count
        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['role'] === 'educator'
                    && $args['fields'] === 'ID'
                    && $args['meta_query'][0]['key'] === 'educator_hospital'
                    && $args['meta_query'][0]['value'] === '1';
            }))
            ->andReturn([1, 2, 3, 4]);

        // Mock for results
        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return $args['meta_query'][0]['key'] === 'educator_hospital';
            }))
            ->andReturn($mock_educators);

        $result = search_educators_by_hospital(1, 10);

        $this->assertIsArray($result);
        $this->assertCount(2, $result['educators']);
        $this->assertEquals(4, $result['total']);
    }

    /**
     * Test search by location sorts by distance correctly.
     *
     * @covers search_educators_by_location
     */
    public function test_search_by_location_sorts_by_distance()
    {
        $location_manager = Mockery::mock('overload:HandmadeWeb\Locations\BusinessLogic\LocationManager');
        
        $mock_courses = [
            (object) ['ID' => 101, 'post_author' => 1, 'distance' => 25.0],
            (object) ['ID' => 102, 'post_author' => 2, 'distance' => 5.0],
            (object) ['ID' => 103, 'post_author' => 3, 'distance' => 15.0],
        ];

        $location_manager->shouldReceive('find')
            ->once()
            ->andReturn(['posts' => $mock_courses]);

        // Source calls get_users once with 'include' (educators sorted by distance: 2, 3, 1)
        $mock_educators = [
            (object) ['ID' => 2, 'display_name' => 'Close Educator'],
            (object) ['ID' => 3, 'display_name' => 'Medium Educator'],
            (object) ['ID' => 1, 'display_name' => 'Far Educator'],
        ];

        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return isset($args['include']) && $args['include'] === [2, 3, 1];
            }))
            ->andReturn($mock_educators);

        $result = search_educators_by_location('2000', 1, 10);

        // Should be sorted by distance: 5.0, 15.0, 25.0
        $this->assertEquals(2, $result['educators'][0]->ID);
        $this->assertEquals(5.0, $result['educators'][0]->distance);
        $this->assertEquals(3, $result['educators'][1]->ID);
        $this->assertEquals(15.0, $result['educators'][1]->distance);
        $this->assertEquals(1, $result['educators'][2]->ID);
        $this->assertEquals(25.0, $result['educators'][2]->distance);
    }

    /**
     * Test search by location uses minimum distance for educator with multiple courses.
     *
     * @covers search_educators_by_location
     */
    public function test_search_by_location_uses_minimum_distance_per_educator()
    {
        $location_manager = Mockery::mock('overload:HandmadeWeb\Locations\BusinessLogic\LocationManager');
        
        // Same educator has two courses at different distances
        $mock_courses = [
            (object) ['ID' => 101, 'post_author' => 1, 'distance' => 25.0],
            (object) ['ID' => 102, 'post_author' => 1, 'distance' => 10.0], // Closer
            (object) ['ID' => 103, 'post_author' => 2, 'distance' => 15.0],
        ];

        $location_manager->shouldReceive('find')
            ->once()
            ->andReturn(['posts' => $mock_courses]);

        // Source calls get_users once with 'include' (educator 1 at min 10.0, educator 2 at 15.0)
        $mock_educators = [
            (object) ['ID' => 1, 'display_name' => 'Multi-Location Educator'],
            (object) ['ID' => 2, 'display_name' => 'Single-Location Educator'],
        ];

        Functions\expect('get_users')
            ->once()
            ->with(Mockery::on(function ($args) {
                return isset($args['include']) && $args['include'] === [1, 2];
            }))
            ->andReturn($mock_educators);

        $result = search_educators_by_location('2000', 1, 10);

        // Educator 1 should have distance 10.0 (minimum), not 25.0
        $educator_1 = array_filter($result['educators'], fn($e) => $e->ID === 1);
        $educator_1 = reset($educator_1);
        $this->assertEquals(10.0, $educator_1->distance);
    }
}
