<?php
/**
 * Base Integration Test Class.
 *
 * Provides common functionality for integration tests including fixtures,
 * database mocking, and test data generation.
 *
 * @package HMWEvents\Tests\Integration
 */

namespace HMWEvents\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Base class for integration tests.
 */
abstract class BaseIntegrationTest extends TestCase
{
    /**
     * Mock wpdb instance.
     *
     * @var Mockery\MockInterface
     */
    protected $wpdb;

    /**
     * Test data fixtures.
     *
     * @var array
     */
    protected $fixtures = [];

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

        // Mock common WordPress functions
        Functions\when('error_log')->justReturn(true);
        Functions\when('current_time')->alias(function ($type) {
            return $type === 'mysql' ? '2025-01-15 10:00:00' : strtotime('2025-01-15 10:00:00');
        });
        Functions\when('wp_parse_args')->alias(function ($args, $defaults = []) {
            return array_merge((array) $defaults, (array) $args);
        });
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });

        // Define constants if not defined
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wordpress/');
        }
        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        // Load fixtures
        $this->loadFixtures();
    }

    /**
     * Tear down test environment.
     */
    protected function tearDown(): void
    {
        $this->fixtures = [];
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Load test fixtures.
     * Override this in child classes to load specific fixtures.
     */
    protected function loadFixtures(): void
    {
        // Default fixtures - override in child classes
    }

    /**
     * Create a mock course post.
     *
     * @param array $overrides Field overrides.
     * @return object Mock course post.
     */
    protected function createMockCourse(array $overrides = []): object
    {
        $defaults = [
            'ID' => 100,
            'post_title' => 'Test Calmbirth Course',
            'post_type' => 'educator_course',
            'post_author' => 1,
            'post_status' => 'publish',
        ];

        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Create mock course meta data.
     *
     * @param array $overrides Field overrides.
     * @return array Course meta data.
     */
    protected function createMockCourseMeta(array $overrides = []): array
    {
        $defaults = [
            'course_full_cost' => 450.00,
            'course_deposit_cost' => 150.00,
            'course_capacity' => 12,
            'course_start_date' => '2025-02-15 09:00:00',
            'course_end_date' => '2025-02-15 17:00:00',
            'course_location_address' => '123 Main St',
            'course_location_suburb' => 'Sydney',
            'course_location_state' => 'NSW',
            'course_location_postcode' => '2000',
            'course_status' => 'published',
            'course_is_external' => false,
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Create a mock customer post.
     *
     * @param array $overrides Field overrides.
     * @return object Mock customer post.
     */
    protected function createMockCustomer(array $overrides = []): object
    {
        $defaults = [
            'ID' => 200,
            'post_title' => 'Jane Smith',
            'post_type' => 'customer',
            'post_status' => 'publish',
        ];

        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Create mock customer meta data.
     *
     * @param array $overrides Field overrides.
     * @return array Customer meta data.
     */
    protected function createMockCustomerMeta(array $overrides = []): array
    {
        $defaults = [
            'customer_email' => 'jane.smith@example.com',
            'customer_phone' => '0412345678',
            'customer_stripe_id' => 'cus_test_123',
        ];

        return array_merge($defaults, $overrides);
    }

    /**
     * Create a mock booking group.
     *
     * @param array $overrides Field overrides.
     * @return object Mock booking group.
     */
    protected function createMockBookingGroup(array $overrides = []): object
    {
        $defaults = [
            'id' => 1,
            'customer_post_id' => 200,
            'total_amount' => 450.00,
            'payment_status' => 'pending',
            'gateway' => 'stripe',
            'gateway_payment_id' => 'pi_test_123',
            'created_at' => '2025-01-15 10:00:00',
        ];

        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Create a mock booking.
     *
     * @param array $overrides Field overrides.
     * @return object Mock booking.
     */
    protected function createMockBooking(array $overrides = []): object
    {
        $defaults = [
            'id' => 10,
            'booking_group_id' => 1,
            'course_post_id' => 100,
            'customer_post_id' => 200,
            'booking_number' => 'BK-20250115-0001',
            'booking_amount' => 450.00,
            'ticket_quantity' => 2,
            'status' => 'pending',
            'payment_status' => 'pending',
            'created_at' => '2025-01-15 10:00:00',
        ];

        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Create a mock payment transaction.
     *
     * @param array $overrides Field overrides.
     * @return object Mock payment transaction.
     */
    protected function createMockPaymentTransaction(array $overrides = []): object
    {
        $defaults = [
            'id' => 1,
            'booking_group_id' => 1,
            'gateway' => 'stripe',
            'transaction_type' => 'payment',
            'transaction_status' => 'succeeded',
            'gateway_transaction_id' => 'pi_test_123',
            'amount' => 450.00,
            'currency' => 'AUD',
            'created_at' => '2025-01-15 10:00:00',
        ];

        return (object) array_merge($defaults, $overrides);
    }

    /**
     * Mock ACF get_field function.
     *
     * @param array $field_values Array of field_name => value.
     */
    protected function mockGetField(array $field_values): void
    {
        Functions\expect('get_field')
            ->andReturnUsing(function ($field, $post_id = null) use ($field_values) {
                return $field_values[$field] ?? null;
            });
    }

    /**
     * Mock ACF update_field function.
     */
    protected function mockUpdateField(): void
    {
        Functions\expect('update_field')
            ->andReturn(true);
    }

    /**
     * Mock WordPress get_post function.
     *
     * @param object $post Mock post object.
     */
    protected function mockGetPost(object $post): void
    {
        Functions\expect('get_post')
            ->with($post->ID)
            ->andReturn($post);
    }

    /**
     * Mock WordPress get_post_meta function.
     *
     * @param int   $post_id Post ID.
     * @param array $meta_values Array of meta_key => value.
     */
    protected function mockGetPostMeta(int $post_id, array $meta_values): void
    {
        Functions\expect('get_post_meta')
            ->andReturnUsing(function ($id, $key = '', $single = false) use ($post_id, $meta_values) {
                if ($id !== $post_id) {
                    return $single ? '' : [];
                }
                if (empty($key)) {
                    return $meta_values;
                }
                return $single ? ($meta_values[$key] ?? '') : [$meta_values[$key] ?? ''];
            });
    }

    /**
     * Assert that a database insert was called with expected data.
     *
     * @param string $table Expected table name.
     * @param array  $expected_data Expected data (subset).
     */
    protected function assertDatabaseInsert(string $table, array $expected_data): void
    {
        $this->wpdb->shouldReceive('insert')
            ->once()
            ->with(
                $table,
                Mockery::on(function ($data) use ($expected_data) {
                    foreach ($expected_data as $key => $value) {
                        if (!isset($data[$key]) || $data[$key] !== $value) {
                            return false;
                        }
                    }
                    return true;
                }),
                Mockery::any()
            )
            ->andReturn(true);
    }

    /**
     * Assert that a database update was called.
     *
     * @param string $table Expected table name.
     * @param array  $expected_data Expected data to update.
     * @param array  $expected_where Expected where clause.
     */
    protected function assertDatabaseUpdate(string $table, array $expected_data, array $expected_where): void
    {
        $this->wpdb->shouldReceive('update')
            ->once()
            ->with(
                $table,
                Mockery::on(function ($data) use ($expected_data) {
                    foreach ($expected_data as $key => $value) {
                        if (!isset($data[$key]) || $data[$key] !== $value) {
                            return false;
                        }
                    }
                    return true;
                }),
                Mockery::on(function ($where) use ($expected_where) {
                    foreach ($expected_where as $key => $value) {
                        if (!isset($where[$key]) || $where[$key] !== $value) {
                            return false;
                        }
                    }
                    return true;
                }),
                Mockery::any(),
                Mockery::any()
            )
            ->andReturn(1);
    }
}
