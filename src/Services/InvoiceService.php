<?php

/**
 * Invoice Service.
 *
 * Generates tax invoices for Net Terms bookings, emails them to the
 * customer as PDF attachments, and reconciles EFT / manual payments.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use HMWEvents\Helpers\ConfigHelper;
use HMWEvents\Services\Emails\EmailAttachmentService;
use HMWEvents\Services\Emails\EmailService;

defined('ABSPATH') || die('Don\'t run this file directly!');

class InvoiceService
{
    private const UPLOAD_SUBDIR = 'hmwevents-invoices';

    private ?EmailService $email_service = null;
    private ?EmailAttachmentService $attachment_service = null;
    private ?EventDataService $event_data_service = null;

    private function email_service(): EmailService
    {
        if ($this->email_service === null) {
            $this->email_service = new EmailService();
        }
        return $this->email_service;
    }

    private function attachment_service(): EmailAttachmentService
    {
        if ($this->attachment_service === null) {
            $this->attachment_service = new EmailAttachmentService();
        }
        return $this->attachment_service;
    }

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

    /**
     * Bank / EFT details configured under HMW Events → Invoicing.
     *
     * @return array
     */
    public function get_bank_details(): array
    {
        return [
            'business_name'       => trim((string) ConfigHelper::get_option('hmwevents_invoice_business_name', '')),
            'abn'                 => trim((string) ConfigHelper::get_option('hmwevents_invoice_abn', '')),
            'account_name'        => trim((string) ConfigHelper::get_option('hmwevents_invoice_bank_account_name', '')),
            'bsb'                 => trim((string) ConfigHelper::get_option('hmwevents_invoice_bank_bsb', '')),
            'account_number'      => trim((string) ConfigHelper::get_option('hmwevents_invoice_bank_account_number', '')),
            'payment_terms_days'  => max(0, (int) ConfigHelper::get_option('hmwevents_invoice_payment_terms_days', 14)),
            'reference_note'      => trim((string) ConfigHelper::get_option('hmwevents_invoice_reference_note', '')),
            'gst_applies'         => (bool) ConfigHelper::get_option('hmwevents_invoice_gst_applies', false),
        ];
    }

    /**
     * Issue the invoice for a net terms booking: assign an invoice number,
     * generate the PDF, and queue the customer email with the PDF attached.
     *
     * @param int $booking_id Bookings table ID.
     * @param int $group_id   Booking group ID.
     * @return bool
     */
    public function issue_invoice(int $booking_id, int $group_id): bool
    {
        $data = $this->get_invoice_data($group_id);
        if ($data === null) {
            return false;
        }

        $invoice_number = $data['invoice_number'];

        $pdf_path = $this->generate_pdf($group_id);
        if ($pdf_path === null) {
            return false;
        }

        $email_id = $this->email_service()->queue_notification([
            'email_type'      => 'invoice_issued',
            'recipient_email' => $data['customer_email'],
            'recipient_name'  => $data['customer_name'],
            'booking_id'      => $booking_id,
            'organizer_id'    => $data['organizer_id'],
            'template_data'   => [
                'first_name'     => $data['customer_first_name'],
                'event_title'    => $data['event_title'],
                'amount'         => $data['amount_formatted'],
                'invoice_number' => $invoice_number,
                'due_date'       => $data['due_date_formatted'],
                'bank_details'   => $data['bank_details_html'],
            ],
        ]);

        if (!$email_id) {
            return false;
        }

        $this->attachment_service()->add($email_id, $pdf_path, $invoice_number . '.pdf');

        $this->mark_invoice_sent($group_id, $invoice_number);

        return true;
    }

    /**
     * Regenerate and resend the invoice for an existing net terms booking.
     *
     * @param int $booking_id Bookings table ID.
     * @return true|\WP_Error
     */
    public function resend_invoice(int $booking_id): true|\WP_Error
    {
        global $wpdb;

        $bookings_table = DatabaseService::get_table_name('bookings');

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT booking_group_id FROM {$bookings_table} WHERE id = %d AND deleted_at IS NULL",
            $booking_id
        ));

        if (!$booking) {
            return new \WP_Error('booking_not_found', __('Booking not found.', 'hmw-events'));
        }

        $group = $wpdb->get_row($wpdb->prepare(
            'SELECT * FROM ' . DatabaseService::get_table_name('booking_groups') . ' WHERE id = %d',
            (int) $booking->booking_group_id
        ));

        if (!$group || $group->payment_status !== 'invoiced') {
            return new \WP_Error('invalid_status', __('Only invoiced bookings can have an invoice resent.', 'hmw-events'));
        }

        if (!$this->issue_invoice($booking_id, (int) $group->id)) {
            return new \WP_Error('invoice_failed', __('Failed to generate and queue the invoice.', 'hmw-events'));
        }

        return true;
    }

    /**
     * Reconcile an EFT / manual payment against an invoiced booking group.
     *
     * Updates the group and its bookings to paid, records the transaction,
     * writes an audit entry, and fires the payment-received hook so a
     * receipt email is queued.
     *
     * @param int    $group_id  Booking group ID.
     * @param string $reference Payment reference supplied by the payer.
     * @return true|\WP_Error
     */
    public function mark_paid(int $group_id, string $reference = ''): true|\WP_Error
    {
        global $wpdb;

        $groups_table   = DatabaseService::get_table_name('booking_groups');
        $bookings_table = DatabaseService::get_table_name('bookings');
        $tx_table       = DatabaseService::get_table_name('payment_transactions');
        $history_table  = DatabaseService::get_table_name('booking_history');

        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$groups_table} WHERE id = %d",
            $group_id
        ));

        if (!$group) {
            return new \WP_Error('not_found', __('Booking group not found.', 'hmw-events'));
        }

        if ($group->payment_status !== 'invoiced') {
            return new \WP_Error('invalid_status', __('Only invoiced bookings can be reconciled as paid.', 'hmw-events'));
        }

        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT id FROM {$bookings_table} WHERE booking_group_id = %d",
            $group_id
        ));

        $reference = $reference !== '' ? $reference : 'eft_' . gmdate('YmdHis');

        $wpdb->query('START TRANSACTION');

        try {
            $wpdb->update(
                $groups_table,
                ['payment_status' => 'paid', 'updated_at' => current_time('mysql')],
                ['id' => $group_id],
                ['%s', '%s'],
                ['%d']
            );

            $wpdb->update(
                $bookings_table,
                ['payment_status' => 'paid', 'updated_at' => current_time('mysql')],
                ['booking_group_id' => $group_id],
                ['%s', '%s'],
                ['%d']
            );

            $wpdb->insert($tx_table, [
                'booking_group_id'       => $group_id,
                'transaction_type'       => 'charge',
                'amount'                 => $group->total_amount,
                'currency'               => $group->currency ?: 'AUD',
                'payment_gateway'        => 'manual',
                'gateway'                => 'manual',
                'gateway_transaction_id' => $reference,
                'status'                 => 'succeeded',
                'metadata'               => wp_json_encode(['type' => 'eft_settlement', 'reference' => $reference]),
                'created_at'             => current_time('mysql'),
            ], ['%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

            foreach ($bookings as $booking) {
                $wpdb->insert($history_table, [
                    'booking_id'    => (int) $booking->id,
                    'field_changed' => 'payment_status',
                    'old_value'     => 'invoiced',
                    'new_value'     => 'paid',
                    'changed_by'    => get_current_user_id(),
                    'change_reason' => $reference !== '' ? sprintf('Invoice paid via EFT (ref: %s)', $reference) : 'Invoice paid via EFT',
                    'created_at'    => current_time('mysql'),
                ], ['%d', '%s', '%s', '%s', '%d', '%s', '%s']);
            }

            $wpdb->query('COMMIT');
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('reconcile_failed', $e->getMessage());
        }

        foreach ($bookings as $booking) {
            do_action('hmwevents_payment_received', (int) $booking->id, ['payment_status' => 'paid']);
        }

        return true;
    }

    /**
     * Get all booking groups currently awaiting payment.
     *
     * @return object[]
     */
    public function get_outstanding_invoices(): array
    {
        global $wpdb;

        $table = DatabaseService::get_table_name('booking_groups');

        return $wpdb->get_results(
            "SELECT * FROM {$table} WHERE payment_status = 'invoiced' ORDER BY created_at DESC"
        ) ?: [];
    }

    /**
     * Build the full invoice data model for a booking group.
     *
     * @param int $group_id Booking group ID.
     * @return array|null
     */
    public function get_invoice_data(int $group_id): ?array
    {
        global $wpdb;

        $groups_table   = DatabaseService::get_table_name('booking_groups');
        $bookings_table = DatabaseService::get_table_name('bookings');

        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$groups_table} WHERE id = %d",
            $group_id
        ));

        if (!$group) {
            return null;
        }

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$bookings_table} WHERE booking_group_id = %d ORDER BY id ASC LIMIT 1",
            $group_id
        ));

        if (!$booking) {
            return null;
        }

        $registrant_id = (int) $group->registrant_post_id;
        $event_id      = (int) $booking->event_post_id;

        $first_name = (string) get_post_meta($registrant_id, 'registrant_first_name', true);
        $last_name  = (string) get_post_meta($registrant_id, 'registrant_last_name', true);
        $name       = trim($first_name . ' ' . $last_name);
        if ($name === '') {
            $name = get_the_title($registrant_id) ?: '';
        }

        $address_parts = array_filter([
            (string) get_post_meta($registrant_id, 'registrant_address', true),
            (string) get_post_meta($registrant_id, 'registrant_suburb', true),
            (string) get_post_meta($registrant_id, 'registrant_state', true),
            (string) get_post_meta($registrant_id, 'registrant_postcode', true),
        ]);

        $currency   = $group->currency ?: $this->event_data()->get_currency($event_id);
        $symbol     = \HMWEvents\Meta\CourseMeta::get_currency_symbol($currency);
        $total      = (float) $group->total_amount;
        $event_date = $this->event_data()->get_start_date($event_id);
        $event_date = $event_date ? date('F j, Y', strtotime($event_date)) : '';

        $bank           = $this->get_bank_details();
        $invoice_number = $this->assign_invoice_number($group_id);
        $invoice_date   = current_time('mysql');
        $due_timestamp  = strtotime($invoice_date) + ($bank['payment_terms_days'] * DAY_IN_SECONDS);

        $gst = $this->calculate_gst($total, $bank['gst_applies']);

        $bank['reference_note'] = $this->apply_reference_note_merge_tags($bank['reference_note'], $invoice_number);

        return [
            'group_id'           => $group_id,
            'booking_id'         => (int) $booking->id,
            'invoice_number'     => $invoice_number,
            'booking_number'     => $booking->booking_number,
            'booking_reference'  => $group->booking_reference,
            'payment_status'     => $group->payment_status ?? '',
            'customer_name'      => $name,
            'customer_first_name'=> $first_name,
            'customer_email'     => (string) get_post_meta($registrant_id, 'registrant_email', true),
            'customer_phone'     => (string) get_post_meta($registrant_id, 'registrant_phone', true),
            'customer_address'   => implode(', ', $address_parts),
            'organisation'       => (string) get_post_meta($registrant_id, 'registrant_organisation', true),
            'event_title'        => get_the_title($event_id) ?: '',
            'event_date'         => $event_date,
            'event_location'     => (string) $this->event_data()->get_venue_name($event_id),
            'ticket_quantity'    => (int) $booking->ticket_quantity,
            'unit_price'         => round($gst['subtotal'] / max(1, (int) $booking->ticket_quantity), 2),
            'subtotal'           => $gst['subtotal'],
            'gst'                => $gst['gst'],
            'total'              => $total,
            'currency'           => $currency,
            'currency_symbol'    => $symbol,
            'amount_formatted'   => $symbol . number_format($total, 2),
            'invoice_date'       => date('F j, Y', strtotime($invoice_date)),
            'due_date'           => gmdate('Y-m-d', $due_timestamp),
            'due_date_formatted' => date('F j, Y', $due_timestamp),
            'organizer_id'       => $this->event_data()->get_organizer_id($event_id),
            'bank'               => $bank,
            'bank_details_html'  => $this->build_bank_details_html($bank),
        ];
    }

    /**
     * Resolve or create the invoice number for a booking group.
     *
     * @param int $group_id Booking group ID.
     * @return string
     */
    public function assign_invoice_number(int $group_id): string
    {
        $metadata = $this->read_metadata($group_id);

        if (!empty($metadata['invoice_number'])) {
            return (string) $metadata['invoice_number'];
        }

        $number = 'INV-' . gmdate('Y') . '-' . str_pad((string) $group_id, 6, '0', STR_PAD_LEFT);

        $metadata['invoice_number'] = $number;
        $this->write_metadata($group_id, $metadata);

        return $number;
    }

    /**
     * Generate the invoice PDF and return its filesystem path.
     *
     * @param int $group_id Booking group ID.
     * @return string|null
     */
    public function generate_pdf(int $group_id): ?string
    {
        $data = $this->get_invoice_data($group_id);
        if ($data === null) {
            return null;
        }

        return $this->render_pdf_file(
            $this->render_invoice_html($data),
            $this->assign_invoice_number($group_id) . '.pdf'
        );
    }

    /**
     * Generate a "paid" tax invoice / receipt PDF and return its filesystem path.
     *
     * @param int $group_id Booking group ID.
     * @return string|null
     */
    public function generate_receipt_pdf(int $group_id): ?string
    {
        $data = $this->get_invoice_data($group_id);
        if ($data === null) {
            return null;
        }

        return $this->render_pdf_file(
            $this->render_receipt_html($data),
            $this->assign_invoice_number($group_id) . '-receipt.pdf'
        );
    }

    /**
     * Render HTML to a PDF file on disk and return the path.
     *
     * @param string $html     Invoice/receipt HTML.
     * @param string $filename Target file name.
     * @return string|null
     */
    private function render_pdf_file(string $html, string $filename): ?string
    {
        try {
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

            $upload_dir = wp_upload_dir();
            $directory  = $upload_dir['basedir'] . '/' . self::UPLOAD_SUBDIR;

            if (!wp_mkdir_p($directory)) {
                return null;
            }

            $filepath = $directory . '/' . $filename;

            if (file_put_contents($filepath, $dompdf->output()) === false) {
                return null;
            }

            return $filepath;
        } catch (\Throwable $e) {
            error_log('HMWEvents invoice PDF error: ' . $e->getMessage());
            return null;
        }
    }

    private function calculate_gst(float $total, bool $gst_applies): array
    {
        if (!$gst_applies || $total <= 0) {
            return ['subtotal' => $total, 'gst' => 0.0];
        }

        $breakdown = \HMWEvents\Services\GstCalculator::from_inc_gst($total);

        return ['subtotal' => $breakdown['ex_gst'], 'gst' => $breakdown['gst']];
    }

    private function apply_reference_note_merge_tags(string $note, string $invoice_number): string
    {
        if ($note === '' || $invoice_number === '') {
            return $note;
        }

        return str_replace('{{invoice_number}}', $invoice_number, $note);
    }

    private function build_bank_details_html(array $bank): string
    {
        if ($bank['account_name'] === '' && $bank['bsb'] === '' && $bank['account_number'] === '') {
            return '';
        }

        $rows = '';

        if ($bank['account_name'] !== '') {
            $rows .= '<tr><td style="padding:2px 0;color:#555;">Account Name:</td><td style="padding:2px 0;font-weight:600;">' . esc_html($bank['account_name']) . '</td></tr>';
        }
        if ($bank['bsb'] !== '') {
            $rows .= '<tr><td style="padding:2px 0;color:#555;">BSB:</td><td style="padding:2px 0;font-weight:600;">' . esc_html($bank['bsb']) . '</td></tr>';
        }
        if ($bank['account_number'] !== '') {
            $rows .= '<tr><td style="padding:2px 0;color:#555;">Account Number:</td><td style="padding:2px 0;font-weight:600;">' . esc_html($bank['account_number']) . '</td></tr>';
        }
        if ($bank['reference_note'] !== '') {
            $rows .= '<tr><td style="padding:2px 0;color:#555;">Reference:</td><td style="padding:2px 0;">' . esc_html($bank['reference_note']) . '</td></tr>';
        }

        return '<table style="margin-top:6px;">' . $rows . '</table>';
    }

    private function read_metadata(int $group_id): array
    {
        global $wpdb;

        $raw = $wpdb->get_var($wpdb->prepare(
            'SELECT metadata FROM ' . DatabaseService::get_table_name('booking_groups') . ' WHERE id = %d',
            $group_id
        ));

        $decoded = $raw ? json_decode($raw, true) : [];

        return is_array($decoded) ? $decoded : [];
    }

    private function write_metadata(int $group_id, array $metadata): void
    {
        global $wpdb;

        $wpdb->update(
            DatabaseService::get_table_name('booking_groups'),
            ['metadata' => wp_json_encode($metadata)],
            ['id' => $group_id],
            ['%s'],
            ['%d']
        );
    }

    private function mark_invoice_sent(int $group_id, string $invoice_number): void
    {
        $metadata = $this->read_metadata($group_id);
        $metadata['invoice_number'] = $invoice_number;
        $metadata['invoice_sent_at'] = current_time('mysql');
        $this->write_metadata($group_id, $metadata);
    }

    private function render_invoice_html(array $d): string
    {
        $b = $d['bank'];

        $seller = $b['business_name'] !== '' ? $b['business_name'] : get_bloginfo('name');
        $abn    = $b['abn'] !== '' ? '<br><span style="font-size:9px;color:#6b7280;">ABN ' . esc_html($b['abn']) . '</span>' : '';

        $organisation = $d['organisation'] !== '' ? esc_html($d['organisation']) . '<br>' : '';
        $address_line = $d['customer_address'] !== '' ? esc_html($d['customer_address']) . '<br>' : '';
        $email_line   = $d['customer_email'] !== '' ? esc_html($d['customer_email']) . '<br>' : '';
        $phone_line   = $d['customer_phone'] !== '' ? esc_html($d['customer_phone']) . '<br>' : '';

        $gst_row = '';
        if ($b['gst_applies']) {
            $gst_row = '<tr><td style="padding:3px 0;color:#555;">GST (10%):</td><td style="padding:3px 0;text-align:right;">' . $d['currency_symbol'] . number_format($d['gst'], 2) . '</td></tr>';
        }

        $bank_section = '';
        if ($d['bank_details_html'] !== '') {
            $bank_section = '<div class="section" style="background:#f9fafb;"><h2>Payment Details — EFT</h2><p style="margin:0;font-size:10px;">Please transfer the total amount to the account below and use your invoice number as the reference.</p>' . $d['bank_details_html'] . '</div>';
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
  .card { border:1px solid #d1d5db; }
  .section { padding:10px 14px; border-bottom:1px solid #e5e7eb; }
  .section:last-child { border-bottom:none; }
  h2 { font-size:11px; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:6px; }
  table { width:100%; border-collapse:collapse; }
  table.details td { padding:2px 0; vertical-align:top; }
  table.details td:first-child { width:35%; font-size:9px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; }
  table.details td:last-child { font-size:10px; }
  .total-row td { font-weight:700; font-size:12px; color:#000; }
  .total-row td:last-child { font-size:14px; }
  @page { margin:0.4in; }
</style>
</head>
<body>
  <h1>Tax Invoice</h1>
  <p class="subtitle">{$seller}{$abn}</p>

  <div class="card">
    <div class="section">
      <table class="details">
        <tr><td>Invoice Number:</td><td>{$d['invoice_number']}</td></tr>
        <tr><td>Invoice Date:</td><td>{$d['invoice_date']}</td></tr>
        <tr><td>Due Date:</td><td>{$d['due_date_formatted']}</td></tr>
        <tr><td>Booking Reference:</td><td>{$d['booking_reference']}</td></tr>
      </table>
    </div>

    <div class="section">
      <h2>Billed To</h2>
      <table class="details">
        <tr><td>Name:</td><td>{$organisation}{$d['customer_name']}</td></tr>
        <tr><td>Address:</td><td>{$address_line}{$email_line}{$phone_line}</td></tr>
      </table>
    </div>

    <div class="section">
      <h2>Description</h2>
      <table style="margin-bottom:8px;">
        <tr>
          <th style="text-align:left;font-size:9px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;padding:3px 0;">Item</th>
          <th style="text-align:center;font-size:9px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;padding:3px 0;">Qty</th>
          <th style="text-align:right;font-size:9px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;padding:3px 0;">Unit Price</th>
          <th style="text-align:right;font-size:9px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;padding:3px 0;">Amount</th>
        </tr>
        <tr>
          <td style="padding:4px 0;">Registration — {$d['event_title']}</td>
          <td style="padding:4px 0;text-align:center;">{$d['ticket_quantity']}</td>
          <td style="padding:4px 0;text-align:right;">{$d['currency_symbol']}{$d['unit_price']}</td>
          <td style="padding:4px 0;text-align:right;">{$d['currency_symbol']}{$d['subtotal']}</td>
        </tr>
      </table>

      <table class="details" style="margin-top:4px;">
        {$gst_row}
        <tr class="total-row" style="border-top:1.5px solid #9ca3af;">
          <td style="padding-top:6px;">Total Due:</td>
          <td style="padding-top:6px;text-align:right;">{$d['currency_symbol']}{$d['total']}</td>
        </tr>
      </table>
    </div>

    {$bank_section}
  </div>
</body>
</html>
HTML;
    }

    private function render_receipt_html(array $d): string
    {
        $b = $d['bank'];

        $seller = $b['business_name'] !== '' ? $b['business_name'] : get_bloginfo('name');
        $abn    = $b['abn'] !== '' ? '<br><span style="font-size:9px;color:#6b7280;">ABN ' . esc_html($b['abn']) . '</span>' : '';

        $organisation = $d['organisation'] !== '' ? esc_html($d['organisation']) . '<br>' : '';
        $address_line = $d['customer_address'] !== '' ? esc_html($d['customer_address']) . '<br>' : '';
        $email_line   = $d['customer_email'] !== '' ? esc_html($d['customer_email']) . '<br>' : '';
        $phone_line   = $d['customer_phone'] !== '' ? esc_html($d['customer_phone']) . '<br>' : '';

        $gst_row = '';
        if ($b['gst_applies']) {
            $gst_row = '<tr><td style="padding:3px 0;color:#555;">GST (10%):</td><td style="padding:3px 0;text-align:right;">' . $d['currency_symbol'] . number_format($d['gst'], 2) . '</td></tr>';
        }

        $paid_label = $d['payment_status'] === 'paid' ? 'Paid' : ucfirst((string) $d['payment_status']);

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
  .card { border:1px solid #d1d5db; }
  .section { padding:10px 14px; border-bottom:1px solid #e5e7eb; }
  .section:last-child { border-bottom:none; }
  h2 { font-size:11px; text-transform:uppercase; letter-spacing:0.03em; margin-bottom:6px; }
  table { width:100%; border-collapse:collapse; }
  table.details td { padding:2px 0; vertical-align:top; }
  table.details td:first-child { width:35%; font-size:9px; color:#6b7280; font-weight:600; text-transform:uppercase; letter-spacing:0.03em; }
  table.details td:last-child { font-size:10px; }
  .total-row td { font-weight:700; font-size:12px; color:#000; }
  .total-row td:last-child { font-size:14px; }
  .paid-badge { display:inline-block; padding:2px 8px; border-radius:3px; background:#16a34a; color:#fff; font-size:10px; font-weight:700; letter-spacing:0.05em; }
  @page { margin:0.4in; }
</style>
</head>
<body>
  <h1>Tax Invoice</h1>
  <p class="subtitle">{$seller}{$abn}</p>

  <div class="card">
    <div class="section">
      <table class="details">
        <tr><td>Invoice Number:</td><td>{$d['invoice_number']}</td></tr>
        <tr><td>Invoice Date:</td><td>{$d['invoice_date']}</td></tr>
        <tr><td>Payment Status:</td><td><span class="paid-badge">{$paid_label}</span></td></tr>
        <tr><td>Booking Reference:</td><td>{$d['booking_reference']}</td></tr>
      </table>
    </div>

    <div class="section">
      <h2>Billed To</h2>
      <table class="details">
        <tr><td>Name:</td><td>{$organisation}{$d['customer_name']}</td></tr>
        <tr><td>Address:</td><td>{$address_line}{$email_line}{$phone_line}</td></tr>
      </table>
    </div>

    <div class="section">
      <h2>Description</h2>
      <table style="margin-bottom:8px;">
        <tr>
          <th style="text-align:left;font-size:9px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;padding:3px 0;">Item</th>
          <th style="text-align:center;font-size:9px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;padding:3px 0;">Qty</th>
          <th style="text-align:right;font-size:9px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;padding:3px 0;">Unit Price</th>
          <th style="text-align:right;font-size:9px;text-transform:uppercase;color:#6b7280;border-bottom:1px solid #e5e7eb;padding:3px 0;">Amount</th>
        </tr>
        <tr>
          <td style="padding:4px 0;">Registration — {$d['event_title']}</td>
          <td style="padding:4px 0;text-align:center;">{$d['ticket_quantity']}</td>
          <td style="padding:4px 0;text-align:right;">{$d['currency_symbol']}{$d['unit_price']}</td>
          <td style="padding:4px 0;text-align:right;">{$d['currency_symbol']}{$d['subtotal']}</td>
        </tr>
      </table>

      <table class="details" style="margin-top:4px;">
        {$gst_row}
        <tr class="total-row" style="border-top:1.5px solid #9ca3af;">
          <td style="padding-top:6px;">Total Paid:</td>
          <td style="padding-top:6px;text-align:right;">{$d['currency_symbol']}{$d['total']}</td>
        </tr>
      </table>
    </div>
  </div>
</body>
</html>
HTML;
    }
}
