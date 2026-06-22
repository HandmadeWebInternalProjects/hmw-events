<?php
/**
 * Stripe Key Migration Helper.
 *
 * Migrates and encrypts educator Stripe keys.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Helpers;

use HMWEvents\Helpers\Encryption;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Stripe Key Migration Class.
 */
class StripeKeyMigration
{
    /**
     * Migrate all educator Stripe keys.
     *
     * This function:
     * 1. Migrates educator_payment_key to educator_stripe_key (publishable keys)
     * 2. Encrypts plain text secret keys
     * 3. Validates all keys are in correct format
     *
     * @since 1.0.0
     * @return array Migration results.
     */
    public static function migrate_all_educator_keys()
    {
        $results = [
            'success' => 0,
            'errors' => 0,
            'skipped' => 0,
            'details' => [],
        ];

        // Get all educators
        $educators = get_users(['role' => 'educator']);

        foreach ($educators as $educator) {
            $user_id = 'user_' . $educator->ID;
            $result = self::migrate_educator_keys($user_id, $educator->user_email);
            
            if ($result['success']) {
                $results['success']++;
            } elseif ($result['skipped']) {
                $results['skipped']++;
            } else {
                $results['errors']++;
            }
            
            $results['details'][] = $result;
        }

        return $results;
    }

    /**
     * Migrate keys for a single educator.
     *
     * @since 1.0.0
     * @param string $user_id ACF user ID format (user_123).
     * @param string $email Educator email for logging.
     * @return array Migration result.
     */
    public static function migrate_educator_keys($user_id, $email)
    {
        $result = [
            'user_id' => $user_id,
            'email' => $email,
            'success' => false,
            'skipped' => false,
            'changes' => [],
            'errors' => [],
        ];

        // Get current values
        $payment_type = get_field('educator_payment_type', $user_id);
        $stripe_key = get_field('educator_stripe_key', $user_id);
        $stripe_secret = get_field('educator_stripe_secret', $user_id);
        $payment_key = get_field('educator_payment_key', $user_id);

        // Skip if not using Stripe
        if ($payment_type !== 'stripe') {
            $result['skipped'] = true;
            $result['changes'][] = 'Not using Stripe payment gateway';
            return $result;
        }

        // Step 1: Migrate publishable key from educator_payment_key to educator_stripe_key
        if (empty($stripe_key) && !empty($payment_key)) {
            // Check if payment_key looks like a Stripe publishable key
            if (strpos($payment_key, 'pk_') === 0) {
                update_field('educator_stripe_key', $payment_key, $user_id);
                $result['changes'][] = 'Migrated publishable key from educator_payment_key to educator_stripe_key';
                $stripe_key = $payment_key;
                
                // Clear old field
                update_field('educator_payment_key', '', $user_id);
            }
        }

        // Step 2: Validate publishable key exists
        if (empty($stripe_key)) {
            $result['errors'][] = 'No Stripe publishable key found';
        } elseif (strpos($stripe_key, 'pk_') !== 0) {
            $result['errors'][] = 'Invalid publishable key format (should start with pk_)';
        }

        // Step 3: Encrypt secret key if it's plain text
        if (!empty($stripe_secret)) {
            // Check if it's already encrypted (encrypted values are hex-encoded and much longer)
            // Plain text secret keys start with sk_ and are typically 108 characters
            // Encrypted values are hex-encoded and much longer
            if (strpos($stripe_secret, 'sk_') === 0) {
                // It's plain text, encrypt it
                $encrypted = Encryption::encrypt($stripe_secret);
                
                if ($encrypted !== false) {
                    update_field('educator_stripe_secret', $encrypted, $user_id);
                    $result['changes'][] = 'Encrypted plain text secret key';
                } else {
                    $result['errors'][] = 'Failed to encrypt secret key';
                }
            } else {
                // Verify it's actually encrypted and can be decrypted
                $decrypted = Encryption::decrypt($stripe_secret);
                
                if ($decrypted === false) {
                    $result['errors'][] = 'Secret key appears corrupted (cannot decrypt)';
                } elseif (strpos($decrypted, 'sk_') !== 0) {
                    $result['errors'][] = 'Decrypted secret key has invalid format';
                } else {
                    $result['changes'][] = 'Secret key already encrypted and valid';
                }
            }
        } else {
            $result['errors'][] = 'No Stripe secret key found';
        }

        // Determine overall success
        if (empty($result['errors'])) {
            $result['success'] = true;
        }

        return $result;
    }

    /**
     * Generate a migration report.
     *
     * @since 1.0.0
     * @param array $results Migration results from migrate_all_educator_keys().
     * @return string HTML report.
     */
    public static function generate_report($results)
    {
        $html = '<div style="padding: 20px; font-family: sans-serif;">';
        $html .= '<h2>Stripe Key Migration Report</h2>';
        $html .= '<p><strong>Total Educators:</strong> ' . count($results['details']) . '</p>';
        $html .= '<p><strong>Successful:</strong> ' . $results['success'] . '</p>';
        $html .= '<p><strong>Errors:</strong> ' . $results['errors'] . '</p>';
        $html .= '<p><strong>Skipped:</strong> ' . $results['skipped'] . '</p>';
        
        $html .= '<h3>Details</h3>';
        $html .= '<table style="width: 100%; border-collapse: collapse;">';
        $html .= '<thead><tr style="background: #f5f5f5;">';
        $html .= '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Email</th>';
        $html .= '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Status</th>';
        $html .= '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Changes</th>';
        $html .= '<th style="padding: 8px; border: 1px solid #ddd; text-align: left;">Errors</th>';
        $html .= '</tr></thead><tbody>';
        
        foreach ($results['details'] as $detail) {
            $status = $detail['success'] ? '✓ Success' : ($detail['skipped'] ? '— Skipped' : '✗ Error');
            $status_color = $detail['success'] ? '#28a745' : ($detail['skipped'] ? '#6c757d' : '#dc3545');
            
            $html .= '<tr>';
            $html .= '<td style="padding: 8px; border: 1px solid #ddd;">' . esc_html($detail['email']) . '</td>';
            $html .= '<td style="padding: 8px; border: 1px solid #ddd; color: ' . $status_color . ';"><strong>' . $status . '</strong></td>';
            $html .= '<td style="padding: 8px; border: 1px solid #ddd;">' . implode('<br>', array_map('esc_html', $detail['changes'])) . '</td>';
            $html .= '<td style="padding: 8px; border: 1px solid #ddd; color: #dc3545;">' . implode('<br>', array_map('esc_html', $detail['errors'])) . '</td>';
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
        
        return $html;
    }

    /**
     * Check if a string is likely encrypted.
     *
     * @since 1.0.0
     * @param string $value Value to check.
     * @return bool True if likely encrypted.
     */
    private static function is_encrypted($value)
    {
        // Encrypted values from Defuse are:
        // - Much longer than plain text
        // - Contain only hex characters after the header
        // - Start with "def" prefix
        
        if (empty($value) || strlen($value) < 100) {
            return false;
        }
        
        // Try to decrypt it - if it works, it's encrypted
        $decrypted = Encryption::decrypt($value);
        return $decrypted !== false;
    }
}
