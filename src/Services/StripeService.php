<?php

/**
 * Stripe Service.
 *
 * Handles all Stripe SDK interactions for payment processing.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Services;

use HMWEvents\Helpers\Encryption;
use HMWEvents\Helpers\ConfigHelper;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Stripe Service Class.
 */
class StripeService
{
    /**
     * Stripe API instance.
     *
     * @var \Stripe\StripeClient|null
     */
    private $stripe = null;

    /**
     * Initialize the service.
     *
     * @since 1.0.0
     */
    public function register()
    {
        // Initialize Stripe on init
        add_action('init', [$this, 'init_stripe']);
    }

    /**
     * Initialize Stripe SDK.
     *
     * @since 1.0.0
     */
    public function init_stripe()
    {
        // Load Stripe SDK if not already loaded
        if (!class_exists('\Stripe\Stripe')) {
            require_once HMWEvents_ABSPATH . 'vendor/autoload.php';
        }

        // Get API keys from options based on mode
        $mode = ConfigHelper::get_option('hmwevents_stripe_mode', 'test');
        $prefix = $mode === 'live' ? 'hmwevents_stripe_live_' : 'hmwevents_stripe_test_';
        $secret_key_encrypted = ConfigHelper::get_option($prefix . 'secret_key', '');

        if (empty($secret_key_encrypted)) {
            error_log('HMWEvents: Stripe secret key not configured for ' . $mode . ' mode');
            return;
        }

        // Check if the key is already plain text (starts with sk_)
        // This handles legacy keys that haven't been encrypted yet
        if (strpos($secret_key_encrypted, 'sk_') === 0) {
            error_log('HMWEvents: Warning - Using plain text Stripe secret key for ' . $mode . ' mode. Please run migration.');
            $secret_key = $secret_key_encrypted;
        } else {
            // Decrypt the secret key
            $secret_key = Encryption::decrypt($secret_key_encrypted);

            if ($secret_key === false || empty($secret_key)) {
                error_log('HMWEvents: Failed to decrypt Stripe secret key for ' . $mode . ' mode');
                return;
            }
        }

        // Set API key
        \Stripe\Stripe::setApiKey($secret_key);

        // Initialize Stripe client
        $this->stripe = new \Stripe\StripeClient($secret_key);
    }

    /**
     * Initialize Stripe SDK with specific API key.
     *
     * @since 1.0.0
     * @param string      $secret_key Secret API key.
     * @param string|null $publishable_key Optional publishable key for reference.
     * @return void
     */
    public function init_stripe_with_key($secret_key, $publishable_key = null)
    {
        // Load Stripe SDK if not already loaded
        if (!class_exists('\Stripe\Stripe')) {
            require_once HMWEvents_ABSPATH . 'vendor/autoload.php';
        }

        if (empty($secret_key)) {
            error_log('HMWEvents: Cannot initialize Stripe - secret key is empty');
            return;
        }

        // Set API key
        \Stripe\Stripe::setApiKey($secret_key);

        // Initialize Stripe client
        $this->stripe = new \Stripe\StripeClient($secret_key);
    }

    /**
     * Get Stripe client instance.
     *
     * @since 1.0.0
     * @return \Stripe\StripeClient|null
     */
    public function get_client()
    {
        return $this->stripe;
    }

  /**
   * Create a payment intent.
   *
   * @since 1.0.0
   * @param float  $amount Amount in dollars.
   * @param string $currency Currency code (default: AUD).
   * @param array  $metadata Additional metadata.
   * @return \Stripe\PaymentIntent|\WP_Error
   */
  public function create_payment_intent($amount, $currency = 'AUD', $metadata = [])
  {
    try {
      $payment_intent = $this->stripe->paymentIntents->create([
        'amount' => $this->convert_to_cents($amount),
        'currency' => strtolower($currency),
        'automatic_payment_methods' => [
          'enabled' => true,
          'allow_redirects' => 'never', // ← ADD THIS LINE
        ],
        'metadata' => $metadata,
      ]);

      return $payment_intent;
    } catch (\Stripe\Exception\ApiErrorException $e) {
      error_log('HMWEvents Stripe Error: ' . $e->getMessage());
      return new \WP_Error('stripe_error', $e->getMessage());
    }
  }

  /**
   * Create and confirm a payment intent in one step.
   *
   * @since 1.0.0
   * @param float  $amount Amount in dollars.
   * @param string $payment_method_id Payment method ID from frontend.
   * @param string $currency Currency code (default: AUD).
   * @param array  $metadata Additional metadata.
   * @return \Stripe\PaymentIntent|\WP_Error
   */
  public function create_and_confirm_payment($amount, $payment_method_id, $currency = 'AUD', $metadata = [])
  {
    try {
      $payment_intent = $this->stripe->paymentIntents->create([
        'amount' => $this->convert_to_cents($amount),
        'currency' => strtolower($currency),
        'payment_method' => $payment_method_id,
        'confirm' => true, // Confirm immediately
        'automatic_payment_methods' => [
          'enabled' => true,
          'allow_redirects' => 'never', // Disable redirect payment methods
        ],
        'metadata' => $metadata,
      ]);

      return $payment_intent;
    } catch (\Stripe\Exception\CardException $e) {
      error_log('HMWEvents Stripe Card Error: ' . $e->getMessage());
      return new \WP_Error('card_error', $e->getError()->message);
    } catch (\Stripe\Exception\ApiErrorException $e) {
      error_log('HMWEvents Stripe Error: ' . $e->getMessage());
      return new \WP_Error('stripe_error', $e->getMessage());
    }
  }

