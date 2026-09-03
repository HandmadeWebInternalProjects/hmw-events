<?php
/**
 * Phase 8-10 tests — Communications, Listings, Reporting.
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use HMWEvents\Registry\CommunicationTriggerMatrix;
use HMWEvents\Services\EventListingService;
use HMWEvents\Services\ReportingService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class CommsAndReportingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('sanitize_email')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('wp_kses_post')->returnArg();
        Functions\when('get_option')->justReturn(false);
        Functions\when('update_option')->justReturn(true);
        Functions\when('get_post_meta')->justReturn('');
        Functions\when('is_wp_error')->alias(function ($thing) {
            return $thing instanceof \WP_Error;
        });
        Functions\when('error_log')->justReturn(true);
        Functions\when('gmdate')->alias('date');
        Functions\when('get_posts')->justReturn([]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // Communication Trigger Matrix
    // ============================================================

    public function test_trigger_matrix_has_all_13_triggers(): void
    {
        $triggers = CommunicationTriggerMatrix::get_all_triggers();
        $this->assertCount(13, $triggers);
        $this->assertArrayHasKey('waitlist_joined', $triggers);
    }

    public function test_resolve_without_event_type(): void
    {
        $this->assertSame('booking_confirmed', CommunicationTriggerMatrix::resolve('booking_confirmed'));
    }

    public function test_resolve_with_parent_course_type(): void
    {
        $key = CommunicationTriggerMatrix::resolve('booking_confirmed', 'parent-course');
        $this->assertSame('parent_booking_confirmed', $key);
    }

    public function test_payment_outcome_paid_triggers(): void
    {
        $triggers = CommunicationTriggerMatrix::get_triggers_for_payment_outcome('paid');
        $this->assertContains('booking_confirmed', $triggers);
        $this->assertContains('payment_received', $triggers);
    }

    public function test_payment_outcome_invoiced_triggers(): void
    {
        $triggers = CommunicationTriggerMatrix::get_triggers_for_payment_outcome('invoiced');
        $this->assertContains('invoice_issued', $triggers);
    }

    public function test_cancellable_triggers(): void
    {
        $triggers = CommunicationTriggerMatrix::get_cancellable_triggers();
        $this->assertContains('reminder_7_days', $triggers);
        $this->assertContains('reminder_1_day', $triggers);
        $this->assertContains('post_event', $triggers);
    }

    // ============================================================
    // Event Listing Service
    // ============================================================

    public function test_listing_instantiable(): void
    {
        $service = new EventListingService();
        $this->assertInstanceOf(EventListingService::class, $service);
    }

    public function test_listing_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(EventListingService::class, $components);
    }

    public function test_build_query_excludes_children(): void
    {
        $service = new EventListingService();
        $args = $service->build_query();
        $this->assertSame(0, $args['post_parent']);
    }

    public function test_build_query_with_filters(): void
    {
        $service = new EventListingService();
        $args = $service->build_query([
            'event_type' => 'parent-one-off-free',
            'free_only'  => true,
        ]);

        $this->assertNotEmpty($args['tax_query']);
        $this->assertNotEmpty($args['meta_query']);
    }

    // ============================================================
    // Reporting Service
    // ============================================================

    public function test_reporting_instantiable(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(true);

        $service = new ReportingService();
        $this->assertInstanceOf(ReportingService::class, $service);
    }

    public function test_reporting_in_components(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(true);

        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(ReportingService::class, $components);
    }
}
