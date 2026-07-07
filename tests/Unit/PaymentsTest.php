<?php
/**
 * Phase 5 tests — GST Calculator, Payment Service, Net Terms, Payment Override.
 *
 * @package HMWEvents\Tests\Unit
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit;

use HMWEvents\Services\GstCalculator;
use HMWEvents\Services\PaymentService;
use HMWEvents\Services\NetTermsHandler;
use HMWEvents\Services\PaymentOverrideService;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

class PaymentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 2) . '/');
        }

        $GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];

        Functions\when('get_option')->justReturn(false);
        Functions\when('get_user_meta')->justReturn([]);
        Functions\when('get_post_meta')->justReturn(false);
        Functions\when('__')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('sanitize_text_field')->returnArg();
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('current_user_can')->justReturn(true);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ============================================================
    // GST Calculator
    // ============================================================

    public function test_gst_from_ex_gst(): void
    {
        $result = GstCalculator::from_ex_gst(100.00);
        $this->assertSame(100.00, $result['ex_gst']);
        $this->assertSame(10.00, $result['gst']);
        $this->assertSame(110.00, $result['total']);
    }

    public function test_gst_from_ex_gst_odd_number(): void
    {
        $result = GstCalculator::from_ex_gst(350.00);
        $this->assertSame(350.00, $result['ex_gst']);
        $this->assertSame(35.00, $result['gst']);
        $this->assertSame(385.00, $result['total']);
    }

    public function test_gst_from_inc_gst(): void
    {
        $result = GstCalculator::from_inc_gst(110.00);
        $this->assertSame(100.00, $result['ex_gst']);
        $this->assertSame(10.00, $result['gst']);
        $this->assertSame(110.00, $result['total']);
    }

    public function test_gst_from_inc_gst_385(): void
    {
        $result = GstCalculator::from_inc_gst(385.00);
        $this->assertSame(350.00, $result['ex_gst']);
        $this->assertSame(35.00, $result['gst']);
        $this->assertSame(385.00, $result['total']);
    }

    public function test_gst_from_ex_zero(): void
    {
        $result = GstCalculator::from_ex_gst(0);
        $this->assertSame(0.0, $result['gst']);
        $this->assertSame(0.0, $result['total']);
    }

    public function test_is_gst_free(): void
    {
        $this->assertTrue(GstCalculator::is_gst_free(0));
        $this->assertFalse(GstCalculator::is_gst_free(110));
    }

    public function test_line_items(): void
    {
        $result = GstCalculator::calculate_line_items([
            ['label' => 'Event Fee', 'amount' => 350, 'gst_applies' => true],
            ['label' => 'Materials', 'amount' => 50,  'gst_applies' => true],
        ]);

        $this->assertSame(400.00, $result['subtotal_ex_gst']);
        $this->assertSame(40.00, $result['total_gst']);
        $this->assertSame(440.00, $result['total_inc_gst']);
        $this->assertCount(2, $result['items']);
    }

    public function test_line_items_with_gst_free_item(): void
    {
        $result = GstCalculator::calculate_line_items([
            ['label' => 'Event Fee', 'amount' => 350, 'gst_applies' => true],
            ['label' => 'Donation',  'amount' => 100, 'gst_applies' => false],
        ]);

        $this->assertSame(450.00, $result['subtotal_ex_gst']);
        $this->assertSame(35.00, $result['total_gst']);
        $this->assertSame(485.00, $result['total_inc_gst']);
    }

    public function test_booking_breakdown_with_discount(): void
    {
        $result = GstCalculator::booking_breakdown(385, false, 38.50, false);

        $this->assertSame(350.00, $result['booking_ex_gst']);
        $this->assertSame(315.00, $result['final_ex_gst']);
        $this->assertSame(31.50, $result['final_gst']);
        $this->assertSame(346.50, $result['final_total']);
    }

    public function test_booking_breakdown_ex_gst_input(): void
    {
        $result = GstCalculator::booking_breakdown(350, true);

        $this->assertSame(350.00, $result['booking_ex_gst']);
        $this->assertSame(35.00, $result['booking_gst']);
        $this->assertSame(385.00, $result['final_total']);
    }

    // ============================================================
    // Payment Service
    // ============================================================

    public function test_payment_service_instantiable(): void
    {
        $service = new PaymentService();
        $this->assertInstanceOf(PaymentService::class, $service);
    }

    public function test_payment_service_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(PaymentService::class, $components);
    }

    // ============================================================
    // Net Terms Handler
    // ============================================================

    public function test_net_terms_instantiable(): void
    {
        $handler = new NetTermsHandler();
        $this->assertInstanceOf(NetTermsHandler::class, $handler);
    }

    public function test_net_terms_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(NetTermsHandler::class, $components);
    }

    // ============================================================
    // Payment Override Service
    // ============================================================

    public function test_override_instantiable(): void
    {
        $service = new PaymentOverrideService();
        $this->assertInstanceOf(PaymentOverrideService::class, $service);
    }

    public function test_override_in_components(): void
    {
        $components = \HMWEvents\HMWEvents::get_components();
        $this->assertContains(PaymentOverrideService::class, $components);
    }
}
