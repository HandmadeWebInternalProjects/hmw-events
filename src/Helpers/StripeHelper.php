<?php
/**
 * Stripe Helper Functions.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Helpers;

use HMWEvents\Helpers\ConfigHelper;

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
     * In live mode: Returns educator's publishable key
     *
     * @since 1.0.0
     * @param int|null $educator_id Optional educator ID. If not provided, will try to get from current course.
     * @return string Stripe publishable key.
     */
    public static function get_publishable_key($educator_id = null)
    {
        $mode = ConfigHelper::get_option('hmwevents_stripe_mode', 'test');
        
        // Test mode: use system test key
        if ($mode === 'test') {
            $key = ConfigHelper::get_option('hmwevents_stripe_test_publishable_key', '');
            
            if (empty($key)) {
                error_log('HMWEvents: Stripe test publishable key not configured');
            }
            
            return $key;
        }
        
        // Live mode: use educator's key
        if (!$educator_id) {
            // Try to get from current course
            $educator_id = get_field('course_educator_id', get_the_ID());
        }
        
        if (!$educator_id) {
            error_log('HMWEvents: No educator ID provided for live mode Stripe key');
            return '';
        }
        
        $key = get_user_meta($educator_id, 'educator_stripe_key', true);
        
        if (empty($key)) {
            error_log('HMWEvents: Educator ' . $educator_id . ' has no Stripe publishable key configured');
        }
        
        return $key ?: '';
    }
}
