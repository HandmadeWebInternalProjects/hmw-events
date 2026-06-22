<?php
/**
 * Payment Gateway Interface.
 *
 * Defines the contract that all payment gateway implementations must follow.
 *
 * @package HMWEvents
 * @since 1.0.0
 */

namespace HMWEvents\Interfaces;

defined('ABSPATH') || die('Don\'t run this file directly!');

/**
 * Payment Gateway Interface.
 */
interface PaymentGatewayInterface
{
    /**
     * Get the gateway identifier.
     *
     * @return string Gateway ID (e.g., 'stripe', 'paypal').
     */
    public function get_gateway_id();

    /**
     * Get the gateway display name.
     *
     * @return string Gateway name (e.g., 'Stripe', 'PayPal').
     */
    public function get_gateway_name();

  /*
      * Get the gateway service instance.
      *
      * @return mixed Gateway service instance.
      */
  public function get_gateway_client();

    /**
     * Check if gateway is properly configured and available.
     *
     * @return bool True if gateway is ready to process payments.
     */
    public function is_available();

    /**
     * Initialize the gateway.
     *
     * @return void
     */
    public function register();

    /**
     * Process a booking with payment intent creation.
     *
     * @param array $booking_data Booking information including customer, course details.
     * @return array|\WP_Error Result with booking and payment info, or error.
     */
    public function process_booking($booking_data);

    /**
     * Process payment with immediate confirmation (single-step).
     *
     * @param array $booking_data Booking data including payment_method_id.
     * @return array|\WP_Error Result with booking details, or error.
     */
    public function process_payment_with_confirmation($booking_data);

    /**
     * Confirm a payment.
     *
     * @param string $transaction_id Gateway transaction ID.
     * @return bool|\WP_Error True on success, error on failure.
     */
    public function confirm_payment($transaction_id);

    /**
     * Get payment status from gateway.
     *
     * @param string $transaction_id Gateway transaction ID.
     * @return array|\WP_Error Payment status data or error.
     */
    public function get_payment_status($transaction_id);

    /**
     * Create or get a customer in the gateway system.
     *
     * @param string $email Customer email.
     * @param array  $data Additional customer data.
     * @return string|\WP_Error Gateway customer ID or error.
     */
    public function create_or_get_customer($email, $data = []);

    /**
     * Refund a payment.
     *
     * @param string $transaction_id Gateway transaction ID.
     * @param float  $amount Amount to refund (null for full refund).
     * @param string $reason Refund reason.
     * @return array|\WP_Error Refund result or error.
     */
    public function refund_payment($transaction_id, $amount = null, $reason = '');

    /**
     * Get supported currencies.
     *
     * @return array Array of currency codes.
     */
    public function get_supported_currencies();

    /**
     * Get the webhook URL for this gateway.
     *
     * @return string Webhook URL.
     */
    public function get_webhook_url();

    /**
     * Verify webhook signature.
     *
     * @param string $payload Webhook payload.
     * @param string $signature Webhook signature.
     * @return bool|\WP_Error True if valid, error otherwise.
     */
    public function verify_webhook_signature($payload, $signature);

    /**
     * Get the publishable/public key for frontend use.
     *
     * @return string Publishable key (empty if not applicable for this gateway).
     */
    public function get_publishable_key();

    /**
     * Get diagnostic information about the gateway configuration.
     *
     * @return array Information about gateway setup and key sources.
     */
    public function get_key_info();
}
