<?php
/**
 * Schema smoke tests for the HMWEvents rebuild (Phase 1).
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;

/**
 * Test the new hmwevents_* database schema definitions.
 */
class SchemaTest extends TestCase
{
    private array $schemas;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        require_once HMWEvents_ABSPATH . 'includes/schema.php';
        $this->schemas = \hmwevents_get_schema();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * All 19 hmwevents_* tables are defined.
     */
    public function test_all_tables_are_defined(): void
    {
        $expected = [
            'event_recurrence',
            'booking_groups',
            'event_availability',
            'event_attendance_options',
            'event_templates',
            'saved_report_filters',
            'email_queue',
            'email_templates',
            'private_registration_tokens',
            'bookings',
            'payment_transactions',
            'booking_details',
            'booking_meta',
            'booking_history',
            'voucher_usage',
            'coupon_usage',
            'registration_documents',
            'waitlist',
            'email_attachments',
        ];

        $this->assertCount(19, $this->schemas, 'Should have exactly 19 table definitions');
        foreach ($expected as $table) {
            $this->assertArrayHasKey($table, $this->schemas, "Missing table: {$table}");
            $this->assertStringContainsString(
                'CREATE TABLE wp_hmwevents_' . $table,
                $this->schemas[$table],
                "SQL for {$table} should target correct table"
            );
        }
    }

    /**
     * Bookings table has waitlisted status and separated attendance_status.
     */
    public function test_booking_status_includes_waitlisted(): void
    {
        $sql = $this->schemas['bookings'];

        $this->assertStringContainsString("'waitlisted'", $sql, 'Bookings status ENUM must include waitlisted');
        $this->assertStringContainsString('attendance_status', $sql, 'Bookings must have attendance_status column');
        $this->assertStringContainsString("'registered'", $sql, 'attendance_status must include registered');
        $this->assertStringContainsString("'attended'", $sql, 'attendance_status must include attended');
        $this->assertStringContainsString("'no_show'", $sql, 'attendance_status must include no_show');
    }

    /**
     * Booking groups payment_status includes invoiced.
     */
    public function test_payment_status_includes_invoiced(): void
    {
        foreach (['booking_groups', 'bookings'] as $table) {
            $sql = $this->schemas[$table];
            $this->assertStringContainsString("'invoiced'", $sql, "{$table} payment_status must include invoiced");
        }
    }

    /**
     * Booking groups has net_terms payment type and gst_amount.
     */
    public function test_booking_groups_has_net_terms_and_gst(): void
    {
        $sql = $this->schemas['booking_groups'];

        $this->assertStringContainsString("'net_terms'", $sql, 'booking_groups payment_type must include net_terms');
        $this->assertStringContainsString('gst_amount', $sql, 'booking_groups must have gst_amount column');
    }

    /**
     * Bookings has attendance_option_id FK reference.
     */
    public function test_bookings_has_attendance_option_reference(): void
    {
        $sql = $this->schemas['bookings'];
        $this->assertStringContainsString('attendance_option_id', $sql);
    }

    /**
     * Event attendance options has a description column after label.
     */
    public function test_event_attendance_options_has_description(): void
    {
        $sql = $this->schemas['event_attendance_options'];
        $this->assertStringContainsString('description mediumtext', $sql, 'event_attendance_options must have a description column');
        $this->assertGreaterThan(strpos($sql, 'label'), (int) strpos($sql, 'description'), 'description must follow label');
    }

    /**
     * Event attendance options has a freeform option_key column after
     * option_type plus a composite event/key index.
     */
    public function test_event_attendance_options_has_option_key(): void
    {
        $sql = $this->schemas['event_attendance_options'];

        $this->assertStringContainsString('option_key varchar(191)', $sql, 'event_attendance_options must have an option_key column');
        $this->assertGreaterThan((int) strpos($sql, 'option_type'), (int) strpos($sql, 'option_key'), 'option_key must follow option_type');
        $this->assertStringContainsString('KEY idx_event_key (event_post_id, option_key)', $sql, 'event_attendance_options must have the idx_event_key index');
    }

    /**
     * Event availability has waitlisted_count.
     */
    public function test_event_availability_has_waitlisted_count(): void
    {
        $sql = $this->schemas['event_availability'];
        $this->assertStringContainsString('waitlisted_count', $sql);
    }

    /**
     * All tables use InnoDB and utf8mb4_unicode_ci.
     */
    public function test_all_tables_use_innodb_and_utf8mb4(): void
    {
        foreach ($this->schemas as $name => $sql) {
            $this->assertStringContainsString('InnoDB', $sql, "{$name} must use InnoDB engine");
            $this->assertStringContainsString('utf8mb4_unicode_ci', $sql, "{$name} must use utf8mb4_unicode_ci");
        }
    }

    /**
     * New tables introduced in rebuild exist.
     */
    public function test_new_rebuild_tables_exist(): void
    {
        $new_tables = [
            'event_attendance_options',
            'event_templates',
            'registration_documents',
            'private_registration_tokens',
            'saved_report_filters',
            'booking_history',
            'event_availability',
        ];

        foreach ($new_tables as $table) {
            $this->assertArrayHasKey($table, $this->schemas, "New table missing: {$table}");
        }
    }

    /**
     * Registration documents has retention_until for PII compliance.
     */
    public function test_registration_documents_has_retention(): void
    {
        $sql = $this->schemas['registration_documents'];
        $this->assertStringContainsString('retention_until', $sql, 'Must have retention_until for PII purge');
        $this->assertStringContainsString('file_size', $sql, 'Must track file size');
        $this->assertStringContainsString('original_filename', $sql);
    }

    /**
     * Private tokens have expiry and use tracking.
     */
    public function test_private_registration_tokens_has_expiry_and_usage(): void
    {
        $sql = $this->schemas['private_registration_tokens'];
        $this->assertStringContainsString('expires_at', $sql);
        $this->assertStringContainsString('max_uses', $sql);
        $this->assertStringContainsString('use_count', $sql);
        $this->assertStringContainsString('UNIQUE KEY idx_token', $sql, 'Token must be unique');
    }

    /**
     * Event templates has is_retired flag.
     */
    public function test_event_templates_has_retired(): void
    {
        $sql = $this->schemas['event_templates'];
        $this->assertStringContainsString('is_retired', $sql, 'Templates must support retirement');
    }
}
