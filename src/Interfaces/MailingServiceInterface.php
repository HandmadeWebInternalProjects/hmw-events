<?php

/**
 * Mailing Service Interface.
 *
 * Defines the contract for newsletter/CRM mailing providers.
 * Swap implementations (Mautic, Mailchimp, etc.) by changing the
 * `hmwevents_mailing_provider` setting without touching calling code.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Interfaces;

defined('ABSPATH') || die('Don\'t run this file directly!');

interface MailingServiceInterface
{
    /**
     * Sync a contact from a booking into the external mailing service.
     *
     * Implementations are responsible for:
     * – Looking up all required data from the booking.
     * – Creating or updating the contact.
     * – Applying appropriate tags (e.g. CourseBooked, MarketingOK).
     *
     * @param int $booking_id The HMWEvents booking ID.
     * @return void
     */
    public function sync_booking(int $booking_id): void;
}