    /**
     * Create a customer in Stripe.
     *
     * @since 1.0.0
     * @param string $email Customer email.
     * @param array  $data Additional customer data.
     * @return \Stripe\Customer|\WP_Error
     */
    public function create_customer($email, $data = [])
    {
        try {
            $customer_data = array_merge([
                'email' => $email,
            ], $data);

            $customer = $this->stripe->customers->create($customer_data);

            return $customer;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('HMWEvents Stripe Error: ' . $e->getMessage());
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    /**
     * Retrieve a customer from Stripe.
     *
     * @since 1.0.0
     * @param string $customer_id Stripe customer ID.
     * @return \Stripe\Customer|\WP_Error
     */
    public function get_customer($customer_id)
    {
        try {
            $customer = $this->stripe->customers->retrieve($customer_id);
            return $customer;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('HMWEvents Stripe Error: ' . $e->getMessage());
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    /**
     * Create a refund.
     *
     * @since 1.0.0
     * @param string $charge_id Stripe charge/payment intent ID.
     * @param float  $amount Refund amount in dollars (optional, full refund if not specified).
     * @param string $reason Refund reason.
     * @return \Stripe\Refund|\WP_Error
     */
    public function create_refund($charge_id, $amount = null, $reason = 'requested_by_customer')
    {
        try {
            $refund_data = [
                'payment_intent' => $charge_id,
                'reason' => $reason,
            ];

            if ($amount !== null) {
                $refund_data['amount'] = $this->convert_to_cents($amount);
            }

            $refund = $this->stripe->refunds->create($refund_data);

            return $refund;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('HMWEvents Stripe Error: ' . $e->getMessage());
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    /**
     * Retrieve a payment intent.
     *
     * @since 1.0.0
     * @param string $payment_intent_id Payment intent ID.
     * @return \Stripe\PaymentIntent|\WP_Error
     */
    public function get_payment_intent($payment_intent_id)
    {
        try {
            $payment_intent = $this->stripe->paymentIntents->retrieve($payment_intent_id);
            return $payment_intent;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('HMWEvents Stripe Error: ' . $e->getMessage());
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    /**
     * Confirm a payment intent.
     *
     * @since 1.0.0
     * @param string $payment_intent_id Payment intent ID.
     * @param array  $params Additional parameters.
     * @return \Stripe\PaymentIntent|\WP_Error
     */
    public function confirm_payment_intent($payment_intent_id, $params = [])
    {
        try {
            $payment_intent = $this->stripe->paymentIntents->confirm($payment_intent_id, $params);
            return $payment_intent;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            error_log('HMWEvents Stripe Error: ' . $e->getMessage());
            return new \WP_Error('stripe_error', $e->getMessage());
        }
    }

    /**
     * Convert dollars to cents.
     *
     * @since 1.0.0
     * @param float $amount Amount in dollars.
     * @return int Amount in cents.
     */
    private function convert_to_cents($amount)
    {
        return (int) round($amount * 100);
    }

    /**
     * Convert cents to dollars.
     *
     * @since 1.0.0
     * @param int $amount Amount in cents.
     * @return float Amount in dollars.
     */
    public function convert_to_dollars($amount)
    {
        return (float) ($amount / 100);
    }

    /**
     * Construct webhook signature for verification.
     *
     * @since 1.0.0
     * @param string $payload Webhook payload.
     * @param string $signature Stripe signature header.
     * @return \Stripe\Event|\WP_Error
     */
    public function construct_webhook_event($payload, $signature)
    {
        try {
            $mode = ConfigHelper::get_option('hmwevents_stripe_mode', 'test');
            $prefix = $mode === 'live' ? 'hmwevents_stripe_live_' : 'hmwevents_stripe_test_';
            $webhook_secret_encrypted = ConfigHelper::get_option($prefix . 'webhook_secret', '');

            if (empty($webhook_secret_encrypted)) {
                return new \WP_Error('webhook_error', 'Webhook secret not configured for ' . $mode . ' mode');
            }

            // Decrypt the webhook secret
            $webhook_secret = Encryption::decrypt($webhook_secret_encrypted);

            if ($webhook_secret === false || empty($webhook_secret)) {
                return new \WP_Error('webhook_error', 'Failed to decrypt webhook secret for ' . $mode . ' mode');
            }

            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $webhook_secret
            );

            return $event;
        } catch (\UnexpectedValueException $e) {
            return new \WP_Error('webhook_error', 'Invalid payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            return new \WP_Error('webhook_error', 'Invalid signature');
        }
    }

    /**
     * Check if Stripe is configured.
     *
     * @since 1.0.0
     * @return bool
     */
    public function is_configured()
    {
        $mode = ConfigHelper::get_option('hmwevents_stripe_mode', 'test');
        $prefix = $mode === 'live' ? 'hmwevents_stripe_live_' : 'hmwevents_stripe_test_';
        $secret_key_encrypted = ConfigHelper::get_option($prefix . 'secret_key', '');

        if (empty($secret_key_encrypted)) {
            return false;
        }

        // Try to decrypt to verify it's valid
        $secret_key = Encryption::decrypt($secret_key_encrypted);
        return ($secret_key !== false && !empty($secret_key));
    }
}
