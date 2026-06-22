<?php

/**
 * Tests for BookingPdfGenerator.
 *
 * @package HMWEvents\Tests
 */

namespace HMWEvents\Tests\Unit\Services;

use HMWEvents\Services\BookingPdfGenerator;
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery;

class BookingPdfGeneratorTest extends TestCase
{
  private BookingPdfGenerator $generator;

  protected function setUp(): void
  {
    parent::setUp();
    Monkey\setUp();

    // Mock WordPress functions used by the generator
    Monkey\Functions\when('sanitize_text_field')->returnArg(1);
    Monkey\Functions\when('esc_html')->returnArg(1);
    Monkey\Functions\when('get_option')->justReturn(false);

    // Native PHP functions (date, strtotime, number_format, ucfirst)
    // are NOT mocked — Brain Monkey only intercepts functions you explicitly define.

    $this->generator = new BookingPdfGenerator();
  }

  protected function tearDown(): void
  {
    Mockery::close();
    Monkey\tearDown();
    parent::tearDown();
  }

  /**
   * Create a sample booking object.
   */
  private function createBooking(array $overrides = []): object
  {
    return (object) array_merge([
      'booking_number'        => 'BN-ABC123',
      'customer_name'         => 'Jane Doe',
      'course_name'           => 'Calmbirth Weekend Course',
      'payment_status'        => 'paid',
      'group_payment_type'    => 'full',
      'paid_amount'           => '450.00',
      'gateway_transaction_id' => 'pi_3abc123xyz',
    ], $overrides);
  }

  /**
   * Create sample metadata array.
   */
  private function createMeta(array $overrides = []): array
  {
    return array_merge([
      'currency_symbol'  => '£',
      'customer_email'   => 'jane@example.com',
      'customer_phone'   => '07700 900 123',
      'course_date'      => '2026-09-15',
      'course_time'      => '9:00 AM - 5:00 PM',
      'course_location'  => 'London Centre, EC1',
      'course_educator'  => 'Sarah Smith',
    ], $overrides);
  }

  // ────────────────────────────────────────────
  //  Fluent interface tests
  // ────────────────────────────────────────────

  /** @test */
  public function set_booking_returns_self(): void
  {
    $booking = $this->createBooking();
    $result = $this->generator->set_booking($booking);

    $this->assertSame($this->generator, $result);
  }

  /** @test */
  public function set_meta_returns_self(): void
  {
    $meta = $this->createMeta();
    $result = $this->generator->set_meta($meta);

    $this->assertSame($this->generator, $result);
  }

  /** @test */
  public function can_chain_set_booking_and_set_meta(): void
  {
    $booking = $this->createBooking();
    $meta = $this->createMeta();

    $result = $this->generator->set_booking($booking)->set_meta($meta);

    $this->assertSame($this->generator, $result);
  }

  // ────────────────────────────────────────────
  //  build_html() — complete booking
  // ────────────────────────────────────────────

