<?php

/**
 * Invoice Service tests — Net Terms invoicing, EFT reconciliation.
 *
 * @package HMWEvents\Tests\Unit\Services
 * @since 2.0.0
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\InvoiceService;
use HMWEvents\Services\NetTermsHandler;
use Mockery;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Patchwork;

class InvoiceServiceTest extends TestCase
{
    /** @var \Mockery\MockInterface */
    private $mockWpdb;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        if (!defined('HMWEvents_ABSPATH')) {
            define('HMWEvents_ABSPATH', dirname(__DIR__, 3) . '/');
        }

        $this->mockWpdb = Mockery::mock();
        $this->mockWpdb->prefix = 'wp_';
        $this->mockWpdb->shouldReceive('prepare')->andReturnUsing(function ($query, ...$args) {
            return $query;
        });
        $GLOBALS['wpdb'] = $this->mockWpdb;

        Functions\when('__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('current_time')->justReturn('2026-01-01 00:00:00');
        Functions\when('wp_json_encode')->alias('json_encode');
        Functions\when('get_current_user_id')->justReturn(0);
        Functions\when('error_log')->justReturn(true);
        Functions\when('add_action')->justReturn(true);

        Patchwork\replace('HMWEvents\\Services\\DatabaseService::get_table_name', function ($name) {
            return 'wp_hmwevents_' . $name;
        });
    }

    protected function tearDown(): void
    {
        Patchwork\restoreAll();
        Mockery::close();
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_invoice_service_is_registered(): void
    {
        $this->assertContains(InvoiceService::class, \HMWEvents\HMWEvents::get_components());
    }

    public function test_net_terms_handler_registers_invoice_hook(): void
    {
        $calls = [];
        Functions\when('add_action')->alias(function ($hook, $cb, $priority = 10, $args = 1) use (&$calls) {
            $calls[] = $hook;
            return true;
        });

        (new NetTermsHandler())->register();

        $this->assertContains('hmwevents_net_terms_booking_created', $calls);
    }

    public function test_assign_invoice_number_generates_and_persists(): void
    {
        $this->mockWpdb->shouldReceive('get_var')->once()->andReturn(null);
        $this->mockWpdb->shouldReceive('update')->once()->andReturn(1);

        $service = new InvoiceService();
        $number  = $service->assign_invoice_number(123);

        $this->assertSame('INV-' . gmdate('Y') . '-000123', $number);
    }

    public function test_assign_invoice_number_reuses_existing(): void
    {
        $this->mockWpdb->shouldReceive('get_var')->once()->andReturn(wp_json_encode(['invoice_number' => 'INV-2026-000999']));

        $service = new InvoiceService();
        $number  = $service->assign_invoice_number(123);

        $this->assertSame('INV-2026-000999', $number);
    }

    public function test_mark_paid_rejects_when_group_not_found(): void
    {
        $this->mockWpdb->shouldReceive('get_row')->once()->andReturn(null);

        $service = new InvoiceService();
        $result  = $service->mark_paid(123);

        $this->assertInstanceOf(\WP_Error::class, $result);
    }

    public function test_mark_paid_rejects_when_not_invoiced(): void
    {
        $group = (object) ['payment_status' => 'pending', 'total_amount' => '110.00', 'currency' => 'AUD'];
        $this->mockWpdb->shouldReceive('get_row')->once()->andReturn($group);

        $service = new InvoiceService();
        $result  = $service->mark_paid(123);

        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertSame('invalid_status', $result->get_error_code());
    }

    public function test_mark_paid_reconciles_and_fires_receipt_hook(): void
    {
        $group = (object) ['payment_status' => 'invoiced', 'total_amount' => '110.00', 'currency' => 'AUD'];
        $bookings = [
            (object) ['id' => 10],
            (object) ['id' => 11],
        ];

        $this->mockWpdb->shouldReceive('get_row')->once()->andReturn($group);
        $this->mockWpdb->shouldReceive('get_results')->once()->andReturn($bookings);
        $this->mockWpdb->shouldReceive('query')->andReturn(true);
        $this->mockWpdb->shouldReceive('update')->andReturn(1);
        $this->mockWpdb->shouldReceive('insert')->andReturn(1);

        $actions = [];
        Functions\when('do_action')->alias(function (...$args) use (&$actions) {
            $actions[] = $args;
            return true;
        });

        $service = new InvoiceService();
        $result  = $service->mark_paid(123, 'REF-100');

        $this->assertTrue($result);

        $receipts = array_values(array_filter($actions, fn($a) => ($a[0] ?? '') === 'hmwevents_payment_received'));
        $this->assertCount(2, $receipts);
        $this->assertSame(10, $receipts[0][1]);
        $this->assertSame(11, $receipts[1][1]);
    }

    public function test_get_outstanding_invoices_returns_rows(): void
    {
        $this->mockWpdb->shouldReceive('get_results')->once()->andReturn([(object) ['id' => 1]]);

        $service = new InvoiceService();
        $rows    = $service->get_outstanding_invoices();

        $this->assertCount(1, $rows);
    }

    public function test_render_receipt_html_shows_paid_status(): void
    {
        $data = [
            'invoice_number'     => 'INV-2026-000123',
            'invoice_date'       => 'January 1, 2026',
            'booking_reference'  => 'REF-ABC',
            'payment_status'     => 'paid',
            'customer_name'      => 'Jane Doe',
            'customer_address'   => '1 Main St, Sydney NSW 2000',
            'customer_email'     => 'jane@example.com',
            'customer_phone'     => '0400 000 000',
            'organisation'       => '',
            'event_title'        => 'Calmbirth Weekend Course',
            'ticket_quantity'    => 2,
            'unit_price'         => 50.0,
            'subtotal'           => 100.0,
            'gst'                => 0.0,
            'total'              => 100.0,
            'currency_symbol'    => '$',
            'bank'               => [
                'business_name' => 'Test Co',
                'abn'           => '12 345 678 901',
                'gst_applies'   => false,
            ],
        ];

        $service    = new InvoiceService();
        $reflection = new \ReflectionMethod(InvoiceService::class, 'render_receipt_html');
        $reflection->setAccessible(true);

        $html = $reflection->invoke($service, $data);

        $this->assertStringContainsString('Payment Status:', $html);
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('Total Paid:', $html);
        $this->assertStringNotContainsString('Total Due:', $html);
        $this->assertStringNotContainsString('Due Date:', $html);
        $this->assertStringNotContainsString('Payment Details — EFT', $html);
    }

    public function test_generate_receipt_pdf_returns_null_when_group_missing(): void
    {
        $this->mockWpdb->shouldReceive('get_row')->andReturn(null);

        $service = new InvoiceService();

        $this->assertNull($service->generate_receipt_pdf(123));
    }
}
