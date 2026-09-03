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

use HMWEvents\Helpers\ConfigHelper;
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
    }

    // ================================================================
    // KEY RESOLUTION
    // ================================================================

    /**
     * Resolve the payment mode (test or live).
     */
    private function resolve_mode(): string
    {
        return ConfigHelper::get_option('hmwevents_stripe_mode', self::MODE_TEST) === self::MODE_TEST
            ? self::MODE_TEST
            : self::MODE_LIVE;
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
            $this->stripe_secret      = $this->decrypt_option('hmwevents_stripe_test_secret_key');
            $this->stripe_publishable = $this->decrypt_option('hmwevents_stripe_test_publishable_key');
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
        $this->stripe_secret      = $this->decrypt_option('hmwevents_stripe_live_secret_key');
        $this->stripe_publishable = $this->decrypt_option('hmwevents_stripe_live_publishable_key');
        $this->key_source         = 'global_live';
    }

    /**
     * Decrypt an option value.
     */
    private function decrypt_option(string $key): ?string
    {
        $encrypted = ConfigHelper::get_option($key, null);
        if ($encrypted === null) {
            $encrypted = get_option($key);
        }

        if (empty($encrypted)) {
            return null;
        }

        // Handle plaintext keys (legacy migration path)
        if (str_starts_with($encrypted, 'pk_') || str_starts_with($encrypted, 'sk_') || str_starts_with($encrypted, 'rk_')) {
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
}