  /** @test */
  public function build_html_contains_booking_number(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['booking_number' => 'BN-XYZ999']))
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('BN-XYZ999', $html);
    $this->assertStringContainsString('Booking Number:', $html);
  }

  /** @test */
  public function build_html_contains_customer_name_and_email(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['customer_name' => 'Alice Cooper']))
      ->set_meta($this->createMeta(['customer_email' => 'alice@example.com']));

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('Alice Cooper', $html);
    $this->assertStringContainsString('alice@example.com', $html);
  }

  /** @test */
  public function build_html_contains_course_details(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['course_name' => 'Advanced Course']))
      ->set_meta($this->createMeta([
        'course_date'     => '2026-10-01',
        'course_time'     => '10:00 AM',
        'course_location' => 'Manchester Hub',
        'course_educator' => 'John Trainer',
      ]));

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('Advanced Course', $html);
    $this->assertStringContainsString('October 1, 2026', $html);
    $this->assertStringContainsString('10:00 AM', $html);
    $this->assertStringContainsString('Manchester Hub', $html);
    $this->assertStringContainsString('John Trainer', $html);
  }

  /** @test */
  public function build_html_contains_payment_summary(): void
  {
    $this->generator
      ->set_booking($this->createBooking([
        'payment_status'        => 'paid',
        'group_payment_type'    => 'full',
        'paid_amount'           => '299.99',
      ]))
      ->set_meta($this->createMeta(['currency_symbol' => '£']));

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('Paid', $html);
    $this->assertStringContainsString('Full', $html);
    $this->assertStringContainsString('£299.99', $html);
    $this->assertStringContainsString('Amount Paid', $html);
  }

  /** @test */
  public function build_html_contains_transaction_id(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['gateway_transaction_id' => 'txn_789xyz']))
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('txn_789xyz', $html);
    $this->assertStringContainsString('Transaction ID', $html);
  }

  // ────────────────────────────────────────────
  //  build_html() — missing optional fields
  // ────────────────────────────────────────────

  /** @test */
  public function build_html_omits_educator_when_empty(): void
  {
    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($this->createMeta(['course_educator' => '']));

    $html = $this->invokeBuildHtml();

    $this->assertStringNotContainsString('Educator:', $html);
  }

  /** @test */
  public function build_html_omits_course_date_when_empty(): void
  {
    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($this->createMeta(['course_date' => '']));

    $html = $this->invokeBuildHtml();

    $this->assertStringNotContainsString('Date:', $html);
  }

  /** @test */
  public function build_html_omits_course_time_when_empty(): void
  {
    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($this->createMeta(['course_time' => '']));

    $html = $this->invokeBuildHtml();

    // Make sure no "Time:" label with empty value
    $this->assertStringNotContainsString('<td>Time:</td><td></td>', $html);
  }

  /** @test */
  public function build_html_omits_location_when_empty(): void
  {
    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($this->createMeta(['course_location' => '']));

    $html = $this->invokeBuildHtml();

    $this->assertStringNotContainsString('Location:', $html);
  }

  /** @test */
  public function build_html_omits_phone_when_empty(): void
  {
    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($this->createMeta(['customer_phone' => '']));

    $html = $this->invokeBuildHtml();

    $this->assertStringNotContainsString('Phone:', $html);
  }

  /** @test */
  public function build_html_omits_transaction_id_when_empty(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['gateway_transaction_id' => '']))
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    $this->assertStringNotContainsString('Transaction ID', $html);
  }

  // ────────────────────────────────────────────
  //  build_html() — deposit payment
  // ────────────────────────────────────────────

  /** @test */
  public function build_html_includes_deposit_notice(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['group_payment_type' => 'deposit']))
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('deposit payment', $html);
    $this->assertStringContainsString('remaining balance', $html);
  }

  /** @test */
  public function build_html_does_not_include_deposit_notice_for_full_payment(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['group_payment_type' => 'full']))
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    $this->assertStringNotContainsString('deposit payment', $html);
    $this->assertStringNotContainsString('remaining balance', $html);
  }

  // ────────────────────────────────────────────
  //  build_html() — voucher
  // ────────────────────────────────────────────

  /** @test */
  public function build_html_includes_voucher_when_present(): void
  {
    $voucher = (object) [
      'voucher_code'    => 'VOUCHER-25OFF',
      'redeemed_value'  => '25.00',
    ];

    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($this->createMeta(['voucher' => $voucher]));

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('VOUCHER-25OFF', $html);
    $this->assertStringContainsString('Voucher Applied', $html);
    $this->assertStringContainsString('£25.00', $html);
  }

  /** @test */
  public function build_html_does_not_include_voucher_when_null(): void
  {
    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($this->createMeta(['voucher' => null]));

    $html = $this->invokeBuildHtml();

    $this->assertStringNotContainsString('Voucher Applied', $html);
  }

  /** @test */
  public function build_html_does_not_include_voucher_when_key_missing(): void
  {
    $meta = $this->createMeta();
    unset($meta['voucher']);

    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($meta);

    $html = $this->invokeBuildHtml();

    $this->assertStringNotContainsString('Voucher Applied', $html);
  }

  // ────────────────────────────────────────────
  //  build_html() — currency fallback
  // ────────────────────────────────────────────

  /** @test */
  public function build_html_uses_default_currency_symbol_when_not_set(): void
  {
    $meta = $this->createMeta();
    unset($meta['currency_symbol']);

    $this->generator
      ->set_booking($this->createBooking(['paid_amount' => '100.00']))
      ->set_meta($meta);

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('£100.00', $html);
  }

  /** @test */
  public function build_html_accepts_different_currency_symbol(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['paid_amount' => '250.50']))
      ->set_meta($this->createMeta(['currency_symbol' => '$']));

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('$250.50', $html);
  }

  // ────────────────────────────────────────────
  //  build_html() — payment status casing
  // ────────────────────────────────────────────

  /** @test */
  public function build_html_uppercases_first_letter_of_payment_status(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['payment_status' => 'pending']))
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('Pending', $html);
    $this->assertStringNotContainsString('pendingpending', $html);
  }

  // ────────────────────────────────────────────
  //  build_html() — HTML structure
  // ────────────────────────────────────────────

  /** @test */
  public function build_html_produces_valid_html_structure(): void
  {
    $this->generator
      ->set_booking($this->createBooking())
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    // Should have doctype
    $this->assertStringContainsString('<!DOCTYPE html>', $html);
    // Should have closing tags
    $this->assertStringContainsString('</body>', $html);
    $this->assertStringContainsString('</html>', $html);
    // Should contain key structural elements
    $this->assertStringContainsString('Booking Confirmed!', $html);
    $this->assertStringContainsString('Your Booking Reference', $html);
    $this->assertStringContainsString('Course Details', $html);
    $this->assertStringContainsString('Your Details', $html);
    $this->assertStringContainsString('Payment Summary', $html);
  }

  /** @test */
  public function build_html_formats_amount_with_two_decimals(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['paid_amount' => '99.5']))
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('99.50', $html);
  }

  /** @test */
  public function build_html_handles_zero_amount(): void
  {
    $this->generator
      ->set_booking($this->createBooking(['paid_amount' => '0']))
      ->set_meta($this->createMeta());

    $html = $this->invokeBuildHtml();

    $this->assertStringContainsString('0.00', $html);
  }

  // ────────────────────────────────────────────
  //  Helper
  // ────────────────────────────────────────────

  /**
   * Invoke the private build_html() method via reflection.
   */
  private function invokeBuildHtml(): string
  {
    $reflection = new \ReflectionClass($this->generator);
    $method = $reflection->getMethod('build_html');

    return $method->invoke($this->generator);
  }
}
