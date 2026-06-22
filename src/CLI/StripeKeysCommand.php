<?php
/**
 * WP-CLI Commands for Stripe Key Migration.
 *
 * Usage:
 * wp cms stripe-keys migrate
 * wp cms stripe-keys check
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\CLI;

use HMWEvents\Helpers\StripeKeyMigration;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Stripe Key Management Commands.
 */
class StripeKeysCommand
{
    /**
     * Migrate all educator Stripe keys.
     *
     * ## EXAMPLES
     *
     *     wp cms stripe-keys migrate
     *
     * @when after_wp_load
     */
    public function migrate($args, $assoc_args)
    {
        \WP_CLI::line('Starting Stripe key migration...');
        \WP_CLI::line('');

        $results = StripeKeyMigration::migrate_all_educator_keys();

        \WP_CLI::success('Migration complete!');
        \WP_CLI::line('');
        \WP_CLI::line('Results:');
        \WP_CLI::line('  Successful: ' . $results['success']);
        \WP_CLI::line('  Errors:     ' . $results['errors']);
        \WP_CLI::line('  Skipped:    ' . $results['skipped']);
        \WP_CLI::line('');

        if ($results['errors'] > 0) {
            \WP_CLI::line('Educators with errors:');
            foreach ($results['details'] as $detail) {
                if (!empty($detail['errors'])) {
                    \WP_CLI::warning($detail['email'] . ': ' . implode(', ', $detail['errors']));
                }
            }
        }

        if ($results['success'] > 0) {
            \WP_CLI::line('');
            \WP_CLI::line('Educators migrated successfully:');
            foreach ($results['details'] as $detail) {
                if ($detail['success']) {
                    \WP_CLI::line('  ✓ ' . $detail['email']);
                    if (!empty($detail['changes'])) {
                        foreach ($detail['changes'] as $change) {
                            \WP_CLI::line('    - ' . $change);
                        }
                    }
                }
            }
        }
    }

    /**
     * Check educator Stripe key status without making changes.
     *
     * ## EXAMPLES
     *
     *     wp cms stripe-keys check
     *
     * @when after_wp_load
     */
    public function check($args, $assoc_args)
    {
        \WP_CLI::line('Checking Stripe key status...');
        \WP_CLI::line('');

        $educators = get_users(['role' => 'educator']);
        $needs_migration = 0;
        $has_errors = 0;
        $ok = 0;

        foreach ($educators as $educator) {
            $user_id = 'user_' . $educator->ID;
            $payment_type = get_field('educator_payment_type', $user_id);
            
            if ($payment_type !== 'stripe') {
                continue;
            }

            $stripe_key = get_field('educator_stripe_key', $user_id);
            $stripe_secret = get_field('educator_stripe_secret', $user_id);
            $payment_key = get_field('educator_payment_key', $user_id);

            $issues = [];

            // Check publishable key
            if (empty($stripe_key) && !empty($payment_key) && strpos($payment_key, 'pk_') === 0) {
                $issues[] = 'Publishable key in old field (needs migration)';
            } elseif (empty($stripe_key)) {
                $issues[] = 'No publishable key';
            }

            // Check secret key
            if (!empty($stripe_secret)) {
                if (strpos($stripe_secret, 'sk_') === 0) {
                    $issues[] = 'Secret key not encrypted (needs migration)';
                }
            } else {
                $issues[] = 'No secret key';
            }

            if (!empty($issues)) {
                if (strpos(implode(' ', $issues), 'needs migration') !== false) {
                    $needs_migration++;
                    \WP_CLI::log('⚠ ' . $educator->user_email . ': ' . implode(', ', $issues));
                } else {
                    $has_errors++;
                    \WP_CLI::error('✗ ' . $educator->user_email . ': ' . implode(', ', $issues), false);
                }
            } else {
                $ok++;
                \WP_CLI::success('✓ ' . $educator->user_email, false);
            }
        }

        \WP_CLI::line('');
        \WP_CLI::line('Summary:');
        \WP_CLI::line('  OK:              ' . $ok);
        \WP_CLI::line('  Needs Migration: ' . $needs_migration);
        \WP_CLI::line('  Has Errors:      ' . $has_errors);

        if ($needs_migration > 0) {
            \WP_CLI::line('');
            \WP_CLI::line('Run: wp cms stripe-keys migrate');
        }
    }
}

// Register WP-CLI command
if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('hmwevents stripe-keys', 'HMWEvents\CLI\StripeKeysCommand');
}
