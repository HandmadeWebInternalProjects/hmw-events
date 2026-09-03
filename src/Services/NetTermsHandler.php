<?php

/**
 * Net Terms Handler.
 *
 * Manages the "Pay Later" / Net Terms registration flow. When an event
 * supports invoiced payment, the booking is created with
 * payment_status = 'invoiced' and no upfront Stripe charge. The invoice
 * is generated and emailed, and payment is reconciled separately.
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

defined('ABSPATH') || die('Don\'t run this file directly!');

class NetTermsHandler
{
    private ?EventDataService $event_data_service = null;
    private ?InvoiceService $invoice_service = null;

    private function event_data(): EventDataService
    {
        if ($this->event_data_service === null) {
            $this->event_data_service = new EventDataService();
        }
        return $this->event_data_service;
    }

    private function invoice_service(): InvoiceService
    {
        if ($this->invoice_service === null) {
            $this->invoice_service = new InvoiceService();
        }
        return $this->invoice_service;
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('hmwevents_net_terms_booking_created', [$this, 'on_net_terms_booking_created'], 10, 2);
    }

    /**
     * Check if an event supports Net Terms payment.
     *
     * Reads from event meta: _event_allow_net_terms
     */
    public function event_supports_net_terms(int $event_id): bool
    {
        return $this->event_data()->get_allow_net_terms($event_id) === true;
    }

    /**
     * Issue the invoice when a net terms booking is created.
     *
     * @param int $booking_id Bookings table ID.
     * @param int $group_id   Booking group ID.
     */
    public function on_net_terms_booking_created(int $booking_id, int $group_id): void
    {
        $this->invoice_service()->issue_invoice($booking_id, $group_id);
    }
}
