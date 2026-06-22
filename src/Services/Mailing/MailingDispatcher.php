<?php

/**
 * Mailing Dispatcher.
 *
 * Listens to booking lifecycle hooks and delegates to whichever mailing
 * provider is configured in Settings → Mailing.
 *
 * Adding a new provider in future:
 *   1. Create `src/Services/Mailing/MailchimpMailingService.php` that
 *      implements `HMWEvents\Interfaces\MailingServiceInterface`.
 *   2. Add a `'mailchimp'` case in `resolve_service()` below.
 *   3. Add `mailchimp` as an option in the Admin settings radio field.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services\Mailing;

use HMWEvents\Interfaces\MailingServiceInterface;

defined('ABSPATH') || die('Don\'t run this file directly!');

class MailingDispatcher
{
    public function __construct()
    {
        // Fire on booking confirmed (status → confirmed)
        add_action('hmwevents_booking_confirmed', [$this, 'handle_booking_confirmed'], 20, 2);
    }

    /**
     * Called when a booking status changes to "confirmed".
     *
     * @param int   $booking_id Booking ID.
     * @param array $data       Additional data (currently empty from the gateway).
     */
    public function handle_booking_confirmed(int $booking_id, array $data): void
    {
        $service = $this->resolve_service();

        if ($service === null) {
            return; // No mailing provider configured – nothing to do.
        }

        try {
            $service->sync_booking($booking_id);
        } catch (\Throwable $e) {
            error_log("HMWEvents MailingDispatcher: exception for booking {$booking_id}: " . $e->getMessage());
        }
    }

    /**
     * Resolve the active mailing service from settings.
     *
     * Returns null when the provider is 'none' or misconfigured.
     *
     * @return MailingServiceInterface|null
     */
    private function resolve_service(): ?MailingServiceInterface
    {
        /**
         * Allow third-party code (or tests) to override the resolved service.
         *
         * @param MailingServiceInterface|null $service Currently resolved service (null = not yet resolved).
         */
        $override = apply_filters('hmwevents_mailing_service', null);

        if ($override instanceof MailingServiceInterface) {
            return $override;
        }

        $provider = \HMWEvents\Helpers\ConfigHelper::get_option('hmwevents_mailing_provider', 'none');

        switch ($provider) {
            case 'mautic':
                return MauticMailingService::from_settings();

            // case 'mailchimp':
            //     return MailchimpMailingService::from_settings();

            default:
                return null;
        }
    }
}
