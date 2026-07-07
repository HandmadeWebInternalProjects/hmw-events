<?php

/**
 * Net Terms Handler.
 *
 * Manages the "Pay Later" / Net Terms registration flow.
 * When an event supports invoiced payment, the booking is created
 * with payment_status = 'invoiced' and no upfront Stripe charge.
 *
 * An invoice can be generated and payment tracked separately.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class NetTermsHandler
{
    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('hmwevents_net_terms_registration', [$this, 'handle_net_terms_registration'], 10, 3);
        add_action('hmwevents_booking_created', [$this, 'attach_invoice_if_net_terms'], 10, 2);
    }

    /**
     * Check if an event supports Net Terms payment.
     *
     * Reads from event meta: _event_allow_net_terms
     */
    public function event_supports_net_terms(int $event_id): bool
    {
        return (bool) get_post_meta($event_id, '_event_allow_net_terms', true);
    }

    /**
     * Handle a Net Terms registration.
     *
     * Creates the booking with invoiced status.
     *
     * @param int    $event_id
     * @param array  $registration_data
     * @param string $attendance_type
     */
    public function handle_net_terms_registration(int $event_id, array $registration_data, string $attendance_type): void
    {
        $gst = GstCalculator::booking_breakdown(
            (float) ($registration_data['amount'] ?? 0),
            false
        );

        $invoice_data = [
            'event_id'        => $event_id,
            'attendance_type' => $attendance_type,
            'payment_type'    => 'net_terms',
            'payment_status'  => 'invoiced',
            'amount'          => $gst['final_total'],
            'gst_breakdown'   => $gst,
        ];

        /**
         * Action: hmwevents_invoice_created
         *
         * Consumers should create the booking group with invoiced status
         * and trigger invoice generation/email.
         *
         * @param int   $event_id
         * @param array $invoice_data
         * @param array $registration_data
         */
        do_action('hmwevents_invoice_created', $event_id, $invoice_data, $registration_data);
    }

    /**
     * Attach invoice metadata when a booking is created with Net Terms.
     *
     * @param int   $booking_id
     * @param array $booking_data
     */
    public function attach_invoice_if_net_terms(int $booking_id, array $booking_data): void
    {
        if (($booking_data['payment_type'] ?? '') !== 'net_terms') {
            return;
        }

        global $wpdb;
        $table = DatabaseService::get_table_name('bookings');

        $wpdb->update(
            $table,
            ['payment_status' => 'invoiced'],
            ['id' => $booking_id],
            ['%s'],
            ['%d']
        );
    }

    /**
     * Mark an invoiced booking as paid.
     *
     * @param int    $booking_group_id
     * @param string $reference  Payment reference / transaction ID.
     * @return bool
     */
    public function mark_invoice_paid(int $booking_group_id, string $reference = ''): bool
    {
        global $wpdb;

        $groups_table = DatabaseService::get_table_name('booking_groups');
        $bookings_table = DatabaseService::get_table_name('bookings');
        $tx_table = DatabaseService::get_table_name('payment_transactions');

        $group = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$groups_table} WHERE id = %d",
            $booking_group_id
        ));

        if (!$group || $group->payment_status !== 'invoiced') {
            return false;
        }

        // Update booking group
        $wpdb->update(
            $groups_table,
            ['payment_status' => 'paid', 'updated_at' => current_time('mysql')],
            ['id' => $booking_group_id],
            ['%s', '%s'],
            ['%d']
        );

        // Update all child bookings
        $wpdb->update(
            $bookings_table,
            ['payment_status' => 'paid', 'updated_at' => current_time('mysql')],
            ['booking_group_id' => $booking_group_id],
            ['%s', '%s'],
            ['%d']
        );

        // Record manual payment transaction
        $wpdb->insert($tx_table, [
            'booking_group_id'       => $booking_group_id,
            'transaction_type'       => 'charge',
            'amount'                 => $group->total_amount,
            'currency'               => $group->currency,
            'payment_gateway'        => 'manual',
            'gateway'                => 'manual',
            'gateway_transaction_id' => $reference,
            'status'                 => 'succeeded',
            'metadata'               => wp_json_encode(['type' => 'invoice_settlement', 'reference' => $reference]),
            'created_at'             => current_time('mysql'),
        ], ['%d', '%s', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);

        return true;
    }

    /**
     * Get all outstanding invoices.
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
}
