<?php
/**
 * Unit tests for ReportingService.
 *
 * Covers export_registrations() filter options (Step 12),
 * ajax_remove_test_data(), and CSV output.
 *
 * @package HMWEvents\Tests\Unit\Services
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\ReportingService;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class ReportingServiceTest extends TestCase
{
    private ?ReportingService $service = null;

    private string $lastPreparedSql = '';

    private array $resultsToReturn = [];

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $this->lastPreparedSql = '';
        $this->resultsToReturn = [];

        \Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });

        $GLOBALS['wpdb'] = Mockery::mock();
        $GLOBALS['wpdb']->prefix = 'wp_';
        $GLOBALS['wpdb']->posts = 'wp_posts';
        $GLOBALS['wpdb']->terms = 'wp_terms';
        $GLOBALS['wpdb']->term_taxonomy = 'wp_term_taxonomy';
        $GLOBALS['wpdb']->term_relationships = 'wp_term_relationships';

        $GLOBALS['wpdb']->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
            $flat = [];
            foreach ($args as $arg) {
                if (is_array($arg)) {
                    foreach ($arg as $v) {
                        $flat[] = $v;
                    }
                } else {
                    $flat[] = $arg;
                }
            }
            $result = $query;
            foreach ($flat as $v) {
                $pos = strpos($result, '%s');
                if ($pos !== false) {
                    $result = substr_replace($result, "'" . $v . "'", $pos, 2);
                } else {
                    $pos = strpos($result, '%d');
                    if ($pos !== false) {
                        $result = substr_replace($result, (string) (int) $v, $pos, 2);
                    }
                }
            }
            return $result;
        });

        $GLOBALS['wpdb']->shouldReceive('get_results')->andReturnUsing(function ($sql) {
            $this->lastPreparedSql = $sql;
            return $this->resultsToReturn;
        })->byDefault();

        $GLOBALS['wpdb']->shouldReceive('query')->andReturn(0)->byDefault();

        Functions\when('__')->returnArg();
        Functions\when('_n')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_key')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('error_log')->justReturn(true);
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->justReturn(null);
        Functions\when('check_ajax_referer')->justReturn(true);

        Functions\when('wp_send_json_error')->alias(function ($data = null) {
            throw new \RuntimeException('JSON_ERROR: ' . json_encode($data));
        });
        Functions\when('wp_send_json_success')->alias(function ($data = null) {
            throw new \RuntimeException('JSON_SUCCESS: ' . json_encode($data));
        });

        $this->service = new ReportingService();
    }

    protected function tearDown(): void
    {
        \Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function makeMockBookingRow(): \stdClass
    {
        $row = new \stdClass();
        $row->booking_number = 'BK-001';
        $row->event_post_id = 123;
        $row->registrant_post_id = 456;
        $row->status = 'confirmed';
        $row->attendance_status = 'registered';
        $row->payment_status = 'paid';
        $row->group_payment_status = null;
        $row->booking_amount = '100.00';
        $row->coupon_code = '';
        $row->discount_amount = '0.00';
        $row->created_at = '2026-01-15 10:00:00';
        return $row;
    }

    // ============================================================
    // Test 1: payment_status filter
    // ============================================================

    public function test_export_registrations_applies_payment_status_filter(): void
    {
        $this->service->export_registrations(['payment_status' => 'paid']);

        $this->assertStringContainsString(
            "(b.payment_status = 'paid' OR bg.payment_status = 'paid')",
            $this->lastPreparedSql
        );
    }

    // ============================================================
    // Test 2: attendance_status filter
    // ============================================================

    public function test_export_registrations_applies_attendance_status_filter(): void
    {
        $this->service->export_registrations(['attendance_status' => 'no_show']);

        $this->assertStringContainsString(
            "b.attendance_status = 'no_show'",
            $this->lastPreparedSql
        );
    }

    // ============================================================
    // Test 3: event_type taxonomy JOIN
    // ============================================================

    public function test_export_registrations_applies_event_type_join(): void
    {
        $this->service->export_registrations(['event_type' => 'parent-course']);

        $this->assertStringContainsString(
            "INNER JOIN wp_term_relationships tr_et ON b.event_post_id = tr_et.object_id",
            $this->lastPreparedSql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_term_taxonomy tt_et ON tr_et.term_taxonomy_id = tt_et.term_taxonomy_id AND tt_et.taxonomy = 'hmw_event_type'",
            $this->lastPreparedSql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_terms t_et ON tt_et.term_id = t_et.term_id AND t_et.slug = 'parent-course'",
            $this->lastPreparedSql
        );
    }

    // ============================================================
    // Test 4: audience taxonomy JOIN
    // ============================================================

    public function test_export_registrations_applies_audience_join(): void
    {
        $this->service->export_registrations(['audience' => 'parents']);

        $this->assertStringContainsString(
            "INNER JOIN wp_term_relationships tr_aud ON b.event_post_id = tr_aud.object_id",
            $this->lastPreparedSql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_term_taxonomy tt_aud ON tr_aud.term_taxonomy_id = tt_aud.term_taxonomy_id AND tt_aud.taxonomy = 'hmw_event_audience'",
            $this->lastPreparedSql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_terms t_aud ON tt_aud.term_id = t_aud.term_id AND t_aud.slug = 'parents'",
            $this->lastPreparedSql
        );
    }

    // ============================================================
    // Test 5: CSV output format
    // ============================================================

    public function test_export_registrations_returns_csv_format(): void
    {
        $row = $this->makeMockBookingRow();
        $this->resultsToReturn = [$row];

        $csv = $this->service->export_registrations();

        $this->assertStringStartsWith('"Booking #"', $csv);
        $this->assertStringContainsString('BK-001', $csv);
        $this->assertStringContainsString(',123,', $csv);
        $this->assertStringContainsString(',456,', $csv);
        $this->assertStringContainsString(',confirmed,', $csv);
        $this->assertStringContainsString(',registered,', $csv);
        $this->assertStringContainsString(',paid,', $csv);
        $this->assertStringContainsString(',100.00,', $csv);

        $lines = explode("\n", trim($csv));
        $this->assertCount(2, $lines, 'CSV should have 1 header line and 1 data line');
    }

    // ============================================================
    // Test 6: excludes cancelled bookings
    // ============================================================

    public function test_export_registrations_excludes_cancelled(): void
    {
        $this->service->export_registrations();

        $this->assertStringContainsString(
            "b.status != 'cancelled'",
            $this->lastPreparedSql
        );
    }

    // ============================================================
    // Test 7: ajax_remove_test_data requires manage_options
    // ============================================================

    public function test_ajax_remove_test_data_requires_manage_options(): void
    {
        Functions\when('current_user_can')->justReturn(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON_ERROR:');

        $this->service->ajax_remove_test_data();
    }

    // ============================================================
    // Test 8: ajax_remove_test_data deletes pending bookings
    // ============================================================

    public function test_ajax_remove_test_data_deletes_pending_bookings(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $GLOBALS['wpdb']->shouldReceive('query')->once()->andReturn(5);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('JSON_SUCCESS:');

        try {
            $this->service->ajax_remove_test_data();
        } catch (\RuntimeException $e) {
            $payload = json_decode(str_replace('JSON_SUCCESS: ', '', $e->getMessage()), true);
            $this->assertEquals(5, $payload['count']);
            throw $e;
        }
    }

    // ============================================================
    // Test 9: all filters combined
    // ============================================================

    public function test_export_registrations_handles_all_filters_combined(): void
    {
        $this->service->export_registrations([
            'payment_status'    => 'paid',
            'attendance_status' => 'registered',
            'event_type'        => 'parent-course',
            'audience'          => 'parents',
        ]);

        $sql = $this->lastPreparedSql;

        // Both taxonomy JOINs are present
        $this->assertStringContainsString(
            "INNER JOIN wp_term_relationships tr_et ON b.event_post_id = tr_et.object_id",
            $sql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_term_taxonomy tt_et ON tr_et.term_taxonomy_id = tt_et.term_taxonomy_id AND tt_et.taxonomy = 'hmw_event_type'",
            $sql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_terms t_et ON tt_et.term_id = t_et.term_id",
            $sql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_term_relationships tr_aud ON b.event_post_id = tr_aud.object_id",
            $sql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_term_taxonomy tt_aud ON tr_aud.term_taxonomy_id = tt_aud.term_taxonomy_id AND tt_aud.taxonomy = 'hmw_event_audience'",
            $sql
        );
        $this->assertStringContainsString(
            "INNER JOIN wp_terms t_aud ON tt_aud.term_id = t_aud.term_id",
            $sql
        );

        // All WHERE clauses are present (OR pattern for payment_status)
        $this->assertStringContainsString("b.payment_status =", $sql);
        $this->assertStringContainsString("bg.payment_status =", $sql);
        $this->assertStringContainsString("b.attendance_status =", $sql);
        $this->assertStringContainsString("b.status != 'cancelled'", $sql);

        // The filter values appear somewhere in the SQL (they're injected via prepare)
        $this->assertStringContainsString("'paid'", $sql);
        $this->assertStringContainsString("'registered'", $sql);
        $this->assertStringContainsString("'parent-course'", $sql);
        $this->assertStringContainsString("'parents'", $sql);
    }
}
