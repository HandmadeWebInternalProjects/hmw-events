<?php

/**
 * Payment Service.
 *
 * Orchestrates payment processing for event bookings using the Stripe
 * fallback hierarchy: organizer Stripe keys → global fallback keys.
 *
 * Handles:
 * - Live vs test mode
 * - Zero-dollar / free events (no Stripe call needed)
 * - Invoiced / pay-later (Net Terms)
 * - Payment intent creation and confirmation
 * - Transaction record creation in hmwevents_payment_transactions
 *
 * @package HMWEvents\Services
 * @since 2.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Helpers\Encryption;

defined('ABSPATH') || die('Don\'t run this file directly!');

class PaymentService
{
    private const MODE_TEST = 'test';
    private const MODE_LIVE = 'live';

    private string $mode;
    private ?string $stripe_secret = null;
    private ?string $stripe_publishable = null;
    private string $key_source = 'none';

    public function __construct(?int $organizer_user_id = null)
    {
        $this->mode = $this->resolve_mode();
        $this->resolve_keys($organizer_user_id);
    }

    /**
     * Register hooks.
     */
    public function register(): void
    {
        add_action('hmwevents_registration_validated', [$this, 'process_registration_payment'], 20, 3);
        add_filter('hmwevents_booking_amount_breakdown', [$this, 'attach_gst_breakdown'], 10, 2);
    }

    // ================================================================
    // KEY RESOLUTION
    // ================================================================

    /**
     * Resolve the payment mode (test or live).
     */
    private function resolve_mode(): string
    {
        $test_mode = get_option('hmwevents_test_mode', true);
        return $test_mode ? self::MODE_TEST : self::MODE_LIVE;
    }

    /**
     * Resolve Stripe keys using the fallback hierarchy.
     *
     * Test mode: global test keys always.
     * Live mode: organizer keys first, fall back to global live keys.
     */
    private function resolve_keys(?int $organizer_user_id): void
    {
        if ($this->mode === self::MODE_TEST) {
            $this->stripe_secret      = $this->decrypt_option('hmwevents_stripe_test_secret');
            $this->stripe_publishable = $this->decrypt_option('hmwevents_stripe_test_publishable');
            $this->key_source         = 'global_test';
            return;
        }

        // Live mode — try organizer keys first
        if ($organizer_user_id) {
            $org_secret = get_user_meta($organizer_user_id, 'hmw_organizer_stripe_secret', true);
            $org_pub    = get_user_meta($organizer_user_id, 'hmw_organizer_stripe_publishable', true);

            if (!empty($org_secret) && !empty($org_pub)) {
                $this->stripe_secret      = Encryption::decrypt($org_secret);
                $this->stripe_publishable = Encryption::decrypt($org_pub);
                $this->key_source         = 'organizer_' . $organizer_user_id;
                return;
            }
        }

        // Fallback to global live keys
        $this->stripe_secret      = $this->decrypt_option('hmwevents_stripe_live_secret');
        $this->stripe_publishable = $this->decrypt_option('hmwevents_stripe_live_publishable');
        $this->key_source         = 'global_live';
    }

    /**
     * Decrypt an option value.
     */
    private function decrypt_option(string $key): ?string
    {
        $encrypted = get_option($key);
        if (empty($encrypted)) {
            return null;
        }

        // Handle plaintext keys (legacy migration path)
        if (str_starts_with($encrypted, 'sk_') || str_starts_with($encrypted, 'rk_')) {
            return $encrypted;
        }

        return Encryption::decrypt($encrypted) ?: null;
    }

    /**
     * Check if the service is properly configured with keys.
     */
    public function has_keys(): bool
    {
        return !empty($this->stripe_secret);
    }

    // ================================================================
    // PAYMENT PROCESSING
    // ================================================================

    /**
     * Process payment for a validated registration.
     *
     * Hooked into hmwevents_registration_validated.
     *
     * @param int    $event_id
     * @param array  $registration_data ['meta' => [...], 'details' => [...], 'documents' => [...]]
     * @param string $attendance_type
     */
    public function process_registration_payment(int $event_id, array $registration_data, string $attendance_type): void
    {
        $amount = (float) ($registration_data['amount'] ?? 0);

        // Determine if this is Net Terms
        $is_net_terms = !empty($registration_data['net_terms']) || !empty($registration_data['pay_later']);

        if ($is_net_terms) {
            do_action('hmwevents_net_terms_registration', $event_id, $registration_data, $attendance_type);
            return;
        }

        // Zero-dollar / free event
        if ($amount <= 0) {
            do_action('hmwevents_free_registration', $event_id, $registration_data, $attendance_type);
            return;
        }

        // Paid event — create payment intent via Stripe
        $this->create_payment_intent($event_id, $registration_data, $attendance_type, $amount);
    }

    /**
     * Create a Stripe PaymentIntent for a booking.
     *
     * @return array|\WP_Error Payment intent data or error.
     */
    public function create_payment_intent(
        int $event_id,
        array $registration_data,
        string $attendance_type,
        float $amount
    ): array|\WP_Error {
        if (!$this->has_keys()) {
            return new \WP_Error(
                'no_stripe_keys',
                __('Payment gateway is not configured.', 'hmw-events')
            );
        }

        // Set up Stripe client
        \Stripe\Stripe::setApiKey($this->stripe_secret);
        \Stripe\Stripe::setApiVersion('2025-06-30.acacia');

        $gst = GstCalculator::booking_breakdown($amount, false);

        try {
            $intent = \Stripe\PaymentIntent::create([
                'amount'   => (int) round($gst['final_total'] * 100), // cents
                'currency' => 'aud',
                'metadata' => [
                    'event_id'        => $event_id,
                    'attendance_type' => $attendance_type,
                    'key_source'      => $this->key_source,
                    'plugin'          => 'hmw-events-v2',
                ],
                'description' => sprintf(
                    __('Event Registration — #%d', 'hmw-events'),
                    $event_id
                ),
            ]);

            /**
             * Action: hmwevents_payment_intent_created
             *
             * @param \Stripe\PaymentIntent $intent
             * @param int    $event_id
             * @param array  $registration_data
             * @param array  $gst_breakdown
             */
            do_action('hmwevents_payment_intent_created', $intent, $event_id, $registration_data, $gst);

            return [
                'client_secret' => $intent->client_secret,
                'intent_id'     => $intent->id,
                'gst'           => $gst,
            ];

        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('HMWEvents Stripe error: ' . $e->getMessage());
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    /**
     * Attach GST breakdown to a booking amount.
     *
     * Filter: hmwevents_booking_amount_breakdown
     */
    public function attach_gst_breakdown(array $breakdown, float $amount): array
    {
        return array_merge($breakdown, GstCalculator::booking_breakdown($amount));
    }
}
