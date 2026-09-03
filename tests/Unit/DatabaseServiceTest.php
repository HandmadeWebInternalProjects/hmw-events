<?php
/**
 * Tests for DatabaseService and install pipeline (Phase 1).
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use HMWEvents\Services\DatabaseService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;

/**
 * Test DatabaseService table naming and version tracking.
 */
class DatabaseServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_get_table_name_uses_hmwevents_prefix(): void
    {
        $name = DatabaseService::get_table_name('bookings');
        $this->assertSame('wp_hmwevents_bookings', $name);
    }

    public function test_current_db_version_is_two_seven(): void
    {
        $this->assertSame('2.7', DatabaseService::CURRENT_DB_VERSION);
    }

    public function test_db_version_option_name(): void
    {
        $this->assertSame('hmwevents_db_version', DatabaseService::DB_VERSION_OPTION);
    }

    /**
     * get_all_table_names returns all 19 tables.
     */
    public function test_get_all_table_names_returns_all_tables(): void
    {
        $tables = DatabaseService::get_all_table_names();

        $this->assertCount(19, $tables);
        $this->assertSame('wp_hmwevents_bookings', $tables['bookings']);
        $this->assertSame('wp_hmwevents_event_templates', $tables['event_templates']);
        $this->assertSame('wp_hmwevents_booking_history', $tables['booking_history']);
        $this->assertSame('wp_hmwevents_registration_documents', $tables['registration_documents']);
        $this->assertSame('wp_hmwevents_private_registration_tokens', $tables['private_registration_tokens']);
    }

    /**
     * Ensures table names do not overlap with legacy educator_ prefix.
     */
    public function test_table_names_do_not_contain_educator(): void
    {
        $tables = DatabaseService::get_all_table_names();
        foreach ($tables as $key => $full) {
            $this->assertStringNotContainsString('educator', $key, "Key '{$key}' must not contain 'educator'");
            $this->assertStringNotContainsString('educator', $full, "Full name '{$full}' must not contain 'educator'");
        }
    }
}
