<?php
/**
 * Stripe Helper Functions.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Helpers;

use HMWEvents\Helpers\ConfigHelper;
use HMWEvents\Services\OrganizerPaymentSettings;
use HMWEvents\Helpers\Encryption;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Stripe Helper Class.
 */
class StripeHelper
{
    /**
     * Get the appropriate Stripe publishable key based on mode.
     *
     * In test mode: Returns system test publishable key
     * In live mode: Returns organizer's publishable key
     *
     * @since 1.0.0
     * @param int|null $organizer_id Optional organizer ID. If not provided, will try to get from current course.
     * @return string Stripe publishable key (decrypted for live use).
     */
    public static function get_publishable_key($organizer_id = null)
    {
        $mode = ConfigHelper::get_option('hmwevents_stripe_mode', 'test');

        if ($mode === 'test') {
            $key = ConfigHelper::get_option('hmwevents_stripe_test_publishable_key', '');

            if (empty($key)) {
                error_log('HMWEvents: Stripe test publishable key not configured');
            }

            return $key;
        }

        if (!$organizer_id) {
            $post_id = get_the_ID();
            if ($post_id) {
                $organizer_id = (int) get_post_meta($post_id, '_organizer_id', true) ?: null;
            }
        }

        if (!$organizer_id) {
            error_log('HMWEvents: No organizer ID provided for live mode Stripe key');
            return '';
        }

        $settings = new OrganizerPaymentSettings((int) $organizer_id);
        $key = $settings->get_stripe_publishable_key();

        if (empty($key)) {
            error_log('HMWEvents: Organizer ' . $organizer_id . ' has no Stripe publishable key configured');
            return '';
        }

        return self::decrypt_if_encrypted($key);
    }

    /**
     * Decrypt a value if it is encrypted (does not start with a known Stripe key prefix).
     */
    private static function decrypt_if_encrypted(string $value): string
    {
        if (str_starts_with($value, 'pk_') || str_starts_with($value, 'sk_') || str_starts_with($value, 'rk_')) {
            return $value;
        }

        $decrypted = Encryption::decrypt($value);
        return $decrypted !== false ? $decrypted : '';
    }
}
