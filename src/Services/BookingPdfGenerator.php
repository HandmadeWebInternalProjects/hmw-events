<?php

/**
 * Booking Confirmation PDF Generator
 *
 * Generates a downloadable PDF of the booking confirmation.
 * The PDF is streamed directly to the browser and never stored on disk.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

defined('ABSPATH') || die('Don\'t run this file directly!');

class BookingPdfGenerator
{
  /** @var object Booking data from the database */
  private $booking;

  /** @var array Additional booking metadata */
  private $meta = [];

  /**
   * Set booking data.
   *
   * @param object $booking
   * @return $this
   */
  public function set_booking($booking)
  {
    $this->booking = $booking;
    return $this;
  }

  /**
   * Set additional metadata.
   *
   * @param array $meta
   * @return $this
   */
  public function set_meta(array $meta)
  {
    $this->meta = $meta;
    return $this;
  }

  /**
   * Generate and stream the PDF.
   *
   * Outputs the PDF directly to the browser and exits.
   */
  public function stream()
  {
    $html = $this->build_html();

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    $options->set('defaultPaperSize', 'a4');
    $options->set('defaultPaperOrientation', 'portrait');
    $options->set('dpi', 96);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    // Stream to browser - no file stored on disk
    $dompdf->stream(
      'booking-confirmation-' . sanitize_text_field($this->booking->booking_number) . '.pdf',
      ['Attachment' => true]
    );
    exit;
  }

  /**
   * Build the HTML for the PDF.
   *
   * @return string
   */
  private function build_html()
  {
    $b = $this->booking;
    $m = $this->meta;

    $currency_symbol = $m['currency_symbol'] ?? '£';
    $customer_name   = esc_html($b->customer_name);
    $registrant_email  = esc_html($m['registrant_email'] ?? ($m['customer_email'] ?? ''));
    $customer_phone  = esc_html($m['customer_phone'] ?? '');
    $course_name     = esc_html($b->course_name);
    $course_date     = !empty($m['course_date']) ? date('F j, Y', strtotime($m['course_date'])) : '';
    $course_time     = esc_html($m['course_time'] ?? '');
    $course_location = esc_html($m['course_location'] ?? '');
    $course_educator = esc_html($m['course_educator'] ?? '');
    $booking_number  = esc_html($b->booking_number);
    $payment_status  = esc_html(ucfirst($b->payment_status ?? ''));
    $payment_type    = esc_html(ucfirst($b->group_payment_type ?? ''));
    $amount_paid     = number_format((float) ($b->paid_amount ?? 0), 2);
    $transaction_id  = esc_html($b->gateway_transaction_id ?? '');
    $is_deposit      = ($b->group_payment_type ?? '') === 'deposit';

    // Voucher
    $voucher_html = '';
    if (!empty($m['voucher'])) {
      $v_code  = esc_html($m['voucher']->voucher_code);
      $v_value = number_format((float) $m['voucher']->redeemed_value, 2);
      $voucher_html = <<<HTML
        <tr>
          <td style="padding:3px 0; color:#555;">Voucher Applied:</td>
          <td style="padding:3px 0; text-align:right;">{$v_code} (-{$currency_symbol}{$v_value})</td>
        </tr>
      HTML;
    }

    $deposit_notice = '';
    if ($is_deposit) {
      $deposit_notice = <<<HTML
        <div style="margin-top:8px; padding:6px 8px; background:#fef3c7; border-left:3px solid #f59e0b; font-size:9px; color:#92400e;">
          <strong>Note:</strong> This is a deposit payment. The remaining balance will be organised by the educator on or before your course date.
        </div>
      HTML;
    }

    $transaction_row = '';
    if ($transaction_id) {
      $transaction_row = <<<HTML
        <tr>
          <td style="padding:3px 0; color:#555; border-top:1px solid #ddd; padding-top:6px;">Transaction ID:</td>
          <td style="padding:3px 0; text-align:right; font-size:10px; color:#777; border-top:1px solid #ddd; padding-top:6px;">{$transaction_id}</td>
        </tr>
      HTML;
    }

    return <<<HTML
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="utf-8">
    <style>
      * { margin:0; padding:0; box-sizing:border-box; }
      body { font-family:Helvetica, Arial, sans-serif; font-size:11px; line-height:1.35; color:#1f2937; padding:20px; }
      h1 { font-size:16px; margin-bottom:4px; }
      .subtitle { font-size:10px; color:#6b7280; margin-bottom:16px; }
      .card { border:1px solid #d1d5db; border-radius:0; }
      .section { padding:10px 14px; border-bottom:1px solid #e5e7eb; }
      .section:last-child { border-bottom:none; }
      .section.highlight { background:#f9fafb; }
      h2 { font-size:11px; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:6px; }
      .ref-box { display:flex; align-items:center; gap:10px; padding:8px 12px; border:1px dashed #9ca3af; background:#fff; }
      .ref-label { font-size:9px; color:#6b7280; }
      .ref-number { font-size:14px; font-weight:700; font-family:monospace; }
      table { width:100%; border-collapse:collapse; }
      table.details td { padding:2px 0; vertical-align:top; }
      table.details td:first-child { width:30%; font-size:9px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; }
      table.details td:last-child { font-size:10px; }
      .total-row td { font-weight:700; font-size:12px; color:#000; }
      .total-row td:last-child { font-size:14px; }
      .section-label { font-size:9px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; }
      .section-value { font-size:10px; }
      @page { margin:0.4in; }
    </style>
    </head>
    <body>
      <h1>Booking Confirmed!</h1>
      <p class="subtitle">Thank you for your booking. We look forward to seeing you!</p>

      <div class="card">
        <div class="section highlight">
          <h2>Your Booking Reference</h2>
          <div class="ref-box">
            <span class="ref-label">Booking Number:</span>
            <span class="ref-number">{$booking_number}</span>
          </div>
        </div>

        <div class="section">
          <h2>Course Details</h2>
          <table class="details">
            <tr><td>Course:</td><td>{$course_name}</td></tr>
HTML
      . ($course_educator ? "<tr><td>Educator:</td><td>{$course_educator}</td></tr>" : '')
      . ($course_date ? "<tr><td>Date:</td><td>{$course_date}</td></tr>" : '')
      . ($course_time ? "<tr><td>Time:</td><td>{$course_time}</td></tr>" : '')
      . ($course_location ? "<tr><td>Location:</td><td>{$course_location}</td></tr>" : '')
      . <<<HTML
          </table>
        </div>

        <div class="section">
          <h2>Your Details</h2>
          <table class="details">
            <tr><td>Name:</td><td>{$customer_name}</td></tr>
            <tr><td>Email:</td><td>{$registrant_email}</td></tr>
HTML
      . ($customer_phone ? "<tr><td>Phone:</td><td>{$customer_phone}</td></tr>" : '')
      . <<<HTML
          </table>
        </div>

        <div class="section">
          <h2>Payment Summary</h2>
          <table class="details" style="margin-bottom:0;">
            <tr>
              <td style="width:40%;">Payment Status:</td>
              <td style="text-align:right;">{$payment_status}</td>
            </tr>
            <tr>
              <td style="width:40%;">Payment Type:</td>
              <td style="text-align:right;">{$payment_type}</td>
            </tr>
            {$voucher_html}
            {$transaction_row}
            <tr class="total-row" style="border-top:1.5px solid #9ca3af;">
              <td style="padding-top:6px;">Amount Paid:</td>
              <td style="padding-top:6px; text-align:right;">{$currency_symbol}{$amount_paid}</td>
            </tr>
          </table>
          {$deposit_notice}
        </div>
      </div>
    </body>
    </html>
    HTML;
  }
}
